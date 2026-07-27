<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Director batch Reject (FR-DIRA-04/05, D-36): status → rejected + reviewer
 * stamps + a mandatory written reason, ZERO appointments created, and the
 * decision is terminal — a decided batch can never be re-decided in either
 * direction (which also means a replayed POST cannot overwrite the reason).
 */
class BatchRejectDecisionTest extends TestCase
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

    /** A valid reason: long enough to clear the min:10 rule (D-36). */
    private const REASON = 'Date unavailable — please resubmit for the following week.';

    /** A pending batch with $studentCount student pivot rows attached. */
    private function makeBatchWithStudents(int $studentCount, array $overrides = []): BatchRequest
    {
        static $seq = 800;

        $batch = BatchRequest::create(array_merge([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => now()->addDays(7)->toDateString(),
            'requested_time' => '07:00:00',  // D-37
            'requested_blocks' => 1,
        ], $overrides));

        User::factory()->count($studentCount)->create()->each(
            fn (User $student) => BatchRequestStudent::create([
                'batch_request_id' => $batch->id,
                'student_id' => $student->id,
            ])
        );

        return $batch;
    }

    private function reject(BatchRequest $batch, ?string $reason = self::REASON): TestResponse
    {
        return $this->actingAs($this->director)
            ->from('/director/batches')
            ->post(
                "/director/batches/{$batch->id}/reject",
                $reason === null ? [] : ['rejection_reason' => $reason],
            );
    }

    public function test_rejecting_stamps_reviewer_fields_and_the_reason_and_creates_zero_appointments(): void
    {
        $batch = $this->makeBatchWithStudents(10);

        $this->reject($batch)->assertRedirect('/director/batches');

        // Status + reviewer stamps + reason (FR-DIRA-04, D-36); no date is ever set.
        $batch->refresh();
        $this->assertSame('rejected', $batch->status);
        $this->assertSame(self::REASON, $batch->rejection_reason);
        $this->assertSame($this->director->id, $batch->reviewed_by);
        $this->assertNotNull($batch->reviewed_at);
        $this->assertNull($batch->scheduled_date);

        // ZERO appointments, no back-written pivot ids.
        $this->assertDatabaseCount('appointments', 0);
        $this->assertSame(0, $batch->batchRequestStudents()->whereNotNull('appointment_id')->count());
    }

    /** D-36: a reason is mandatory, and 'no' is not a reason. */
    public function test_a_missing_or_too_short_reason_is_refused(): void
    {
        foreach ([null, '', '   ', 'no', 'too short'] as $badReason) {
            $batch = $this->makeBatchWithStudents(2);

            $this->reject($batch, $badReason)
                ->assertRedirect('/director/batches')
                ->assertSessionHasErrors('rejection_reason');

            $batch->refresh();
            $this->assertSame('pending', $batch->status, "reason [{$badReason}] should not have decided the batch");
            $this->assertNull($batch->rejection_reason);
            $this->assertNull($batch->reviewed_by);
        }
    }

    /** max:500 bounds the TEXT column that gets rendered back to the admin. */
    public function test_an_over_long_reason_is_refused(): void
    {
        $batch = $this->makeBatchWithStudents(2);

        $this->reject($batch, str_repeat('a', 501))
            ->assertRedirect('/director/batches')
            ->assertSessionHasErrors('rejection_reason');

        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->rejection_reason);
    }

    public function test_a_duplicate_reject_post_is_a_no_op_and_keeps_the_first_reason(): void
    {
        $batch = $this->makeBatchWithStudents(3);

        $this->reject($batch)->assertRedirect('/director/batches');
        $firstReviewedAt = $batch->fresh()->reviewed_at;

        // Double-click / replayed POST: the in-transaction status re-check
        // must leave the first decision — reason included — untouched
        // (FR-DIRA-05, D-36).
        $this->reject($batch, 'A completely different second reason entirely.')
            ->assertRedirect('/director/batches')
            ->assertSessionHas('error');

        $batch->refresh();
        $this->assertSame('rejected', $batch->status);
        $this->assertSame(self::REASON, $batch->rejection_reason);
        $this->assertEquals($firstReviewedAt, $batch->reviewed_at);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_an_approved_batch_cannot_be_rejected(): void
    {
        $batch = $this->makeBatchWithStudents(4);

        // Real approval first — appointments exist and must survive.
        $this->actingAs($this->director)
            ->post("/director/batches/{$batch->id}/approve")
            ->assertRedirect('/director/batches');
        $this->assertDatabaseCount('appointments', 4);

        $approvedReviewedAt = $batch->fresh()->reviewed_at;

        $this->reject($batch)
            ->assertRedirect('/director/batches')
            ->assertSessionHas('error');

        // Decision terminal in both directions: still approved, appointments intact.
        $batch->refresh();
        $this->assertSame('approved', $batch->status);
        $this->assertEquals($approvedReviewedAt, $batch->reviewed_at);
        $this->assertDatabaseCount('appointments', 4);
        $this->assertSame(4, $batch->batchRequestStudents()->whereNotNull('appointment_id')->count());
    }

    public function test_a_rejected_batch_cannot_be_approved(): void
    {
        $batch = $this->makeBatchWithStudents(3);

        $this->reject($batch)->assertRedirect('/director/batches');

        $this->actingAs($this->director)
            ->post("/director/batches/{$batch->id}/approve")
            ->assertRedirect('/director/batches');

        // Still rejected, still zero appointments (FR-DIRA-05).
        $batch->refresh();
        $this->assertSame('rejected', $batch->status);
        $this->assertNull($batch->scheduled_date);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_rejected_rows_show_static_text_with_no_action_buttons(): void
    {
        $batch = $this->makeBatchWithStudents(2);
        $this->reject($batch);

        // FR-DIRA-05: decided rows render static text, not buttons.
        $this->actingAs($this->director)
            ->get('/director/batches')
            ->assertOk()
            ->assertSee('✕ Rejected')
            ->assertDontSee(route('director.batches.reject', $batch));
    }

    public function test_non_directors_cannot_reject(): void
    {
        $batch = $this->makeBatchWithStudents(2);

        foreach (['student', 'nurse', 'college_admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post("/director/batches/{$batch->id}/reject", ['rejection_reason' => self::REASON])
                ->assertRedirect();
        }

        $batch->refresh();
        $this->assertSame('pending', $batch->status);
        $this->assertNull($batch->rejection_reason);
        $this->assertNull($batch->reviewed_by);
    }
}
