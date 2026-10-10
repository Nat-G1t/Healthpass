<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Actions\CancelBatch;
use App\Actions\Kiosk\SubmitKioskVisit;
use App\Actions\Nurse\SubmitManualScreening;
use App\Exceptions\BatchNotCancellable;
use App\Models\ClinicVisit;
use App\Models\MedicalAssessment;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ManualScreeningFixture;
use Tests\TestCase;

/**
 * D-133 — Manual Screening's save: the nurse's typed-in screening becomes an
 * ordinary submitted visit in the PHYSICIAN's queue — `captured` for a Medical
 * Clearance, `interviewed` (with the interview) for a Medical Assessment —
 * recorded as `login_method = manual` / `entry_method = manual`, with the
 * student, BMI and every flag decided on the server.
 */
class ManualScreeningSubmitTest extends TestCase
{
    use ManualScreeningFixture;
    use RefreshDatabase;

    /** @return list<int> The visit ids in $user's Live Queue. */
    private function queueIds(User $user): array
    {
        return $this->actingAs($user)->getJson(route('nurse.queue.feed'))->assertOk()->json('visits.*.id');
    }

    // ── Medical Clearance ────────────────────────────────────────────────────

    public function test_a_clearance_becomes_a_captured_manual_visit_in_the_physicians_queue(): void
    {
        $appointment = $this->scheduledStudent('clearance', name: 'Clara Manual');

        $response = $this->submitManual($appointment, $this->clearancePayload());

        $visit = ClinicVisit::sole();
        $response->assertRedirect(route('nurse.queue'))
            ->assertSessionHas('status', "Clara Manual sent to the physician — {$visit->reference_no}.");

        $this->assertSame('captured', $visit->status);
        $this->assertSame('manual', $visit->login_method);
        $this->assertSame($appointment->id, $visit->appointment_id);
        $this->assertSame($appointment->student_id, $visit->student_id);
        $this->assertNotNull($visit->checked_in_at);
        $this->assertNotNull($visit->privacy_consent_at);
        $this->assertNull($visit->interviewed_by);
        $this->assertMatchesRegularExpression('/^HP-\d{4}-\d{4}$/', $visit->reference_no);

        $this->assertSame('manual', $visit->vitalSigns->entry_method);
        $this->assertSame(118, (int) $visit->vitalSigns->bp_systolic);
        $this->assertFalse((bool) $visit->screeningResponse->skin);
        // A Medical Clearance has no social history and no interview.
        $this->assertNull($visit->screeningResponse->smoking);
        $this->assertSame(0, MedicalAssessment::count());

        $this->assertSame([$visit->id], $this->queueIds($this->physician()));
        $this->assertSame([], $this->queueIds($this->nurse()));
    }

    public function test_bmi_flags_and_identity_come_from_the_server_not_the_form(): void
    {
        $appointment = $this->scheduledStudent();
        $someoneElse = $this->scheduledStudent();

        $this->submitManual($appointment, $this->clearancePayload([
            'vitals' => ['systolic' => '150', 'diastolic' => '95'],
            // Forged: none of these has a rule, so none reaches the action.
            'vitals_bmi' => '99',
            'bmi' => '99',
            'is_bp_flagged' => '0',
            'studentUserId' => (string) $someoneElse->student_id,
            'login_method' => 'qr',
            'status' => 'encoded',
        ]))->assertRedirect(route('nurse.queue'));

        $visit = ClinicVisit::sole();
        $this->assertSame($appointment->student_id, $visit->student_id);
        $this->assertSame('manual', $visit->login_method);
        $this->assertSame('captured', $visit->status);
        // 60 kg ÷ 1.70 m² = 20.76 → 20.8; 150/95 trips the 140/90 flag.
        $this->assertSame(20.8, (float) $visit->vitalSigns->bmi);
        $this->assertTrue((bool) $visit->vitalSigns->is_bp_flagged);
        $this->assertFalse((bool) $visit->vitalSigns->is_temp_flagged);
    }

    public function test_flagged_readings_go_straight_through_with_no_rest(): void
    {
        $this->submitManual($this->scheduledStudent(), $this->clearancePayload([
            'vitals' => ['temperature' => '38.4', 'heartRate' => '120'],
        ]))->assertRedirect(route('nurse.queue'));

        $visit = ClinicVisit::sole();
        $this->assertSame('captured', $visit->status);
        $this->assertNull($visit->resting_until);
        $this->assertTrue((bool) $visit->vitalSigns->is_temp_flagged);
        $this->assertTrue((bool) $visit->vitalSigns->is_hr_flagged);
    }

    public function test_a_clearance_yes_needs_details_of_at_least_three_characters(): void
    {
        $appointment = $this->scheduledStudent('clearance');

        $this->submitManual($appointment, $this->clearancePayload(['screening' => ['skin' => '1']]))
            ->assertSessionHasErrors('screening.details.skin');
        $this->submitManual($appointment, $this->clearancePayload(['screening' => ['skin' => '1', 'details' => ['skin' => 'ok']]]))
            ->assertSessionHasErrors('screening.details.skin');
        $this->assertSame(0, ClinicVisit::count());

        $this->submitManual($appointment, $this->clearancePayload(['screening' => ['skin' => '1', 'details' => ['skin' => 'Rash on the left arm']]]))
            ->assertRedirect(route('nurse.queue'));
        $this->assertSame('Rash on the left arm', ClinicVisit::sole()->screeningResponse->detailFor('skin'));
    }

