<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ScreeningResponse;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * D-133 — Manual Screening: a student scheduled today on an approved batch
 * naming the given form, with NO visit yet, and the payloads the Manual
 * Screening form posts (the kiosk's shape, plus the interview on an
 * Assessment).
 */
trait ManualScreeningFixture
{
    use ClinicQueueFixture;

    /**
     * @param  'clearance'|'assessment'  $formType
     * @param  array<string, mixed>  $appointment  Overrides for the appointment row.
     */
    private function scheduledStudent(
        string $formType = 'clearance',
        string $sex = 'F',
        ?string $name = null,
        array $appointment = [],
    ): Appointment {
        static $seq = 1;

        $profile = StudentProfile::factory()->forCollege($this->queueCollege())->create([
            'sex' => $sex,
            'student_number' => sprintf('2023-9%05d', $seq),
        ]);
        $profile->user->update(['name' => $name ?? "Manual Student {$seq}"]);

        $batch = BatchRequest::create([
            'reference_no' => sprintf('BR-2026-%03d', 800 + $seq++),
            'college_id' => $profile->college_id,
            'requested_by' => $profile->user_id,
            'form_type' => $formType,
            'reason' => $formType === 'assessment' ? 'ojt' : 'fieldtrip',
            'service_type' => 'medical',
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => '09:00:00',
            'scheduled_blocks' => 1,
            'status' => 'approved',
        ]);

        return Appointment::factory()->medical()->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => '09:00:00',
            'arrival_time' => '09:03:00',
            'status' => 'scheduled',
            'source' => 'batch',
            'batch_request_id' => $batch->id,
            ...$appointment,
        ]);
    }

    /** @return array<string, mixed> A complete Medical Clearance form: normal vitals, every row No. */
    private function clearancePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'privacy_consent' => '1',
            'vitals' => [
                'height' => '170', 'weight' => '60', 'temperature' => '36.8',
                'systolic' => '118', 'diastolic' => '76', 'heartRate' => '72',
            ],
            'screening' => [
                ...array_fill_keys(array_keys(ScreeningResponse::QUESTIONS), '0'),
                'isPregnant' => '0',
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> The Clearance form plus the social history and the nurse's interview. */
    private function assessmentPayload(array $overrides = []): array
    {
        return $this->clearancePayload(array_replace_recursive([
            'socialHistory' => ['smoking' => 'no', 'alcohol' => 'quit', 'illicitDrugs' => 'no', 'sexuallyActive' => '0'],
            'respiratory_rate' => '18',
            'medical_history' => ['patient' => ['asthma'], 'family' => ['hypertension']],
            'immunizations' => ['given' => ['bcg', 'hpv'], 'others' => 'Typhoid'],
            'family_planning_access' => '1',
            'surgical_history' => ['procedures' => 'Appendectomy', 'date_done' => '2019'],
        ], $overrides));
    }

    private function submitManual(Appointment $appointment, array $payload, ?User $nurse = null): TestResponse
    {
        return $this->actingAs($nurse ?? $this->nurse())
            ->post(route('nurse.manual.store', $appointment), $payload);
    }
}
