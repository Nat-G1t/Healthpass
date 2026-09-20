<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;

/**
 * D-73 — one fixture for the student's record page and their Save as PDF.
 *
 * A PHP trait is a block of methods a class can pull in; both test classes
 * `use` this one so a visit is built the same way for the page and for the
 * document it downloads, and the two can never drift apart.
 */
trait StudentRecordFixture
{
    private function college(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    private function nurse(): User
    {
        return User::factory()->create(['role' => 'nurse', 'name' => 'Jane Dela Cruz']);
    }

    private function makeStudent(string $sex = 'F'): User
    {
        $student = User::factory()->create(['role' => 'student', 'name' => 'Ana Cruz']);

        $student->studentProfile()->create([
            'college_id' => $this->college()->id,
            'student_number' => fake()->unique()->numerify('2023-######'),
            'first_name' => 'Ana',
            'middle_name' => 'Reyes',
            'last_name' => 'Cruz',
            'sex' => $sex,
            'course' => 'Bachelor of Science in Information Technology',
            'year_level' => '3rd Year',
            'date_of_birth' => '2004-05-10',
            'place_of_birth' => 'San Fernando',
            'civil_status' => 'Single',
            'address' => 'Bacolor, Pampanga',
            'qr_token' => fake()->unique()->sha256(),
        ]);

        return $student;
    }

    /** A visit on an approved batch that named $formType (D-62), with vitals + answers. */
    private function makeVisit(User $student, string $formType = 'clearance', string $status = 'captured'): ClinicVisit
    {
        static $seq = 1;
        $seq++;

        $batch = BatchRequest::create([
            'reference_no' => sprintf('BR-2026-%03d', $seq),
            'college_id' => $this->college()->id,
            'requested_by' => $student->id,
            'form_type' => $formType,
            'reason' => $formType === 'assessment' ? 'ojt' : 'fieldtrip',
            'service_type' => 'medical',
            'requested_date' => today()->toDateString(),
            'scheduled_date' => today()->toDateString(),
            'status' => 'approved',
        ]);

        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'source' => 'batch',
            'batch_request_id' => $batch->id,
        ]);

        $visit = ClinicVisit::create([
            'reference_no' => sprintf('HP-2026-R%03d', $seq),
            'student_id' => $student->id,
            'college_id' => $this->college()->id,
            'appointment_id' => $appointment->id,
            'login_method' => 'qr',
            'status' => $status,
            'privacy_consent_at' => now(),
            'checked_in_at' => now()->subMinutes(5),
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
            'respiratory_rate' => 16,
            'entry_method' => 'manual',
        ]);

        ScreeningResponse::create([
            'clinic_visit_id' => $visit->id,
            ...array_fill_keys(array_keys(ScreeningResponse::QUESTIONS), false),
            'is_pregnant' => false,
            ...($formType === 'assessment' ? [
                'smoking' => 'no',
                'alcohol' => 'quit',
                'illicit_drugs' => 'no',
                'sexually_active' => false,
            ] : []),
        ]);

        return $visit;
    }

    /** Flip a visit to encoded, with its clearance record and (D-69) sections. */
    private function encode(
        ClinicVisit $visit,
        User $encoder,
        array $overrides = [],
        array $sections = [],
    ): ClearanceRecord {
        $record = ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $encoder->id,
            'result' => 'Fit',
            'purpose' => $visit->formType() === 'assessment' ? 'On-the-job Training' : 'Field Trip/Educational Tour',
            'nurse_notes' => 'Advised rest and hydration.',
            'encoded_vitals' => [
                'height_cm' => 165.0,
                'weight_kg' => 60.0,
                'bmi' => 22.0,
                'temperature_c' => 36.5,
                'bp_systolic' => 115,
                'bp_diastolic' => 75,
                'heart_rate_bpm' => 75,
                'respiratory_rate' => 16,
            ],
            'encoded_at' => now(),
            ...$overrides,
        ]);

        if ($visit->formType() === 'assessment') {
            $record->medicalAssessment()->create([
                'medical_history' => ['patient' => [], 'family' => [], 'specify' => []],
                'immunizations' => ['given' => [], 'others' => null],
                'surgical_history' => ['procedures' => null, 'date_done' => null],
                ...$sections,
            ]);
        }

        $visit->update(['status' => 'encoded']);

        return $record;
    }

    /** The common case: a student's finished visit, ready to view or download. */
    private function encodedVisit(User $student, string $formType = 'clearance', array $sections = []): ClinicVisit
    {
        $visit = $this->makeVisit($student, $formType);

        $this->encode($visit, $this->nurse(), sections: $sections);

        return $visit->fresh();
    }
}
