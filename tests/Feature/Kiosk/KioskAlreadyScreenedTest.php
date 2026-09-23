<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use App\Actions\Kiosk\SubmitKioskVisit;
use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-KSK-03b — "You're all done for today".
 *
 * A student whose appointment today already has a SUBMITTED visit (`captured`
 * or `encoded`) has used it: a second scan/login says so (with the visit's
 * reference, never Fit/Unfit), and the server refuses a second submit. A
 * `resting` visit (D-72) is not submitted, so the re-check path is untouched.
 */
class KioskAlreadyScreenedTest extends TestCase
{
    use RefreshDatabase;

    /** First-pass BP high enough that /kiosk/rest accepts the visit (D-72). */
    private const HIGH_BP = ['systolic' => 150, 'diastolic' => 95];

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** An active student with a `scheduled` appointment today on a batch naming $formType. */
    private function student(string $formType = 'clearance'): User
    {
        static $seq = 0;
        $seq++;

        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $profile = StudentProfile::factory()->forCollege($college)->create(['qr_token' => "KIOSK-03B-{$seq}"]);

        $batch = BatchRequest::create([
            'reference_no' => sprintf('BR-2026-%03d', $seq),
            'college_id' => $profile->college_id,
            'requested_by' => $profile->user_id,
            'form_type' => $formType,
            'reason' => $formType === 'assessment' ? 'ojt' : 'fieldtrip',
            'service_type' => 'medical',
            'requested_date' => today()->toDateString(),
            'scheduled_date' => today()->toDateString(),
            'status' => 'approved',
        ]);

        Appointment::factory()->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => today()->toDateString(),
            'status' => 'scheduled',
            'source' => 'batch',
            'batch_request_id' => $batch->id,
        ]);

        return $profile->user;
    }

    private function payload(array $vitals = [], array $extra = []): array
    {
        return [
            'privacyConsentAt' => now()->toIso8601String(),
            'vitalMethods' => ['manual', 'manual', 'manual', 'manual'],
            'vitals' => [
                'height' => 170, 'weight' => 60, 'temperature' => 36.8,
                'systolic' => 118, 'diastolic' => 76, 'heartRate' => 72,
                ...$vitals,
            ],
            'screening' => [
                'skin' => false, 'head' => false, 'eyes' => false, 'ears' => false,
                'nose' => false, 'throat' => false, 'chest_lungs' => false, 'heart' => false,
                'abdomen' => false, 'kidney_bladder' => false, 'brain' => false,
                'mental_disorder' => false,
                'isPregnant' => false, 'lastMenstrualPeriod' => null,
            ],
            ...$extra,
        ];
    }

    /** Post to a kiosk write endpoint with $student bound in the session, as scan() leaves it. */
    private function asKiosk(User $student, string $route, array $payload)
    {
        return $this->withSession([
            'kiosk.student_id' => $student->id,
            'kiosk.login_method' => 'qr',
        ])->postJson(route($route), $payload);
    }

    private function scan(User $student): array
    {
        return $this->postJson(route('kiosk.scan'), ['token' => $student->studentProfile->qr_token])
            ->assertOk()
            ->json('identity');
    }

    // ── Scan / login payload ─────────────────────────────────────────────────

    public function test_a_captured_visit_today_means_already_screened(): void
    {
        $student = $this->student();
        $reference = $this->asKiosk($student, 'kiosk.submit', $this->payload())->assertOk()->json('reference');

        $identity = $this->scan($student);

        $this->assertFalse($identity['hasAppointmentToday']);
        $this->assertTrue($identity['alreadyScreenedToday']);
        $this->assertSame($reference, $identity['screenedReference']);
        $this->assertSame('captured', $identity['screenedStatus']);
    }

    public function test_after_encode_the_status_reads_encoded(): void
    {
        $student = $this->student();
        $this->asKiosk($student, 'kiosk.submit', $this->payload())->assertOk();

        // What EncodeController leaves behind: the visit encoded, the appointment completed.
        $visit = ClinicVisit::sole();
        $visit->update(['status' => 'encoded']);
        $visit->appointment->update(['status' => 'completed']);

        $identity = $this->postJson(route('kiosk.login'), ['email' => $student->email, 'password' => 'password'])
            ->assertOk()
            ->json('identity');

        $this->assertFalse($identity['hasAppointmentToday']);
        $this->assertTrue($identity['alreadyScreenedToday']);
        $this->assertSame($visit->reference_no, $identity['screenedReference']);
        $this->assertSame('encoded', $identity['screenedStatus']);
    }

    public function test_a_resting_visit_is_not_already_screened(): void
    {
        $student = $this->student();
        $this->asKiosk($student, 'kiosk.rest', $this->payload(self::HIGH_BP))->assertOk();

        $identity = $this->scan($student);

        // Exactly the D-72 payload as before: still holds the appointment, told to keep resting.
        $this->assertTrue($identity['hasAppointmentToday']);
        $this->assertFalse($identity['alreadyScreenedToday']);
        $this->assertArrayHasKey('recheckWaitUntil', $identity);
        $this->assertArrayNotHasKey('screenedReference', $identity);
    }

    public function test_a_rechecked_visit_is_already_screened(): void
    {
        $student = $this->student();
        $this->asKiosk($student, 'kiosk.rest', $this->payload(self::HIGH_BP))->assertOk();

        // The rest is over; the re-check releases the visit as `captured`.
        $this->travel(config('healthpass.kiosk.recheck_rest_minutes') + 1)->minutes();
        $this->scan($student);
        $this->postJson(route('kiosk.recheck'), [
            'vitalMethods' => ['manual'],
            'systolic' => 120,
            'diastolic' => 80,
            'heartRate' => 72,
        ])->assertOk();

        $identity = $this->scan($student);

        $this->assertTrue($identity['alreadyScreenedToday']);
        $this->assertSame('captured', $identity['screenedStatus']);
    }

    public function test_no_appointment_at_all_is_not_already_screened(): void
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $profile = StudentProfile::factory()->forCollege($college)->create(['qr_token' => 'KIOSK-03B-NONE']);

        $identity = $this->scan($profile->user);

        $this->assertFalse($identity['hasAppointmentToday']);
        $this->assertFalse($identity['alreadyScreenedToday']);
        $this->assertArrayNotHasKey('screenedReference', $identity);
        $this->assertArrayNotHasKey('screenedStatus', $identity);
    }

    // ── The server gate ──────────────────────────────────────────────────────

    public function test_a_second_submit_on_the_same_appointment_is_refused(): void
    {
        $student = $this->student();
        $this->asKiosk($student, 'kiosk.submit', $this->payload())->assertOk();

        $this->asKiosk($student, 'kiosk.submit', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('message', SubmitKioskVisit::ALREADY_SCREENED_MESSAGE);

        $this->assertSame(1, ClinicVisit::count());
    }

    /**
     * An Assessment visit may leave a YES without details (D-75). Had the
     * second submit been validated as a Clearance, it would be refused with
     * "please add details" — the refusal must be the already-screened one.
     */
    public function test_a_second_assessment_submit_gets_the_already_screened_message(): void
    {
        $student = $this->student('assessment');
        $body = $this->payload([], [
            'screening' => [...$this->payload()['screening'], 'skin' => true],
            'socialHistory' => ['smoking' => 'no', 'alcohol' => 'no', 'illicitDrugs' => 'no', 'sexuallyActive' => false],
        ]);
        $this->asKiosk($student, 'kiosk.submit', $body)->assertOk();

        $this->asKiosk($student, 'kiosk.submit', $body)
            ->assertStatus(422)
            ->assertJsonPath('message', SubmitKioskVisit::ALREADY_SCREENED_MESSAGE);

        $this->assertSame(1, ClinicVisit::count());
    }

    public function test_rest_on_a_used_appointment_is_refused(): void
    {
        $student = $this->student();
        $this->asKiosk($student, 'kiosk.submit', $this->payload())->assertOk();

        $this->asKiosk($student, 'kiosk.rest', $this->payload(self::HIGH_BP))
            ->assertStatus(422)
            ->assertJsonPath('message', SubmitKioskVisit::ALREADY_SCREENED_MESSAGE);

        $this->assertSame(1, ClinicVisit::count());
    }

    // ── Legacy: two appointments today ───────────────────────────────────────

    public function test_a_second_unused_appointment_today_is_still_resolved(): void
    {
        $student = $this->student();
        $this->asKiosk($student, 'kiosk.submit', $this->payload())->assertOk();
        $used = ClinicVisit::sole()->appointment_id;

        // Pre-D-77 data: a second seat on another batch the same day.
        $second = Appointment::factory()->create([
            'student_id' => $student->id,
            'scheduled_date' => today()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->assertSame($second->id, Appointment::todayFor($student->id)?->id);
        $this->assertNotSame($used, $second->id);
        $this->assertTrue($this->scan($student)['hasAppointmentToday']);
    }
}
