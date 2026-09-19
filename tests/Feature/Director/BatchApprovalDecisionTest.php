<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\User;
use App\Services\ClinicScheduleService;
use App\Services\ReferenceNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Director batch Approve (FR-DIRA-02/03/05/06, BR-08, D-36): one transaction
 * that flips the batch to approved, stamps the reviewer fields +
 * scheduled_date, fans out one appointment per listed student, and
 * back-writes each new appointment_id onto its batch_request_students row.
 *
 * D-36 made approval confirm-only: the date is always the College Admin's
 * `requested_date`, read off the locked row — never the request body.
 */
class BatchApprovalDecisionTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private User $director;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);

        $this->director = User::factory()->create(['role' => 'director']);
        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->ccs->id,
        ]);
    }

    /**
     * A pending batch with $studentCount student pivot rows attached.
     * `requested_date` is set by default — since D-36 it is what approval
     * confirms, so a batch without one is the exception, not the norm — and
     * since D-37 the same is true of the hour span (`requested_time` +
     * `requested_blocks`, the latter derived from the roster size).
     */
    private function makeBatchWithStudents(int $studentCount, array $overrides = []): BatchRequest
    {
        static $seq = 700;

        $batch = BatchRequest::create(array_merge([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => now()->addDays(7)->toDateString(),
            'requested_time' => '07:00:00',
            'requested_blocks' => app(ClinicScheduleService::class)->blocksFor($studentCount),
        ], $overrides));

        User::factory()->count($studentCount)->create()->each(
            fn (User $student) => BatchRequestStudent::create([
                'batch_request_id' => $batch->id,
                'student_id' => $student->id,
            ])
        );

        return $batch;
    }

    /** D-36: the approve endpoint takes no input; $body only exists to prove it. */
    private function approve(BatchRequest $batch, array $body = []): TestResponse
    {
        return $this->actingAs($this->director)->post("/director/batches/{$batch->id}/approve", $body);
    }

    public function test_approving_a_25_student_batch_creates_exactly_25_linked_appointments(): void
    {
        $date = now()->addDays(7)->toDateString();
        $batch = $this->makeBatchWithStudents(25, ['requested_date' => $date]);

        $this->approve($batch)->assertRedirect('/director/batches');

        // Batch flipped + reviewer stamps + the requested date (FR-DIRA-02, D-36).
        $batch->refresh();
        $this->assertSame('approved', $batch->status);
        $this->assertSame($this->director->id, $batch->reviewed_by);
        $this->assertNotNull($batch->reviewed_at);
        $this->assertSame($date, $batch->scheduled_date->toDateString());

        // Exactly one appointment per listed student (BR-08).
        $this->assertDatabaseCount('appointments', 25);

        $pivots = $batch->batchRequestStudents()->with('appointment')->get();
        $this->assertCount(25, $pivots);

        foreach ($pivots as $pivot) {
            $this->assertNotNull($pivot->appointment_id, 'pivot row missing back-written appointment_id');
            $appointment = $pivot->appointment;
            $this->assertSame($pivot->student_id, $appointment->student_id);
            $this->assertSame('medical', $appointment->service_type);
            $this->assertSame($date, $appointment->scheduled_date->toDateString());
            $this->assertSame('scheduled', $appointment->status);
            $this->assertSame('batch', $appointment->source);
            $this->assertSame($batch->id, $appointment->batch_request_id);
            $this->assertSame($this->director->id, $appointment->created_by);
            $this->assertMatchesRegularExpression('/^APT-\d{4}-\d{4}$/', $appointment->reference_no);
        }

        // 25 distinct appointments and 25 distinct APT references.
        $this->assertCount(25, $pivots->pluck('appointment_id')->unique());
        $this->assertCount(25, $pivots->pluck('appointment.reference_no')->unique());
    }

    public function test_a_mid_loop_failure_rolls_back_the_entire_approval(): void
    {
        $batch = $this->makeBatchWithStudents(5);

        // Real references for the first two students, then blow up on the
        // third — the transaction must undo EVERYTHING already written.
        $this->mock(ReferenceNumberService::class, function (MockInterface $mock) {
            $calls = 0;
            $mock->shouldReceive('generateAppointmentRef')->andReturnUsing(function () use (&$calls) {
                if (++$calls === 3) {
                    throw new RuntimeException('simulated mid-loop failure');
                }

                return sprintf('APT-%d-9%03d', now()->year, $calls);
            });
        });

        $this->approve($batch)->assertServerError();

        // Full rollback: no partial appointments, no back-written pivot ids,
        // batch untouched and still decidable (AC for FR-DIRA-02).
        $this->assertDatabaseCount('appointments', 0);
        $this->assertSame(0, $batch->batchRequestStudents()->whereNotNull('appointment_id')->count());

        $batch->refresh();
        $this->assertSame('pending', $batch->status);
        $this->assertNull($batch->reviewed_by);
        $this->assertNull($batch->reviewed_at);
        $this->assertNull($batch->scheduled_date);
    }

    public function test_a_duplicate_approve_post_does_not_double_generate(): void
    {
        $batch = $this->makeBatchWithStudents(3);

        $this->approve($batch)->assertRedirect('/director/batches');
        $firstReviewedAt = $batch->fresh()->reviewed_at;

        // Double-click / replayed POST: the in-transaction status re-check
        // must make the second request a no-op (FR-DIRA-05).
        $this->approve($batch)->assertRedirect('/director/batches');

        $this->assertDatabaseCount('appointments', 3);

        $batch->refresh();
        $this->assertSame('approved', $batch->status);
        $this->assertEquals($firstReviewedAt, $batch->reviewed_at);
    }

    public function test_an_already_decided_batch_cannot_be_re_decided(): void
    {
        foreach (['approved', 'rejected'] as $decidedStatus) {
            $batch = $this->makeBatchWithStudents(2, ['status' => $decidedStatus]);

            $this->approve($batch)->assertRedirect('/director/batches');

            $this->assertSame($decidedStatus, $batch->fresh()->status);
            $this->assertSame(0, $batch->batchRequestStudents()->whereNotNull('appointment_id')->count());
        }

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_generated_appointments_appear_on_the_students_dashboard(): void
    {
        $batch = $this->makeBatchWithStudents(2);

        $this->approve($batch)->assertRedirect('/director/batches');

        // FR-DIRA-03: each student immediately sees their new appointment —
        // the dashboard's Next Appointment card shows its APT reference.
        foreach ($batch->batchRequestStudents()->with(['student', 'appointment'])->get() as $pivot) {
            $this->actingAs($pivot->student)
                ->get('/student/dashboard')
                ->assertOk()
                ->assertSee($pivot->appointment->reference_no);
        }
    }

    /**
     * D-36: approval is confirm-only. A client that posts its own
     * `scheduled_date` — the field the pre-D-36 modal used to send — must not
     * be able to move the cohort off the date the college asked for.
     */
    public function test_an_injected_scheduled_date_is_ignored(): void
    {
        $requested = now()->addDays(7)->toDateString();
        $injected = now()->addDays(40)->toDateString();

        $batch = $this->makeBatchWithStudents(3, ['requested_date' => $requested]);

        $this->approve($batch, ['scheduled_date' => $injected])
            ->assertRedirect('/director/batches');

        $batch->refresh();
        $this->assertSame('approved', $batch->status);
        $this->assertSame($requested, $batch->scheduled_date->toDateString());

        foreach ($batch->batchRequestStudents()->with('appointment')->get() as $pivot) {
            $this->assertSame($requested, $pivot->appointment->scheduled_date->toDateString());
        }
    }

    /**
     * D-36: a batch submitted before D-29 has no requested_date, so there is
     * nothing to confirm — approval is refused outright and the Director is
     * pointed at reject-with-a-reason instead.
     */
    public function test_a_batch_without_a_requested_date_cannot_be_approved(): void
    {
        $batch = $this->makeBatchWithStudents(4, ['requested_date' => null]);

        $this->approve($batch)
            ->assertRedirect('/director/batches')
            ->assertSessionHas('error');

        $batch->refresh();
        $this->assertSame('pending', $batch->status);
        $this->assertNull($batch->scheduled_date);
        $this->assertNull($batch->reviewed_by);
        $this->assertNull($batch->reviewed_at);
        $this->assertDatabaseCount('appointments', 0);
    }

    /**
     * D-36: a batch can sit pending until its requested date passes. Approval
     * is confirm-only, so there is no date left to confirm and approving would
     * schedule the cohort in the past — refused, reject-and-resubmit instead.
     */
    public function test_a_batch_whose_requested_date_has_passed_cannot_be_approved(): void
    {
        $batch = $this->makeBatchWithStudents(3, [
            'requested_date' => now()->subDay()->toDateString(),
        ]);

        $this->approve($batch)
            ->assertRedirect('/director/batches')
            ->assertSessionHas('error');

        $batch->refresh();
        $this->assertSame('pending', $batch->status);
        $this->assertNull($batch->scheduled_date);
        $this->assertNull($batch->reviewed_by);
        $this->assertNull($batch->reviewed_at);
        $this->assertDatabaseCount('appointments', 0);
    }

    /**
     * The boundary: TODAY is still confirmable — only past dates are stale.
     *
     * The clock is pinned and the span put in the afternoon so this tests
     * D-36's date rule alone; a same-day span whose hours have already ended
     * is BR-23's business and is covered separately below.
     */
    public function test_a_batch_requested_for_today_can_still_be_approved(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 08:00', 'Asia/Manila'));

        $today = now()->toDateString();
        $batch = $this->makeBatchWithStudents(2, [
            'requested_date' => $today,
            'requested_time' => '14:00:00',
        ]);

        $this->approve($batch)->assertRedirect('/director/batches');

        $batch->refresh();
        $this->assertSame('approved', $batch->status);
        $this->assertSame($today, $batch->scheduled_date->toDateString());
        $this->assertDatabaseCount('appointments', 2);
    }

    /** A posted date cannot rescue a batch that has no requested date (D-36). */
    public function test_a_batch_without_a_requested_date_cannot_be_approved_by_posting_one(): void
    {
        $batch = $this->makeBatchWithStudents(2, ['requested_date' => null]);

        $this->approve($batch, ['scheduled_date' => now()->addDays(7)->toDateString()])
            ->assertRedirect('/director/batches')
            ->assertSessionHas('error');

        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->scheduled_date);
        $this->assertDatabaseCount('appointments', 0);
    }

    /** …and it cannot rescue a stale one either (D-36). */
    public function test_a_stale_batch_cannot_be_approved_by_posting_a_future_date(): void
    {
        $batch = $this->makeBatchWithStudents(2, [
            'requested_date' => now()->subWeek()->toDateString(),
        ]);

        $this->approve($batch, ['scheduled_date' => now()->addDays(7)->toDateString()])
            ->assertRedirect('/director/batches')
            ->assertSessionHas('error');

        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->scheduled_date);
        $this->assertDatabaseCount('appointments', 0);
    }

    // ── D-37: hour span, hard capacity block, and the timed fan-out ──────────

    public function test_the_fan_out_puts_twelve_students_in_each_hour_and_the_remainder_last(): void
    {
        $date = now()->addDays(7)->toDateString();

        // 25 students → 3 hours from 7 AM: 12 + 12 + 1.
        $batch = $this->makeBatchWithStudents(25, [
            'requested_date' => $date,
            'requested_time' => '07:00:00',
        ]);

        $this->approve($batch)->assertRedirect('/director/batches');

        $perSlot = Appointment::where('batch_request_id', $batch->id)
            ->get()
            ->groupBy('scheduled_time')
            ->map->count()
            ->all();

        $this->assertSame(
            ['07:00:00' => 12, '08:00:00' => 12, '09:00:00' => 1],
            $perSlot,
        );
    }

    public function test_the_fan_out_assignment_is_deterministic_by_pivot_order(): void
    {
        $batch = $this->makeBatchWithStudents(13, ['requested_time' => '10:00:00']);

        $this->approve($batch)->assertRedirect('/director/batches');

        // Pivot rows in id order: the first 12 land in 10 AM, the 13th in 11 AM.
        $times = $batch->batchRequestStudents()
            ->orderBy('id')
            ->with('appointment')
            ->get()
            ->map(fn ($pivot) => $pivot->appointment->scheduled_time)
            ->all();

        $this->assertSame(array_fill(0, 12, '10:00:00'), array_slice($times, 0, 12));
        $this->assertSame('11:00:00', $times[12]);
    }

    /**
     * FR-DIRA-06 as amended by D-37: capacity is a HARD BLOCK, not a warning.
     * With D-36's confirm-only approval this batch has exactly one outcome —
     * rejection and resubmission. That is the intended workflow.
     */
    public function test_approval_is_refused_when_an_hour_in_the_span_is_full(): void
    {
        $date = now()->addDays(7)->toDateString();

        $batch = $this->makeBatchWithStudents(25, [
            'requested_date' => $date,
            'requested_time' => '07:00:00',
        ]);

        // 8–9 AM sits inside the batch's 7–10 AM span and is already at 12.
        Appointment::factory()->count(12)->inSlot('08:00:00')->create([
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $this->approve($batch)
            ->assertRedirect('/director/batches')
            ->assertSessionHas('error', fn (string $error) => str_contains($error, '8:00 AM – 9:00 AM'));

        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->scheduled_date);
        // Only the 12 pre-existing appointments — none from the batch.
        $this->assertDatabaseCount('appointments', 12);
    }

    public function test_a_full_hour_outside_the_span_does_not_block_approval(): void
    {
        $date = now()->addDays(7)->toDateString();

        $batch = $this->makeBatchWithStudents(5, [
            'requested_date' => $date,
            'requested_time' => '07:00:00',
        ]);

        Appointment::factory()->count(12)->inSlot('15:00:00')->create([
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $this->approve($batch)->assertRedirect('/director/batches');

        $this->assertSame('approved', $batch->fresh()->status);
    }

    // ── BR-23: hours that ended while the batch sat pending ─────────────────

    /**
     * D-36 still lets a batch requested for TODAY be approved. BR-23 closes
     * the hole that opens when the review happens after those hours end —
     * approving would mint appointments for a time already gone.
     */
    public function test_approval_is_refused_when_an_hour_in_the_span_has_already_passed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        // Requested for today 7–10 AM; it is now noon, so all three have ended.
        $batch = $this->makeBatchWithStudents(25, [
            'requested_date' => today()->toDateString(),
            'requested_time' => '07:00:00',
        ]);

        $this->approve($batch)
            ->assertRedirect('/director/batches')
            ->assertSessionHas('error', fn (string $e) => str_contains($e, '7:00 AM – 8:00 AM')
                && str_contains($e, 'already passed'));

        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->scheduled_date);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_approval_is_refused_when_only_part_of_the_span_has_passed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        // 11 AM–2 PM: the first hour has ended, the other two have not. A
        // partially-elapsed span is still refused — some students would be
        // scheduled into the past.
        $batch = $this->makeBatchWithStudents(25, [
            'requested_date' => today()->toDateString(),
            'requested_time' => '11:00:00',
        ]);

        $this->approve($batch)
            ->assertRedirect('/director/batches')
            ->assertSessionHas('error', fn (string $e) => str_contains($e, '11:00 AM – 12:00 PM'));

        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_a_batch_for_today_is_still_approvable_while_its_span_is_ahead(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        // Noon onwards is still to come, so D-36's same-day approval survives.
        $batch = $this->makeBatchWithStudents(5, [
            'requested_date' => today()->toDateString(),
            'requested_time' => '13:00:00',
        ]);

        $this->approve($batch)->assertRedirect('/director/batches');

        $this->assertSame('approved', $batch->fresh()->status);
        $this->assertDatabaseCount('appointments', 5);
        $this->assertSame(
            ['13:00:00'],
            Appointment::where('batch_request_id', $batch->id)
                ->pluck('scheduled_time')->unique()->values()->all(),
        );
    }

    public function test_a_future_dated_batch_is_never_blocked_by_elapsed_hours(): void
    {
        // Late in the day, but the batch is for tomorrow — nothing has elapsed.
        Carbon::setTestNow(Carbon::parse('2026-07-27 16:45', 'Asia/Manila'));

        $batch = $this->makeBatchWithStudents(5, [
            'requested_date' => today()->addDay()->toDateString(),
            'requested_time' => '07:00:00',
        ]);

        $this->approve($batch)->assertRedirect('/director/batches');

        $this->assertSame('approved', $batch->fresh()->status);
    }

    public function test_a_batch_without_a_requested_time_cannot_be_approved(): void
    {
        // Pre-D-37 batch: a date but no hour span — nothing to confirm, so it
        // follows the same reject-and-resubmit path as a pre-D-29 batch (D-36).
        $batch = $this->makeBatchWithStudents(3, [
            'requested_time' => null,
            'requested_blocks' => null,
        ]);

        $this->approve($batch)
            ->assertRedirect('/director/batches')
            ->assertSessionHas('error');

        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
    }

    // ── D-54 / BR-25: students already scheduled during the span ────────────
    // Submission refuses a clashing batch, so these can only arise for a batch
    // submitted before D-54, or one that raced a booking. Built directly in the
    // database for exactly that reason.

    public function test_approval_is_refused_when_students_are_already_scheduled_during_its_hours(): void
    {
        $date = now()->addDays(7)->toDateString();
        $batch = $this->makeBatchWithStudents(5, ['requested_date' => $date, 'requested_time' => '07:00:00']);

        // Four of the five students are already held at 7 AM that day by
        // another pending batch — the only kind of clash since D-61.
        $clashing = $batch->batchRequestStudents()->orderBy('id')->take(4)->get();
        $other = $this->makeBatchWithStudents(0, ['requested_date' => $date, 'requested_time' => '07:00:00', 'requested_blocks' => 1]);

        foreach ($clashing as $row) {
            BatchRequestStudent::create(['batch_request_id' => $other->id, 'student_id' => $row->student_id]);
        }

        $names = User::whereIn('id', $clashing->pluck('student_id'))->orderBy('name')->pluck('name');

        $this->approve($batch)
            ->assertRedirect('/director/batches')
            ->assertSessionHas(
                'error',
                "{$batch->reference_no} cannot be approved — 4 student(s) are already scheduled during its hours: "
                ."{$names[0]}, {$names[1]}, {$names[2]} and 1 more. Reject it with a reason so the college can resubmit.",
            );

        $batch->refresh();
        $this->assertSame('pending', $batch->status);
        $this->assertNull($batch->scheduled_date);
        $this->assertNull($batch->reviewed_by);
        // Nothing fanned out.
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_a_legacy_self_booking_no_longer_blocks_approval(): void
    {
        // D-61 removed self-booking and with it D-54's self-booking clash; a
        // pre-D-61 row in an old database holds nothing against a batch.
        $date = now()->addDays(7)->toDateString();
        $batch = $this->makeBatchWithStudents(1, ['requested_date' => $date, 'requested_time' => '07:00:00']);

        Appointment::factory()->inSlot('07:00:00')->create([
            'student_id' => $batch->batchRequestStudents()->value('student_id'),
            'scheduled_date' => $date,
            'source' => 'self',
        ]);

        $this->approve($batch)->assertRedirect('/director/batches')->assertSessionMissing('error');

        $this->assertSame('approved', $batch->fresh()->status);
        $this->assertSame(1, Appointment::where('batch_request_id', $batch->id)->count());
    }

    public function test_approval_is_refused_when_a_student_is_on_an_overlapping_batch(): void
    {
        $date = now()->addDays(7)->toDateString();
        $batch = $this->makeBatchWithStudents(2, ['requested_date' => $date, 'requested_time' => '09:00:00']);
        $studentId = $batch->batchRequestStudents()->orderBy('id')->value('student_id');

        // An approved 8–10 AM batch already holds that student.
        $other = BatchRequest::create([
            'reference_no' => 'BR-'.now()->year.'-999',
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => $date,
            'scheduled_date' => $date,
            'requested_time' => '08:00:00',
            'requested_blocks' => 2,
            'status' => 'approved',
        ]);
        BatchRequestStudent::create(['batch_request_id' => $other->id, 'student_id' => $studentId]);

        $name = User::findOrFail($studentId)->name;

        $this->approve($batch)
            ->assertRedirect('/director/batches')
            ->assertSessionHas('error', fn (string $error) => str_contains(
                $error,
                "1 student(s) are already scheduled during its hours: {$name}. Reject it with a reason",
            ));

        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertSame(0, Appointment::where('batch_request_id', $batch->id)->count());
    }

    public function test_non_directors_cannot_approve(): void
    {
        $batch = $this->makeBatchWithStudents(2);

        foreach (['student', 'nurse', 'college_admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post("/director/batches/{$batch->id}/approve")
                ->assertRedirect();
        }

        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
    }
}
