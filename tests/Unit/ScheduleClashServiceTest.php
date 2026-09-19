<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\User;
use App\Services\ScheduleClashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-54 / BR-25 — when is a student already scheduled at an hour?
 *
 * The one definition read by the batch submission and the Director's
 * approval. Since D-61 the only clash is batch vs batch — self-booking, and
 * with it D-54's kind (a), is gone. Nothing here depends on the clock: a clash
 * is a fact about two bookings, not about "now".
 */
class ScheduleClashServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Nat's reported case: Thursday, September 10. */
    private const DATE = '2026-09-10';

    private College $college;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->college = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->college->id,
        ]);
    }

    private function service(): ScheduleClashService
    {
        return app(ScheduleClashService::class);
    }

    private function student(): User
    {
        return User::factory()->create(['role' => 'student']);
    }

    /**
     * A batch holding $students for $blocks hours from $start on DATE.
     *
     * @param  list<User>  $students
     */
    private function batch(string $status, array $students, string $start = '09:00:00', int $blocks = 2, array $overrides = []): BatchRequest
    {
        static $seq = 100;

        $batch = BatchRequest::create(array_merge([
            'reference_no' => 'BR-2026-'.$seq++,
            'college_id' => $this->college->id,
            'requested_by' => $this->admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => self::DATE,
            'requested_time' => $start,
            'requested_blocks' => $blocks,
            'status' => $status,
        ], $overrides));

        foreach ($students as $student) {
            BatchRequestStudent::create(['batch_request_id' => $batch->id, 'student_id' => $student->id]);
        }

        return $batch;
    }

    /**
     * An APPROVED batch, with every student given an appointment in the FIRST
     * hour of the span — the way the Director's fan-out assigns a small cohort.
     *
     * @param  list<User>  $students
     */
    private function approvedBatch(array $students, string $start = '09:00:00', int $blocks = 2): BatchRequest
    {
        $batch = $this->batch('approved', $students, $start, $blocks, ['scheduled_date' => self::DATE]);

        foreach ($batch->batchRequestStudents as $row) {
            $appointment = Appointment::factory()->medical()->inSlot($start)->create([
                'student_id' => $row->student_id,
                'scheduled_date' => self::DATE,
                'source' => 'batch',
                'batch_request_id' => $batch->id,
            ]);

            $row->update(['appointment_id' => $appointment->id]);
        }

        return $batch;
    }

    /** A pre-D-61 self-booking, as an old database may still hold one. */
    private function selfBooking(User $student, ?string $slot, array $overrides = []): Appointment
    {
        return Appointment::factory()->medical()->create(array_merge([
            'student_id' => $student->id,
            'scheduled_date' => self::DATE,
            'scheduled_time' => $slot,
            'source' => 'self',
        ], $overrides));
    }

    // ── What a batch holds (decision 2) ──────────────────────────────────────

    public function test_a_pending_batch_holds_its_whole_span(): void
    {
        $student = $this->student();
        $held = $this->batch('pending', [$student], '09:00:00', 2);

        // Both hours of the pending batch clash, whichever one a new batch starts in.
        foreach (['09:00:00', '10:00:00'] as $hour) {
            $this->assertSame(
                [$student->id => ["on batch {$held->reference_no}, 9:00 AM – 11:00 AM"]],
                $this->service()->clashesForBatch([$student->id], self::DATE, [$hour]),
            );
        }
    }

    public function test_an_approved_batch_holds_the_whole_span_not_only_the_assigned_hour(): void
    {
        // The student was given 9 AM, but the batch holds 9–11 for everyone on it.
        $student = $this->student();
        $held = $this->approvedBatch([$student], '09:00:00', 2);

        $this->assertSame(
            [$student->id => ["on batch {$held->reference_no}, 9:00 AM – 11:00 AM"]],
            $this->service()->clashesForBatch([$student->id], self::DATE, ['10:00:00']),
        );
    }

    public function test_a_student_withdrawn_from_an_approved_batch_is_not_held(): void
    {
        $withdrawn = $this->student();
        $stays = $this->student();
        $batch = $this->approvedBatch([$withdrawn, $stays]);

        // FR-ADM-07: withdrawal flips the appointment, the pivot row stays.
        Appointment::where('batch_request_id', $batch->id)
            ->where('student_id', $withdrawn->id)
            ->update(['status' => 'cancelled']);

        $clashes = $this->service()->clashesForBatch([$withdrawn->id, $stays->id], self::DATE, ['09:00:00']);

        $this->assertSame([$stays->id], array_keys($clashes));
    }

    public function test_rejected_and_cancelled_batches_never_hold(): void
    {
        $student = $this->student();
        $this->batch('rejected', [$student], '09:00:00', 2);
        $this->batch('cancelled', [$student], '13:00:00', 2);

        $this->assertSame(
            [],
            $this->service()->clashesForBatch([$student->id], self::DATE, ['09:00:00', '10:00:00', '13:00:00', '14:00:00']),
        );
    }

    public function test_a_batch_only_holds_its_own_students_on_its_own_date(): void
    {
        $onBatch = $this->student();
        $notOnBatch = $this->student();
        $this->batch('pending', [$onBatch]);

        $this->assertSame([], $this->service()->clashesForBatch([$notOnBatch->id], self::DATE, ['09:00:00']));
        $this->assertSame([], $this->service()->clashesForBatch([$onBatch->id], '2026-09-11', ['09:00:00']));
    }

    public function test_a_batch_without_an_hour_span_never_holds(): void
    {
        // Pre-D-37 batch: no requested_time, so it holds no hour at all.
        $student = $this->student();
        $this->batch('pending', [$student], '09:00:00', 2, ['requested_time' => null, 'requested_blocks' => null]);

        $this->assertSame([], $this->service()->clashesForBatch([$student->id], self::DATE, ['09:00:00', '10:00:00']));
    }

    // ── D-61: a self-booking is no longer a clash ────────────────────────────

    public function test_a_legacy_self_booking_inside_the_span_no_longer_clashes(): void
    {
        // D-54's kind (a) is gone with self-booking. A pre-D-61 row may still
        // sit in an old database; it holds nothing against a batch.
        $student = $this->student();
        $this->selfBooking($student, '10:00:00');

        $this->assertSame([], $this->service()->clashesForBatch([$student->id], self::DATE, ['09:00:00', '10:00:00']));
    }

    // ── clashesForBatch(): another batch the student is on ───────────────────

    public function test_overlapping_batches_clash_and_name_the_other_batch(): void
    {
        $student = $this->student();
        $other = $this->batch('pending', [$student], '09:00:00', 2);

        $this->assertSame(
            [$student->id => ["on batch {$other->reference_no}, 9:00 AM – 11:00 AM"]],
            $this->service()->clashesForBatch([$student->id], self::DATE, ['10:00:00', '11:00:00']),
        );
    }

    public function test_adjacent_spans_do_not_clash(): void
    {
        // 9–11 and 11–1 touch at 11:00 but share no hour.
        $student = $this->student();
        $this->batch('pending', [$student], '09:00:00', 2);

        $this->assertSame([], $this->service()->clashesForBatch([$student->id], self::DATE, ['11:00:00', '12:00:00']));
    }

    public function test_an_approved_batch_clashes_once_through_its_roster(): void
    {
        // Its generated appointment is not counted separately — the roster row is the clash.
        $student = $this->student();
        $other = $this->approvedBatch([$student], '09:00:00', 1);

        $this->assertSame(
            [$student->id => ["on batch {$other->reference_no}, 9:00 AM – 10:00 AM"]],
            $this->service()->clashesForBatch([$student->id], self::DATE, ['09:00:00']),
        );
    }

    public function test_a_student_withdrawn_from_an_overlapping_batch_does_not_clash(): void
    {
        $student = $this->student();
        $other = $this->approvedBatch([$student]);
        Appointment::where('batch_request_id', $other->id)->update(['status' => 'cancelled']);

        $this->assertSame([], $this->service()->clashesForBatch([$student->id], self::DATE, ['09:00:00']));
    }

    public function test_the_batch_being_approved_is_not_counted_against_itself(): void
    {
        $student = $this->student();
        $batch = $this->batch('pending', [$student], '09:00:00', 2);

        $this->assertSame(
            [],
            $this->service()->clashesForBatch([$student->id], self::DATE, $batch->requestedSpan(), exceptBatchId: $batch->id),
        );
    }

    public function test_a_student_with_two_clashes_gets_both_reasons_and_others_get_none(): void
    {
        $twice = $this->student();
        $clear = $this->student();
        $first = $this->batch('pending', [$twice], '09:00:00', 1);
        $second = $this->batch('pending', [$twice], '10:00:00', 1);

        $clashes = $this->service()->clashesForBatch([$twice->id, $clear->id], self::DATE, ['09:00:00', '10:00:00']);

        $this->assertSame([$twice->id], array_keys($clashes));
        $this->assertSame(
            ["on batch {$first->reference_no}, 9:00 AM – 10:00 AM", "on batch {$second->reference_no}, 10:00 AM – 11:00 AM"],
            $clashes[$twice->id],
        );
    }
}
