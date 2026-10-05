<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ClinicScheduleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * D-110 — one fixture for the 2nd-chance tests: an approved batch on a clinic
 * day, its students driven to a kiosk stage, other students filling seats,
 * and the clock moved to the 8 PM cutoff.
 *
 * A PHP trait is a block of methods a class can pull in; both 2nd-chance test
 * classes `use` this one so their batches are built the same way.
 */
trait SecondChanceFixture
{
    /** The clinic day every case starts from — a Monday. The day after is "tomorrow". */
    private const CLINIC_DAY = '2026-10-05';

    private College $college;

    private User $director;

    private User $admin;

    private User $nurse;

    /**
     * Call from setUp(). Mail jobs are faked (counted, never sent), and the
     * clock starts mid-morning on the clinic day — nobody has missed it yet.
     */
    private function setUpSecondChance(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 09:00:00'));

        $this->college = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->director = User::factory()->create(['role' => 'director']);
        $this->admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->college->id]);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
    }

    /** A batch on the clinic day at 9 AM with $students booked students. */
    private function makeBatch(int $students = 0, string $form = 'clearance', string $status = 'approved'): BatchRequest
    {
        static $seq = 700;

        $batch = BatchRequest::create([
            'reference_no' => 'BR-2026-'.$seq++,
            'college_id' => $this->college->id,
            'requested_by' => $this->admin->id,
            'form_type' => $form,
            'reason' => $form === 'clearance' ? 'fieldtrip' : 'ojt',
            'service_type' => 'medical',
            'scheduled_date' => self::CLINIC_DAY,
            'scheduled_time' => '09:00:00',
            'scheduled_blocks' => 1,
            'status' => $status,
            'reviewed_by' => $this->director->id,
            'reviewed_at' => now(),
        ]);

        for ($added = 0; $added < $students; $added++) {
            $this->addStudent($batch);
        }

        return $batch;
    }

    /** One student on $batch: a profile, a 9 AM appointment on the batch's day, and the roster row. */
    private function addStudent(BatchRequest $batch, string $userStatus = 'active'): Appointment
    {
        $profile = StudentProfile::factory()->forCollege($this->college)->create();
        $profile->user->update(['status' => $userStatus]);

        $appointment = Appointment::factory()->create([
            'student_id' => $profile->user_id,
            'scheduled_date' => $batch->scheduled_date,
            'scheduled_time' => '09:00:00',
            'status' => 'scheduled',
            'source' => 'batch',
            'batch_request_id' => $batch->id,
            'created_by' => $this->director->id,
        ]);

        BatchRequestStudent::create([
            'batch_request_id' => $batch->id,
            'student_id' => $profile->user_id,
            'appointment_id' => $appointment->id,
        ]);

        return $appointment;
    }

    /**
     * A kiosk visit on $appointment: `resting` or `captured`, or `encoded`
     * with $result (which, like the nurse's encode, completes the appointment).
     */
    private function visit(Appointment $appointment, string $status, ?string $result = null): ClinicVisit
    {
        static $seq = 7000;

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => $appointment->student_id,
            'college_id' => $this->college->id,
            'appointment_id' => $appointment->id,
            'login_method' => 'qr',
            'status' => $status,
            'checked_in_at' => now(),
            'resting_until' => $status === 'resting' ? now()->addMinutes(10) : null,
        ]);

        if ($result !== null) {
            ClearanceRecord::create([
                'clinic_visit_id' => $visit->id,
                'encoded_by' => $this->nurse->id,
                'result' => $result,
                'encoded_at' => now(),
            ]);
            $appointment->update(['status' => 'completed']);
        }

        return $visit;
    }

    /** Other students holding $count seats of $slot on $date, on the slot's first arrival times. */
    private function fill(string $date, string $slot, int $count): void
    {
        $times = app(ClinicScheduleService::class)->arrivalTimes($slot);

        foreach (range(0, $count - 1) as $seat) {
            Appointment::factory()->create([
                'scheduled_date' => $date,
                'scheduled_time' => $slot,
                'arrival_time' => $times[$seat] ?? null,
                'status' => 'scheduled',
            ]);
        }
    }

    /** 'Y-m-d' of the clinic day plus $days. */
    private function day(int $days): string
    {
        return Carbon::parse(self::CLINIC_DAY)->addDays($days)->toDateString();
    }

    private function atCutoffOf(string $date): void
    {
        $this->travelTo(Carbon::parse($date.' '.config('healthpass.absent_cutoff')));
    }

    private function runJob(): void
    {
        $this->artisan('healthpass:second-chances')->assertExitCode(0);
    }

    /** The 2nd chance booked for $original, or null. */
    private function secondChanceOf(Appointment $original): ?Appointment
    {
        return Appointment::where('rescheduled_from_id', $original->id)->first();
    }

    /** The appointment the student's roster row on $batch points at now. */
    private function rosterAppointment(BatchRequest $batch, int $studentId): Appointment
    {
        return BatchRequestStudent::where('batch_request_id', $batch->id)
            ->where('student_id', $studentId)
            ->firstOrFail()
            ->appointment;
    }
}
