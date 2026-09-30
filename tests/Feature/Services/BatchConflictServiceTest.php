<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\User;
use App\Services\BatchConflictService;
use App\Services\ClinicScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * D-90 — two pending batches conflict when each fits on its own but, together,
 * they would put more than 12 students into some hour. Small batches that can
 * share an hour do not conflict; first come is the one to approve.
 */
class BatchConflictServiceTest extends TestCase
{
    use RefreshDatabase;

    private College $cit;

    private College $ccs;

    private User $admin;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        // Written around D-37's 12 an hour and 120 a day, which these tests'
        // numbers (13 → 12 + 1, a full hour of 12, a 121-student batch …)
        // still exercise. The D-95 default of 20 is asserted in
        // the unit test ClinicScheduleServiceTest.
        config(['healthpass.hourly_capacity' => 12, 'healthpass.daily_capacity' => 120]);

        $this->cit = College::create(['code' => 'CIT', 'name' => 'College of Industrial Technology']);
        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
        $this->date = now()->addDays(7)->toDateString();
    }

    private function service(): BatchConflictService
    {
        return app(BatchConflictService::class);
    }

    /** A pending batch of $students students starting at $start on $this->date. */
    private function pendingBatch(College $college, int $students, string $start, array $overrides = []): BatchRequest
    {
        static $seq = 100;

        $batch = BatchRequest::create(array_merge([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $college->id,
            'requested_by' => $this->admin->id,
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'service_type' => 'medical',
            'requested_date' => $this->date,
            'requested_time' => $start,
            'requested_blocks' => app(ClinicScheduleService::class)->blocksFor($students),
            // Explicit: the column default only reaches the model on a re-read.
            'status' => 'pending',
        ], $overrides));

        User::factory()->count($students)->create()->each(
            fn (User $student) => BatchRequestStudent::create([
                'batch_request_id' => $batch->id,
                'student_id' => $student->id,
            ])
        );

        return $batch;
    }

    public function test_two_batches_that_cannot_both_fit_conflict_with_each_other(): void
    {
        // 10 + 8 students in the 7 AM hour is 18, over the 12 cap.
        $cit = $this->pendingBatch($this->cit, 10, '07:00:00');
        $ccs = $this->pendingBatch($this->ccs, 8, '07:00:00');

        $this->assertSame([$cit->id], $this->service()->conflictsWith($ccs)->pluck('id')->all());
        $this->assertSame([$ccs->id], $this->service()->conflictsWith($cit)->pluck('id')->all());
    }

    public function test_two_small_batches_that_can_share_an_hour_do_not_conflict(): void
    {
        $cit = $this->pendingBatch($this->cit, 5, '07:00:00');
        $this->pendingBatch($this->ccs, 5, '07:00:00');

        $this->assertTrue($this->service()->conflictsWith($cit)->isEmpty());
    }

    public function test_seats_already_booked_in_the_hour_count(): void
    {
        // 5 + 5 would share 7 AM — but 6 seats are already taken: 16 > 12.
        Appointment::factory()->count(6)->inSlot('07:00:00')->create([
            'scheduled_date' => $this->date,
            'status' => 'scheduled',
        ]);

        $cit = $this->pendingBatch($this->cit, 5, '07:00:00');
        $ccs = $this->pendingBatch($this->ccs, 5, '07:00:00');

        $this->assertSame([$cit->id], $this->service()->conflictsWith($ccs)->pluck('id')->all());
    }

    public function test_other_hours_and_other_days_do_not_conflict(): void
    {
        $cit = $this->pendingBatch($this->cit, 12, '07:00:00');
        $this->pendingBatch($this->ccs, 12, '08:00:00');
        $this->pendingBatch($this->ccs, 12, '07:00:00', ['requested_date' => now()->addDays(8)->toDateString()]);

        $this->assertTrue($this->service()->conflictsWith($cit)->isEmpty());
    }

    public function test_batches_that_are_not_pending_or_cannot_be_approved_are_ignored(): void
    {
        $ccs = $this->pendingBatch($this->ccs, 8, '07:00:00');

        $this->pendingBatch($this->cit, 10, '07:00:00', ['status' => 'rejected']);
        $this->pendingBatch($this->cit, 10, '07:00:00', ['status' => 'cancelled']);
        // Pre-D-37: no hour span, so nothing the Director could approve.
        $this->pendingBatch($this->cit, 10, '07:00:00', ['requested_time' => null, 'requested_blocks' => null]);

        $this->assertTrue($this->service()->conflictsWith($ccs)->isEmpty());
    }

    public function test_a_batch_that_does_not_fit_on_its_own_reports_no_conflicts(): void
    {
        // 7 AM is already full: the capacity block speaks for this batch.
        Appointment::factory()->count(12)->inSlot('07:00:00')->create([
            'scheduled_date' => $this->date,
            'status' => 'scheduled',
        ]);

        $ccs = $this->pendingBatch($this->ccs, 8, '07:00:00');
        $this->pendingBatch($this->cit, 10, '07:00:00');

        $this->assertTrue($this->service()->conflictsWith($ccs)->isEmpty());
    }

    public function test_free_starts_skip_the_hours_the_earlier_batch_takes(): void
    {
        // CIT takes 7 AM and 8 AM whole (24 students); CCS needs one hour.
        $cit = $this->pendingBatch($this->cit, 24, '07:00:00');
        $ccs = $this->pendingBatch($this->ccs, 12, '07:00:00');

        $this->assertSame(
            ['09:00:00', '10:00:00', '11:00:00', '12:00:00', '13:00:00', '14:00:00', '15:00:00', '16:00:00'],
            $this->service()->freeStartsAfter($ccs, collect([$cit])),
        );
    }

    public function test_free_starts_leave_out_hours_that_have_passed_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));
        $this->date = today()->toDateString();

        $cit = $this->pendingBatch($this->cit, 12, '13:00:00');
        $ccs = $this->pendingBatch($this->ccs, 12, '13:00:00');

        // 7–12 are over at noon; 12–1 is still open (BR-23); 1 PM is CIT's.
        $this->assertSame(
            ['12:00:00', '14:00:00', '15:00:00', '16:00:00'],
            $this->service()->freeStartsAfter($ccs, collect([$cit])),
        );
    }

    public function test_submitted_before_breaks_a_same_second_tie_by_id(): void
    {
        $first = $this->pendingBatch($this->cit, 1, '07:00:00');
        $second = $this->pendingBatch($this->ccs, 1, '07:00:00');
        $second->created_at = $first->created_at;

        $this->assertTrue($first->submittedBefore($second));
        $this->assertFalse($second->submittedBefore($first));
    }
}
