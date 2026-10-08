<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClinicVisit;
use App\Models\User;

/**
 * D-125 (O2) — the clinic prints an encoded form only once the Director has
 * released its batch's documents (ClinicVisit::isReleasedForPrint()).
 *
 * The print tests are about WHAT prints, so they start from a released visit:
 * releaseForPrint() gives the visit's batch an approved release request. A
 * visit with no batch (the legacy shape many of these tests were written
 * with) first gets an approved Medical Clearance batch and an appointment on
 * it — the form it already printed. The record's printed purpose is stored on
 * the clearance record at encode, so it is unchanged.
 */
trait ReleasesForPrint
{
    private function releaseForPrint(ClinicVisit $visit): ClinicVisit
    {
        static $seq = 900;

        $batch = $visit->appointment?->batchRequest;

        if ($batch === null) {
            $admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $visit->college_id]);

            $batch = BatchRequest::create([
                'reference_no' => 'BR-2026-'.$seq++,
                'college_id' => $visit->college_id,
                'requested_by' => $admin->id,
                'form_type' => 'clearance',
                'reason' => 'fieldtrip',
                'service_type' => 'medical',
                'scheduled_date' => today()->toDateString(),
                'status' => 'approved',
            ]);

            $appointment = Appointment::factory()->medical()->create([
                'student_id' => $visit->student_id,
                'source' => 'batch',
                'batch_request_id' => $batch->id,
            ]);

            // The relation was loaded (as null) above; drop it so the caller's
            // $visit reads the new appointment.
            $visit->update(['appointment_id' => $appointment->id]);
            $visit->unsetRelation('appointment');
        }

        $batch->releaseRequests()->create([
            'requested_by' => $batch->requested_by,
            'reason' => 'The college needs the forms for its records.',
            'status' => 'approved',
            'reviewed_by' => User::factory()->create(['role' => 'director'])->id,
            'reviewed_at' => now(),
        ]);

        return $visit->fresh();
    }
}
