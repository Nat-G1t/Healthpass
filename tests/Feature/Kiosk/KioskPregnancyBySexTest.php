<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\ScreeningResponse;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * D-79 — the kiosk asks "Are you Pregnant?" only to female students, on both
 * forms.
 *
 * Sex is decided on the SERVER from the session-bound student's profile. The
 * scan payload's `isFemale` only chooses what the kiosk shows; at submit a
 * male student's `isPregnant` / `lastMenstrualPeriod` are not required and are
 * ignored, and the row always stores `is_pregnant = false` with no LMP.
 */
class KioskPregnancyBySexTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function forms(): array
    {
        return [
            'Medical Clearance' => ['clearance'],
            'Medical Assessment' => ['assessment'],
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** A student of $sex holding today's appointment on a $formType batch (D-61). */
    private function student(string $sex, string $formType): StudentProfile
    {
        static $seq = 0;
        $seq++;

        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $profile = StudentProfile::factory()->forCollege($college)->create([
            'sex' => $sex,
            'qr_token' => "KIOSK-D79-TOKEN-{$seq}",
        ]);

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

        return $profile;
    }

    /**
     * A complete submission WITHOUT the pregnancy pair; $screening adds to
     * the screening block (e.g. the pair itself).
     */
    private function payload(string $formType, array $screening = []): array
    {
        return [
            'privacyConsentAt' => now()->toIso8601String(),
            'vitalMethods' => ['manual', 'manual', 'manual', 'manual'],
            'vitals' => [
                'height' => 170, 'weight' => 60, 'temperature' => 36.8,
                'systolic' => 118, 'diastolic' => 76, 'heartRate' => 72,
            ],
            'screening' => [
                ...array_fill_keys(array_keys(ScreeningResponse::QUESTIONS), false),
                ...$screening,
            ],
            ...($formType === 'assessment' ? ['socialHistory' => [
                'smoking' => 'no', 'alcohol' => 'no', 'illicitDrugs' => 'no', 'sexuallyActive' => false,
            ]] : []),
        ];
    }

    /** Submit as this student, with the kiosk.* session keys scan() would set. */
    private function submit(StudentProfile $profile, array $payload)
    {
        return $this->withSession([
            'kiosk.student_id' => $profile->user_id,
            'kiosk.login_method' => 'qr',
        ])->postJson(route('kiosk.submit'), $payload);
    }

    private function storedScreening(): ScreeningResponse
    {
        $this->assertSame(1, ClinicVisit::count());

        return ScreeningResponse::firstOrFail();
    }

    // ── The scan payload says whether to ask (display only) ──────────────────

    public function test_the_scan_payload_carries_is_female(): void
    {
        foreach (['F' => true, 'M' => false] as $sex => $expected) {
            $profile = $this->student($sex, 'clearance');

            $identity = $this->postJson(route('kiosk.scan'), ['token' => $profile->qr_token])
                ->assertOk()
                ->json('identity');

            $this->assertSame($expected, $identity['isFemale'], "sex {$sex}");
        }
    }

    // ── Male: never required, never trusted ──────────────────────────────────

    #[DataProvider('forms')]
    public function test_a_male_student_may_omit_the_pregnancy_question(string $formType): void
    {
        $profile = $this->student('M', $formType);

        $this->submit($profile, $this->payload($formType))->assertOk();

        $screening = $this->storedScreening();
        $this->assertFalse($screening->is_pregnant);
        $this->assertNull($screening->last_menstrual_period);
    }

    #[DataProvider('forms')]
    public function test_a_male_students_posted_pregnancy_answer_is_ignored(string $formType): void
    {
        $profile = $this->student('M', $formType);

        $this->submit($profile, $this->payload($formType, [
            'isPregnant' => true,
            'lastMenstrualPeriod' => today()->subDays(10)->toDateString(),
        ]))->assertOk();

        $screening = $this->storedScreening();
        $this->assertFalse($screening->is_pregnant);
        $this->assertNull($screening->last_menstrual_period);
    }

    // ── Female: unchanged ────────────────────────────────────────────────────

    #[DataProvider('forms')]
    public function test_a_female_student_must_answer_the_pregnancy_question(string $formType): void
    {
        $profile = $this->student('F', $formType);

        $this->submit($profile, $this->payload($formType))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('screening.isPregnant');

        $this->assertSame(0, ClinicVisit::count());
    }

    #[DataProvider('forms')]
    public function test_a_female_students_pregnancy_answer_is_stored_as_sent(string $formType): void
    {
        $profile = $this->student('F', $formType);
        $lmp = today()->subDays(10)->toDateString();

        $this->submit($profile, $this->payload($formType, [
            'isPregnant' => true,
            'lastMenstrualPeriod' => $lmp,
        ]))->assertOk();

        $screening = $this->storedScreening();
        $this->assertTrue($screening->is_pregnant);
        $this->assertSame($lmp, $screening->last_menstrual_period?->toDateString());
    }

    public function test_a_pregnant_female_still_needs_her_lmp(): void
    {
        $profile = $this->student('F', 'clearance');

        $this->submit($profile, $this->payload('clearance', ['isPregnant' => true]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('screening.lastMenstrualPeriod');
    }
}
