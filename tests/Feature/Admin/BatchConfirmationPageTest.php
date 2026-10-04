<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\BatchRequest;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Batch Request Submitted page (FR-ADM-04).
 *
 * D-88: it lists the students on the batch and offers the D-52 Cancel while the
 * batch is pending, so the admin can check what they sent and withdraw it on
 * the spot. D-108: it shows the clinic date and hours the Director scheduled —
 * "To be scheduled by the Director" while pending — and D-90's "another
 * request is waiting for these hours" heads-up is gone (a pending batch holds
 * no hours).
 */
class BatchConfirmationPageTest extends TestCase
{
    use RefreshDatabase;

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

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
        $this->date = now()->addDays(7)->toDateString();
    }

    /** Submit $count CCS students through the real form (no date or hour — D-108); returns the new batch. */
    private function submitBatch(int $count): BatchRequest
    {
        $students = StudentProfile::factory()->count($count)->forCollege($this->ccs)->create();

        $this->actingAs($this->admin)->post('/admin/batches', [
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'students' => $students->pluck('id')->all(),
        ])->assertSessionHasNoErrors();

        return BatchRequest::latest('id')->first();
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
        $batch = $this->submitBatch(2);

        $this->confirmationPage($batch)
            ->assertDontSee('may adjust')
            // The old "Service" row always read "Medical Clearance".
            ->assertDontSee('Service</dt>', false)
            ->assertSee('rejects it with a reason');
    }

    public function test_a_pending_batch_is_to_be_scheduled_by_the_director(): void
    {
        $batch = $this->submitBatch(2);

        $this->confirmationPage($batch)
            ->assertSee('>Clinic date</dt>', false)
            ->assertSee('To be scheduled by the Director')
            ->assertSee('picks the clinic date and')
            ->assertDontSee('Requested Date')
            ->assertDontSee('Requested Time')
            ->assertDontSee('>Hours</dt>', false)
            // D-90's heads-up is gone with the hours a pending batch used to hold.
            ->assertDontSee('Another request is waiting for these hours');
    }

    public function test_an_approved_batch_shows_the_directors_date_and_hours(): void
    {
        $batch = $this->submitBatch(13);
        $batch->update([
            'status' => 'approved',
            'scheduled_date' => $this->date,
            'scheduled_time' => '09:00:00',
            'scheduled_blocks' => 2,
            'reviewed_at' => now(),
        ]);

        $this->confirmationPage($batch)
            ->assertSee(Carbon::parse($this->date)->format('l, F j, Y'))
            ->assertSee('>Hours</dt>', false)
            ->assertSee('9:00 AM – 11:00 AM (2 slots)')
            ->assertDontSee('To be scheduled by the Director');
    }

    public function test_a_rejected_batch_has_no_clinic_date(): void
    {
        $batch = $this->submitBatch(2);
        $batch->update(['status' => 'rejected', 'rejection_reason' => 'Roster includes graduates.', 'reviewed_at' => now()]);

        $this->confirmationPage($batch)->assertDontSee('To be scheduled by the Director');
    }
}
