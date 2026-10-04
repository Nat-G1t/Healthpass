<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Director's Review page (FR-DIRA-07, D-108): one pending request — its
 * details, its students, and the Schedule card the Director approves from.
 */
class BatchReviewPageTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private User $director;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // The D-95 figures, pinned: 20 an hour, 200 a day.
        config(['healthpass.hourly_capacity' => 20, 'healthpass.daily_capacity' => 200]);

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->director = User::factory()->create(['role' => 'director']);
        $this->admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id, 'name' => 'Ada Admin']);
    }

    private function makeBatch(int $studentCount = 3, array $overrides = []): BatchRequest
    {
        static $seq = 300;

        $batch = BatchRequest::create(array_merge([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'service_type' => 'medical',
            'status' => 'pending',
        ], $overrides));

        static $number = 0;

        foreach (range(1, $studentCount) as $i) {
            $student = User::factory()->create(['role' => 'student', 'name' => sprintf('Student %03d', $i)]);
            StudentProfile::factory()->forCollege($this->ccs)->create([
                'user_id' => $student->id,
                'student_number' => sprintf('2026%06d', ++$number),
            ]);
            BatchRequestStudent::create(['batch_request_id' => $batch->id, 'student_id' => $student->id]);
        }

        return $batch;
    }

    private function review(BatchRequest $batch)
    {
        return $this->actingAs($this->director)->get("/director/batches/{$batch->id}/review");
    }

    public function test_the_director_sees_the_batch_details(): void
    {
        $batch = $this->makeBatch(25);

        $this->review($batch)
            ->assertOk()
            ->assertSeeInOrder(['Batch Details', 'Students in this batch', 'Schedule'])
            ->assertSee($batch->reference_no)
            ->assertSee('College of Computing Studies')
            ->assertSee('(CCS)', false)
            ->assertSee('Medical Clearance')
            ->assertSee('Field Trip/Educational Tour')
            ->assertSee('Ada Admin')
            // 25 students at the D-95 default of 20 an hour.
            ->assertSee('2 hours')
            ->assertSee('25 students, 20 per hour');
    }

    public function test_the_students_card_lists_every_student(): void
    {
        $batch = $this->makeBatch(25);

        $response = $this->review($batch)->assertOk();

        foreach ($batch->batchRequestStudents()->with('student.studentProfile')->get() as $row) {
            $response->assertSee($row->student->name)->assertSee($row->student->studentProfile->student_number);
        }
    }

    public function test_the_schedule_card_posts_to_the_approve_and_reject_endpoints(): void
    {
        $batch = $this->makeBatch();

        $this->review($batch)
            ->assertSee(route('director.batches.approve', $batch))
            ->assertSee(route('director.batches.reject', $batch))
            ->assertSee('name="scheduled_date"', false)
            ->assertSee('name="start_time"', false)
            ->assertSee('Ring = how full the day is (out of 200 students)');
    }

    public function test_only_a_legacy_batch_shows_what_it_originally_requested(): void
    {
        $legacy = $this->makeBatch(2, [
            'requested_date' => '2026-10-07',
            'requested_time' => '09:00:00',
            'requested_blocks' => 1,
        ]);
        $fresh = $this->makeBatch(2);

        $this->review($legacy)->assertSee('Originally requested:')
            ->assertSee('Wed, Oct 7, 2026 · 9:00 AM')
            ->assertSee('(before D-108)');

        $this->review($fresh)->assertDontSee('Originally requested');
    }

    /** A refused reject or approve comes back here with its message, the dialog re-opened. */
    public function test_validation_errors_render_on_the_review_page(): void
    {
        $batch = $this->makeBatch(2);
        $url = "/director/batches/{$batch->id}/review";

        $rejected = $this->actingAs($this->director)->from($url)
            ->followingRedirects()
            ->post("/director/batches/{$batch->id}/reject", ['rejection_reason' => 'no'])
            ->assertOk()
            ->assertSee('The reason must be at least 10 characters')
            ->assertSee('Reject Batch');
        // The page config (Js::from escapes each quote) re-opens the reject dialog.
        $this->assertMatchesRegularExpression('/rejectOpen.{1,8}:true/', $rejected->getContent());

        $this->actingAs($this->director)->from($url)
            ->followingRedirects()
            ->post("/director/batches/{$batch->id}/approve", [])
            ->assertOk()
            ->assertSee('Pick the clinic date for this batch.')
            ->assertSee('Pick the hour this batch starts.');
    }

    public function test_a_refused_approve_comes_back_with_the_picked_day_and_hour(): void
    {
        // 25 students need two hours, so a 4 PM start runs past closing.
        $big = $this->makeBatch(25);
        $date = now()->addDays(3)->toDateString();

        $refused = $this->actingAs($this->director)
            ->followingRedirects()
            ->post("/director/batches/{$big->id}/approve", ['scheduled_date' => $date, 'start_time' => '16:00:00'])
            ->assertOk()
            ->assertSee('it would run past 5:00 PM');

        // The page config brings the picked day and hour back (Js::from escapes each quote).
        $this->assertMatchesRegularExpression('/oldDate.{1,8}:.{1,8}'.$date.'/', $refused->getContent());
        $this->assertMatchesRegularExpression('/oldStart.{1,8}:.{1,8}16:00:00/', $refused->getContent());
    }

    public function test_a_decided_batch_goes_back_to_the_queue(): void
    {
        foreach (['approved', 'rejected'] as $status) {
            $batch = $this->makeBatch(2, ['status' => $status]);

            $this->review($batch)
                ->assertRedirect('/director/batches')
                ->assertSessionHas('error', "{$batch->reference_no} has already been decided — nothing was changed.");
        }
    }

    public function test_a_batch_cancelled_by_the_college_goes_back_to_the_queue(): void
    {
        $batch = $this->makeBatch(2, ['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->review($batch)
            ->assertRedirect('/director/batches')
            ->assertSessionHas('error', "{$batch->reference_no} was cancelled by the college — nothing was changed.");
    }

    public function test_other_roles_and_guests_are_refused(): void
    {
        $batch = $this->makeBatch(2);

        foreach (['student', 'nurse', 'physician', 'college_admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get("/director/batches/{$batch->id}/review")
                ->assertRedirect();
        }

        auth()->logout();
        $this->get("/director/batches/{$batch->id}/review")->assertRedirect('/login');
    }

    public function test_an_unknown_batch_is_a_404(): void
    {
        $this->actingAs($this->director)->get('/director/batches/99999/review')->assertNotFound();
    }
}
