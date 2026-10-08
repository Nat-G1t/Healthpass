<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\DocumentReleaseRequest;
use App\Models\ScreeningResponse;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * D-113 — one fixture for the document release tests: an approved batch of a
 * college, its students driven to a stage, and release requests.
 *
 * A PHP trait is a block of methods a class can pull in; each D-113 test class
 * `use`s this one and calls setUpRelease() from setUp().
 *
 * The clock: the batch's clinic day is Thursday Sep 10, 2026 at 9 AM, and
 * "now" is the next morning — past that day's 8 PM cutoff, so a batch whose
 * students are all encoded reads Completed, and one with a no-show reads
 * Finished incomplete (D-111). An encoded student gets everything the official
 * form prints (vitals, kiosk answers, the clinic's record, and the Medical
 * Assessment Form's own sections on that form), so the ZIP really renders.
 */
trait DocumentReleaseFixture
{
    private const CLINIC_DAY = '2026-09-10';

    private College $ccs;

    private College $coe;

    private User $admin;

    private User $director;

    private User $nurse;

    private function setUpRelease(): void
    {
        // Every memo, legacy attachment and ZIP lands on a throwaway disk.
        Storage::fake('local');
        // Nothing here should mail anyone; counted, never sent.
        Queue::fake();

        $this->travelTo(Carbon::parse('2026-09-11 10:00:00'));

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->coe = College::create(['code' => 'COE', 'name' => 'College of Engineering']);

        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'name' => 'Carla Admin',
            'managed_college_id' => $this->ccs->id,
        ]);
        $this->director = User::factory()->create(['role' => 'director', 'name' => 'Dina Director']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
    }

    /** A batch scheduled on CLINIC_DAY at 9 AM — approved unless told otherwise. */
    private function makeBatch(string $formType = 'clearance', ?College $college = null, string $status = 'approved'): BatchRequest
    {
        static $seq = 700;

        $isScheduled = in_array($status, ['approved', 'cancelled'], true);

        return BatchRequest::create([
            'reference_no' => 'BR-2026-'.$seq++,
            'college_id' => ($college ?? $this->ccs)->id,
            'requested_by' => $this->admin->id,
            'form_type' => $formType,
            'reason' => $formType === 'assessment' ? 'ojt' : 'fieldtrip',
            'service_type' => 'medical',
            'scheduled_date' => $isScheduled ? self::CLINIC_DAY : null,
            'scheduled_time' => $isScheduled ? '09:00:00' : null,
            'scheduled_blocks' => $isScheduled ? 1 : null,
            'status' => $status,
            'reviewed_by' => $status === 'pending' ? null : $this->director->id,
            'reviewed_at' => $status === 'pending' ? null : now()->subDays(3),
        ]);
    }

    /**
     * Put one student called $name ("First Last …") on $batch and drive them to
     * $stage: 'booked' (no visit), 'withdrawn' (a seat pulled before D-87),
     * 'resting' (D-72), 'in_clinic' (captured, not encoded), 'Fit' / 'Unfit'
     * (encoded). Returns the roster row's appointment.
     */
    private function addStudent(BatchRequest $batch, string $stage = 'Fit', ?string $name = null): Appointment
    {
        static $seq = 1;
        $n = $seq++;

        [$first, $last] = explode(' ', $name ?? "Student{$n} Lastname{$n}", 2);

        $student = User::factory()->create(['role' => 'student', 'name' => "{$first} {$last}"]);
        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'college_id' => $batch->college_id,
            'student_number' => sprintf('2023-%06d', $n),
            'first_name' => $first,
            'last_name' => $last,
            'sex' => 'F',
        ]);

        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'service_type' => 'medical',
            'scheduled_date' => self::CLINIC_DAY,
            'scheduled_time' => '09:00:00',
            'status' => $stage === 'withdrawn' ? 'cancelled' : 'scheduled',
            'source' => 'batch',
            'batch_request_id' => $batch->id,
            'created_by' => $this->director->id,
        ]);

        BatchRequestStudent::create([
            'batch_request_id' => $batch->id,
            'student_id' => $student->id,
            'appointment_id' => $appointment->id,
        ]);

        if (! in_array($stage, ['booked', 'withdrawn'], true)) {
            $this->visit($appointment, $stage);
        }

        return $appointment->fresh();
    }

    /** The kiosk visit (and, for Fit / Unfit, the clinic's encode) on $appointment. */
    private function visit(Appointment $appointment, string $stage): ClinicVisit
    {
        static $seq = 7000;

        $isEncoded = in_array($stage, ['Fit', 'Unfit'], true);

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $appointment->student_id,
            'college_id' => $appointment->batchRequest->college_id,
            'appointment_id' => $appointment->id,
            'login_method' => 'qr',
            'status' => match ($stage) {
                'resting' => 'resting',
                'in_clinic' => 'captured',
                default => 'encoded',
            },
            'privacy_consent_at' => Carbon::parse(self::CLINIC_DAY.' 09:05:00'),
            'checked_in_at' => Carbon::parse(self::CLINIC_DAY.' 09:05:00'),
        ]);

        VitalSigns::create([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 160.0,
            'weight_kg' => 55.0,
            'bmi' => 21.5,
            'temperature_c' => 36.6,
            'heart_rate_bpm' => 78,
            'bp_systolic' => 112,
            'bp_diastolic' => 74,
            'entry_method' => 'manual',
        ]);

        $formType = $appointment->batchRequest->form_type;

        ScreeningResponse::create([
            'clinic_visit_id' => $visit->id,
            ...array_fill_keys(array_keys(ScreeningResponse::QUESTIONS), false),
            'is_pregnant' => false,
            ...($formType === 'assessment' ? [
                'smoking' => 'no', 'alcohol' => 'no', 'illicit_drugs' => 'no', 'sexually_active' => false,
            ] : []),
        ]);

        if ($isEncoded) {
            $record = ClearanceRecord::create([
                'clinic_visit_id' => $visit->id,
                'encoded_by' => $this->nurse->id,
                'result' => $stage,
                'purpose' => $formType === 'assessment' ? 'On-the-job Training' : 'Field Trip/Educational Tour',
                'encoded_vitals' => [
                    'height_cm' => 160.0, 'weight_kg' => 55.0, 'bmi' => 21.5, 'temperature_c' => 36.6,
                    'bp_systolic' => 112, 'bp_diastolic' => 74, 'heart_rate_bpm' => 78, 'respiratory_rate' => 16,
                ],
                'encoded_at' => Carbon::parse(self::CLINIC_DAY.' 10:00:00'),
            ]);

            if ($formType === 'assessment') {
                $record->medicalAssessment()->create([
                    'medical_history' => ['patient' => [], 'family' => [], 'specify' => []],
                    'immunizations' => ['given' => [], 'others' => null],
                    'surgical_history' => ['procedures' => null, 'date_done' => null],
                ]);
            }

            // Like EncodeController: the encode completes the appointment.
            $appointment->update(['status' => 'completed']);
        }

        return $visit;
    }

    /** An approved batch whose $count students are all encoded Fit — Completed. */
    private function completedBatch(int $count = 2, string $formType = 'clearance', ?College $college = null): BatchRequest
    {
        $batch = $this->makeBatch($formType, $college);

        foreach (range(1, $count) as $i) {
            $this->addStudent($batch, 'Fit');
        }

        return $batch;
    }

    /** A release request on $batch as made since D-123 — no attachment. */
    private function release(BatchRequest $batch, string $status = 'pending', array $attributes = []): DocumentReleaseRequest
    {
        $isDecided = $status !== 'pending';

        // forceCreate: a test may set created_at, which is not fillable.
        return $batch->releaseRequests()->forceCreate([
            'requested_by' => $this->admin->id,
            'reason' => 'The college needs the forms for the OJT partner companies.',
            'status' => $status,
            'reviewed_by' => $isDecided ? $this->director->id : null,
            'reviewed_at' => $isDecided ? now() : null,
            'rejection_message' => $status === 'rejected' ? 'Please attach the memo signed by the dean.' : null,
            ...$attributes,
        ]);
    }

    /** A request made BEFORE D-123, with its attachment on the (fake) private disk. */
    private function legacyRelease(BatchRequest $batch, string $status = 'pending', array $attributes = []): DocumentReleaseRequest
    {
        static $seq = 1;
        $path = 'release-requests/attachment-'.$seq++.'.png';
        Storage::disk('local')->put($path, 'fake image bytes');

        return $this->release($batch, $status, [
            'attachment_path' => $path,
            'attachment_original_name' => 'signed-request.png',
            'attachment_mime' => 'image/png',
            ...$attributes,
        ]);
    }

    /** Give $batch its D-109 signed memo — an image unless told otherwise. */
    private function withMemo(BatchRequest $batch, string $mime = 'image/png', string $name = 'signed-memo.png'): BatchRequest
    {
        $path = 'memos/memo-'.$batch->id.'.'.($mime === 'application/pdf' ? 'pdf' : 'png');
        Storage::disk('local')->put($path, 'fake memo bytes');

        $batch->update(['memo_path' => $path, 'memo_original_name' => $name, 'memo_mime' => $mime]);

        return $batch;
    }

    /** The encoded visit behind a roster appointment. */
    private function visitOf(Appointment $appointment): ClinicVisit
    {
        return $appointment->clinicVisit()->firstOrFail();
    }
}
