<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\ClinicVisit;
use App\Models\MedicalAssessment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\ClinicQueueFixture;
use Tests\Support\EncodePayload;
use Tests\Support\ReleasesForPrint;
use Tests\TestCase;

/**
 * D-127 — the physician's encode page.
 *
 *  - On an interviewed Medical Assessment the nurse's work shows as a
 *    read-only summary, with Edit interview to open the fields.
 *  - Before Save there are Back and Save only — no print buttons.
 *  - Save stays on the page: the visit comes back read-only with the print
 *    buttons and Finished (→ the Live Queue).
 *  - The physician who encoded a visit may print it on the day they encoded
 *    it, release or not; otherwise printing follows the release (D-125).
 *  - The pre-save Preview & Print route is gone.
 */
class PhysicianEncodePageTest extends TestCase
{
    use ClinicQueueFixture;
    use RefreshDatabase;
    use ReleasesForPrint;

    /**
     * What the physician's page posts when nobody clicks Edit interview: the
     * hidden fields, pre-filled with the nurse's interview (markInterviewed()).
     *
     * @return array<string, mixed>
     */
    private function unchangedInterviewPayload(array $overrides = []): array
    {
        return [
            ...EncodePayload::make(EncodePayload::vitals(['temperature_c' => 36.9, 'respiratory_rate' => 18])),
            'medical_history' => ['patient' => ['asthma']],
            'immunizations' => ['given' => ['bcg']],
            'family_planning_access' => '1',
            'surgical_history' => ['procedures' => 'Appendectomy', 'date_done' => '2019'],
            ...$overrides,
        ];
    }

    /** Save a visit as the physician, the way the encode page does. */
    private function save(ClinicVisit $visit, User $physician, array $payload): TestResponse
    {
        return $this->actingAs($physician)->post(route('nurse.visits.encode.store', $visit), $payload);
    }

    // ── The interview as a summary ──────────────────────────────────────────

    public function test_an_interviewed_visit_shows_the_nurses_work_as_a_summary_with_edit(): void
    {
        $visit = $this->queueVisit('assessment', 'interviewed');
        $visit->medicalAssessment->update([
            'medical_history' => ['patient' => ['asthma'], 'family' => ['hypertension'], 'specify' => ['hypertension' => '150/95']],
            'physical_exam' => ['heent' => ['findings' => ['normal'], 'others' => 'Mild redness']],
        ]);

        $this->actingAs($this->physician())->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('data-interview-summary', false)
            ->assertSee('Interviewed by')
            ->assertSee($visit->interviewer->name)
            ->assertSee('data-edit-interview', false)
            ->assertSee('Edit interview')
            // Only what was answered, labelled from the model constants.
            ->assertSee('Patient: Asthma · Family: Hypertension (Highest BP: 150/95)')
            ->assertSee('BCG')
            ->assertSee('Appendectomy (2019)')
            ->assertSee('Temperature 36.9 °C')
            ->assertSee('Respiratory Rate 18 breaths/min')
            ->assertSee('A. HEENT:')
            ->assertSee('Essentially Normal · Others: Mild redness')
            // The editable fields are still in the form, only hidden.
            ->assertSee('x-show="editing"', false)
            ->assertSee('value="Appendectomy"', false);
    }

    public function test_a_section_with_nothing_answered_reads_as_a_dash(): void
    {
        $visit = $this->queueVisit('assessment', 'interviewed');
        $visit->medicalAssessment->update(['immunizations' => ['given' => [], 'others' => null]]);

        $html = $this->actingAs($this->physician())->get(route('nurse.visits.encode', $visit))->getContent();

        $this->assertMatchesRegularExpression('~>Immunizations</dt>\s*<dd class="text-hp-slate/40">—</dd>~u', $html);
        // Sections V and VI (female student) — the nurse left them empty.
        $this->assertMatchesRegularExpression('~>OB/Pregnancy history</dt>\s*<dd class="text-hp-slate/40">—</dd>~u', $html);
    }

    public function test_a_clearance_visit_has_no_summary(): void
    {
        $visit = $this->queueVisit('clearance');

        $this->actingAs($this->physician())->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertDontSee('data-interview-summary', false)
            ->assertDontSee('Edit interview')
            ->assertSee('name="result"', false);
    }

