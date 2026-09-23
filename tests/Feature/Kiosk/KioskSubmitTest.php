<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use App\Actions\Kiosk\SubmitKioskVisit;
use App\Http\Controllers\Kiosk\BpReadingController;
use App\Models\Appointment;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\ScreeningResponse;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * FR-KSK-12 — kiosk submit. ONE endpoint that, in a single transaction, creates
 * the clinic_visits + vital_signs + screening_responses trio, computes the §7.4
 * flag booleans server-side (BR-13/14), and links today's appointment (BR-10).
 * D-61: a student with no `scheduled` appointment today is refused with a 422
 * and nothing is written — there are no walk-ins.
 */
class KioskSubmitTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * An active student user; returns the User (clinic_visits.student_id = users.id).
     *
     * D-61: by default the student also holds a `scheduled` appointment today,
     * because without one the kiosk refuses the submit. The linkage tests pass
     * false and create exactly the appointments they are about.
     */
    private function student(bool $scheduledToday = true): User
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $profile = StudentProfile::factory()->forCollege($college)->create();

        if ($scheduledToday) {
            $this->scheduleToday($profile->user);
        }

        return $profile->user;
    }

    /** A `scheduled` appointment today — what a Director-approved batch leaves (D-61). */
    private function scheduleToday(User $student): Appointment
    {
        return Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);
    }

    /** A complete, valid submission payload; override any leaf via dot-free nesting. */
    private function payload(int $studentId, array $overrides = []): array
    {
        $base = [
            'studentUserId' => $studentId,
            'loginMethod' => 'qr',
            'privacyConsentAt' => now()->toIso8601String(),
            'vitalMethods' => ['sensor', 'sensor', 'sensor', 'sensor'],
            'vitals' => [
                'height' => 170,
                'weight' => 60,
                'bmi' => 20.8, // sent by client for display; server recomputes
                'temperature' => 36.8,
                'systolic' => 118,
                'diastolic' => 76,
                'heartRate' => 72,
            ],
            'screening' => [
                'skin' => false, 'head' => false, 'eyes' => false, 'ears' => false,
                'nose' => false, 'throat' => false, 'chest_lungs' => false, 'heart' => false,
                'abdomen' => false, 'kidney_bladder' => false, 'brain' => false,
                'mental_disorder' => false,
                'isPregnant' => false, 'lastMenstrualPeriod' => null,
            ],
        ];

        return array_replace_recursive($base, $overrides);
    }

    /**
     * Submit as $studentId. Identity is now taken from the SERVER session (set
     * at scan/login), never the body, so we seed the kiosk.* session keys the
     * way scan() would — the payload's studentUserId is along for the ride but
     * ignored by the endpoint.
     */
    private function submit(int $studentId, array $overrides = [])
    {
        return $this->withSession([
            'kiosk.student_id' => $studentId,
            'kiosk.login_method' => 'qr',
        ])->postJson(route('kiosk.submit'), $this->payload($studentId, $overrides));
    }

    // ── Three linked rows in one transaction ──────────────────────────────────

    public function test_submit_creates_exactly_three_linked_rows(): void
    {
        $student = $this->student();

        $response = $this->submit($student->id)->assertOk()->assertJson(['ok' => true]);

        // Exactly one of each row, no orphans.
        $this->assertSame(1, ClinicVisit::count());
        $this->assertSame(1, VitalSigns::count());
        $this->assertSame(1, ScreeningResponse::count());

        $visit = ClinicVisit::first();
        $this->assertSame($student->id, $visit->student_id);
        $this->assertSame('captured', $visit->status);
        $this->assertSame('qr', $visit->login_method);
        $this->assertNotNull($visit->privacy_consent_at);
        $this->assertNotNull($visit->checked_in_at);
        $this->assertMatchesRegularExpression('/^HP-\d{4}-\d{4}$/', $visit->reference_no);
        $this->assertSame($visit->reference_no, $response->json('reference'));

        // The 1:1 children point back at the visit.
        $this->assertSame($visit->id, $visit->vitalSigns->clinic_visit_id);
        $this->assertSame($visit->id, $visit->screeningResponse->clinic_visit_id);
        $this->assertSame('sensor', $visit->vitalSigns->entry_method);
    }

    public function test_invalid_payload_persists_nothing(): void
    {
        $student = $this->student();

        // Temperature above the plausibility bound → 422, no rows written.
        $this->submit($student->id, ['vitals' => ['temperature' => 99]])
            ->assertStatus(422);

        $this->assertSame(0, ClinicVisit::count());
        $this->assertSame(0, VitalSigns::count());
        $this->assertSame(0, ScreeningResponse::count());
    }

    // ── The new forms' twelve questions (D-63) + YES details (D-56) ──────────

    public function test_the_twelve_form_answers_are_stored(): void
    {
        $this->assertCount(12, ScreeningResponse::QUESTIONS);

        // A Medical Clearance YES carries its details (D-75).
        $this->submit($this->student()->id, ['screening' => [
            'kidney_bladder' => true,
            'mental_disorder' => true,
            'details' => ['kidney_bladder' => 'Frequent urination', 'mental_disorder' => 'Anxiety'],
        ]])->assertOk();

        $row = ScreeningResponse::first();
        foreach (array_keys(ScreeningResponse::QUESTIONS) as $question) {
            $this->assertSame(in_array($question, ['kidney_bladder', 'mental_disorder'], true), $row->{$question}, $question);
        }
    }

    /** Submit rejects a payload missing any one of the twelve answers. */
    public function test_each_of_the_twelve_questions_is_a_required_boolean(): void
    {
        // Two submits per question = 24 requests, over the route's
        // throttle:20,1 — switch the rate limiter off for this test only.
        $this->withoutMiddleware(ThrottleRequests::class);

        $student = $this->student();

        foreach (array_keys(ScreeningResponse::QUESTIONS) as $question) {
            $body = $this->payload($student->id);
            unset($body['screening'][$question]);

            $this->withSession(['kiosk.student_id' => $student->id, 'kiosk.login_method' => 'qr'])
                ->postJson(route('kiosk.submit'), $body)
                ->assertStatus(422)
                ->assertJsonValidationErrors("screening.{$question}");

            $this->submit($student->id, ['screening' => [$question => 'maybe']])
                ->assertStatus(422)
                ->assertJsonValidationErrors("screening.{$question}");
        }

        $this->assertSame(0, ClinicVisit::count());
    }

    public function test_a_d56_nine_row_questionnaire_payload_is_refused(): void
    {
        $student = $this->student();
        $body = $this->payload($student->id);
        $body['screening'] = [
            'skin' => false, 'abdomen_git' => false, 'heent' => false,
            'gut' => false, 'chest_lungs' => false, 'extremities' => false,
            'heart_cvs' => false, 'neurological' => false, 'breast' => false,
            'isPregnant' => false, 'lastMenstrualPeriod' => null,
        ];

        $this->withSession(['kiosk.student_id' => $student->id, 'kiosk.login_method' => 'qr'])
            ->postJson(route('kiosk.submit'), $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['screening.head', 'screening.mental_disorder']);

        $this->assertSame(0, ScreeningResponse::count());
    }

    public function test_an_old_self_report_questionnaire_payload_is_refused(): void
    {
        $student = $this->student();
        $body = $this->payload($student->id);
        $body['screening'] = [
            'vision' => false, 'hearing' => false, 'nose' => false,
            'skin' => false, 'respiratory' => false, 'heart' => false,
            'digestive' => false, 'bones' => false, 'nervous' => false,
            'isPregnant' => false, 'lastMenstrualPeriod' => null,
        ];

        $this->withSession(['kiosk.student_id' => $student->id, 'kiosk.login_method' => 'qr'])
            ->postJson(route('kiosk.submit'), $body)
            ->assertStatus(422);

        $this->assertSame(0, ScreeningResponse::count());
    }

    public function test_a_yes_detail_is_stored_against_the_session_student(): void
    {
        $sessionStudent = $this->student();
        $victim = $this->student();

        // The body names another student; the detail still lands on the session's.
        $this->withSession(['kiosk.student_id' => $sessionStudent->id, 'kiosk.login_method' => 'qr'])
            ->postJson(route('kiosk.submit'), $this->payload($victim->id, ['screening' => [
                'skin' => true,
                'details' => ['skin' => 'Itchy rash on left arm'],
            ]]))
            ->assertOk();

        $visit = ClinicVisit::first();
        $this->assertSame($sessionStudent->id, $visit->student_id);
        $this->assertSame(['skin' => 'Itchy rash on left arm'], $visit->screeningResponse->details);
    }

    public function test_a_detail_on_a_no_answer_is_dropped(): void
    {
        $this->submit($this->student()->id, ['screening' => [
            'skin' => true,
            'kidney_bladder' => false,
            'details' => [
                'skin' => 'Itchy rash on left arm',
                'kidney_bladder' => str_repeat('x', 500), // answered NO — dropped, never measured
            ],
        ]])->assertOk();

        $this->assertSame(['skin' => 'Itchy rash on left arm'], ScreeningResponse::first()->details);
    }

    public function test_a_detail_on_an_unknown_key_is_dropped(): void
    {
        $this->submit($this->student()->id, ['screening' => [
            'skin' => true,
            // Unknown keys, even with a YES beside them, never reach `details`.
            'heent' => true,
            'details' => [
                'skin' => 'Itchy rash on left arm',
                'heent' => 'A D-56 row the new forms dropped', // unknown key
                'vision' => 'A pre-D-56 question', // unknown key
                'isPregnant' => 'Not a physical-signs question', // unknown key
            ],
        ]])->assertOk();

        $this->assertSame(['skin' => 'Itchy rash on left arm'], ScreeningResponse::first()->details);
    }

    public function test_a_detail_over_120_characters_is_refused(): void
    {
        $student = $this->student();

        $this->submit($student->id, ['screening' => [
            'skin' => true,
            'details' => ['skin' => str_repeat('a', 121)],
        ]])->assertStatus(422)->assertJsonValidationErrors('screening.details.skin');

        $this->assertSame(0, ClinicVisit::count());

        // Exactly at the cap is fine.
        $this->submit($student->id, ['screening' => [
            'skin' => true,
            'details' => ['skin' => str_repeat('a', 120)],
        ]])->assertOk();

        $this->assertSame(120, mb_strlen(ScreeningResponse::first()->details['skin']));
    }

    public function test_control_characters_are_stripped_from_a_detail(): void
    {
        $student = $this->student();

        // Nothing but control characters is no detail at all — on a Medical
        // Clearance that YES is then refused (D-75).
        $this->submit($student->id, ['screening' => [
            'throat' => true,
            'details' => ['throat' => "\u{0007}\u{001B}"],
        ]])->assertStatus(422)->assertJsonValidationErrors('screening.details.throat');

        $this->submit($student->id, ['screening' => [
            'skin' => true,
            'details' => ['skin' => "Rash\u{0000} on\t arm\u{0085}\n"],
        ]])->assertOk();

        $this->assertSame(['skin' => 'Rash on arm'], ScreeningResponse::first()->details);
    }

    public function test_details_are_null_when_none_are_typed(): void
    {
        $student = $this->student();

        $this->submit($student->id)->assertOk();
        $this->assertNull(ScreeningResponse::latest('id')->first()->details);

        // A YES whose detail is only blank space has no detail — on a Medical
        // Clearance that is refused (D-75; the optional Medical Assessment case
        // is in KioskRequiredYesDetailsTest).
        $this->submit($student->id, ['screening' => ['skin' => true, 'details' => ['skin' => '   ']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('screening.details.skin');
        $this->assertSame(1, ScreeningResponse::count());
    }

    public function test_a_detail_that_is_not_text_is_refused(): void
    {
        $student = $this->student();

        $this->submit($student->id, ['screening' => [
            'skin' => true,
            'details' => ['skin' => ['nested' => 'array']],
        ]])->assertStatus(422);

        $this->submit($student->id, ['screening' => ['details' => 'just a string']])->assertStatus(422);

        $this->assertSame(0, ClinicVisit::count());
    }

    // ── Identity is server-side, never trusted from the body (security) ───────

    /**
     * The endpoint attributes the visit to the SESSION student, not whatever id
     * the body carries. Forging a different studentUserId must have no effect —
     * this is the whole point of binding identity server-side.
     */
    public function test_forged_student_id_in_body_is_ignored(): void
    {
        $sessionStudent = $this->student();
        $victim = $this->student();

        // Session says sessionStudent; the body tries to pin it on the victim.
        $this->withSession([
            'kiosk.student_id' => $sessionStudent->id,
            'kiosk.login_method' => 'qr',
        ])->postJson(route('kiosk.submit'), $this->payload($victim->id))
            ->assertOk();

        $this->assertSame(1, ClinicVisit::count());
        $this->assertSame($sessionStudent->id, ClinicVisit::first()->student_id);
    }

    /**
     * No established kiosk identity (never scanned/logged in, or the session
     * expired) → 422 and nothing written, even with an otherwise-valid payload.
     */
    public function test_submit_without_kiosk_identity_is_rejected(): void
    {
        $student = $this->student();

        $this->postJson(route('kiosk.submit'), $this->payload($student->id))
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame(0, ClinicVisit::count());
    }

    /**
     * End-to-end wiring: a real QR scan binds identity to the session, and a
     * follow-up submit (same session) persists THAT student — no studentUserId
     * needed in the body at all.
     */
    public function test_scan_establishes_identity_then_submit_persists_it(): void
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $profile = StudentProfile::factory()->forCollege($college)->create(['qr_token' => 'INTEGRATION-TOKEN']);
        $student = $profile->user;
        $this->scheduleToday($student);

        $this->postJson(route('kiosk.scan'), ['token' => 'INTEGRATION-TOKEN'])
            ->assertOk()
            ->assertSessionHas('kiosk.student_id', $student->id)
            ->assertSessionHas('kiosk.login_method', 'qr');

        // No studentUserId/loginMethod needed — identity comes from the session.
        $body = $this->payload($student->id);
        unset($body['studentUserId'], $body['loginMethod']);
        $this->postJson(route('kiosk.submit'), $body)->assertOk();

        $visit = ClinicVisit::first();
        $this->assertSame($student->id, $visit->student_id);
        $this->assertSame('qr', $visit->login_method);
    }

    /**
     * Identity is single-use: submit forgets the kiosk.* keys on success, so a
     * replayed POST in the same session cannot mint a second visit.
     */
    public function test_identity_is_forgotten_after_a_successful_submit(): void
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $profile = StudentProfile::factory()->forCollege($college)->create(['qr_token' => 'REPLAY-TOKEN']);
        $student = $profile->user;
        $this->scheduleToday($student);

        $this->postJson(route('kiosk.scan'), ['token' => 'REPLAY-TOKEN'])->assertOk();
        $this->postJson(route('kiosk.submit'), $this->payload($student->id))->assertOk();

        // Same session, identity now cleared → the replay is refused.
        $this->postJson(route('kiosk.submit'), $this->payload($student->id))
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame(1, ClinicVisit::count());
    }

    // ── Flag booleans at boundary values (§7.4, D-10) ─────────────────────────

    /** Temperature flag is "> 37.2": 37.2 is NOT flagged, 37.3 IS. */
    public function test_temperature_flag_boundary(): void
    {
        $this->submit($this->student()->id, ['vitals' => ['temperature' => 37.2]]);
        $this->assertFalse(VitalSigns::latest('id')->first()->is_temp_flagged);

        $this->submit($this->student()->id, ['vitals' => ['temperature' => 37.3]]);
        $this->assertTrue(VitalSigns::latest('id')->first()->is_temp_flagged);
    }

    /** BP flag is "systolic >= 140 OR diastolic >= 90". */
    public function test_blood_pressure_flag_boundary(): void
    {
        // 139/89 — both below → not flagged.
        $this->submit($this->student()->id, ['vitals' => ['systolic' => 139, 'diastolic' => 89]]);
        $this->assertFalse(VitalSigns::latest('id')->first()->is_bp_flagged);

        // 140/90 — both at threshold → flagged.
        $this->submit($this->student()->id, ['vitals' => ['systolic' => 140, 'diastolic' => 90]]);
        $this->assertTrue(VitalSigns::latest('id')->first()->is_bp_flagged);

        // 145/85 — systolic alone trips it → flagged.
        $this->submit($this->student()->id, ['vitals' => ['systolic' => 145, 'diastolic' => 85]]);
        $this->assertTrue(VitalSigns::latest('id')->first()->is_bp_flagged);
    }

    /**
     * D-78: BMI is flagged whenever it is outside Normal (18.5–24.9) — "< 18.5
     * OR >= 25.0". height 100cm makes BMI == weight, so boundaries are exact.
     */
    public function test_bmi_flag_boundary(): void
    {
        foreach ([[18.4, true], [18.5, false], [24.9, false], [25.0, true], [32.0, true]] as [$bmi, $flagged]) {
            $this->submit($this->student()->id, ['vitals' => ['height' => 100, 'weight' => $bmi]]);
            $row = VitalSigns::latest('id')->first();
            $this->assertEquals($bmi, (float) $row->bmi);
            $this->assertSame($flagged, $row->is_bmi_flagged, "BMI {$bmi}");
        }
    }

    /** D-78: the ONE rule, straight through flagsFor(), at each boundary. */
    public function test_flags_for_bmi_boundaries(): void
    {
        $normal = ['temperature' => 36.5, 'systolic' => 110, 'diastolic' => 70, 'heartRate' => 75];

        foreach ([[18.4, true], [18.5, false], [24.9, false], [25.0, true], [32.0, true]] as [$bmi, $flagged]) {
            $flags = SubmitKioskVisit::flagsFor($normal, config('healthpass.thresholds'), $bmi);
            $this->assertSame($flagged, $flags['is_bmi_flagged'], "BMI {$bmi}");
        }
    }

    /** D-78 + D-72: an overweight BMI is flagged, yet the visit is queued, not resting. */
    public function test_an_overweight_bmi_is_flagged_and_still_captured(): void
    {
        // 75 kg at 170 cm = BMI 26.0.
        $this->submit($this->student()->id, ['vitals' => ['height' => 170, 'weight' => 75]])->assertOk();

        $visit = ClinicVisit::firstOrFail();
        $this->assertSame('captured', $visit->status);
        $this->assertEquals(26.0, (float) $visit->vitalSigns->bmi);
        $this->assertTrue($visit->vitalSigns->is_bmi_flagged);
    }

    /** D-66: heart-rate flag is "> 100": 100 is NOT flagged, 101 IS. */
    public function test_heart_rate_flag_boundary(): void
    {
        $this->submit($this->student()->id, ['vitals' => ['heartRate' => 100]]);
        $this->assertFalse(VitalSigns::latest('id')->first()->is_hr_flagged);

        $this->submit($this->student()->id, ['vitals' => ['heartRate' => 101]]);
        $this->assertTrue(VitalSigns::latest('id')->first()->is_hr_flagged);
    }

    /** D-66: there is deliberately no LOW heart-rate flag. */
    public function test_a_low_heart_rate_is_not_flagged(): void
    {
        $this->submit($this->student()->id, ['vitals' => ['heartRate' => 48]]);

        $this->assertFalse(VitalSigns::latest('id')->first()->is_hr_flagged);
    }

    /**
     * D-66 trust boundary: /kiosk/submit is public, so a posted is_hr_flagged
     * must be ignored — the boolean is derived from the heart rate itself,
     * exactly like BMI and the other flags.
     */
    public function test_a_posted_heart_rate_flag_is_ignored(): void
    {
        // A calm 72 bpm posted alongside a forged "already flagged" boolean.
        $this->submit($this->student()->id, ['vitals' => ['heartRate' => 72], 'is_hr_flagged' => true]);
        $this->assertFalse(VitalSigns::latest('id')->first()->is_hr_flagged);

        // And the reverse: 130 bpm posted with the flag forged OFF.
        $this->submit($this->student()->id, ['vitals' => ['heartRate' => 130], 'is_hr_flagged' => false]);
        $this->assertTrue(VitalSigns::latest('id')->first()->is_hr_flagged);
    }

    /**
     * D-65/D-66: the kiosk measures no respiratory rate, so a capture leaves
     * both the value and its flag empty for the clinic to fill at encode.
     */
    public function test_capture_leaves_the_respiratory_rate_and_its_flag_empty(): void
    {
        $this->submit($this->student()->id);

        $row = VitalSigns::latest('id')->first();
        $this->assertNull($row->respiratory_rate);
        $this->assertFalse($row->is_rr_flagged);
    }

    // ── Appointment linkage (BR-10) ───────────────────────────────────────────

    public function test_booked_student_links_todays_appointment(): void
    {
        $student = $this->student(scheduledToday: false);
        $appointment = Appointment::factory()->medical()->create([
            'student_id' => $student->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->submit($student->id)->assertOk();

        $this->assertSame($appointment->id, ClinicVisit::first()->appointment_id);
    }

    /**
     * D-54: with two appointments today, the one whose hour starts closest to
     * check-in wins the link and the other stays `scheduled`. The far one is
     * created FIRST (lower id), so a plain id-order pick would fail this test.
     */
    public function test_the_appointment_closest_to_check_in_wins(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 13:30', 'Asia/Manila'));

        $student = $this->student(scheduledToday: false);
        $far = $this->todaysAppointment($student, '08:00:00');
        $near = $this->todaysAppointment($student, '14:00:00');

        $this->submit($student->id)->assertOk();

        $this->assertSame($near->id, ClinicVisit::first()->appointment_id);
        $this->assertSame('scheduled', $far->fresh()->status);
    }

    // ── D-54: the appointment closest to check-in wins the link ───────────────

    /** A scheduled appointment today for $student in $slot (NULL = pre-D-37). */
    private function todaysAppointment(User $student, ?string $slot, array $overrides = []): Appointment
    {
        return Appointment::factory()->medical()->create(array_merge([
            'student_id' => $student->id,
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => $slot,
            'status' => 'scheduled',
        ], $overrides));
    }

    // Two batches may hold one student on the same day when their hours don't
    // overlap (BR-25) — a 9 AM seat on one and a 2 PM seat on another.

    public function test_a_morning_check_in_links_the_9am_appointment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:10', 'Asia/Manila'));

        $student = $this->student(scheduledToday: false);
        // The 2 PM one is created first, so an id-order pick would choose it.
        $this->todaysAppointment($student, '14:00:00');
        $morning = $this->todaysAppointment($student, '09:00:00');

        $this->submit($student->id)->assertOk();

        $this->assertSame($morning->id, ClinicVisit::first()->appointment_id);
    }

    public function test_an_afternoon_check_in_links_the_2pm_appointment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 13:50', 'Asia/Manila'));

        $student = $this->student(scheduledToday: false);
        $morning = $this->todaysAppointment($student, '09:00:00');
        $afternoon = $this->todaysAppointment($student, '14:00:00');

        $this->submit($student->id)->assertOk();

        $this->assertSame($afternoon->id, ClinicVisit::first()->appointment_id);
        $this->assertSame('scheduled', $morning->fresh()->status);
    }

    public function test_a_tie_goes_to_the_earlier_hour(): void
    {
        // 10:00 is exactly one hour from both 9 AM and 11 AM.
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00', 'Asia/Manila'));

        $student = $this->student(scheduledToday: false);
        $this->todaysAppointment($student, '11:00:00');
        $earlier = $this->todaysAppointment($student, '09:00:00');

        $this->submit($student->id)->assertOk();

        $this->assertSame($earlier->id, ClinicVisit::first()->appointment_id);
    }

    public function test_an_appointment_with_no_time_links_only_when_nothing_else_is_booked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 08:00', 'Asia/Manila'));

        $student = $this->student(scheduledToday: false);
        // A pre-D-37 row has no hour to measure — it loses to any timed one.
        $this->todaysAppointment($student, null);
        $timed = $this->todaysAppointment($student, '16:00:00');

        $this->submit($student->id)->assertOk();

        $this->assertSame($timed->id, ClinicVisit::first()->appointment_id);
    }

    /**
     * The appointment linkage is resolved SERVER-side: an appointmentId smuggled
     * into the body (someone else's, or the student's own on another day) never
     * influences the link — same trust rule as the student identity.
     */
    public function test_forged_appointment_id_in_body_is_ignored(): void
    {
        $student = $this->student(scheduledToday: false);
        $own = $this->scheduleToday($student);
        $otherStudentsAppointment = Appointment::factory()->create([
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->submit($student->id, ['appointmentId' => $otherStudentsAppointment->id])
            ->assertOk();

        $this->assertSame($own->id, ClinicVisit::first()->appointment_id);
        $this->assertSame('scheduled', $otherStudentsAppointment->fresh()->status);
    }

    /** ...and a forged id cannot get a student with no appointment of their own past the D-61 gate. */
    public function test_forged_appointment_id_does_not_unlock_the_gate(): void
    {
        $student = $this->student(scheduledToday: false);
        $otherStudentsAppointment = Appointment::factory()->create([
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->submit($student->id, ['appointmentId' => $otherStudentsAppointment->id])
            ->assertStatus(422);

        $this->assertSame(0, ClinicVisit::count());
        $this->assertSame('scheduled', $otherStudentsAppointment->fresh()->status);
    }

    // ── D-61: no walk-ins ────────────────────────────────────────────────────

    /**
     * No `scheduled` appointment today → 422 and NOTHING written: no visit, no
     * vitals, no screening answers. A cancelled appointment today and a
     * scheduled one tomorrow don't count. The kiosk's no-schedule screen should
     * have stopped the student already; this is the server refusing on its own.
     */
    public function test_student_with_no_appointment_today_is_refused_and_nothing_is_written(): void
    {
        $student = $this->student(scheduledToday: false);
        Appointment::factory()->cancelled()->create([
            'student_id' => $student->id,
            'scheduled_date' => now()->toDateString(),
        ]);
        Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->submit($student->id)
            ->assertStatus(422)
            ->assertJsonPath('message', SubmitKioskVisit::NO_SCHEDULE_MESSAGE);

        $this->assertSame(0, ClinicVisit::count());
        $this->assertSame(0, VitalSigns::count());
        $this->assertSame(0, ScreeningResponse::count());
    }

    /** Already encoded this morning: that appointment is `completed`, so a second visit is refused. */
    public function test_a_second_visit_after_the_appointment_is_completed_is_refused(): void
    {
        $student = $this->student(scheduledToday: false);
        Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'completed',
        ]);

        $this->submit($student->id)->assertStatus(422);

        $this->assertSame(0, ClinicVisit::count());
    }

    // ── College snapshot, transfer-proof (FR-STU-09 / D-17) ───────────────────

    public function test_visit_snapshots_college_and_survives_a_transfer(): void
    {
        $ccs = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $cea = College::create(['code' => 'CEA', 'name' => 'College of Engineering and Architecture']);

        $profile = StudentProfile::factory()->forCollege($ccs)->create();
        $student = $profile->user;
        $this->scheduleToday($student);

        // Visit 1 captured while the student is still in CCS.
        $this->submit($student->id)->assertOk();
        $firstVisit = ClinicVisit::latest('id')->first();
        $this->assertSame($ccs->id, $firstVisit->college_id);

        // The student transfers to CEA (their LIVE college changes)…
        $profile->update(['college_id' => $cea->id]);

        // …Visit 2 snapshots the NEW college. It needs an appointment of its
        // own: a used one refuses a second visit (FR-KSK-03b).
        $this->scheduleToday($student);
        $this->submit($student->id)->assertOk();
        $secondVisit = ClinicVisit::latest('id')->first();
        $this->assertSame($cea->id, $secondVisit->college_id);

        // The OLD visit is untouched — a past case is never re-attributed.
        $this->assertSame($ccs->id, $firstVisit->fresh()->college_id);

        // Grouping by the snapshot (the FR-ANL-05/08 source) counts the old visit
        // under CCS and the new one under CEA, even though the student's current
        // college is now CEA for their profile and all future data.
        $byCollege = ClinicVisit::query()
            ->selectRaw('college_id, COUNT(*) as total')
            ->groupBy('college_id')
            ->pluck('total', 'college_id');

        $this->assertSame(1, (int) $byCollege[$ccs->id]);
        $this->assertSame(1, (int) $byCollege[$cea->id]);
        $this->assertSame($cea->id, $profile->fresh()->college_id);
    }

    // ── Program snapshot, shift-proof (D-43) ──────────────────────────────────

    public function test_visit_snapshots_the_students_program(): void
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $profile = StudentProfile::factory()->forCollege($college)->create([
            'course' => 'Bachelor of Science in Information Systems',
        ]);
        $this->scheduleToday($profile->user);

        $this->submit($profile->user->id)->assertOk();

        $this->assertSame(
            'Bachelor of Science in Information Systems',
            ClinicVisit::first()->course
        );
    }

    /**
     * The whole point of the snapshot: a program shift must not restate history.
     * Without this column, one shift would silently rewrite every past monthly
     * per-program report the student appears in.
     */
    public function test_a_later_program_shift_does_not_rewrite_the_snapshot(): void
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $profile = StudentProfile::factory()->forCollege($college)->create([
            'course' => 'Bachelor of Science in Information Technology',
        ]);
        $this->scheduleToday($profile->user);

        $this->submit($profile->user->id)->assertOk();
        $visit = ClinicVisit::latest('id')->first();

        // The student shifts program — their LIVE profile changes…
        $profile->update(['course' => 'Bachelor of Science in Computer Science']);

        // …the captured visit keeps what was true at capture…
        $this->assertSame('Bachelor of Science in Information Technology', $visit->fresh()->course);

        // …and the NEXT visit (on its own appointment — FR-KSK-03b) snapshots
        // the new program.
        $this->scheduleToday($profile->user);
        $this->submit($profile->user->id)->assertOk();
        $this->assertSame(
            'Bachelor of Science in Computer Science',
            ClinicVisit::latest('id')->first()->course
        );

        $this->assertSame('Bachelor of Science in Computer Science', $profile->fresh()->course);
    }

    /**
     * A profile predating the D-42 catalog can carry an empty course. Unlike a
     * missing college — which fails loudly, since the visit could not be
     * attributed at all — a missing program is stored as null and reports as
     * "—". It must never block the student standing at the kiosk.
     */
    public function test_student_without_a_program_still_submits_with_a_null_snapshot(): void
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $profile = StudentProfile::factory()->forCollege($college)->create(['course' => '']);
        $this->scheduleToday($profile->user);

        $this->submit($profile->user->id)->assertOk()->assertJson(['ok' => true]);

        $visit = ClinicVisit::first();
        $this->assertNull($visit->course);
        $this->assertSame($college->id, $visit->college_id);
    }

    // ── Bluetooth BP device record (D-58) ─────────────────────────────────────

    /** A reading as BpReadingController caches it — the same 118/76, 72 payload() submits. */
    private function claimedBpReading(): array
    {
        return [
            'systolic' => 118,
            'diastolic' => 76,
            'pulse' => 72,
            'mean_arterial' => 90,
            'taken_at' => '2026-09-15T14:30:05',
            'device_model' => 'A&D UA-651BLE',
            'raw' => '16800052006100ea07090f0e1e0548000400',
            'flags' => ['body_movement' => false, 'cuff_too_loose' => false, 'irregular_pulse' => true, 'pulse_out_of_range' => false, 'improper_position' => false],
            'suspect' => false,
            'received_at' => '2026-09-15T14:30:07.123456+08:00',
        ];
    }

    /** Submit with a Bluetooth reading already claimed into the kiosk session. */
    private function submitWithClaimedBp(int $studentId, array $overrides = [])
    {
        return $this->withSession([
            'kiosk.student_id' => $studentId,
            'kiosk.login_method' => 'qr',
            BpReadingController::SESSION_KEY => $this->claimedBpReading(),
        ])->postJson(route('kiosk.submit'), $this->payload($studentId, $overrides));
    }

    public function test_claimed_bluetooth_reading_is_stored_with_its_irregular_pulse(): void
    {
        $this->submitWithClaimedBp($this->student()->id)->assertOk();

        $vitals = VitalSigns::first();
        $this->assertSame('sensor', $vitals->entry_method); // a Bluetooth reading is a sensor reading
        $this->assertTrue($vitals->hasIrregularPulse());
        $this->assertSame('A&D UA-651BLE', $vitals->bp_device_reading['device_model']);
        $this->assertSame('16800052006100ea07090f0e1e0548000400', $vitals->bp_device_reading['raw']);
    }

    public function test_bp_retaken_by_hand_after_a_claim_stores_no_device_record(): void
    {
        $this->submitWithClaimedBp($this->student()->id, [
            'vitalMethods' => ['sensor', 'sensor', 'sensor', 'manual'],
            'vitals' => ['systolic' => 124, 'diastolic' => 80],
        ])->assertOk();

        $vitals = VitalSigns::first();
        $this->assertNull($vitals->bp_device_reading);
        $this->assertFalse($vitals->hasIrregularPulse());
        $this->assertSame('mixed', $vitals->entry_method);
    }

    public function test_typed_bp_without_a_claim_stays_manual_with_no_device_record(): void
    {
        $this->submit($this->student()->id, ['vitalMethods' => ['manual', 'manual', 'manual', 'manual']])
            ->assertOk();

        $vitals = VitalSigns::first();
        $this->assertSame('manual', $vitals->entry_method);
        $this->assertNull($vitals->bp_device_reading);
    }

    /** The device record comes from the session only — a request body can't forge one. */
    public function test_device_record_in_the_request_body_is_ignored(): void
    {
        $this->submit($this->student()->id, [
            'bpReading' => $this->claimedBpReading(),
            'bp_device_reading' => $this->claimedBpReading(),
        ])->assertOk();

        $this->assertNull(VitalSigns::first()->bp_device_reading);
    }

    public function test_submit_forgets_the_claimed_reading(): void
    {
        $this->submitWithClaimedBp($this->student()->id)
            ->assertOk()
            ->assertSessionMissing(BpReadingController::SESSION_KEY);
    }

    // ── entry_method roll-up (FR-KSK-06) ──────────────────────────────────────

    public function test_entry_method_rolls_up_mixed(): void
    {
        // All-sensor → 'sensor'.
        $this->submit($this->student()->id, ['vitalMethods' => ['sensor', 'sensor', 'sensor', 'sensor']]);
        $this->assertSame('sensor', VitalSigns::latest('id')->first()->entry_method);

        // Any mix of the two → 'mixed'.
        $this->submit($this->student()->id, ['vitalMethods' => ['sensor', 'manual', 'sensor', 'sensor']]);
        $this->assertSame('mixed', VitalSigns::latest('id')->first()->entry_method);

        // All-manual → 'manual'.
        $this->submit($this->student()->id, ['vitalMethods' => ['manual', 'manual', 'manual', 'manual']]);
        $this->assertSame('manual', VitalSigns::latest('id')->first()->entry_method);
    }

    /** Provenance values outside sensor/manual are rejected, not coerced. */
    public function test_unknown_vital_method_value_is_rejected(): void
    {
        $this->submit($this->student()->id, ['vitalMethods' => ['sensor', 'spoofed']])
            ->assertStatus(422);

        $this->assertSame(0, VitalSigns::count());
    }

    // ── Adversarial vitals payloads (FR-KSK-08 hardening) ─────────────────────
    // The kiosk endpoint is public; a bypassed client (or a mis-parsed sensor
    // value the front-end somehow let through) must fail range validation with
    // a 422 — never crash, never silently persist.

    /** Absurd-but-parseable sensor values (H:999-style) fail the range check. */
    public function test_absurd_vital_values_are_rejected_and_persist_nothing(): void
    {
        $absurd = [
            ['vitals' => ['height' => 999]],
            ['vitals' => ['weight' => 999]],
            ['vitals' => ['temperature' => 99]],
            ['vitals' => ['systolic' => 999, 'diastolic' => 999]],
            ['vitals' => ['heartRate' => 999]],
            ['vitals' => ['temperature' => -40]],
        ];

        foreach ($absurd as $overrides) {
            $this->submit($this->student()->id, $overrides)->assertStatus(422);
        }

        $this->assertSame(0, ClinicVisit::count());
        $this->assertSame(0, VitalSigns::count());
    }

    /** Non-numeric garbage in a vitals field is a 422, never a 500. */
    public function test_garbage_vital_values_are_rejected_and_persist_nothing(): void
    {
        $garbage = [
            ['vitals' => ['height' => 'abc']],
            ['vitals' => ['temperature' => '36.8; DROP TABLE vital_signs']],
            ['vitals' => ['systolic' => '118/76']],
            ['vitals' => ['heartRate' => ['nested' => 'array']]],
        ];

        foreach ($garbage as $overrides) {
            $this->submit($this->student()->id, $overrides)->assertStatus(422);
        }

        $this->assertSame(0, ClinicVisit::count());
        $this->assertSame(0, VitalSigns::count());
    }
}
