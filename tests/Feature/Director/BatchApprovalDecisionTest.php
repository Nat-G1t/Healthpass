<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Jobs\SendAppointmentScheduledMail;
use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\User;
use App\Services\ReferenceNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Director batch Approve (FR-DIRA-02/03/05/06, BR-08, D-108): one transaction
 * that flips the batch to approved, stamps the reviewer fields and the
 * schedule the Director picked (scheduled_date / _time / _blocks), fans out
 * one appointment per listed student, and back-writes each new
 * appointment_id onto its batch_request_students row.
 *
 * D-108: the Director posts the clinic date and the start hour. Every rule —
 * span within the day, no ended hour, room in every hour and in the day, no
 * BR-25 clash — is re-checked under the row lock; a refusal sends the Director
 * back to the Review page.
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

        // Written around D-37's 12 an hour and 120 a day, which these tests'
        // numbers (13 → 12 + 1, a full hour of 12 …) still exercise. The D-95
        // default of 20 is asserted in the unit test ClinicScheduleServiceTest.
        config(['healthpass.hourly_capacity' => 12, 'healthpass.daily_capacity' => 120]);

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);

        $this->director = User::factory()->create(['role' => 'director']);
        $this->admin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->ccs->id,
        ]);
    }

    /** A pending batch with $studentCount student pivot rows — no date, no hour (D-108). */
    private function makeBatchWithStudents(int $studentCount, array $overrides = []): BatchRequest
    {
        static $seq = 700;

        $batch = BatchRequest::create(array_merge([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
        ], $overrides));

        User::factory()->count($studentCount)->create()->each(
            fn (User $student) => BatchRequestStudent::create([
                'batch_request_id' => $batch->id,
                'student_id' => $student->id,
            ])
        );

        return $batch;
    }

    /** The default pick: a week ahead, from 7 AM. */
    private function inAWeek(): string
    {
        return now()->addDays(7)->toDateString();
    }

    private function approve(BatchRequest $batch, ?string $date = null, ?string $start = '07:00:00', array $extra = []): TestResponse
    {
        return $this->actingAs($this->director)->post(
            "/director/batches/{$batch->id}/approve",
            array_filter(['scheduled_date' => $date ?? $this->inAWeek(), 'start_time' => $start]) + $extra,
        );
    }

    private function reviewUrl(BatchRequest $batch): string
    {
        return "/director/batches/{$batch->id}/review";
    }

    /** Refused: still pending, nothing scheduled, nothing created. */
    private function assertNotApproved(BatchRequest $batch, int $appointmentsBefore = 0): void
    {
        $batch->refresh();
        $this->assertSame('pending', $batch->status);
        $this->assertNull($batch->scheduled_date);
        $this->assertNull($batch->scheduled_time);
        $this->assertNull($batch->scheduled_blocks);
        $this->assertNull($batch->reviewed_by);
        $this->assertDatabaseCount('appointments', $appointmentsBefore);
    }

    public function test_approving_a_25_student_batch_creates_exactly_25_linked_appointments(): void
    {
        $date = $this->inAWeek();
        $batch = $this->makeBatchWithStudents(25);

        $this->approve($batch, $date, '09:00:00')->assertRedirect('/director/batches');

        // Batch flipped + reviewer stamps + the Director's schedule (D-108).
        $batch->refresh();
        $this->assertSame('approved', $batch->status);
        $this->assertSame($this->director->id, $batch->reviewed_by);
        $this->assertNotNull($batch->reviewed_at);
        $this->assertSame($date, $batch->scheduled_date->toDateString());
        $this->assertSame('09:00:00', $batch->scheduled_time);
        $this->assertSame(3, (int) $batch->scheduled_blocks);   // 25 → 12 + 12 + 1
        // The legacy columns stay empty on a D-108 batch.
        $this->assertNull($batch->requested_date);

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

    public function test_the_success_flash_names_the_date_and_hours(): void
    {
        $batch = $this->makeBatchWithStudents(13);

        $this->approve($batch, '2030-03-05', '09:00:00')->assertSessionHas(
            'status',
            "{$batch->reference_no} approved for Tue, Mar 5, 2030, 9:00 AM – 11:00 AM — 13 appointments created.",
        );
    }

    public function test_one_mail_job_per_student_is_queued_after_the_commit(): void
    {
        Queue::fake();
        $batch = $this->makeBatchWithStudents(4);

        $this->approve($batch)->assertRedirect('/director/batches');

        Queue::assertPushed(SendAppointmentScheduledMail::class, 4);
    }

    // ── The two inputs (ApproveBatchRequest) ────────────────────────────────

    public function test_both_the_date_and_the_start_hour_are_required(): void
    {
        $batch = $this->makeBatchWithStudents(3);

        $this->actingAs($this->director)
            ->from($this->reviewUrl($batch))
            ->post("/director/batches/{$batch->id}/approve", [])
            ->assertRedirect($this->reviewUrl($batch))
            ->assertSessionHasErrors(['scheduled_date', 'start_time']);

        $this->assertNotApproved($batch);
    }

    public function test_a_past_date_is_refused(): void
    {
        $batch = $this->makeBatchWithStudents(3);

        $this->approve($batch, now()->subDay()->toDateString())
            ->assertSessionHasErrors('scheduled_date');

        $this->assertNotApproved($batch);
    }

    public function test_a_start_hour_outside_the_clinic_slots_is_refused(): void
    {
        $batch = $this->makeBatchWithStudents(3);

        foreach (['07:00', '06:00:00', '17:00:00', '07:30:00'] as $start) {
            $this->approve($batch, null, $start)->assertSessionHasErrors('start_time');
        }

        $this->assertNotApproved($batch);
    }

    /** The span length is the roster's — a posted block count is never read. */
    public function test_a_posted_block_count_is_ignored(): void
    {
        $batch = $this->makeBatchWithStudents(13);

        $this->approve($batch, null, '07:00:00', ['scheduled_blocks' => 1])->assertRedirect('/director/batches');

        $this->assertSame(2, (int) $batch->fresh()->scheduled_blocks);
    }

    // ── Atomicity and replays (FR-DIRA-02/05) ───────────────────────────────

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
        $this->assertSame(0, $batch->batchRequestStudents()->whereNotNull('appointment_id')->count());
        $this->assertNotApproved($batch);
    }

    public function test_a_duplicate_approve_post_does_not_double_generate(): void
    {
        $batch = $this->makeBatchWithStudents(3);

        $this->approve($batch)->assertRedirect('/director/batches');
        $firstReviewedAt = $batch->fresh()->reviewed_at;

        // Double-click / replayed POST — even one naming another day: the
        // in-transaction status re-check makes it a no-op (FR-DIRA-05).
        $this->approve($batch, now()->addDays(20)->toDateString(), '13:00:00')
            ->assertRedirect('/director/batches')
            ->assertSessionHas('error', "{$batch->reference_no} has already been decided — nothing was changed.");

        $this->assertDatabaseCount('appointments', 3);

        $batch->refresh();
        $this->assertSame('approved', $batch->status);
        $this->assertSame($this->inAWeek(), $batch->scheduled_date->toDateString());
        $this->assertSame('07:00:00', $batch->scheduled_time);
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

    public function test_a_batch_the_college_cancelled_cannot_be_approved(): void
    {
        $batch = $this->makeBatchWithStudents(2, ['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->approve($batch)
            ->assertRedirect('/director/batches')
            ->assertSessionHas('error', "{$batch->reference_no} was cancelled by the college — nothing was changed.");

        $this->assertSame('cancelled', $batch->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_generated_appointments_appear_on_the_students_dashboard(): void
    {
        $batch = $this->makeBatchWithStudents(2);

        $this->approve($batch)->assertRedirect('/director/batches');

        // FR-DIRA-03: each student immediately sees their new appointment —
        // the dashboard's Next Appointment card shows its Batch ID (D-101).
        foreach ($batch->batchRequestStudents()->with(['student', 'appointment'])->get() as $pivot) {
            $this->actingAs($pivot->student)
                ->get('/student/dashboard')
                ->assertOk()
                ->assertSee('Batch ID: '.$batch->reference_no)
                ->assertDontSee($pivot->appointment->reference_no);
        }
    }

    /** A legacy batch's old requested date is information only (D-108). */
    public function test_a_legacy_requested_date_does_not_decide_the_schedule(): void
    {
        $batch = $this->makeBatchWithStudents(2, [
            'requested_date' => now()->subWeek()->toDateString(),   // stale under D-36
            'requested_time' => '15:00:00',
            'requested_blocks' => 1,
        ]);

        $this->approve($batch, $this->inAWeek(), '10:00:00')->assertRedirect('/director/batches');

        $batch->refresh();
        $this->assertSame('approved', $batch->status);
        $this->assertSame($this->inAWeek(), $batch->scheduled_date->toDateString());
        $this->assertSame('10:00:00', $batch->scheduled_time);
    }

    // ── D-37: the timed fan-out ─────────────────────────────────────────────

    public function test_the_fan_out_puts_twelve_students_in_each_hour_and_the_remainder_last(): void
    {
        // 25 students → 3 hours from 7 AM: 12 + 12 + 1.
        $batch = $this->makeBatchWithStudents(25);

        $this->approve($batch, null, '07:00:00')->assertRedirect('/director/batches');

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
        $batch = $this->makeBatchWithStudents(13);

        $this->approve($batch, null, '10:00:00')->assertRedirect('/director/batches');

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

    // ── (1) the span must end by closing time ───────────────────────────────

    public function test_a_start_hour_whose_span_runs_past_closing_is_refused(): void
    {
        // 25 students need 3 hours; from 3 PM that runs to 6 PM.
        $batch = $this->makeBatchWithStudents(25);

        $this->approve($batch, null, '15:00:00')
            ->assertRedirect($this->reviewUrl($batch))
            ->assertSessionHas('error', "{$batch->reference_no} needs 3 clinic hours for 25 students — starting at 3:00 PM it would run past 5:00 PM. Pick an earlier start hour.");

        $this->assertNotApproved($batch);
    }

    public function test_the_last_hour_that_still_fits_is_accepted(): void
    {
        $batch = $this->makeBatchWithStudents(25);

        $this->approve($batch, null, '14:00:00')->assertRedirect('/director/batches');

        $this->assertSame('approved', $batch->fresh()->status);
    }

    // ── (2) BR-23: hours that have already ended ────────────────────────────

    public function test_an_hour_that_has_already_passed_today_is_refused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        $batch = $this->makeBatchWithStudents(25);

        $this->approve($batch, today()->toDateString(), '07:00:00')
            ->assertRedirect($this->reviewUrl($batch))
            ->assertSessionHas('error', fn (string $e) => str_contains($e, '7:00 AM – 8:00 AM')
                && str_contains($e, 'already passed'));

        $this->assertNotApproved($batch);
    }

    public function test_a_span_only_partly_passed_is_refused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        // 11 AM–2 PM: the first hour has ended, the other two have not.
        $batch = $this->makeBatchWithStudents(25);

        $this->approve($batch, today()->toDateString(), '11:00:00')
            ->assertSessionHas('error', fn (string $e) => str_contains($e, '11:00 AM – 12:00 PM'));

        $this->assertNotApproved($batch);
    }

    public function test_same_day_scheduling_takes_hours_still_to_come(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        $batch = $this->makeBatchWithStudents(5);

        $this->approve($batch, today()->toDateString(), '13:00:00')->assertRedirect('/director/batches');

        $this->assertSame('approved', $batch->fresh()->status);
        $this->assertSame(
            ['13:00:00'],
            Appointment::where('batch_request_id', $batch->id)->pluck('scheduled_time')->unique()->values()->all(),
        );
    }

    public function test_a_future_date_is_never_blocked_by_elapsed_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 16:45', 'Asia/Manila'));

        $batch = $this->makeBatchWithStudents(5);

        $this->approve($batch, today()->addDay()->toDateString(), '07:00:00')->assertRedirect('/director/batches');

        $this->assertSame('approved', $batch->fresh()->status);
    }

    // ── (3) room in every hour (D-91) and in the day (BR-02) ────────────────

    public function test_an_hour_in_the_span_that_is_full_is_refused(): void
    {
        $date = $this->inAWeek();
        $batch = $this->makeBatchWithStudents(25);

        // 8–9 AM sits inside the 7–10 AM span and is already at 12.
        Appointment::factory()->count(12)->inSlot('08:00:00')->create(['scheduled_date' => $date, 'status' => 'scheduled']);

        $this->approve($batch, $date, '07:00:00')
            ->assertRedirect($this->reviewUrl($batch))
            ->assertSessionHas('error', fn (string $error) => str_contains($error, '8:00 AM – 9:00 AM slot in its span')
                && str_contains($error, 'is already fully booked'));

        $this->assertNotApproved($batch, appointmentsBefore: 12);
    }

    public function test_an_hour_without_room_for_the_batchs_seats_is_refused(): void
    {
        $date = $this->inAWeek();
        $batch = $this->makeBatchWithStudents(8);

        Appointment::factory()->count(8)->inSlot('07:00:00')->create(['scheduled_date' => $date, 'status' => 'scheduled']);

        $this->approve($batch, $date, '07:00:00')
            ->assertSessionHas('error', fn (string $error) => str_contains($error, '7:00 AM – 8:00 AM slot in its span')
                && str_contains($error, 'has only 4 seats left'));

        $this->assertNotApproved($batch, appointmentsBefore: 8);
    }

    public function test_a_batch_that_exactly_fills_the_seats_left_is_approved(): void
    {
        $date = $this->inAWeek();
        $batch = $this->makeBatchWithStudents(4);

        Appointment::factory()->count(8)->inSlot('07:00:00')->create(['scheduled_date' => $date, 'status' => 'scheduled']);

        $this->approve($batch, $date, '07:00:00')->assertSessionHas('status');

        $this->assertSame(12, Appointment::whereDate('scheduled_date', $date)->where('scheduled_time', '07:00:00')->count());
    }

    public function test_an_hour_other_colleges_use_still_takes_a_batch_that_fits(): void
    {
        $date = $this->inAWeek();
        $batch = $this->makeBatchWithStudents(5);

        // 6 already there + this batch's 5 = 11 of 12.
        Appointment::factory()->count(6)->inSlot('09:00:00')->create(['scheduled_date' => $date, 'status' => 'scheduled']);

        $this->approve($batch, $date, '09:00:00')->assertRedirect('/director/batches');

        $this->assertSame(11, Appointment::whereDate('scheduled_date', $date)->where('scheduled_time', '09:00:00')->count());
    }

    public function test_a_full_hour_outside_the_span_does_not_block_approval(): void
    {
        $date = $this->inAWeek();
        $batch = $this->makeBatchWithStudents(5);

        Appointment::factory()->count(12)->inSlot('15:00:00')->create(['scheduled_date' => $date, 'status' => 'scheduled']);

        $this->approve($batch, $date, '07:00:00')->assertRedirect('/director/batches');

        $this->assertSame('approved', $batch->fresh()->status);
    }

    public function test_cancelled_appointments_free_their_seats(): void
    {
        $date = $this->inAWeek();
        $batch = $this->makeBatchWithStudents(12);

        Appointment::factory()->count(12)->inSlot('07:00:00')->create(['scheduled_date' => $date, 'status' => 'cancelled']);

        $this->approve($batch, $date, '07:00:00')->assertRedirect('/director/batches');

        $this->assertSame('approved', $batch->fresh()->status);
    }

    public function test_a_day_already_at_daily_capacity_is_refused(): void
    {
        $date = $this->inAWeek();
        $batch = $this->makeBatchWithStudents(2);

        // 119 legacy rows with no hour — invisible to every hourly counter,
        // seen only by the daily cap (BR-02).
        Appointment::factory()->count(119)->create(['scheduled_date' => $date, 'scheduled_time' => null, 'status' => 'scheduled']);

        $this->approve($batch, $date, '07:00:00')
            ->assertRedirect($this->reviewUrl($batch))
            ->assertSessionHas('error', fn (string $error) => str_contains($error, 'the day has only 1 places left for its 2 students'));

        $this->assertNotApproved($batch, appointmentsBefore: 119);
    }

    // ── (4) BR-25: students already scheduled that day ──────────────────────

    /** An approved batch on $date holding the given students. */
    private function approvedBatchOn(string $date, array $studentIds, string $start = '07:00:00', string $form = 'clearance'): BatchRequest
    {
        static $seq = 900;

        $other = BatchRequest::create([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'form_type' => $form,
            'reason' => $form === 'clearance' ? 'fieldtrip' : 'ojt',
            'service_type' => 'medical',
            'scheduled_date' => $date,
            'scheduled_time' => $start,
            'scheduled_blocks' => 1,
            'status' => 'approved',
        ]);

        foreach ($studentIds as $studentId) {
            BatchRequestStudent::create(['batch_request_id' => $other->id, 'student_id' => $studentId]);
        }

        return $other;
    }

    public function test_approval_is_refused_when_students_are_already_scheduled_that_day(): void
    {
        $date = '2030-03-05';
        $batch = $this->makeBatchWithStudents(5);

        // Four of the five are already on an approved batch that day — at a
        // different hour, which doesn't matter since D-77.
        $clashing = $batch->batchRequestStudents()->orderBy('id')->take(4)->pluck('student_id');
        $this->approvedBatchOn($date, $clashing->all(), '14:00:00', 'assessment');

        $names = User::whereIn('id', $clashing)->orderBy('name')->pluck('name');

        $this->approve($batch, $date, '07:00:00')
            ->assertRedirect($this->reviewUrl($batch))
            ->assertSessionHas(
                'error',
                "{$batch->reference_no} cannot be scheduled on Tue, Mar 5, 2030 — 4 student(s) already have a clinic schedule that day: "
                ."{$names[0]}, {$names[1]}, {$names[2]} and 1 more. Pick another day.",
            );

        $this->assertNotApproved($batch);
    }

    public function test_the_same_students_can_be_scheduled_on_another_day(): void
    {
        $batch = $this->makeBatchWithStudents(2);
        $this->approvedBatchOn('2030-03-05', $batch->batchRequestStudents()->pluck('student_id')->all());

        $this->approve($batch, '2030-03-06', '07:00:00')->assertRedirect('/director/batches')->assertSessionMissing('error');

        $this->assertSame('approved', $batch->fresh()->status);
    }

    /** D-108: a pending batch has no day, so it holds nobody. */
    public function test_a_pending_batch_sharing_a_student_no_longer_blocks_approval(): void
    {
        $first = $this->makeBatchWithStudents(1);
        $second = $this->makeBatchWithStudents(0);
        BatchRequestStudent::create(['batch_request_id' => $second->id, 'student_id' => $first->batchRequestStudents()->value('student_id')]);

        $this->approve($first)->assertRedirect('/director/batches')->assertSessionMissing('error');
        $this->assertSame('approved', $first->fresh()->status);

        // …but once the first is approved, the second can't take that day.
        $this->approve($second)->assertRedirect($this->reviewUrl($second))->assertSessionHas('error');
        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_a_withdrawn_student_holds_nothing(): void
    {
        $date = $this->inAWeek();
        $batch = $this->makeBatchWithStudents(1);
        $studentId = $batch->batchRequestStudents()->value('student_id');

        $other = $this->approvedBatchOn($date, [$studentId]);
        $appointment = Appointment::factory()->inSlot('07:00:00')->create([
            'student_id' => $studentId, 'scheduled_date' => $date, 'status' => 'cancelled', 'batch_request_id' => $other->id,
        ]);
        $other->batchRequestStudents()->update(['appointment_id' => $appointment->id]);

        $this->approve($batch, $date, '09:00:00')->assertRedirect('/director/batches')->assertSessionMissing('error');
    }

    public function test_a_legacy_self_booking_no_longer_blocks_approval(): void
    {
        // D-61 removed self-booking and with it D-54's self-booking clash.
        $date = $this->inAWeek();
        $batch = $this->makeBatchWithStudents(1);

        Appointment::factory()->inSlot('07:00:00')->create([
            'student_id' => $batch->batchRequestStudents()->value('student_id'),
            'scheduled_date' => $date,
            'source' => 'self',
        ]);

        $this->approve($batch, $date, '08:00:00')->assertRedirect('/director/batches')->assertSessionMissing('error');

        $this->assertSame(1, Appointment::where('batch_request_id', $batch->id)->count());
    }

    public function test_non_directors_cannot_approve(): void
    {
        $batch = $this->makeBatchWithStudents(2);

        foreach (['student', 'nurse', 'physician', 'college_admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post("/director/batches/{$batch->id}/approve", [
                    'scheduled_date' => $this->inAWeek(),
                    'start_time' => '07:00:00',
                ])
                ->assertRedirect();
        }

        $this->assertNotApproved($batch);
    }
}
