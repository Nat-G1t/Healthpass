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
 * D-54 / D-77 / BR-25 — does a student already have a clinic schedule that day?
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
     * A batch holding $students for $blocks hours from $start on DATE
     * (a Medical Assessment Form batch unless $overrides says otherwise).
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
            'form_type' => 'assessment',
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

    /** What the service says about a clash with $batch, hours as given. */
    private function message(BatchRequest $batch, string $form, ?string $hours): string
    {
        return "Already scheduled for a {$form} that day, on {$batch->reference_no}".($hours === null ? '' : " ({$hours})");
    }

    // ── What a batch holds (decision 2) ──────────────────────────────────────

    public function test_a_pending_batch_holds_its_date(): void
    {
        $student = $this->student();
        $held = $this->batch('pending', [$student], '09:00:00', 2);

        $this->assertSame(
            [$student->id => [$this->message($held, 'Medical Assessment Form', '9:00 AM – 11:00 AM')]],
            $this->service()->clashesForBatch([$student->id], self::DATE),
        );
    }

    public function test_an_approved_batch_holds_its_date_through_its_roster(): void
    {
        // Its generated appointment is not counted separately — the roster row is the clash.
        $student = $this->student();
        $held = $this->approvedBatch([$student], '09:00:00', 1);

        $this->assertSame(
            [$student->id => [$this->message($held, 'Medical Assessment Form', '9:00 AM – 10:00 AM')]],
            $this->service()->clashesForBatch([$student->id], self::DATE),
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

        $clashes = $this->service()->clashesForBatch([$withdrawn->id, $stays->id], self::DATE);

        $this->assertSame([$stays->id], array_keys($clashes));
    }

    public function test_rejected_and_cancelled_batches_never_hold(): void
    {
        $student = $this->student();
        $this->batch('rejected', [$student], '09:00:00', 2);
        $this->batch('cancelled', [$student], '13:00:00', 2);

        $this->assertSame([], $this->service()->clashesForBatch([$student->id], self::DATE));
    }

    public function test_a_batch_only_holds_its_own_students_on_its_own_date(): void
    {
        $onBatch = $this->student();
        $notOnBatch = $this->student();
        $this->batch('pending', [$onBatch]);

        $this->assertSame([], $this->service()->clashesForBatch([$notOnBatch->id], self::DATE));
        $this->assertSame([], $this->service()->clashesForBatch([$onBatch->id], '2026-09-11'));
    }

    public function test_a_batch_without_an_hour_span_holds_its_date_and_names_no_hours(): void
    {
        // Pre-D-37 batch: no requested_time. A day rule needs no hour (D-77).
        $student = $this->student();
        $held = $this->batch('pending', [$student], '09:00:00', 2, ['requested_time' => null, 'requested_blocks' => null]);

        $this->assertSame(
            [$student->id => [$this->message($held, 'Medical Assessment Form', null)]],
            $this->service()->clashesForBatch([$student->id], self::DATE),
        );
    }

    // ── D-61: a self-booking is no longer a clash ────────────────────────────

    public function test_a_legacy_self_booking_that_day_no_longer_clashes(): void
    {
        // D-54's kind (a) is gone with self-booking. A pre-D-61 row may still
        // sit in an old database; it holds nothing against a batch.
        $student = $this->student();
        $this->selfBooking($student, '10:00:00');

        $this->assertSame([], $this->service()->clashesForBatch([$student->id], self::DATE));
    }

    // ── D-77: the same date is a clash, whatever the hours or form ───────────

    public function test_non_overlapping_hours_on_the_same_date_clash(): void
    {
        // The case Nat hit: 7–8 AM and 2–3 PM share no hour, but it is one day.
        $student = $this->student();
        $morning = $this->batch('pending', [$student], '07:00:00', 1);

        $this->assertSame(
            [$student->id => [$this->message($morning, 'Medical Assessment Form', '7:00 AM – 8:00 AM')]],
            $this->service()->clashesForBatch([$student->id], self::DATE),
        );
    }

    public function test_the_other_form_on_the_same_date_clashes_and_is_named(): void
    {
        $student = $this->student();
        $clearance = $this->batch('pending', [$student], '09:00:00', 1, ['form_type' => 'clearance']);

        $this->assertSame(
            [$student->id => [$this->message($clearance, 'Medical Clearance', '9:00 AM – 10:00 AM')]],
            $this->service()->clashesForBatch([$student->id], self::DATE),
        );
    }

    public function test_the_batch_being_approved_is_not_counted_against_itself(): void
    {
        $student = $this->student();
        $batch = $this->batch('pending', [$student], '09:00:00', 2);

        $this->assertSame(
            [],
            $this->service()->clashesForBatch([$student->id], self::DATE, exceptBatchId: $batch->id),
        );
    }

    public function test_a_student_with_two_clashes_gets_both_reasons_and_others_get_none(): void
    {
        $twice = $this->student();
        $clear = $this->student();
        $first = $this->batch('pending', [$twice], '07:00:00', 1);
        $second = $this->batch('pending', [$twice], '14:00:00', 1, ['form_type' => 'clearance']);

        $clashes = $this->service()->clashesForBatch([$twice->id, $clear->id], self::DATE);

        $this->assertSame([$twice->id], array_keys($clashes));
        $this->assertSame(
            [
                $this->message($first, 'Medical Assessment Form', '7:00 AM – 8:00 AM'),
                $this->message($second, 'Medical Clearance', '2:00 PM – 3:00 PM'),
            ],
            $clashes[$twice->id],
        );
    }
}
