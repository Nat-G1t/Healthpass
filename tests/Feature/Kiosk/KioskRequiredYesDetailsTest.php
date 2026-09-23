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
use Tests\TestCase;

/**
 * D-75 — on a Medical Clearance, a kiosk "Yes" must carry details.
 *
 * The official form PSU-QSP-OSS-004-FO002-R04 says "If YES, give details
 * under Remarks", so a Clearance YES without text is refused with a 422. A
 * Medical Assessment keeps the details optional (D-56): the physician takes
 * the written history on that form anyway.
 *
 * The form type is resolved on the SERVER from today's appointment
 * (`Appointment::todayFor()`), never from the request body. /kiosk/rest runs
 * the same KioskSubmitRequest as /kiosk/submit, so it inherits the rule.
 */
class KioskRequiredYesDetailsTest extends TestCase
{
    use RefreshDatabase;

    /** First-pass BP high enough that /kiosk/rest accepts the visit (D-72). */
    private const HIGH_BP = ['systolic' => 150, 'diastolic' => 95];

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** A student with a `scheduled` appointment today on a batch naming $formType. */
    private function studentOnFormBatch(string $formType): User
    {
        static $seq = 0;
        $seq++;

        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $profile = StudentProfile::factory()->forCollege($college)->create();

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

    /**
     * A complete payload with SKIN answered YES and the given detail (null =
     * none sent). $vitals overrides readings — a high BP makes /kiosk/rest
     * accept it.
     */
    private function payload(?string $skinDetail, array $vitals = []): array
    {
        $payload = [
            'privacyConsentAt' => now()->toIso8601String(),
            'vitalMethods' => ['manual', 'manual', 'manual', 'manual'],
            'vitals' => array_replace([
                'height' => 170, 'weight' => 60, 'temperature' => 36.8,
                'systolic' => 118, 'diastolic' => 76, 'heartRate' => 72,
            ], $vitals),
            'screening' => [
                'skin' => true, 'head' => false, 'eyes' => false, 'ears' => false,
                'nose' => false, 'throat' => false, 'chest_lungs' => false, 'heart' => false,
                'abdomen' => false, 'kidney_bladder' => false, 'brain' => false,
                'mental_disorder' => false,
                'isPregnant' => false, 'lastMenstrualPeriod' => null,
            ],
            // Only read on an assessment visit; dropped on a clearance (D-68).
            'socialHistory' => [
                'smoking' => 'no', 'alcohol' => 'no', 'illicitDrugs' => 'no', 'sexuallyActive' => false,
            ],
        ];

        if ($skinDetail !== null) {
            $payload['screening']['details'] = ['skin' => $skinDetail];
        }

        return $payload;
    }

    /** POST $payload to a kiosk route as $student, bound in the session as scan() would. */
    private function kioskPost(string $route, User $student, array $payload)
    {
        return $this->withSession([
            'kiosk.student_id' => $student->id,
            'kiosk.login_method' => 'qr',
        ])->postJson(route($route), $payload);
    }

    // ── /kiosk/submit ────────────────────────────────────────────────────────

    public function test_a_clearance_yes_without_details_is_refused_naming_the_question(): void
    {
        $student = $this->studentOnFormBatch('clearance');

        $this->kioskPost('kiosk.submit', $student, $this->payload(null))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['screening.details.skin' => 'You answered Yes to SKIN. Please add details.']);

        $this->assertSame(0, ClinicVisit::count());
    }

    public function test_a_clearance_detail_of_only_spaces_or_under_the_minimum_is_refused(): void
    {
        $student = $this->studentOnFormBatch('clearance');

        $this->kioskPost('kiosk.submit', $student, $this->payload('   '))
            ->assertStatus(422)
            ->assertJsonValidationErrors('screening.details.skin');

        // Two characters after trimming is under DETAIL_MIN_LENGTH (3).
        $this->kioskPost('kiosk.submit', $student, $this->payload('  x. '))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['screening.details.skin' => 'at least 3 characters']);

        $this->assertSame(0, ClinicVisit::count());
    }

    public function test_a_clearance_yes_with_details_is_stored(): void
    {
        $student = $this->studentOnFormBatch('clearance');

        $this->kioskPost('kiosk.submit', $student, $this->payload('  blurry vision '))->assertOk();

        $this->assertSame(['skin' => 'blurry vision'], ScreeningResponse::firstOrFail()->details);
    }

    public function test_exactly_the_minimum_is_enough(): void
    {
        $student = $this->studentOnFormBatch('clearance');

        $this->assertSame(3, ScreeningResponse::DETAIL_MIN_LENGTH);
        $this->kioskPost('kiosk.submit', $student, $this->payload('ash'))->assertOk();
    }

    public function test_an_assessment_yes_without_details_still_submits(): void
    {
        $student = $this->studentOnFormBatch('assessment');

        $this->kioskPost('kiosk.submit', $student, $this->payload(null))->assertOk();

        $this->assertNull(ScreeningResponse::firstOrFail()->details);
    }

    public function test_an_assessment_detail_of_only_spaces_is_simply_not_stored(): void
    {
        $student = $this->studentOnFormBatch('assessment');

        $this->kioskPost('kiosk.submit', $student, $this->payload('   '))->assertOk();

        $this->assertNull(ScreeningResponse::firstOrFail()->details);
    }

    public function test_a_body_supplied_form_type_never_relaxes_the_rule(): void
    {
        $student = $this->studentOnFormBatch('clearance');

        $this->kioskPost('kiosk.submit', $student, [...$this->payload(null), 'formType' => 'assessment'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('screening.details.skin');

        $this->kioskPost('kiosk.rest', $student, [...$this->payload(null, self::HIGH_BP), 'formType' => 'assessment'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('screening.details.skin');

        $this->assertSame(0, ClinicVisit::count());
    }

    // ── /kiosk/rest (D-72) — the same Form Request ───────────────────────────

    public function test_rest_refuses_a_clearance_yes_without_details_with_a_readable_message(): void
    {
        $student = $this->studentOnFormBatch('clearance');

        $response = $this->kioskPost('kiosk.rest', $student, $this->payload(null, self::HIGH_BP))
            ->assertStatus(422)
            ->assertJsonValidationErrors('screening.details.skin');

        // The kiosk shows `message` on the Review screen, so it must read as a sentence.
        $this->assertStringStartsWith('You answered Yes to SKIN. Please add details.', $response->json('message'));
        $this->assertSame(0, ClinicVisit::count());
    }

    public function test_rest_accepts_a_clearance_yes_with_details(): void
    {
        $student = $this->studentOnFormBatch('clearance');

        $this->kioskPost('kiosk.rest', $student, $this->payload('itchy rash', self::HIGH_BP))->assertOk();

        $visit = ClinicVisit::firstOrFail();
        $this->assertSame('resting', $visit->status);
        $this->assertSame(['skin' => 'itchy rash'], $visit->screeningResponse->details);
    }

    public function test_rest_accepts_an_assessment_yes_without_details(): void
    {
        $student = $this->studentOnFormBatch('assessment');

        $this->kioskPost('kiosk.rest', $student, $this->payload(null, self::HIGH_BP))->assertOk();

        $this->assertSame('resting', ClinicVisit::firstOrFail()->status);
    }
}
