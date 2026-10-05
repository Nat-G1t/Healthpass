<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Director's Batch Tracking page (FR-DIRA-09, FR-DIRA-10, D-112).
 *
 * Since D-108 a decided batch leaves the Batch Approvals queue, so the
 * Director needs somewhere to find it again. Batch Tracking has two cards:
 *   - Batch Results — every college's APPROVED batches, each with its D-111
 *     status and a View link to the same results page the college sees
 *   - Rejected batches — the ones the Director rejected PLUS the ones a
 *     college cancelled (Q20), each with a View link to a details page that
 *     shows the request, its students and the written reason
 */
class BatchTrackingPageTest extends TestCase
{
    use RefreshDatabase;

    /** The clinic day approved batches run on — a Thursday. */
    private const CLINIC_DAY = '2026-09-10';

    private College $ccs;

    private College $coe;

    private User $ccsAdmin;

    private User $coeAdmin;

    private User $director;

    /** The Director account that decided the batches — not the one signed in, whose name the sidebar shows. */
    private User $reviewer;

    /** A second CCS admin: FR-AUTH-10 lets any of the college's admins cancel, not only the submitter. */
    private User $canceller;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        // Two days before the clinic day, so an approved batch reads Scheduled.
        $this->travelTo(Carbon::parse('2026-09-08 09:00:00'));

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->coe = College::create(['code' => 'COE', 'name' => 'College of Engineering']);

