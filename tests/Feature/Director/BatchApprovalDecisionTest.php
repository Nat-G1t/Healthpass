<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\User;
use App\Services\ReferenceNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * confirms, so a batch without one is the exception, not the norm.
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

    /** The boundary: TODAY is still confirmable — only past dates are stale. */
    public function test_a_batch_requested_for_today_can_still_be_approved(): void
    {
        $today = now()->toDateString();
        $batch = $this->makeBatchWithStudents(2, ['requested_date' => $today]);

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
