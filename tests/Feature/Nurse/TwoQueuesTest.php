<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\MedicalAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ClinicQueueFixture;
use Tests\Support\EncodePayload;
use Tests\TestCase;

/**
 * D-126 — separate nurse and physician queues.
 *
 * Medical Assessment visits go to the NURSE, who interviews the student and
 * submits them to the physician; Medical Clearance visits go straight to the
 * PHYSICIAN; only the physician encodes Fit / Unfit. Each role sees only its
 * own queue (Q1), and a wrong role or state lands back on the Live Queue with
 * a plain message — never a 403 / 404 page.
 */
class TwoQueuesTest extends TestCase
{
    use ClinicQueueFixture;
    use RefreshDatabase;

    /** @return array<string, mixed> The nurse's interview payload: vitals + sections, no Result. */
    private function interviewPayload(array $overrides = []): array
    {
        return [
            ...EncodePayload::vitals(['respiratory_rate' => 24]),
            'medical_history' => ['patient' => ['asthma'], 'family' => ['hypertension'], 'specify' => ['hypertension' => '150/95']],
            'immunizations' => ['given' => ['bcg', 'hpv'], 'others' => 'Typhoid'],
            'family_planning_access' => '1',
            'surgical_history' => ['procedures' => 'Appendectomy', 'date_done' => '2019'],
            ...$overrides,
        ];
    }

    // ── Queues ───────────────────────────────────────────────────────────────

    public function test_the_nurse_sees_only_captured_assessments(): void
    {
        $assessment = $this->queueVisit('assessment');
        $this->queueVisit('clearance');
        $this->queueVisit('assessment', 'interviewed');

        $ids = $this->actingAs($this->nurse())->getJson(route('nurse.queue.feed'))
            ->assertOk()
            ->json('visits.*.id');

        $this->assertSame([$assessment->id], $ids);
    }

    public function test_the_physician_sees_captured_clearances_and_interviewed_visits_in_queue_entry_order(): void
    {
        // Entered the physician's queue at: kiosk 09:00 / nurse 09:40 / kiosk 09:20.
        $clearanceEarly = $this->queueVisit('clearance', checkedInAt: today()->setTime(9, 0));
        $interviewed = $this->queueVisit('assessment', 'interviewed', today()->setTime(8, 0), today()->setTime(9, 40));
        $clearanceLate = $this->queueVisit('clearance', checkedInAt: today()->setTime(9, 20));
        $this->queueVisit('assessment');                 // the nurse's
        $this->queueVisit('clearance', 'encoded');       // done

        $ids = $this->actingAs($this->physician())->getJson(route('nurse.queue.feed'))
            ->assertOk()
            ->json('visits.*.id');

        // COALESCE(interviewed_at, checked_in_at): the interviewed visit came
        // to the kiosk first but reached the physician last.
        $this->assertSame([$clearanceEarly->id, $clearanceLate->id, $interviewed->id], $ids);
    }

    public function test_the_feed_matches_the_page_for_each_role(): void
    {
        $this->queueVisit('assessment');
        $this->queueVisit('clearance');
        $this->queueVisit('assessment', 'interviewed');

        foreach ([$this->nurse(), $this->physician()] as $user) {
            $pageIds = $this->actingAs($user)->get(route('nurse.queue'))
                ->assertOk()
                ->viewData('visits')->pluck('id')->all();
            $feed = $this->actingAs($user)->getJson(route('nurse.queue.feed'))->json();

            $this->assertSame($pageIds, array_column($feed['visits'], 'id'), $user->role);
            $this->assertSame(count($pageIds), $feed['count']);
        }
    }

    public function test_each_role_gets_its_own_row_action(): void
    {
        $this->queueVisit('assessment');
        $this->queueVisit('clearance');

        $this->actingAs($this->nurse())->getJson(route('nurse.queue.feed'))
            ->assertJsonPath('visits.0.action_label', 'Interview');
        $this->actingAs($this->physician())->getJson(route('nurse.queue.feed'))
            ->assertJsonPath('visits.0.action_label', 'Encode Result');
    }

    public function test_the_nav_badge_and_dashboard_tile_count_the_viewers_own_queue(): void
    {
        $this->queueVisit('assessment');
        $this->queueVisit('clearance');
        $this->queueVisit('clearance');

        $this->actingAs($this->nurse())->get(route('nurse.dashboard'))
            ->assertViewHas('stats', fn (array $stats): bool => $stats['awaitingEncode'] === 1)
            ->assertSee('Awaiting Interview');
        $this->actingAs($this->physician())->get(route('nurse.dashboard'))
            ->assertViewHas('stats', fn (array $stats): bool => $stats['awaitingEncode'] === 2)
            ->assertSee('Awaiting Encode');
    }

    // ── Submit to physician ─────────────────────────────────────────────────

