<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\ScreeningResponse;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * FR-KSK-10a (D-68) — the kiosk follows the batch's form.
 *
 * A Medical Assessment Form batch also asks section I of the form's back page,
 * "Personal / Social History"; a Medical Clearance batch does not, because its
 * paper has no such section.
 *
 * The form type is decided ENTIRELY on the server, twice: once at scan/login
 * (so the kiosk knows which screens to show) and again at submit (so what is
 * stored never depends on what the browser did or claimed). Both reads go
 * through `Appointment::todayFor()`, the one resolution FR-KSK-12 / D-54 use,
 * so the screens the student saw and the row that gets written can never
 * disagree.
 */
class KioskSocialHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** A valid Personal / Social History block, as the kiosk posts it. */
    private const ANSWERS = [
        'smoking' => 'quit',
        'alcohol' => 'yes',
        'illicitDrugs' => 'no',
        'sexuallyActive' => true,
    ];

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function college(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    /**
     * A student holding a `scheduled` appointment today on an approved batch
     * that named $formType — the only way a student reaches the kiosk (D-61).
     */
    private function studentOnFormBatch(string $formType): StudentProfile
    {
        static $seq = 0;
        $seq++;

        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'password' => Hash::make('password'),
        ]);
        $profile = StudentProfile::factory()->forCollege($this->college())->create([
            'user_id' => $user->id,
            'qr_token' => "KIOSK-D68-TOKEN-{$seq}",
        ]);

        $batch = BatchRequest::create([
            'reference_no' => sprintf('BR-2026-%03d', $seq),
            'college_id' => $profile->college_id,
            'requested_by' => $user->id,
            'form_type' => $formType,
            'reason' => $formType === 'assessment' ? 'ojt' : 'fieldtrip',
            'service_type' => 'medical',
            'requested_date' => today()->toDateString(),
            'scheduled_date' => today()->toDateString(),
            'status' => 'approved',
        ]);

        Appointment::factory()->create([
            'student_id' => $user->id,
            'scheduled_date' => today()->toDateString(),
            'status' => 'scheduled',
            'source' => 'batch',
            'batch_request_id' => $batch->id,
        ]);

        return $profile;
    }

    /** The identity payload the kiosk front-end receives from a QR scan. */
    private function scan(StudentProfile $profile): array
    {
        return $this->postJson(route('kiosk.scan'), ['token' => $profile->qr_token])
            ->assertOk()
            ->json('identity');
    }

    /** A complete, valid submission; $overrides is merged recursively. */
    private function payload(array $overrides = []): array
    {
        $base = [
            'privacyConsentAt' => now()->toIso8601String(),
            'vitalMethods' => ['manual', 'manual', 'manual', 'manual'],
            'vitals' => [
                'height' => 170, 'weight' => 60, 'temperature' => 36.8,
                'systolic' => 118, 'diastolic' => 76, 'heartRate' => 72,
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

    /** Submit as this student, with the kiosk.* session keys scan() would set. */
    private function submit(StudentProfile $profile, array $overrides = [])
    {
        return $this->withSession([
            'kiosk.student_id' => $profile->user_id,
            'kiosk.login_method' => 'qr',
        ])->postJson(route('kiosk.submit'), $this->payload($overrides));
    }

    /** The stored questionnaire row for the one visit this test wrote. */
    private function storedScreening(): ScreeningResponse
    {
        $this->assertSame(1, ClinicVisit::count());

        return ScreeningResponse::firstOrFail();
    }

    // ── The identity payload carries the form type (FR-KSK-03) ────────────────

    public function test_the_scan_payload_names_the_assessment_form(): void
    {
        $profile = $this->studentOnFormBatch('assessment');

        $this->assertSame('assessment', $this->scan($profile)['formType']);
    }

    public function test_the_scan_payload_names_the_clearance_form(): void
    {
        $profile = $this->studentOnFormBatch('clearance');

        $this->assertSame('clearance', $this->scan($profile)['formType']);
    }

    public function test_the_email_login_payload_names_the_same_form(): void
    {
        // Login and scan share one payload builder, so they can never disagree.
        $profile = $this->studentOnFormBatch('assessment');

        $identity = $this->postJson(route('kiosk.login'), [
            'email' => $profile->user->email,
            'password' => 'password',
        ])->assertOk()->json('identity');

        $this->assertSame('assessment', $identity['formType']);
    }

    public function test_a_student_with_nothing_today_falls_back_to_clearance(): void
    {
        // No appointment → no form. The kiosk shows "No Clinic Schedule Today"
        // on the boolean beside it and never reaches a questionnaire, so the
        // neutral default is all this needs to be.
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $profile = StudentProfile::factory()->forCollege($this->college())->create([
            'user_id' => $user->id,
            'qr_token' => 'KIOSK-D68-NO-SCHEDULE',
        ]);

        $identity = $this->scan($profile);

        $this->assertFalse($identity['hasAppointmentToday']);
        $this->assertSame('clearance', $identity['formType']);
    }

    // ── Submit: Medical Assessment Form (FR-KSK-10a) ──────────────────────────

    public function test_an_assessment_submit_without_the_four_answers_is_refused(): void
    {
        $profile = $this->studentOnFormBatch('assessment');

        $this->submit($profile)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['socialHistory']);

        $this->assertSame(0, ClinicVisit::count()); // nothing written
    }

    public function test_an_assessment_submit_missing_one_answer_is_refused(): void
    {
        $profile = $this->studentOnFormBatch('assessment');
        $answers = self::ANSWERS;
        unset($answers['illicitDrugs']);

        $this->submit($profile, ['socialHistory' => $answers])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['socialHistory.illicitDrugs']);

        $this->assertSame(0, ClinicVisit::count());
    }

    public function test_an_invalid_habit_value_is_refused(): void
    {
        $profile = $this->studentOnFormBatch('assessment');

        $this->submit($profile, ['socialHistory' => [...self::ANSWERS, 'smoking' => 'maybe']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['socialHistory.smoking']);

        $this->assertSame(0, ClinicVisit::count());
    }

    public function test_an_assessment_submit_stores_the_four_answers(): void
    {
        $profile = $this->studentOnFormBatch('assessment');

        $this->submit($profile, ['socialHistory' => self::ANSWERS])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $screening = $this->storedScreening();

        $this->assertSame('quit', $screening->smoking);
        $this->assertSame('yes', $screening->alcohol);
        $this->assertSame('no', $screening->illicit_drugs);
        $this->assertTrue($screening->sexually_active);
    }

    public function test_a_stored_no_is_kept_as_no_not_as_a_missing_answer(): void
    {
        // 'no' is a real answer, not an absence — the paper has a box for it.
        $profile = $this->studentOnFormBatch('assessment');

        $this->submit($profile, [
            'socialHistory' => [...self::ANSWERS, 'sexuallyActive' => false],
        ])->assertOk();

        $screening = $this->storedScreening();

        $this->assertNotNull($screening->sexually_active);
        $this->assertFalse($screening->sexually_active);
    }

    // ── Submit: Medical Clearance drops whatever is posted ────────────────────

    public function test_a_clearance_submit_stores_null_even_when_answers_are_posted(): void
    {
        $profile = $this->studentOnFormBatch('clearance');

        $this->submit($profile, ['socialHistory' => self::ANSWERS])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $screening = $this->storedScreening();

        $this->assertNull($screening->smoking);
        $this->assertNull($screening->alcohol);
        $this->assertNull($screening->illicit_drugs);
        $this->assertNull($screening->sexually_active);
    }

    public function test_a_clearance_submit_is_not_asked_for_the_four_answers(): void
    {
        // The questions were never shown, so their absence must not be a 422.
        $profile = $this->studentOnFormBatch('clearance');

        $this->submit($profile)->assertOk()->assertJson(['ok' => true]);
    }

    // ── A forged form type in the body changes nothing ────────────────────────

    public function test_claiming_clearance_does_not_let_an_assessment_student_skip_the_questions(): void
    {
        $profile = $this->studentOnFormBatch('assessment');

        $this->submit($profile, ['formType' => 'clearance'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['socialHistory']);

        $this->assertSame(0, ClinicVisit::count());
    }

    public function test_claiming_assessment_does_not_let_a_clearance_visit_store_a_social_history(): void
    {
        $profile = $this->studentOnFormBatch('clearance');

        $this->submit($profile, [
            'formType' => 'assessment',
            'socialHistory' => self::ANSWERS,
        ])->assertOk();

        $screening = $this->storedScreening();

        $this->assertNull($screening->smoking);
        $this->assertNull($screening->sexually_active);
    }

    // ── The two resolutions agree (D-54 + D-68) ───────────────────────────────

    public function test_scan_and_submit_pick_the_same_appointment_and_therefore_the_same_form(): void
    {
        // D-54: two seats on one day — an early Clearance and a later
        // Assessment. At 14:10 the Assessment hour is the closest, so BOTH the
        // payload the kiosk got and the visit the server writes must be that one.
        $profile = $this->studentOnFormBatch('clearance');
        $morning = Appointment::where('student_id', $profile->user_id)->firstOrFail();
        $morning->update(['scheduled_time' => '08:00:00']);

        $afternoonBatch = BatchRequest::create([
            'reference_no' => 'BR-2026-900',
            'college_id' => $profile->college_id,
            'requested_by' => $profile->user_id,
            'form_type' => 'assessment',
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => today()->toDateString(),
            'scheduled_date' => today()->toDateString(),
            'status' => 'approved',
        ]);
        $afternoon = Appointment::factory()->inSlot('14:00:00')->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => today()->toDateString(),
            'status' => 'scheduled',
            'source' => 'batch',
            'batch_request_id' => $afternoonBatch->id,
        ]);

        $this->travelTo(today()->setTime(14, 10));

        $this->assertSame('assessment', $this->scan($profile)['formType']);

        $this->submit($profile, ['socialHistory' => self::ANSWERS])->assertOk();

        $visit = ClinicVisit::firstOrFail();
        $this->assertSame($afternoon->id, $visit->appointment_id);
        $this->assertSame('quit', $visit->screeningResponse->smoking);
    }
}