    public function test_before_save_there_are_back_and_save_and_no_print_buttons(): void
    {
        foreach ([$this->queueVisit('assessment', 'interviewed'), $this->queueVisit('clearance')] as $visit) {
            $this->actingAs($this->physician())->get(route('nurse.visits.encode', $visit))
                ->assertOk()
                ->assertSee('data-encode-back', false)
                ->assertSee('data-pending-label="Saving…"', false)
                ->assertDontSee('Print front')
                ->assertDontSee('Save as PDF')
                ->assertDontSee(route('nurse.visits.print.reprint', $visit), false)
                ->assertDontSee('Preview &amp; Print', false)
                ->assertDontSee('name="printed"', false)
                ->assertDontSee('data-encode-finished', false);
        }
    }

    public function test_a_failed_save_reopens_the_fields(): void
    {
        $visit = $this->queueVisit('assessment', 'interviewed');
        $physician = $this->physician();

        $this->actingAs($physician)
            ->from(route('nurse.visits.encode', $visit))
            ->post(route('nurse.visits.encode.store', $visit), $this->unchangedInterviewPayload(['result' => null]))
            ->assertSessionHasErrors('result');

        // The flashed errors ride into the next request, as in the browser.
        $this->actingAs($physician)
            ->get(route('nurse.visits.encode', $visit))
            ->assertSee('x-data="{ editing: true }"', false);
    }

    // ── Save ────────────────────────────────────────────────────────────────

    public function test_save_without_editing_keeps_the_nurses_sections(): void
    {
        $visit = $this->queueVisit('assessment', 'interviewed');
        $before = $visit->medicalAssessment->only(['medical_history', 'immunizations', 'family_planning_access', 'surgical_history', 'interview_vitals']);

        $this->save($visit, $this->physician(), $this->unchangedInterviewPayload())->assertSessionHasNoErrors();

        $after = MedicalAssessment::sole();
        $this->assertSame($before, $after->only(array_keys($before)));
        // The nurse's confirmed vitals became the record's.
        $this->assertSame(36.9, (float) $visit->fresh()->clearanceRecord->encoded_vitals['temperature_c']);
    }

    public function test_save_after_editing_stores_the_edits(): void
    {
        $visit = $this->queueVisit('assessment', 'interviewed');

        $this->save($visit, $this->physician(), $this->unchangedInterviewPayload([
            'medical_history' => ['patient' => ['asthma'], 'family' => ['diabetes']],
            'physical_exam' => ['chest' => ['findings' => ['wheezes_crackles_rales']]],
        ]))->assertSessionHasNoErrors();

        $sections = MedicalAssessment::sole();
        $this->assertSame(['diabetes'], $sections->medical_history['family']);
        $this->assertTrue($sections->hasFinding('chest', 'wheezes_crackles_rales'));
    }

    public function test_save_stays_on_the_page_with_the_print_buttons_and_finished(): void
    {
        $visit = $this->queueVisit('assessment', 'interviewed');
        $physician = $this->physician();

        $this->save($visit, $physician, $this->unchangedInterviewPayload(['result' => 'Unfit']))
            ->assertRedirect(route('nurse.visits.encode', $visit))
            ->assertSessionHas('status', "Saved — {$visit->reference_no} is Unfit.")
            // No queue ghost for this path any more.
            ->assertSessionMissing('left_queue_visit_id');

        $this->actingAs($physician)->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertViewHas('mode', 'view')
            ->assertSee('Print front')
            ->assertSee('Print back')
            ->assertSee('Save as PDF')
            ->assertSee(route('nurse.visits.print.reprint', $visit), false)
            ->assertSee('data-encode-finished', false)
            ->assertSee('href="'.route('nurse.queue').'" data-encode-finished', false)
            ->assertDontSee('data-print-disabled-hint', false);
    }

