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
 * Director batch Reject (FR-DIRA-04/05): status → rejected + reviewer
 * stamps, ZERO appointments created, and the decision is terminal — a
 * decided batch can never be re-decided in either direction.
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
        ], $overrides));

        User::factory()->count($studentCount)->create()->each(
            fn (User $student) => BatchRequestStudent::create([
                'batch_request_id' => $batch->id,
                'student_id' => $student->id,
            ])
        );

        return $batch;
    }

    private function reject(BatchRequest $batch): TestResponse
    {
        return $this->actingAs($this->director)
            ->post("/director/batches/{$batch->id}/reject");
    }

    public function test_rejecting_stamps_reviewer_fields_and_creates_zero_appointments(): void
    {
        $batch = $this->makeBatchWithStudents(10);

        $this->reject($batch)->assertRedirect('/director/batches');

        // Status + reviewer stamps (FR-DIRA-04); no date is ever set.
        $batch->refresh();
        $this->assertSame('rejected', $batch->status);
        $this->assertSame($this->director->id, $batch->reviewed_by);
        $this->assertNotNull($batch->reviewed_at);
        $this->assertNull($batch->scheduled_date);

        // ZERO appointments, no back-written pivot ids.
        $this->assertDatabaseCount('appointments', 0);
        $this->assertSame(0, $batch->batchRequestStudents()->whereNotNull('appointment_id')->count());
    }

    public function test_a_duplicate_reject_post_is_a_no_op(): void
    {
        $batch = $this->makeBatchWithStudents(3);

        $this->reject($batch)->assertRedirect('/director/batches');
        $firstReviewedAt = $batch->fresh()->reviewed_at;

        // Double-click / replayed POST: the in-transaction status re-check
        // must leave the first decision untouched (FR-DIRA-05).
        $this->reject($batch)
            ->assertRedirect('/director/batches')
            ->assertSessionHas('error');

        $batch->refresh();
        $this->assertSame('rejected', $batch->status);
        $this->assertEquals($firstReviewedAt, $batch->reviewed_at);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_an_approved_batch_cannot_be_rejected(): void
    {
        $batch = $this->makeBatchWithStudents(4);
        $date = now()->addDays(7)->toDateString();

        // Real approval first — appointments exist and must survive.
        $this->actingAs($this->director)
            ->post("/director/batches/{$batch->id}/approve", ['scheduled_date' => $date])
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
            ->post("/director/batches/{$batch->id}/approve", [
                'scheduled_date' => now()->addDays(7)->toDateString(),
            ])
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
                ->post("/director/batches/{$batch->id}/reject")
                ->assertRedirect();
        }

        $batch->refresh();
        $this->assertSame('pending', $batch->status);
        $this->assertNull($batch->reviewed_by);
    }
}