    public function test_out_of_range_vitals_and_a_missing_consent_are_refused(): void
    {
        $this->submitManual($this->scheduledStudent(), $this->clearancePayload([
            'privacy_consent' => '0',
            'vitals' => ['height' => '20', 'temperature' => '50'],
        ]))->assertSessionHasErrors(['privacy_consent', 'vitals.height', 'vitals.temperature']);

        $this->assertSame(0, ClinicVisit::count());
    }

    // ── Medical Assessment ───────────────────────────────────────────────────

    public function test_an_assessment_becomes_an_interviewed_visit_with_the_interview_in_the_physicians_queue(): void
    {
        $appointment = $this->scheduledStudent('assessment', name: 'Ana Assess');
        $nurse = $this->nurse();

        $this->submitManual($appointment, $this->assessmentPayload([
            // Details stay optional on an Assessment (D-75).
            'screening' => ['skin' => '1'],
        ]), $nurse)->assertRedirect(route('nurse.queue'));

        $visit = ClinicVisit::sole();
        $this->assertSame('interviewed', $visit->status);
        $this->assertSame('manual', $visit->login_method);
        $this->assertSame($nurse->id, $visit->interviewed_by);
        $this->assertNotNull($visit->interviewed_at);

        $this->assertSame('quit', $visit->screeningResponse->alcohol);
        $this->assertTrue((bool) $visit->screeningResponse->skin);

        $sections = $visit->medicalAssessment;
        $this->assertSame(['asthma'], $sections->medical_history['patient']);
        $this->assertSame(['bcg', 'hpv'], $sections->immunizations['given']);
        $this->assertTrue($sections->family_planning_access);
        // interview_vitals = these vitals + the respiratory rate, BMI recomputed.
        $this->assertSame(18, $sections->interview_vitals['respiratory_rate']);
        $this->assertSame(170.0, (float) $sections->interview_vitals['height_cm']);
        $this->assertSame(20.8, (float) $sections->interview_vitals['bmi']);
        $this->assertSame(18, (int) $visit->vitalSigns->respiratory_rate);
        $this->assertFalse((bool) $visit->vitalSigns->is_rr_flagged);

        $this->assertSame([$visit->id], $this->queueIds($this->physician()));
        $this->assertSame([], $this->queueIds($nurse));
    }

    public function test_an_assessment_needs_the_respiratory_rate_and_the_social_history(): void
    {
        $payload = $this->assessmentPayload();
        unset($payload['respiratory_rate'], $payload['socialHistory']);

        $this->submitManual($this->scheduledStudent('assessment'), $payload)
            ->assertSessionHasErrors(['respiratory_rate', 'socialHistory']);

        $this->assertSame(0, ClinicVisit::count());
    }

    public function test_a_clearance_ignores_a_posted_interview_and_social_history(): void
    {
        $this->submitManual($this->scheduledStudent('clearance'), $this->assessmentPayload())
            ->assertRedirect(route('nurse.queue'));

        $visit = ClinicVisit::sole();
        $this->assertSame('captured', $visit->status);
        $this->assertNull($visit->screeningResponse->smoking);
        $this->assertNull($visit->vitalSigns->respiratory_rate);
        $this->assertSame(0, MedicalAssessment::count());
    }

    // ── Pregnancy (D-79) ─────────────────────────────────────────────────────

    public function test_pregnancy_is_not_required_and_is_ignored_for_a_male_student(): void
    {
        $payload = $this->clearancePayload();
        unset($payload['screening']['isPregnant']);

        $this->submitManual($this->scheduledStudent(sex: 'M'), $payload)->assertRedirect(route('nurse.queue'));
        $this->assertFalse((bool) ClinicVisit::sole()->screeningResponse->is_pregnant);

        $this->submitManual($this->scheduledStudent(sex: 'M'), $this->clearancePayload([
            'screening' => ['isPregnant' => '1', 'lastMenstrualPeriod' => today()->subWeeks(6)->toDateString()],
        ]))->assertRedirect(route('nurse.queue'));

        $response = ClinicVisit::latest('id')->first()->screeningResponse;
        $this->assertFalse((bool) $response->is_pregnant);
        $this->assertNull($response->last_menstrual_period);
    }

    public function test_pregnancy_is_required_for_a_female_student(): void
    {
        $payload = $this->clearancePayload();
        unset($payload['screening']['isPregnant']);

        $this->submitManual($this->scheduledStudent(sex: 'F'), $payload)
            ->assertSessionHasErrors('screening.isPregnant');
    }

    // ── Races and later visits ───────────────────────────────────────────────