        $this->ccsAdmin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->ccs->id,
        ]);
        $this->coeAdmin = User::factory()->create([
            'role' => 'college_admin',
            'managed_college_id' => $this->coe->id,
        ]);

        $this->director = User::factory()->create(['role' => 'director', 'name' => 'Dina Director']);
        $this->reviewer = User::factory()->create(['role' => 'director', 'name' => 'Rhea Reviewer']);
        $this->canceller = User::factory()->create([
            'role' => 'college_admin',
            'name' => 'Cory Canceller',
            'managed_college_id' => $this->ccs->id,
        ]);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * A batch in $status with no students yet:
     *   approved               — scheduled on CLINIC_DAY at 9 AM
     *   rejected               — with the Director's written reason
     *   cancelled              — the college withdrew it while pending
     *   cancelled_after_approval — approved first, then cancelled (D-92)
     *   pending                — waiting in the queue
     */
    private function makeBatch(string $status = 'approved', ?College $college = null, array $overrides = []): BatchRequest
    {
        static $seq = 500;

        $college ??= $this->ccs;
        $admin = $college->is($this->ccs) ? $this->ccsAdmin : $this->coeAdmin;
        $wasApproved = in_array($status, ['approved', 'cancelled_after_approval'], true);
        $isCancelled = in_array($status, ['cancelled', 'cancelled_after_approval'], true);

        return BatchRequest::create(array_merge([
            'reference_no' => 'BR-2026-'.$seq++,
            'college_id' => $college->id,
            'requested_by' => $admin->id,
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'service_type' => 'medical',
            'scheduled_date' => $wasApproved ? self::CLINIC_DAY : null,
            'scheduled_time' => $wasApproved ? '09:00:00' : null,
            'scheduled_blocks' => $wasApproved ? 1 : null,
            'status' => $isCancelled ? 'cancelled' : $status,
            'reviewed_by' => $wasApproved || $status === 'rejected' ? $this->reviewer->id : null,
            'reviewed_at' => $wasApproved || $status === 'rejected' ? now()->subDay() : null,
            'rejection_reason' => $status === 'rejected' ? 'The memo is not signed by the dean.' : null,
            'cancelled_at' => $isCancelled ? now() : null,
            'cancelled_by' => $isCancelled ? $this->canceller->id : null,
            'cancellation_reason' => $isCancelled ? 'The field trip was moved to next semester.' : null,
        ], $overrides));
    }

    /** A student called $name ("First Last") on $batch's roster, with no appointment (pending / rejected). */
    private function addRosterStudent(BatchRequest $batch, string $name): User
    {
        $student = $this->makeStudent($batch->college_id, $name);

        BatchRequestStudent::create([
            'batch_request_id' => $batch->id,
            'student_id' => $student->id,
        ]);

        return $student;
    }

    /**
     * A student called $name on an approved $batch, driven to $stage:
     * 'booked' (appointment only), 'in_clinic' (captured, not encoded) or
     * 'Fit' / 'Unfit' (encoded with that result).
     */
    private function addStudent(BatchRequest $batch, string $stage, string $name): Appointment
    {
        static $visitSeq = 5000;

        $student = $this->makeStudent($batch->college_id, $name);

        $appointment = Appointment::factory()->create([
            'student_id' => $student->id,
            'service_type' => 'medical',
            'scheduled_date' => $batch->scheduled_date,
            'scheduled_time' => '09:00:00',
            'status' => 'scheduled',
            'source' => 'batch',
            'batch_request_id' => $batch->id,
            'created_by' => $this->director->id,
        ]);

        BatchRequestStudent::create([
            'batch_request_id' => $batch->id,
            'student_id' => $student->id,
            'appointment_id' => $appointment->id,
        ]);

        if ($stage === 'booked') {
            return $appointment;
        }

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$visitSeq++,
            'student_id' => $student->id,
            'college_id' => $batch->college_id,
            'appointment_id' => $appointment->id,
            'login_method' => 'qr',
            'status' => $stage === 'in_clinic' ? 'captured' : 'encoded',
            'checked_in_at' => now(),
        ]);

        if (in_array($stage, ['Fit', 'Unfit'], true)) {
            ClearanceRecord::create([
                'clinic_visit_id' => $visit->id,
                'encoded_by' => $this->nurse->id,
                'result' => $stage,
                'encoded_at' => now(),
            ]);
            $appointment->update(['status' => 'completed']);
        }

        return $appointment;
    }

    private function makeStudent(int $collegeId, string $name): User
    {
        [$first, $last] = explode(' ', $name, 2);

        $student = User::factory()->create(['role' => 'student', 'name' => $name]);

        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'college_id' => $collegeId,
            'first_name' => $first,
            'last_name' => $last,
        ]);

        return $student;
    }

    private function tracking(array $query = []): TestResponse
    {
        return $this->actingAs($this->director)->get(route('director.tracking.index', $query));
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    // ── Navigation ───────────────────────────────────────────────────────────

    public function test_the_director_sidebar_lists_batch_tracking_right_after_batch_approvals(): void
    {
        $this->actingAs($this->director)->get(route('director.dashboard'))
            ->assertOk()
            ->assertSee('Batch Tracking')
            ->assertSeeInOrder([
                route('director.batches.index'),
                route('director.tracking.index'),
                route('director.analytics'),
            ], false);
    }

    public function test_no_other_role_gets_the_directors_batch_tracking_link(): void
    {
        $physician = User::factory()->create(['role' => 'physician']);
        $student = $this->makeStudent($this->ccs->id, 'Sam Student');

        $pages = [
            [$this->ccsAdmin, route('admin.batches.index')],
            [$this->nurse, route('nurse.dashboard')],
            [$physician, route('nurse.dashboard')],
            [$student, route('student.dashboard')],
        ];

        foreach ($pages as [$user, $url]) {
            $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertDontSee(route('director.tracking.index'));
        }
    }

    // ── Card 1: Batch Results ────────────────────────────────────────────────

    public function test_batch_results_lists_only_approved_batches_from_every_college(): void
    {
        $ccs = $this->makeBatch('approved');
        $coe = $this->makeBatch('approved', $this->coe);
        $this->makeBatch('pending');
        $this->makeBatch('rejected');
        $this->makeBatch('cancelled');
        $this->makeBatch('cancelled_after_approval');

        $response = $this->tracking()->assertOk();

        $this->assertEqualsCanonicalizing(
            [$ccs->id, $coe->id],
            $response->viewData('approvedBatches')->pluck('id')->all(),
        );

        $response->assertSeeInOrder([
            'Batch Results',
            'Batch ID', 'College', 'Form Type', 'Purpose', 'Date scheduled', 'Status',
            $ccs->reference_no,
        ]);
        $response->assertSee('COE');
        $response->assertSee('Medical Clearance');
        $response->assertSee('Field Trip');
        $response->assertSee('Sep 10, 2026');
        $response->assertSee(route('director.tracking.results', $ccs));
        $response->assertSee(route('director.tracking.results', $coe));
    }

    public function test_batch_results_shows_each_batchs_d111_status(): void
    {
        $scheduled = $this->makeBatch('approved', null, ['scheduled_date' => '2026-09-10']);
        $this->addStudent($scheduled, 'booked', 'Ana Booked');

        $done = $this->makeBatch('approved', $this->coe, ['scheduled_date' => '2026-09-07']);
        $this->addStudent($done, 'Fit', 'Ben Done');

        $response = $this->tracking()->assertOk();

        $statuses = $response->viewData('approvedBatches')->getCollection()
            ->mapWithKeys(fn (BatchRequest $batch) => [$batch->id => $batch->resultsStatus()])
            ->all();

        $this->assertSame('scheduled', $statuses[$scheduled->id]);
        $this->assertSame('completed', $statuses[$done->id]);
        $response->assertSeeInOrder([$scheduled->reference_no, 'Scheduled', $done->reference_no, 'Completed']);
    }

    public function test_batch_results_puts_the_newest_clinic_date_first(): void
    {
        $older = $this->makeBatch('approved', null, ['scheduled_date' => '2026-09-01']);
        $newer = $this->makeBatch('approved', $this->coe, ['scheduled_date' => '2026-09-20']);
        $middle = $this->makeBatch('approved', null, ['scheduled_date' => '2026-09-10']);

        $this->assertSame(
            [$newer->id, $middle->id, $older->id],
            $this->tracking()->viewData('approvedBatches')->pluck('id')->all(),
        );
    }

    public function test_batch_results_pages_at_ten_without_moving_the_rejected_card(): void
    {
        foreach (range(1, 11) as $day) {
            $this->makeBatch('approved', null, ['scheduled_date' => sprintf('2026-09-%02d', $day)]);
        }
        $rejected = $this->makeBatch('rejected');

        $first = $this->tracking()->assertOk();
        $this->assertCount(10, $first->viewData('approvedBatches'));
        $this->assertSame(11, $first->viewData('approvedBatches')->total());

        $second = $this->tracking(['results_page' => 2])->assertOk();
        $this->assertCount(1, $second->viewData('approvedBatches'));
        $this->assertSame([$rejected->id], $second->viewData('closedBatches')->pluck('id')->all());
    }

    public function test_the_batch_id_search_finds_an_approved_batch_on_any_page(): void
    {
        $batches = collect(range(1, 12))->map(fn (int $day) => $this->makeBatch('approved', null, [
            'scheduled_date' => sprintf('2026-09-%02d', $day),
        ]));
        // The oldest clinic date sits on page 2 without a search.
        $target = $batches->first();

        $response = $this->tracking(['q' => $target->reference_no])->assertOk();

        $this->assertSame([$target->id], $response->viewData('approvedBatches')->pluck('id')->all());
        $response->assertSee('value="'.$target->reference_no.'"', false);
    }

    public function test_a_search_with_no_match_keeps_the_card_and_says_so(): void
    {
        $this->makeBatch('approved');

        $this->tracking(['q' => 'BR-1999'])
            ->assertOk()
            ->assertSee('Batch Results')
            ->assertSee('No approved batch matches');
    }

    public function test_both_cards_render_their_empty_state_with_no_decided_batches(): void
    {
        $this->makeBatch('pending');

        $this->tracking()
            ->assertOk()
            ->assertSee('No approved batches yet.')
            ->assertSee('No rejected or cancelled batches.');
    }

    // ── Card 2: Rejected batches ─────────────────────────────────────────────

    public function test_the_rejected_card_lists_rejected_and_college_cancelled_batches(): void
    {
        $rejected = $this->makeBatch('rejected');
        $cancelled = $this->makeBatch('cancelled', $this->coe);
        $afterApproval = $this->makeBatch('cancelled_after_approval');
        $this->makeBatch('approved');
        $this->makeBatch('pending');

        $response = $this->tracking()->assertOk();

        $this->assertEqualsCanonicalizing(
            [$rejected->id, $cancelled->id, $afterApproval->id],
            $response->viewData('closedBatches')->pluck('id')->all(),
        );

        $response->assertSeeInOrder([
            'Rejected batches',
            'Batch ID', 'College', 'Form Type', 'Purpose', 'Date scheduled', 'Status',
        ]);
        $response->assertSee(route('director.tracking.show', $rejected));
        $response->assertSee(route('director.tracking.show', $cancelled));
        $response->assertSee(route('director.tracking.show', $afterApproval));
    }

    public function test_the_rejected_card_tags_each_row_with_who_ended_it(): void
    {
        $rejected = $this->makeBatch('rejected');
        $cancelled = $this->makeBatch('cancelled');

        // Newest decision first: the cancellation (now) above the rejection (yesterday).
        $response = $this->tracking()->assertOk();
        $response->assertSeeInOrder([
            $cancelled->reference_no, 'Cancelled by college',
            $rejected->reference_no, 'Rejected',
        ]);
        $response->assertDontSee('after approval');

        $cancelled->update(['reviewed_by' => $this->reviewer->id, 'reviewed_at' => now()->subDay()]);

        $this->tracking()->assertOk()->assertSee('after approval');
    }

    public function test_the_rejected_card_shows_a_date_only_for_a_batch_that_was_scheduled(): void
    {
        $rejected = $this->makeBatch('rejected');
        $this->makeBatch('cancelled_after_approval', null, ['scheduled_date' => '2026-09-25']);

        $response = $this->tracking()->assertOk();

        // The cancelled-after-approval batch keeps the date it was scheduled for…
        $response->assertSee('Sep 25, 2026');
        // …and the rejected one, never scheduled, reads "—" before its status.
        $response->assertSeeInOrder([$rejected->reference_no, 'Field Trip', '&mdash;', 'Rejected'], false);
    }

    public function test_the_rejected_card_puts_the_newest_decision_first(): void
    {
        $rejectedLongAgo = $this->makeBatch('rejected', null, ['reviewed_at' => now()->subDays(5)]);
        $cancelledYesterday = $this->makeBatch('cancelled', null, ['cancelled_at' => now()->subDay()]);
        $rejectedToday = $this->makeBatch('rejected', $this->coe, ['reviewed_at' => now()]);
        // Approved 4 days ago, cancelled 2 days ago — the cancellation is the newer event.
        $cancelledAfterApproval = $this->makeBatch('cancelled_after_approval', null, [
            'reviewed_at' => now()->subDays(4),
            'cancelled_at' => now()->subDays(2),
        ]);

        $this->assertSame(
            [$rejectedToday->id, $cancelledYesterday->id, $cancelledAfterApproval->id, $rejectedLongAgo->id],
            $this->tracking()->viewData('closedBatches')->pluck('id')->all(),
        );
    }

    public function test_the_rejected_card_pages_at_ten_on_its_own_page_parameter(): void
    {
        foreach (range(1, 11) as $i) {
            $this->makeBatch('rejected', null, ['reviewed_at' => now()->subMinutes($i)]);
        }
        $approved = $this->makeBatch('approved');

        $first = $this->tracking()->assertOk();
        $this->assertCount(10, $first->viewData('closedBatches'));
        $this->assertSame(11, $first->viewData('closedBatches')->total());

        $second = $this->tracking(['rejected_page' => 2])->assertOk();
        $this->assertCount(1, $second->viewData('closedBatches'));
        $this->assertSame([$approved->id], $second->viewData('approvedBatches')->pluck('id')->all());
    }

    public function test_the_tracking_page_does_not_query_per_student(): void
    {
        $batch = $this->makeBatch('approved');
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $this->makeBatch('rejected');

        $this->tracking();
        $withOne = $this->countQueries(fn () => $this->tracking());

        $this->addStudent($batch, 'Unfit', 'Ben Flagged');
        $this->addStudent($batch, 'in_clinic', 'Cara Waiting');
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->assertSame($withOne, $this->countQueries(fn () => $this->tracking()));
    }

    // ── The results page (approved) ──────────────────────────────────────────

    public function test_the_results_page_opens_for_an_approved_batch_of_any_college(): void
    {
        $batch = $this->makeBatch('approved', $this->coe);
        $this->addStudent($batch, 'Fit', 'Ana Cleared');

        $this->actingAs($this->director)->get(route('director.tracking.results', $batch))
            ->assertOk()
            ->assertSee('Batch '.$batch->reference_no)
            ->assertSee('College of Engineering')
            ->assertSee(route('director.tracking.index'))
            ->assertSee('Batch Tracking')
            ->assertSee('data-results-summary', false)
            ->assertSee('data-results-filters', false)
            ->assertSee('Ana Cleared');
    }

    public function test_the_results_page_is_404_for_a_batch_that_is_not_approved(): void
    {
        foreach (['rejected', 'cancelled', 'cancelled_after_approval', 'pending'] as $status) {
            $batch = $this->makeBatch($status);

            $this->actingAs($this->director)
                ->get(route('director.tracking.results', $batch))
                ->assertNotFound();
        }

        $this->actingAs($this->director)->get('/director/batch-tracking/999999/results')->assertNotFound();
    }

    public function test_the_results_page_shows_the_same_numbers_as_the_colleges_page(): void
    {
        $batch = $this->makeBatch('approved');
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $this->addStudent($batch, 'Unfit', 'Ben Flagged');
        $this->addStudent($batch, 'in_clinic', 'Cara Waiting');
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $director = $this->actingAs($this->director)
            ->get(route('director.tracking.results', $batch))->assertOk()->viewData('results');
        $admin = $this->actingAs($this->ccsAdmin)
            ->get(route('admin.batches.results', $batch->id))->assertOk()->viewData('results');

        $this->assertSame($admin, $director);
        $this->assertSame(['total' => 4, 'completed' => 2, 'fit' => 1, 'rescheduled' => 0], $director['summary']);
    }

    public function test_the_results_page_does_not_query_per_student(): void
    {
        $batch = $this->makeBatch('approved');
        $this->addStudent($batch, 'Fit', 'Ana Cleared');
        $url = route('director.tracking.results', $batch);

        $this->actingAs($this->director)->get($url);
        $withOne = $this->countQueries(fn () => $this->actingAs($this->director)->get($url));

        $this->addStudent($batch, 'Unfit', 'Ben Flagged');
        $this->addStudent($batch, 'in_clinic', 'Cara Waiting');
        $this->addStudent($batch, 'booked', 'Dino Booked');

        $this->assertSame($withOne, $this->countQueries(fn () => $this->actingAs($this->director)->get($url)));
    }

    // ── The details page (rejected / cancelled) ──────────────────────────────

    public function test_a_rejected_batch_shows_its_details_students_and_the_reason(): void
    {
        $batch = $this->makeBatch('rejected', $this->coe);
        $this->addRosterStudent($batch, 'Ana Waiting');
        $this->addRosterStudent($batch, 'Ben Waiting');

        $this->actingAs($this->director)->get(route('director.tracking.show', $batch))
            ->assertOk()
            ->assertSee('Batch Details')
            ->assertSee($batch->reference_no)
            ->assertSee('College of Engineering')
            ->assertSee('Medical Clearance')
            ->assertSee('Field Trip')
            ->assertSee('Students in this batch')
            ->assertSee('Ana Waiting')
            ->assertSee('Ben Waiting')
            ->assertSee('Rejection reason')
            ->assertSee('The memo is not signed by the dean.')
            ->assertSee('Rhea Reviewer')
            ->assertSee(now()->subDay()->format('M j, Y'))
            ->assertSee(route('director.tracking.index'));
    }

    public function test_a_cancelled_batch_shows_the_colleges_reason_and_who_cancelled(): void
    {
        $batch = $this->makeBatch('cancelled');
        $this->addRosterStudent($batch, 'Ana Waiting');

        $this->actingAs($this->director)->get(route('director.tracking.show', $batch))
            ->assertOk()
            ->assertSee('Cancelled by college')
            ->assertSee('The field trip was moved to next semester.')
            ->assertSee('Cory Canceller')
            ->assertSee('Ana Waiting')
            ->assertDontSee('after approval')
            ->assertDontSee('Rejection reason');
    }

    public function test_a_batch_cancelled_after_approval_says_so_and_keeps_its_students(): void
    {
        $batch = $this->makeBatch('cancelled_after_approval');
        $this->addStudent($batch, 'booked', 'Ana Booked');
        // D-92: cancelling an approved batch cancels each student's appointment.
        $batch->appointments()->update(['status' => 'cancelled']);

        $this->actingAs($this->director)->get(route('director.tracking.show', $batch))
            ->assertOk()
            ->assertSee('after approval')
            ->assertSee('Rhea Reviewer')
            ->assertSee('Ana Booked')
            ->assertSee('Sep 10, 2026');
    }

    public function test_an_old_decision_without_a_written_reason_says_none_was_recorded(): void
    {
        $rejected = $this->makeBatch('rejected', null, ['rejection_reason' => null]);
        $cancelled = $this->makeBatch('cancelled', null, ['cancellation_reason' => null]);

        $this->actingAs($this->director)->get(route('director.tracking.show', $rejected))
            ->assertOk()->assertSee('No reason was recorded');
        $this->actingAs($this->director)->get(route('director.tracking.show', $cancelled))
            ->assertOk()->assertSee('No reason was recorded');
    }

    public function test_the_details_page_links_the_signed_memo_only_when_there_is_one(): void
    {
        $withMemo = $this->makeBatch('rejected', null, [
            'memo_path' => 'memos/signed.pdf',
            'memo_original_name' => 'signed.pdf',
            'memo_mime' => 'application/pdf',
        ]);
        $withoutMemo = $this->makeBatch('rejected');

        $this->actingAs($this->director)->get(route('director.tracking.show', $withMemo))
            ->assertOk()
            ->assertSee(route('director.batches.memo', $withMemo))
            ->assertSee('Signed memo');

        $this->actingAs($this->director)->get(route('director.tracking.show', $withoutMemo))
            ->assertOk()
            ->assertDontSee(route('director.batches.memo', $withoutMemo))
            ->assertDontSee('Signed memo');
    }

    public function test_the_details_page_is_404_for_an_approved_or_pending_batch(): void
    {
        foreach (['approved', 'pending'] as $status) {
            $batch = $this->makeBatch($status);

            $this->actingAs($this->director)
                ->get(route('director.tracking.show', $batch))
                ->assertNotFound();
        }

        $this->actingAs($this->director)->get('/director/batch-tracking/999999')->assertNotFound();
    }

    // ── Who ──────────────────────────────────────────────────────────────────

    /**
     * Director only. Like every other /director page, a signed-in user of
     * another role is sent to their own dashboard by the role middleware
     * (FR-AUTH-03) rather than shown a bare 403, and a guest goes to login.
     */
    public function test_only_the_director_can_open_the_three_pages(): void
    {
        $approved = $this->makeBatch('approved');
        $rejected = $this->makeBatch('rejected');
        $physician = User::factory()->create(['role' => 'physician']);
        $student = $this->makeStudent($this->ccs->id, 'Sam Student');

        $urls = [
            route('director.tracking.index'),
            route('director.tracking.results', $approved),
            route('director.tracking.show', $rejected),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('login'));
            $this->actingAs($this->ccsAdmin)->get($url)->assertRedirect('/admin/dashboard');
            $this->actingAs($this->nurse)->get($url)->assertRedirect('/nurse/dashboard');
            $this->actingAs($physician)->get($url)->assertRedirect('/nurse/dashboard');
            $this->actingAs($student)->get($url)->assertRedirect('/student/dashboard');
            $this->actingAs($this->director)->get($url)->assertOk();
            auth()->logout();
        }
    }
}