    public function test_a_clearance_save_shows_a_single_print(): void
    {
        $visit = $this->queueVisit('clearance');
        $physician = $this->physician();

        $this->save($visit, $physician, EncodePayload::make())
            ->assertRedirect(route('nurse.visits.encode', $visit))
            ->assertSessionHas('status', "Saved — {$visit->reference_no} is Fit.");

        $this->actingAs($physician)->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee(route('nurse.visits.print.reprint', $visit), false)
            ->assertDontSee('Print front')
            ->assertSee('Save as PDF')
            ->assertSee('data-encode-finished', false);
    }

    // ── The print rule (D-125 + D-127) ──────────────────────────────────────

    public function test_the_encoding_physician_can_print_today_without_a_release(): void
    {
        $visit = $this->queueVisit('clearance');
        $physician = $this->physician();
        $this->save($visit, $physician, EncodePayload::make());

        $this->assertTrue($visit->fresh()->isReleasedForPrint($physician));
        $this->assertFalse($visit->fresh()->isReleasedForPrint());

        $this->actingAs($physician)->post(route('nurse.visits.print.reprint', $visit))
            ->assertOk()
            ->assertSee('data-hp-print-doc', false);
        $this->actingAs($physician)->get(route('nurse.visits.print', $visit))->assertOk();
    }

    public function test_another_physician_cannot_print_without_a_release(): void
    {
        $visit = $this->queueVisit('clearance');
        $this->save($visit, $this->physician(), EncodePayload::make());
        $other = $this->physician();

        $this->assertFalse($visit->fresh()->isReleasedForPrint($other));
        $this->actingAs($other)->post(route('nurse.visits.print.reprint', $visit))
            ->assertRedirect()
            ->assertSessionHas('error', ClinicVisit::NOT_RELEASED_HINT);
    }

    public function test_the_encoding_physician_cannot_print_tomorrow_without_a_release(): void
    {
        $visit = $this->queueVisit('clearance');
        $physician = $this->physician();
        $this->save($visit, $physician, EncodePayload::make());

        $this->travel(1)->days();

        $this->assertFalse($visit->fresh()->isReleasedForPrint($physician));
        $this->actingAs($physician)->get(route('nurse.visits.print', $visit))
            ->assertRedirect()
            ->assertSessionHas('error', ClinicVisit::NOT_RELEASED_HINT);
    }

    public function test_after_a_release_everyone_on_staff_can_print(): void
    {
        $visit = $this->queueVisit('clearance');
        $this->save($visit, $this->physician(), EncodePayload::make());
        $this->releaseForPrint($visit);
        $this->travel(1)->days();

        foreach ([$this->nurse(), $this->physician()] as $user) {
            $this->assertTrue($visit->fresh()->isReleasedForPrint($user), $user->role);
            $this->actingAs($user)->post(route('nurse.visits.print.reprint', $visit))->assertOk();
        }
    }

    // ── Opened later from the Clinic Dashboard (View) ───────────────────────

    public function test_view_from_the_dashboard_shows_print_only_when_the_rule_allows_and_no_finished(): void
    {
        $visit = $this->queueVisit('clearance');
        $physician = $this->physician();
        $this->save($visit, $physician, EncodePayload::make());
        $url = route('nurse.visits.encode', ['visit' => $visit, 'from' => 'dashboard']);

        // The encoding physician, the same day: the buttons show.
        $this->actingAs($physician)->get($url)
            ->assertOk()
            ->assertSee('Back to Clinic Dashboard')
            ->assertSee(route('nurse.visits.print.reprint', $visit), false)
            ->assertDontSee('data-encode-finished', false);

        // Anyone else, before a release: hidden (not disabled), with the reason.
        $this->actingAs($this->nurse())->get($url)
            ->assertOk()
            ->assertDontSee(route('nurse.visits.print.reprint', $visit), false)
            ->assertDontSee('Save as PDF')
            ->assertSee('data-print-disabled-hint', false)
            ->assertDontSee('data-encode-finished', false);
    }

    // ── Preview & Print is gone ─────────────────────────────────────────────

    public function test_the_pre_save_preview_route_is_gone(): void
    {
        $visit = $this->queueVisit('clearance');

        $this->assertFalse(Route::has('nurse.visits.print.preview'));
        $this->actingAs($this->physician())
            ->post("/nurse/visits/{$visit->id}/print-preview", EncodePayload::make())
            ->assertNotFound();
    }
}
