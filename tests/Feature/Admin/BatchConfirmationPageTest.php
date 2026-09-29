<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ClinicScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Batch Request Submitted page (FR-ADM-04).
 *
 * D-88: it lists the students on the batch and offers the D-52 Cancel while the
 * batch is pending, so the admin can check what they sent and withdraw it on
 * the spot. D-90: while pending, a heads-up when another request for the same
 * hours came in FIRST and the clinic can't fit both.
 */
class BatchConfirmationPageTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $cit;

    private User $admin;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->cit = College::create(['code' => 'CIT', 'name' => 'College of Industrial Technology']);
        $this->admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
        $this->date = now()->addDays(7)->toDateString();
    }

    /** Submit $count CCS students through the real form; returns the new batch. */
    private function submitBatch(int $count, string $start = '07:00:00'): BatchRequest
    {
        $students = StudentProfile::factory()->count($count)->forCollege($this->ccs)->create();

        $this->actingAs($this->admin)->post('/admin/batches', [
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'requested_date' => $this->date,
            'requested_time' => $start,
            'students' => $students->pluck('id')->all(),
        ])->assertSessionHasNoErrors();

        return BatchRequest::latest('id')->first();
    }

    /** Another college's pending batch, written directly. */
    private function citBatch(int $students, string $start = '07:00:00'): BatchRequest
    {
        $batch = BatchRequest::create([
            'reference_no' => 'BR-'.now()->year.'-777',
            'college_id' => $this->cit->id,
            'requested_by' => User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->cit->id])->id,
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'service_type' => 'medical',
            'requested_date' => $this->date,
            'requested_time' => $start,
            'requested_blocks' => app(ClinicScheduleService::class)->blocksFor($students),
        ]);

        User::factory()->count($students)->create()->each(
            fn (User $student) => BatchRequestStudent::create(['batch_request_id' => $batch->id, 'student_id' => $student->id])
        );

        return $batch;
    }

    private function confirmationPage(BatchRequest $batch)
    {
        return $this->actingAs($this->admin)->get(route('admin.batches.confirmation', $batch))->assertOk();
    }

    public function test_it_lists_the_students_on_the_batch(): void
    {
        $batch = $this->submitBatch(3);
        $profiles = StudentProfile::with('user')->get();

        $response = $this->confirmationPage($batch)->assertSee('Students in this batch');

        foreach ($profiles as $profile) {
            $response->assertSee($profile->user->name)->assertSee($profile->student_number);
        }
    }

    public function test_the_student_list_pages_at_ten(): void
    {
        $batch = $this->submitBatch(12);

        $rows = $this->confirmationPage($batch)->viewData('rows');

        $this->assertCount(10, $rows->items());
        $this->assertSame(12, $rows->total());
    }

    public function test_a_pending_batch_offers_cancel_with_the_confirm_dialog(): void
    {
        $batch = $this->submitBatch(2);

        $this->confirmationPage($batch)
            ->assertSee('Cancel Request')
            ->assertSee('id="cancel-batch-title"', false)
            ->assertSee('Keep it');

        // The button feeds the SAME cancel endpoint Batch Tracking uses. D-92:
        // the dialog now carries a required written reason.
        $this->confirmationPage($batch)->assertSee('name="cancellation_reason"', false);

        $this->actingAs($this->admin)
            ->delete("/admin/batches/{$batch->id}/cancel", ['cancellation_reason' => 'The field trip was postponed.'])
            ->assertRedirect(route('admin.batches.index'));

        $this->assertSame('cancelled', $batch->fresh()->status);
    }

    public function test_a_decided_batch_offers_no_cancel(): void
    {
        $batch = $this->submitBatch(2);
        $batch->update(['status' => 'rejected']);

        $this->confirmationPage($batch)->assertDontSee('Cancel Request');
    }

    public function test_the_page_no_longer_says_the_director_may_move_the_date(): void
    {
        $batch = $this->submitBatch(1);

        $this->confirmationPage($batch)
            ->assertDontSee('may adjust')
            // The old "Service" row always read "Medical Clearance".
            ->assertDontSee('Service</dt>', false)
            ->assertSee('rejects it with a reason');
    }

    public function test_a_heads_up_when_an_earlier_request_takes_the_hours(): void
    {
        // CIT's 10 students asked for 7 AM first; CCS's 8 won't fit beside them.
        $cit = $this->citBatch(10);
        $this->travel(5)->minutes();
        $batch = $this->submitBatch(8);

        $this->confirmationPage($batch)
            ->assertSee('Another request is waiting for these hours')
            ->assertSee('8:00 AM')
            // It never says whose request it is.
            ->assertDontSee($cit->reference_no)
            ->assertDontSee('CIT');
    }

    public function test_no_heads_up_for_the_request_that_came_first(): void
    {
        $batch = $this->submitBatch(8);
        $this->travel(5)->minutes();
        $this->citBatch(10);

        $this->confirmationPage($batch)->assertDontSee('Another request is waiting for these hours');
    }

    public function test_no_heads_up_when_both_requests_fit(): void
    {
        $this->citBatch(4);
        $this->travel(5)->minutes();
        $batch = $this->submitBatch(8);

        $this->confirmationPage($batch)->assertDontSee('Another request is waiting for these hours');
    }
}
