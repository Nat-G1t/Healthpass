<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use App\Models\Appointment;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\ScreeningResponse;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\VitalSigns;
use App\Services\ClinicAnalytics;
use App\Support\NavBadges;
use App\Support\VisitMonths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * FR-KSK-11a / FR-KSK-13a — kiosk "Rest & re-check" (D-72).
 *
 * A first pass with a high temperature, blood pressure or heart rate is saved
 * as a `resting` visit: a real row holding everything the student answered,
 * which reaches NOBODY — not the queue, not the analytics, not the student's
 * own records — until they come back, re-take that reading, and submit.
 */
class KioskRestAndRecheckTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function student(bool $scheduledToday = true): User
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $profile = StudentProfile::factory()->forCollege($college)->create();

        if ($scheduledToday) {
            Appointment::factory()->create([
                'student_id' => $profile->user_id,
                'scheduled_date' => now()->toDateString(),
                'status' => 'scheduled',
            ]);
        }

        return $profile->user;
    }

    /** A complete kiosk payload — the same shape /kiosk/submit takes. */
    private function payload(array $vitals = []): array
    {
        return [
            'privacyConsentAt' => now()->toIso8601String(),
            'vitalMethods' => ['manual', 'manual', 'manual', 'manual'],
            'vitals' => array_replace([
                'height' => 170,
                'weight' => 60,
                'temperature' => 36.8,
                'systolic' => 118,
                'diastolic' => 76,
                'heartRate' => 72,
            ], $vitals),
            'screening' => [
                'skin' => true, 'head' => false, 'eyes' => false, 'ears' => false,
                'nose' => false, 'throat' => false, 'chest_lungs' => false, 'heart' => false,
                'abdomen' => false, 'kidney_bladder' => false, 'brain' => false,
                'mental_disorder' => false,
                'details' => ['skin' => 'itchy rash'],
                'isPregnant' => false, 'lastMenstrualPeriod' => null,
            ],
        ];
    }

    /** POST /kiosk/rest as a student whose identity is bound in the session. */
    private function rest(User $student, array $vitals = [])
    {
        return $this->withSession([
            'kiosk.student_id' => $student->id,
            'kiosk.login_method' => 'qr',
        ])->postJson(route('kiosk.rest'), $this->payload($vitals));
    }

    /** A student who has already rested, with the high BP that put them there. */
    private function resting(User $student): ClinicVisit
    {
        $this->rest($student, ['systolic' => 150, 'diastolic' => 95])->assertOk();

        return ClinicVisit::where('student_id', $student->id)->firstOrFail();
    }

    // ── Rest ─────────────────────────────────────────────────────────────────

    public function test_rest_is_refused_when_nothing_relevant_is_flagged(): void
    {
        $student = $this->student();

        // Normal vitals throughout: nothing to re-take, so the kiosk is told to
        // show Submit to Clinic instead.
        $this->rest($student)->assertStatus(422);

        $this->assertSame(0, ClinicVisit::count());
    }

    public function test_an_obese_bmi_alone_does_not_earn_a_rest(): void
    {
        $student = $this->student();

        // BMI 41.5 — flagged, but resting cannot change a height or a weight.
        $this->rest($student, ['height' => 160, 'weight' => 106])->assertStatus(422);

        $this->assertSame(0, ClinicVisit::count());
    }

    public function test_rest_creates_a_resting_visit_with_every_answer_stored(): void
    {
        $student = $this->student();

        $response = $this->rest($student, ['systolic' => 150, 'diastolic' => 95])->assertOk();

        $visit = ClinicVisit::firstOrFail();
        $this->assertSame('resting', $visit->status);
        $this->assertNotNull($visit->resting_until);
        $this->assertNotNull($visit->appointment_id);
        $this->assertNotNull($visit->privacy_consent_at);

        // The whole session is on disk, so the re-check only re-asks the reading.
        $this->assertTrue($visit->screeningResponse->skin);
        $this->assertSame('itchy rash', $visit->screeningResponse->detailFor('skin'));
        $this->assertTrue($visit->vitalSigns->is_bp_flagged);
        $this->assertNull($visit->vitalSigns->first_reading);

        // The server's come-back time and which steps are owed.
        $this->assertSame(['bp'], $response->json('steps'));
        $this->assertSame($visit->resting_until->format('g:i A'), $response->json('restingUntil'));
    }

    public function test_a_high_temperature_asks_for_the_temperature_step(): void
    {
        $student = $this->student();

        $response = $this->rest($student, ['temperature' => 38.1])->assertOk();

        $this->assertSame(['temp'], $response->json('steps'));
    }

    public function test_a_high_heart_rate_asks_for_the_blood_pressure_step(): void
    {
        $student = $this->student();

        // The cuff measures BP and pulse together, so a high HR re-takes both.
        $response = $this->rest($student, ['heartRate' => 118])->assertOk();

        $this->assertSame(['bp'], $response->json('steps'));
    }

    public function test_a_resting_visit_counts_nowhere(): void
    {
        $student = $this->student();
        $this->resting($student);

        // The Live Queue.
        $this->assertSame(0, ClinicVisit::liveQueue()->count());

        // Flagged Anomalies + the Director dashboard preview + the nav badge.
        $this->assertSame(0, ClinicVisit::flagged()->count());
        $director = User::factory()->create(['role' => 'director']);
        $this->assertSame(0, NavBadges::forUser($director)['director.anomalies'] ?? 0);

        // Every analytics card, and the month picker.
        $analytics = new ClinicAnalytics(CarbonImmutable::now()->startOfMonth());
        $this->assertSame(0, $analytics->visitsByCollege()['totalVisits']);
        $this->assertSame(0, $analytics->visitsByProgram()['totalVisits']);
        $this->assertSame(0, $analytics->vitalSignFlags()['screenings']);
        $this->assertSame(0, $analytics->bySexDonut()['totalScreened']);
        $this->assertSame([], $analytics->visitsTrend()['trend']['labels']);
        $this->assertSame([], VisitMonths::available());
    }

    public function test_a_student_may_rest_only_once_a_day(): void
    {
        $student = $this->student();
        $this->resting($student);

        $this->rest($student, ['systolic' => 150, 'diastolic' => 95])->assertStatus(422);

        $this->assertSame(1, ClinicVisit::count());
    }

    public function test_rest_needs_a_bound_identity(): void
    {
        $student = $this->student();

        $this->postJson(route('kiosk.rest'), $this->payload(['systolic' => 150, 'diastolic' => 95]))
            ->assertStatus(422);

        $this->assertSame(0, ClinicVisit::count());
    }

    // ── Coming back: scan / login ────────────────────────────────────────────

    public function test_scanning_before_the_rest_is_over_returns_a_wait_payload(): void
    {
        $student = $this->student();
        $this->resting($student);

        $response = $this->postJson(route('kiosk.scan'), ['token' => $student->studentProfile->qr_token])
            ->assertOk();

        $this->assertNotNull($response->json('identity.recheckWaitUntil'));
        $this->assertNull($response->json('identity.recheck'));
    }

    public function test_scanning_after_the_rest_is_over_returns_the_recheck_steps(): void
    {
        $student = $this->student();
        $visit = $this->resting($student);

        // The clinic's 10 minutes have passed (the server clock decides).
        $visit->update(['resting_until' => now()->subMinute()]);

        $response = $this->postJson(route('kiosk.scan'), ['token' => $student->studentProfile->qr_token])
            ->assertOk();

        $this->assertNull($response->json('identity.recheckWaitUntil'));
        $this->assertSame(['bp'], $response->json('identity.recheck.steps'));

        // The kept answers ride along for the Review screen to show.
        $this->assertEquals(170, $response->json('identity.recheck.kept.vitals.height'));
        $this->assertTrue($response->json('identity.recheck.kept.screening.skin'));

        // The visit is bound to the SESSION; the payload never names it, so the
        // client has no id it could post back (CLAUDE.md's kiosk trust rule).
        $this->assertSame($visit->id, session('kiosk.resting_visit_id'));
        $this->assertSame(['steps', 'kept'], array_keys($response->json('identity.recheck')));
    }

    public function test_a_resting_visit_from_an_earlier_day_is_ignored(): void
    {
        $student = $this->student();
        $visit = $this->resting($student);

        // Yesterday's abandoned pass: the student is already Absent for it, and
        // it must not hijack today's fresh appointment.
        $visit->update([
            'checked_in_at' => now()->subDay(),
            'resting_until' => now()->subDay(),
        ]);

        $response = $this->postJson(route('kiosk.scan'), ['token' => $student->studentProfile->qr_token])
            ->assertOk();

        $this->assertNull($response->json('identity.recheck'));
        $this->assertNull($response->json('identity.recheckWaitUntil'));
        $this->assertNull(session('kiosk.resting_visit_id'));
    }

    public function test_email_login_returns_the_recheck_payload_too(): void
    {
        $student = $this->student();
        $visit = $this->resting($student);
        $visit->update(['resting_until' => now()->subMinute()]);

        $this->postJson(route('kiosk.login'), ['email' => $student->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('identity.recheck.steps', ['bp']);
    }

    // ── Re-check submit ──────────────────────────────────────────────────────

    /** POST /kiosk/recheck with the resting visit bound, as scan would leave it. */
    private function recheck(User $student, ClinicVisit $visit, array $body)
    {
        return $this->withSession([
            'kiosk.student_id' => $student->id,
            'kiosk.login_method' => 'qr',
            'kiosk.resting_visit_id' => $visit->id,
        ])->postJson(route('kiosk.recheck'), $body);
    }

    /** A resting visit whose rest is over — ready to be re-checked. */
    private function readyToRecheck(User $student): ClinicVisit
    {
        $visit = $this->resting($student);
        $visit->update(['resting_until' => now()->subMinute()]);

        return $visit->fresh();
    }

    public function test_recheck_writes_the_first_reading_and_releases_the_visit(): void
    {
        $student = $this->student();
        $visit = $this->readyToRecheck($student);
        $restedAt = $visit->checked_in_at;

        Carbon::setTestNow(now()->addMinutes(11));

        $this->recheck($student, $visit, [
            'vitalMethods' => ['manual'],
            'systolic' => 124,
            'diastolic' => 80,
            'heartRate' => 74,
        ])->assertOk()->assertJson(['ok' => true, 'reference' => $visit->reference_no]);

        $visit->refresh();
        $vitals = $visit->vitalSigns;

        // Released into the queue, at the BACK of it.
        $this->assertSame('captured', $visit->status);
        $this->assertNull($visit->resting_until);
        $this->assertTrue($visit->checked_in_at->gt($restedAt));

        // The re-taken numbers are stored and the flags follow them.
        $this->assertSame(124, $vitals->bp_systolic);
        $this->assertSame(80, $vitals->bp_diastolic);
        $this->assertSame(74, $vitals->heart_rate_bpm);
        $this->assertFalse($vitals->is_bp_flagged);

        // The first reading is kept for the clinic to see at encode.
        $this->assertSame(150, $vitals->first_reading['bp_systolic']);
        $this->assertSame(95, $vitals->first_reading['bp_diastolic']);
        $this->assertTrue($vitals->first_reading['is_bp_flagged']);
        $this->assertNotNull($vitals->first_reading['taken_at']);
        $this->assertSame('150/95 mmHg', $vitals->firstReadingSummary());

        Carbon::setTestNow();
    }

    public function test_recheck_ignores_every_field_it_was_not_asked_for(): void
    {
        $student = $this->student();
        $visit = $this->readyToRecheck($student);

        // Only the BP step was owed. Everything else here is a forgery attempt.
        $this->recheck($student, $visit, [
            'vitalMethods' => ['manual'],
            'systolic' => 124,
            'diastolic' => 80,
            'heartRate' => 74,
            'temperature' => 41.0,
            'height' => 99,
            'weight' => 300,
            'status' => 'encoded',
            'is_bp_flagged' => false,
        ])->assertOk();

        $vitals = $visit->fresh()->vitalSigns;
        $this->assertSame('36.8', (string) $vitals->temperature_c);
        $this->assertSame('170.0', (string) $vitals->height_cm);
        $this->assertSame('60.0', (string) $vitals->weight_kg);
        $this->assertSame('captured', $visit->fresh()->status);
    }

    public function test_recheck_rolls_the_entry_method_up_to_mixed(): void
    {
        $student = $this->student();
        $visit = $this->readyToRecheck($student);

        $this->assertSame('manual', $visit->vitalSigns->entry_method);

        $this->recheck($student, $visit, [
            'vitalMethods' => ['sensor'],
            'systolic' => 124, 'diastolic' => 80, 'heartRate' => 74,
        ])->assertOk();

        $this->assertSame('mixed', $visit->fresh()->vitalSigns->entry_method);
    }

    public function test_a_visit_can_be_rechecked_only_once(): void
    {
        $student = $this->student();
        $visit = $this->readyToRecheck($student);

        // Still high on the re-take: there is no second rest, the visit goes to
        // the clinic and the nurse decides (D-72).
        $this->recheck($student, $visit, [
            'vitalMethods' => ['manual'],
            'systolic' => 150, 'diastolic' => 95, 'heartRate' => 74,
        ])->assertOk();

        $visit->refresh();
        $this->assertSame('captured', $visit->status);
        $this->assertTrue($visit->vitalSigns->is_bp_flagged);
        $this->assertSame(1, ClinicVisit::liveQueue()->count());

        // A replayed re-check of the same visit finds it is no longer resting.
        $this->recheck($student, $visit, [
            'vitalMethods' => ['manual'],
            'systolic' => 110, 'diastolic' => 70, 'heartRate' => 66,
        ])->assertStatus(422);

        $this->assertSame(150, $visit->fresh()->vitalSigns->bp_systolic);
    }

    public function test_another_students_session_cannot_touch_the_visit(): void
    {
        $student = $this->student();
        $visit = $this->readyToRecheck($student);

        $intruder = $this->student();

        // The intruder's own session, pointed at somebody else's resting visit.
        $this->withSession([
            'kiosk.student_id' => $intruder->id,
            'kiosk.login_method' => 'qr',
            'kiosk.resting_visit_id' => $visit->id,
        ])->postJson(route('kiosk.recheck'), [
            'vitalMethods' => ['manual'],
            'systolic' => 110, 'diastolic' => 70, 'heartRate' => 66,
        ])->assertStatus(422);

        $this->assertSame('resting', $visit->fresh()->status);
        $this->assertSame(150, $visit->fresh()->vitalSigns->bp_systolic);
    }

    public function test_recheck_before_the_rest_is_over_is_refused(): void
    {
        $student = $this->student();
        $visit = $this->resting($student); // resting_until still in the future

        $this->recheck($student, $visit, [
            'vitalMethods' => ['manual'],
            'systolic' => 124, 'diastolic' => 80, 'heartRate' => 74,
        ])->assertStatus(422);

        $this->assertSame('resting', $visit->fresh()->status);
    }

    public function test_recheck_validates_the_retaken_reading(): void
    {
        $student = $this->student();
        $visit = $this->readyToRecheck($student);

        // 400 systolic is outside the FR-KSK-08 plausibility bounds.
        $this->recheck($student, $visit, [
            'vitalMethods' => ['manual'],
            'systolic' => 400, 'diastolic' => 80, 'heartRate' => 74,
        ])->assertStatus(422);

        $this->assertSame('resting', $visit->fresh()->status);
    }

    // ── Batch Results (FR-ADM-12) ────────────────────────────────────────────

    public function test_clearance_progress_is_rechecking_before_the_cutoff(): void
    {
        // Mid-morning on the clinic day - well before the 8 PM cutoff. Pinned,
        // because otherwise the result would depend on the hour the suite runs.
        Carbon::setTestNow(today()->setTime(9, 0));

        $student = $this->student();
        $this->resting($student);

        $appointment = Appointment::where('student_id', $student->id)->firstOrFail();
        $this->assertSame('rechecking', $appointment->fresh()->clearanceProgress());

        Carbon::setTestNow();
    }

    public function test_a_still_resting_student_is_absent_after_the_cutoff(): void
    {
        Carbon::setTestNow(today()->setTime(9, 0));

        $student = $this->student();
        $this->resting($student);

        $appointment = Appointment::where('student_id', $student->id)->firstOrFail();

        // 8:00 PM on the clinic date — their result never reached the queue, so
        // they are as absent as a student who never came (D-55/D-72).
        Carbon::setTestNow(today()->setTimeFromTimeString(config('healthpass.absent_cutoff')));
        $this->assertSame('absent', $appointment->fresh()->clearanceProgress());

        Carbon::setTestNow();
    }

    public function test_a_recheck_puts_the_student_back_in_the_clinic(): void
    {
        $student = $this->student();
        $visit = $this->readyToRecheck($student);

        $this->recheck($student, $visit, [
            'vitalMethods' => ['manual'],
            'systolic' => 124, 'diastolic' => 80, 'heartRate' => 74,
        ])->assertOk();

        $appointment = Appointment::where('student_id', $student->id)->firstOrFail();
        $this->assertSame('in_clinic', $appointment->fresh()->clearanceProgress());
        $this->assertSame(1, ClinicVisit::liveQueue()->count());
    }

    // ── The student sees nothing for a resting visit ─────────────────────────

    public function test_my_records_lists_nothing_for_a_resting_visit(): void
    {
        $student = $this->student();
        $visit = $this->resting($student);

        $this->actingAs($student)
            ->get(route('student.records'))
            ->assertOk()
            ->assertDontSee($visit->reference_no);
    }

    // ── The encode page shows the first reading (FR-NRS-03) ──────────────────

    public function test_the_encode_page_shows_the_first_reading(): void
    {
        $student = $this->student();
        $visit = $this->readyToRecheck($student);

        $this->recheck($student, $visit, [
            'vitalMethods' => ['manual'],
            'systolic' => 124, 'diastolic' => 80, 'heartRate' => 74,
        ])->assertOk();

        $nurse = User::factory()->create(['role' => 'nurse']);

        $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('First reading')
            ->assertSee('150/95 mmHg');
    }

    // ── The reading helpers themselves ───────────────────────────────────────

    public function test_recheck_steps_pair_blood_pressure_with_heart_rate(): void
    {
        $both = ['is_temp_flagged' => true, 'is_bp_flagged' => true, 'is_hr_flagged' => false];
        $this->assertSame(['bp', 'temp'], VitalSigns::recheckStepsFor($both));

        $hrOnly = ['is_temp_flagged' => false, 'is_bp_flagged' => false, 'is_hr_flagged' => true];
        $this->assertSame(['bp'], VitalSigns::recheckStepsFor($hrOnly));

        $none = ['is_temp_flagged' => false, 'is_bp_flagged' => false, 'is_hr_flagged' => false];
        $this->assertSame([], VitalSigns::recheckStepsFor($none));
    }

    public function test_submitted_scope_excludes_resting_visits_only(): void
    {
        $student = $this->student();
        $this->resting($student);

        $this->assertSame(1, ClinicVisit::count());
        $this->assertSame(0, ClinicVisit::submitted()->count());

        ClinicVisit::query()->update(['status' => 'captured']);
        $this->assertSame(1, ClinicVisit::submitted()->count());
    }

    /** A screening response is written for a resting visit like any other. */
    public function test_a_resting_visit_still_stores_its_screening_row(): void
    {
        $student = $this->student();
        $this->resting($student);

        $this->assertSame(1, ScreeningResponse::count());
        $this->assertSame(1, VitalSigns::count());
    }
}