    public function test_a_kiosk_visit_between_the_form_and_the_save_wins(): void
    {
        $appointment = $this->scheduledStudent(name: 'Race Student');
        $nurse = $this->nurse();
        $this->actingAs($nurse)->get(route('nurse.manual.show', $appointment))->assertOk();

        // The kiosk came back up and the student used it meanwhile.
        $this->withSession(['kiosk.student_id' => $appointment->student_id, 'kiosk.login_method' => 'qr'])
            ->postJson(route('kiosk.submit'), [
                'privacyConsentAt' => now()->toIso8601String(),
                'vitalMethods' => ['sensor'],
                ...collect($this->clearancePayload())->only(['vitals', 'screening'])->all(),
            ])->assertOk();

        $this->submitManual($appointment, $this->clearancePayload(), $nurse)
            ->assertRedirect(route('nurse.manual.index'))
            ->assertSessionHas('error', 'Race Student has just been screened at the kiosk.');

        $this->assertSame(1, ClinicVisit::count());
        $this->assertSame('qr', ClinicVisit::sole()->login_method);
    }

    public function test_the_action_rechecks_under_the_lock_and_writes_nothing(): void
    {
        $appointment = $this->scheduledStudent();
        ClinicVisit::create([
            'reference_no' => 'HP-2026-M002',
            'student_id' => $appointment->student_id,
            'college_id' => $appointment->student->studentProfile->college_id,
            'appointment_id' => $appointment->id,
            'login_method' => 'qr',
            'status' => 'resting',
            'checked_in_at' => now(),
        ]);

        $visit = app(SubmitManualScreening::class)->handle(
            $appointment,
            $this->nurse(),
            collect($this->clearancePayload())->only(['vitals', 'screening'])->all(),
            null,
        );

        $this->assertNull($visit);
        $this->assertSame(1, ClinicVisit::count());
        $this->assertSame(0, VitalSigns::count());
        $this->assertSame(0, ScreeningResponse::count());
    }

    public function test_a_double_submit_is_refused_with_the_first_reference(): void
    {
        $appointment = $this->scheduledStudent(name: 'Double Click');
        $nurse = $this->nurse();

        $this->submitManual($appointment, $this->clearancePayload(), $nurse)->assertRedirect(route('nurse.queue'));
        $reference = ClinicVisit::sole()->reference_no;

        $this->submitManual($appointment, $this->clearancePayload(), $nurse)
            ->assertRedirect(route('nurse.manual.index'))
            ->assertSessionHas('error', "Double Click was already screened by hand — {$reference}.");
        $this->assertSame(1, ClinicVisit::count());
    }

    public function test_the_kiosk_tells_the_student_they_are_already_screened(): void
    {
        $appointment = $this->scheduledStudent();
        $this->submitManual($appointment, $this->clearancePayload())->assertRedirect(route('nurse.queue'));
        $visit = ClinicVisit::sole();

        $identity = $this->postJson(route('kiosk.scan'), ['token' => $appointment->student->studentProfile->qr_token])
            ->assertOk()
            ->json('identity');

        $this->assertFalse($identity['hasAppointmentToday']);
        $this->assertTrue($identity['alreadyScreenedToday']);
        $this->assertSame($visit->reference_no, $identity['screenedReference']);

        $this->withSession(['kiosk.student_id' => $appointment->student_id, 'kiosk.login_method' => 'qr'])
            ->postJson(route('kiosk.submit'), [
                'privacyConsentAt' => now()->toIso8601String(),
                'vitalMethods' => ['manual'],
                ...collect($this->clearancePayload())->only(['vitals', 'screening'])->all(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', SubmitKioskVisit::ALREADY_SCREENED_MESSAGE);
        $this->assertSame(1, ClinicVisit::count());
    }

    public function test_a_manual_visit_blocks_cancelling_the_batch(): void
    {
        $appointment = $this->scheduledStudent();
        $batch = $appointment->batchRequest;
        $this->assertFalse($batch->hasKioskVisit());

        $this->submitManual($appointment, $this->clearancePayload())->assertRedirect(route('nurse.queue'));

        $this->assertTrue($batch->fresh()->hasKioskVisit());

        try {
            app(CancelBatch::class)->handle($batch, User::factory()->create(['role' => 'director']), 'Testing the D-92 guard.');
            $this->fail('A batch with a manual visit was cancelled.');
        } catch (BatchNotCancellable $e) {
            $this->assertStringContainsString('kiosk', $e->getMessage());
        }

        $this->assertSame('approved', $batch->fresh()->status);
    }

    public function test_a_waiting_manual_visit_is_removed_at_the_cutoff_like_any_other(): void
    {
        Queue::fake();
        $this->travelTo(today()->setTime(9, 30));
        $appointment = $this->scheduledStudent();
        $this->submitManual($appointment, $this->clearancePayload())->assertRedirect(route('nurse.queue'));
        $visit = ClinicVisit::sole();

        $this->travelTo(today()->setTime(20, 5));
        $this->artisan('healthpass:second-chances')->assertExitCode(0);

        $this->assertModelMissing($visit);
        $this->assertSame(0, VitalSigns::where('clinic_visit_id', $visit->id)->count());
    }
}
