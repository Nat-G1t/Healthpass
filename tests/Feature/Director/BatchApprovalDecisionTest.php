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
 * Director batch Approve (FR-DIRA-02/03/05/06, BR-08): one transaction that
 * flips the batch to approved, stamps the reviewer fields + scheduled_date,
 * fans out one appointment per listed student, and back-writes each new
 * appointment_id onto its batch_request_students row.
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

    /** A pending batch with $studentCount student pivot rows attached. */
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

    private function approve(BatchRequest $batch, ?string $date = null): TestResponse
    {
        return $this->actingAs($this->director)->post(
            "/director/batches/{$batch->id}/approve",
            ['scheduled_date' => $date ?? now()->addDays(7)->toDateString()],
        );
    }

    public function test_approving_a_25_student_batch_creates_exactly_25_linked_appointments(): void
    {
        $batch = $this->makeBatchWithStudents(25);
        $date = now()->addDays(7)->toDateString();

        $this->approve($batch, $date)->assertRedirect('/director/batches');

        // Batch flipped + reviewer stamps + Director-chosen date (FR-DIRA-02).
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
        $this->approve($batch, now()->addDays(9)->toDateString())
            ->assertRedirect('/director/batches');

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

    public function test_past_or_missing_dates_are_rejected(): void
    {
        $batch = $this->makeBatchWithStudents(2);

        $this->actingAs($this->director)
            ->from('/director/batches')
            ->post("/director/batches/{$batch->id}/approve", ['scheduled_date' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors('scheduled_date');

        $this->actingAs($this->director)
            ->from('/director/batches')
            ->post("/director/batches/{$batch->id}/approve", [])
            ->assertSessionHasErrors('scheduled_date');

        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_non_directors_cannot_approve(): void
    {
        $batch = $this->makeBatchWithStudents(2);

        foreach (['student', 'nurse', 'college_admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post("/director/batches/{$batch->id}/approve", ['scheduled_date' => now()->toDateString()])
                ->assertRedirect();
        }

        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
    }
}