    public function test_the_nurses_submit_saves_the_interview_and_moves_the_visit_to_the_physician(): void
    {
        $visit = $this->queueVisit('assessment');
        $nurse = $this->nurse();

        $this->actingAs($nurse)->post(route('nurse.visits.interview', $visit), $this->interviewPayload())
            ->assertRedirect(route('nurse.queue'))
            ->assertSessionHas('status', "Submitted to the physician — {$visit->reference_no}.")
            ->assertSessionHas('left_queue_visit_id', $visit->id);

        $visit->refresh();
        $this->assertSame('interviewed', $visit->status);
        $this->assertSame($nurse->id, $visit->interviewed_by);
        $this->assertNotNull($visit->interviewed_at);

        $sections = $visit->medicalAssessment;
        $this->assertSame(['asthma'], $sections->medical_history['patient']);
        $this->assertSame('150/95', $sections->specifyFor('hypertension'));
        $this->assertTrue($sections->family_planning_access);
        // The confirmed vitals, encoded_vitals' shape, BMI recomputed (165 cm, 60 kg).
        $this->assertSame(24, $sections->interview_vitals['respiratory_rate']);
        $this->assertSame(22.0, (float) $sections->interview_vitals['bmi']);

        // RR + its flag on vital_signs (24 > 20, D-66).
        $this->assertSame(24, $visit->vitalSigns->respiratory_rate);
        $this->assertTrue((bool) $visit->vitalSigns->is_rr_flagged);

        // No result yet: nothing for the physician has been written.
        $this->assertNull($visit->clearanceRecord);
        $this->assertSame('scheduled', $visit->appointment->status);
    }

    public function test_the_nurses_queue_animates_a_submitted_row_out_once(): void
    {
        $visit = $this->queueVisit('assessment', 'interviewed');

        $this->actingAs($this->nurse())
            ->withSession(['left_queue_visit_id' => $visit->id])
            ->get(route('nurse.queue'))
            ->assertSee('data-leaving', false)
            ->assertSee('Submitted ✓');
    }

    public function test_a_double_submit_is_a_friendly_no_op(): void
    {
        $visit = $this->queueVisit('assessment');
        $nurse = $this->nurse();
        $this->actingAs($nurse)->post(route('nurse.visits.interview', $visit), $this->interviewPayload());

        $this->actingAs($nurse)->post(route('nurse.visits.interview', $visit), $this->interviewPayload(['respiratory_rate' => 14]))
            ->assertRedirect(route('nurse.queue'))
            ->assertSessionHas('status', "{$visit->reference_no} was already submitted to the physician.");

        $this->assertSame(1, MedicalAssessment::count());
        $this->assertSame(24, $visit->medicalAssessment()->sole()->interview_vitals['respiratory_rate']);
    }

    public function test_a_physician_posting_to_the_nurses_route_is_refused(): void
    {
        $visit = $this->queueVisit('assessment');

        $this->actingAs($this->physician())->post(route('nurse.visits.interview', $visit), $this->interviewPayload())
            ->assertRedirect(route('nurse.queue'))
            ->assertSessionHas('error');

        $this->assertSame('captured', $visit->fresh()->status);
        $this->assertSame(0, MedicalAssessment::count());
    }

    public function test_a_nurse_cannot_submit_a_medical_clearance(): void
    {
        $visit = $this->queueVisit('clearance');

        $this->actingAs($this->nurse())->post(route('nurse.visits.interview', $visit), $this->interviewPayload())
            ->assertRedirect(route('nurse.queue'))
            ->assertSessionHas('error');

        $this->assertSame('captured', $visit->fresh()->status);
    }

