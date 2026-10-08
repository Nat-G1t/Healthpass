<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;
use Carbon\CarbonInterface;

/**
 * D-126 — visits for the two clinic queues: a kiosk visit on an approved
 * batch naming the given form, at the given lifecycle status. The vitals and
 * answers match EncodePayload's defaults, so a save with no overrides corrects
 * nothing.
 */
trait ClinicQueueFixture
{
    private function nurse(): User
    {
        return User::factory()->create(['role' => 'nurse']);
    }

    private function physician(): User
    {
        return User::factory()->physician()->create();
    }

    private function queueCollege(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    /**
     * @param  'clearance'|'assessment'  $formType
     * @param  'captured'|'interviewed'|'encoded'  $status
     */
    private function queueVisit(
        string $formType,
        string $status = 'captured',
        ?CarbonInterface $checkedInAt = null,
        ?CarbonInterface $interviewedAt = null,
        string $sex = 'F',
    ): ClinicVisit {
        static $seq = 1;

        $college = $this->queueCollege();
        $student = User::factory()->create(['role' => 'student', 'name' => "Student {$seq}"]);
        $student->studentProfile()->create([
            'college_id' => $college->id,
            'student_number' => fake()->unique()->numerify('2023-######'),
            'first_name' => 'Student',
            'last_name' => (string) $seq,
            'sex' => $sex,
            'course' => 'Bachelor of Science in Information Technology',
            'year_level' => '3rd Year',
            'date_of_birth' => '2004-05-10',
            'place_of_birth' => 'San Fernando',
            'civil_status' => 'Single',
            'address' => 'Bacolor, Pampanga',
            'qr_token' => fake()->unique()->sha256(),
        ]);

        $batch = BatchRequest::create([
            'reference_no' => sprintf('BR-2026-%03d', 500 + $seq),
            'college_id' => $college->id,
            'requested_by' => $student->id,
            'form_type' => $formType,
            'reason' => $formType === 'assessment' ? 'ojt' : 'fieldtrip',
            'service_type' => 'medical',
            'scheduled_date' => today()->toDateString(),
            'status' => 'approved',
        ]);

        $appointment = Appointment::factory()->medical()->create([
            'student_id' => $student->id,
            'scheduled_date' => today()->toDateString(),
            'status' => 'scheduled',
            'source' => 'batch',
            'batch_request_id' => $batch->id,
        ]);

        $visit = ClinicVisit::create([
            'reference_no' => sprintf('HP-2026-Q%03d', $seq++),
            'student_id' => $student->id,
            'college_id' => $college->id,
            'appointment_id' => $appointment->id,
            'login_method' => 'qr',
            'status' => $status,
            'privacy_consent_at' => now(),
            'checked_in_at' => $checkedInAt ?? now()->subMinutes(30),
        ]);

        VitalSigns::create([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 165.0,
            'weight_kg' => 60.0,
            'bmi' => 22.0,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 115,
            'bp_diastolic' => 75,
            'entry_method' => 'manual',
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            'is_bmi_flagged' => false,
        ]);

        ScreeningResponse::create([
            'clinic_visit_id' => $visit->id,
            ...array_fill_keys(array_keys(ScreeningResponse::QUESTIONS), false),
            'is_pregnant' => false,
        ]);

        if ($status !== 'captured' && $formType === 'assessment') {
            $this->markInterviewed($visit, $interviewedAt ?? now()->subMinutes(10));
        }

        return $visit;
    }

    /** What InterviewController leaves behind (D-126), without going through HTTP. */
    private function markInterviewed(ClinicVisit $visit, CarbonInterface $at, ?User $nurse = null): void
    {
        $visit->medicalAssessment()->create([
            'medical_history' => ['patient' => ['asthma'], 'family' => [], 'specify' => []],
            'immunizations' => ['given' => ['bcg'], 'others' => null],
            'family_planning_access' => true,
            'surgical_history' => ['procedures' => 'Appendectomy', 'date_done' => '2019'],
            'interview_vitals' => [...EncodePayload::vitals(['temperature_c' => 36.9, 'respiratory_rate' => 18]), 'bmi' => 22.0],
        ]);

        $visit->update([
            'interviewed_by' => ($nurse ?? $this->nurse())->id,
            'interviewed_at' => $at,
        ]);
    }
}