    public function test_a_nurse_posting_fit_or_unfit_is_refused_before_validation(): void
    {
        $assessment = $this->queueVisit('assessment');
        $clearance = $this->queueVisit('clearance');

        foreach ([$assessment, $clearance] as $visit) {
            // An empty body: refused for the role, not for missing fields.
            $this->actingAs($this->nurse())->post(route('nurse.visits.encode.store', $visit), [])
                ->assertRedirect(route('nurse.queue'))
                ->assertSessionHas('error')
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(0, ClearanceRecord::count());
    }

    // ── Opening a visit ─────────────────────────────────────────────────────

    public function test_a_nurse_opening_a_clearance_or_interviewed_visit_is_sent_back(): void
    {
        foreach ([$this->queueVisit('clearance'), $this->queueVisit('assessment', 'interviewed')] as $visit) {
            $this->actingAs($this->nurse())->get(route('nurse.visits.encode', $visit))
                ->assertRedirect(route('nurse.queue'))
                ->assertSessionHas('error', fn (string $message): bool => str_contains($message, "physician's queue"));
        }
    }

    public function test_a_physician_opening_a_captured_assessment_is_sent_back(): void
    {
        $visit = $this->queueVisit('assessment');

        $this->actingAs($this->physician())->get(route('nurse.visits.encode', $visit))
            ->assertRedirect(route('nurse.queue'))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, "nurse's interview"));
    }

    public function test_both_roles_can_view_an_encoded_visit(): void
    {
        $visit = $this->queueVisit('assessment', 'encoded');

        foreach ([$this->nurse(), $this->physician()] as $user) {
            $this->actingAs($user)->get(route('nurse.visits.encode', $visit))
                ->assertOk()
                ->assertViewHas('mode', 'view');
        }
    }

    public function test_the_nurses_interview_page_has_no_result_and_no_prints(): void
    {
        $visit = $this->queueVisit('assessment');

        $this->actingAs($this->nurse())->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertViewHas('mode', 'interview')
            ->assertSee('Submit to physician')
            ->assertSee(route('nurse.visits.interview', $visit), false)
            ->assertDontSee('name="result"', false)
            ->assertDontSee('Print front')
            ->assertDontSee('Save &amp; Close', false);
    }

    public function test_the_physicians_page_prefills_the_interview_and_names_the_nurse(): void
    {
        $visit = $this->queueVisit('assessment', 'interviewed');

        $this->actingAs($this->physician())->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertViewHas('mode', 'encode')
            ->assertSee('name="result"', false)
            ->assertSee('Interviewed by')
            ->assertSee($visit->interviewer->name)
            // interview_vitals win over the kiosk's 36.5 and the empty RR.
            ->assertSee('value="36.9"', false)
            ->assertSee('value="18"', false)
            ->assertSee('value="Appendectomy"', false);
    }

    // ── The physician's encode ──────────────────────────────────────────────

    public function test_the_physicians_save_on_an_interviewed_visit_encodes_it_and_updates_the_same_assessment_row(): void
    {
        $visit = $this->queueVisit('assessment', 'interviewed');
        $rowId = $visit->medicalAssessment->id;
        $physician = $this->physician();

        $this->actingAs($physician)->post(route('nurse.visits.encode.store', $visit), EncodePayload::make([
            'result' => 'Unfit',
            'respiratory_rate' => 18,
            'medical_history' => ['patient' => ['asthma', 'diabetes'], 'family' => [], 'specify' => []],
            'surgical_history' => ['procedures' => 'Tonsillectomy', 'date_done' => '2015'],
        ]))->assertRedirect(route('nurse.visits.encode', $visit));

        $visit->refresh();
        $this->assertSame('encoded', $visit->status);
        $this->assertSame('completed', $visit->appointment->status);

        $record = $visit->clearanceRecord;
        $this->assertSame('Unfit', $record->result);
        $this->assertSame($physician->id, $record->encoded_by);
        $this->assertSame($physician->license_number, $record->physician_license_no);

        // The physician's correction, saved over the nurse's row — not a second one.
        $this->assertSame(1, MedicalAssessment::count());
        $sections = MedicalAssessment::sole();
        $this->assertSame($rowId, $sections->id);
        $this->assertSame(['asthma', 'diabetes'], $sections->medical_history['patient']);
        $this->assertSame('Tonsillectomy', $sections->surgical_history['procedures']);
        // What the nurse confirmed is kept as it was.
        $this->assertSame(18, $sections->interview_vitals['respiratory_rate']);
        $this->assertSame($sections->id, $record->medicalAssessment->id);
    }

    public function test_the_physician_encodes_a_captured_clearance_directly(): void
    {
        $visit = $this->queueVisit('clearance');

        $this->actingAs($this->physician())->post(route('nurse.visits.encode.store', $visit), EncodePayload::make())
            ->assertRedirect(route('nurse.visits.encode', $visit));

        $this->assertSame('encoded', $visit->fresh()->status);
        $this->assertSame(0, MedicalAssessment::count());
    }

    // ── Remove from queue (D-93) ────────────────────────────────────────────

    public function test_each_role_removes_only_from_its_own_queue(): void
    {
        $assessment = $this->queueVisit('assessment');
        $clearance = $this->queueVisit('clearance');

        $this->actingAs($this->nurse())->delete(route('nurse.visits.remove', $clearance))
            ->assertSessionHas('error');
        $this->actingAs($this->physician())->delete(route('nurse.visits.remove', $assessment))
            ->assertSessionHas('error');
        $this->assertSame(2, ClinicVisit::count());

        $this->actingAs($this->nurse())->delete(route('nurse.visits.remove', $assessment))
            ->assertSessionHas('status');
        $this->actingAs($this->physician())->delete(route('nurse.visits.remove', $clearance))
            ->assertSessionHas('status');
        $this->assertSame(0, ClinicVisit::count());
    }

    public function test_removing_an_interviewed_visit_removes_its_assessment_row(): void
    {
        $visit = $this->queueVisit('assessment', 'interviewed');

        $this->actingAs($this->physician())->delete(route('nurse.visits.remove', $visit))
            ->assertSessionHas('status');

        $this->assertModelMissing($visit);
        $this->assertSame(0, MedicalAssessment::count());
    }

    // ── Counts ──────────────────────────────────────────────────────────────

    public function test_an_interviewed_visit_is_a_submitted_visit_and_reads_in_clinic(): void
    {
        $visit = $this->queueVisit('assessment', 'interviewed');

        $this->assertSame([$visit->id], ClinicVisit::submitted()->pluck('id')->all());
        $this->assertSame($visit->id, ClinicVisit::submittedTodayFor($visit->student_id)?->id);
        $this->assertSame('in_clinic', $visit->appointment->fresh()->clearanceProgress());
    }
}
