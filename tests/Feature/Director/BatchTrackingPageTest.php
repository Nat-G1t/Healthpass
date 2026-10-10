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
 * Director needs somewhere to find it again. Batch Tracking has two tables:
 *   - Batch Results — every college's APPROVED batches, each with its D-111
 *     status and a View link to the same results page the college sees
 *   - Rejected and Cancelled batches (D-116) — the ones the Director rejected PLUS the ones a
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

        $response = $this->tracking(['results_q' => $target->reference_no])->assertOk();

        $this->assertSame([$target->id], $response->viewData('approvedBatches')->pluck('id')->all());
        $response->assertSee('value="'.$target->reference_no.'"', false);
    }

    public function test_a_search_with_no_match_keeps_the_card_and_says_so(): void
    {
        $this->makeBatch('approved');

        $this->tracking(['results_q' => 'BR-1999'])
            ->assertOk()
            ->assertSee('Batch Results')
            ->assertSee('No approved batch matches these filters.');
    }

    // ── D-114: each card's own Batch ID search + College dropdown ───────────

    public function test_each_card_has_its_own_search_and_college_dropdown(): void
    {
        $this->makeBatch('approved');
        $this->makeBatch('rejected');

        $this->tracking()
            ->assertOk()
            ->assertSee('data-card-filters="results"', false)
            ->assertSee('data-card-filters="rejected"', false)
            ->assertSee('name="results_q"', false)
            ->assertSee('name="results_college"', false)
            ->assertSee('name="rejected_q"', false)
            ->assertSee('name="rejected_college"', false)
            ->assertSeeInOrder(['All colleges', 'CCS', 'COE'], false);
    }

    public function test_the_college_dropdown_filters_its_own_card_only(): void
    {
        $ccsApproved = $this->makeBatch('approved');
        $coeApproved = $this->makeBatch('approved', $this->coe);
        $ccsRejected = $this->makeBatch('rejected');
        $coeRejected = $this->makeBatch('rejected', $this->coe);

        $response = $this->tracking(['results_college' => $this->coe->id])->assertOk();
        $this->assertSame([$coeApproved->id], $response->viewData('approvedBatches')->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$ccsRejected->id, $coeRejected->id],
            $response->viewData('closedBatches')->pluck('id')->all(),
        );

        $response = $this->tracking(['rejected_college' => $this->ccs->id])->assertOk();
        $this->assertEqualsCanonicalizing(
            [$ccsApproved->id, $coeApproved->id],
            $response->viewData('approvedBatches')->pluck('id')->all(),
        );
        $this->assertSame([$ccsRejected->id], $response->viewData('closedBatches')->pluck('id')->all());
    }

    public function test_the_rejected_card_searches_by_batch_id(): void
    {
        $target = $this->makeBatch('cancelled');
        $this->makeBatch('rejected');

        $response = $this->tracking(['rejected_q' => $target->reference_no])->assertOk();

        $this->assertSame([$target->id], $response->viewData('closedBatches')->pluck('id')->all());

        $this->tracking(['rejected_q' => 'BR-1999'])
            ->assertOk()
            ->assertSee('No rejected or cancelled batch matches these filters.');
    }

    public function test_one_cards_form_keeps_the_other_cards_filters(): void
    {
        $this->makeBatch('approved');
        $this->makeBatch('rejected');

        $response = $this->tracking([
            'results_q' => 'BR', 'results_college' => $this->ccs->id, 'results_page' => 2, 'rejected_page' => 1,
        ])->assertOk();

        // Card 2's form carries card 1's filters and page along as hidden
        // fields, and drops its own page (a new search starts at page 1).
        $rejectedForm = $this->cardForm($response, 'rejected');
        $this->assertStringContainsString('<input type="hidden" name="results_q" value="BR">', $rejectedForm);
        $this->assertStringContainsString('<input type="hidden" name="results_college" value="'.$this->ccs->id.'">', $rejectedForm);
        $this->assertStringContainsString('<input type="hidden" name="results_page" value="2">', $rejectedForm);
        $this->assertStringNotContainsString('name="rejected_page"', $rejectedForm);

        // Card 1's form: its own filters are visible inputs, card 2's page rides along.
        $resultsForm = $this->cardForm($response, 'results');
        $this->assertStringContainsString('<input type="hidden" name="rejected_page" value="1">', $resultsForm);
        $this->assertStringNotContainsString('name="results_page"', $resultsForm);
        $this->assertStringNotContainsString('type="hidden" name="results_q"', $resultsForm);
    }

    /** The HTML of one card's filter form (data-card-filters="$prefix"). */
    private function cardForm(TestResponse $response, string $prefix): string
    {
        $found = preg_match('/<form[^>]*data-card-filters="'.$prefix.'"[^>]*>.*?<\/form>/s', $response->getContent(), $match);
        $this->assertSame(1, $found, "No {$prefix} filter form on the page.");

        return $match[0];
    }

    public function test_malformed_filters_read_as_no_filter(): void
    {
        $approved = $this->makeBatch('approved');

        $response = $this->actingAs($this->director)
            ->get(route('director.tracking.index').'?results_college=abc&results_q[]=x')
            ->assertOk();

        $this->assertSame([$approved->id], $response->viewData('approvedBatches')->pluck('id')->all());
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
            'Rejected and Cancelled batches',
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

    // ── D-130: month, form type, status and release filters ─────────────────

    /** A document release request on $batch with $status, from its college's admin. */
    private function addReleaseRequest(BatchRequest $batch, string $status): void
    {
        $batch->releaseRequests()->create([
            'requested_by' => $this->ccsAdmin->id,
            'reason' => 'The OJT office needs the forms by Friday.',
            'status' => $status,
        ]);
    }

    /** @return list<int> the ids on the Batch Results card */
    private function resultIds(array $query): array
    {
        return $this->tracking($query)->assertOk()->viewData('approvedBatches')->pluck('id')->all();
    }

    public function test_batch_results_filters_by_the_month_of_its_clinic_date(): void
    {
        $september = $this->makeBatch('approved', null, ['scheduled_date' => '2026-09-10']);
        $october = $this->makeBatch('approved', null, ['scheduled_date' => '2026-10-01']);
        $lastDay = $this->makeBatch('approved', null, ['scheduled_date' => '2026-10-31']);

        $this->assertEqualsCanonicalizing([$october->id, $lastDay->id], $this->resultIds(['results_month' => '2026-10']));
        $this->assertSame([$september->id], $this->resultIds(['results_month' => '2026-09']));
        $this->assertSame([], $this->resultIds(['results_month' => '2026-11']));
    }

    public function test_the_month_dropdowns_list_each_cards_own_months_newest_first(): void
    {
        $this->makeBatch('approved', null, ['scheduled_date' => '2026-09-10']);
        $this->makeBatch('approved', null, ['scheduled_date' => '2026-10-05']);
        $this->makeBatch('approved', null, ['scheduled_date' => '2026-10-20']);
        $this->makeBatch('rejected', null, ['reviewed_at' => '2026-08-20 10:00:00']);

        $response = $this->tracking()->assertOk();

        $this->assertSame(
            ['2026-10' => 'October 2026', '2026-09' => 'September 2026'],
            $response->viewData('resultsMonths'),
        );
        $this->assertSame(['2026-08' => 'August 2026'], $response->viewData('rejectedMonths'));

        $this->assertMatchesRegularExpression(
            '/name="results_month".*All months.*value="2026-10"[^>]*>October 2026.*value="2026-09"[^>]*>September 2026/s',
            $this->cardForm($response, 'results'),
        );
        $this->assertStringContainsString('name="rejected_month"', $this->cardForm($response, 'rejected'));
    }

    public function test_the_rejected_card_filters_by_the_cancel_date_or_else_the_review_date(): void
    {
        // Rejected late on August 31 — still August.
        $rejected = $this->makeBatch('rejected', null, ['reviewed_at' => '2026-08-31 22:00:00']);
        // Approved in August, cancelled in September: its decision is the cancellation.
        $cancelled = $this->makeBatch('cancelled_after_approval', null, [
            'reviewed_at' => '2026-08-15 09:00:00',
            'cancelled_at' => '2026-09-03 14:00:00',
        ]);

        $august = $this->tracking(['rejected_month' => '2026-08'])->assertOk();
        $this->assertSame([$rejected->id], $august->viewData('closedBatches')->pluck('id')->all());

        $september = $this->tracking(['rejected_month' => '2026-09'])->assertOk();
        $this->assertSame([$cancelled->id], $september->viewData('closedBatches')->pluck('id')->all());

        $this->assertSame(
            ['2026-09' => 'September 2026', '2026-08' => 'August 2026'],
            $september->viewData('rejectedMonths'),
        );
    }

    public function test_the_rejected_card_gets_the_month_filter_only(): void
    {
        $this->makeBatch('rejected');

        $form = $this->cardForm($this->tracking(), 'rejected');

        $this->assertStringContainsString('name="rejected_month"', $form);
        $this->assertStringNotContainsString('name="rejected_form"', $form);
        $this->assertStringNotContainsString('name="rejected_status"', $form);
        $this->assertStringNotContainsString('name="rejected_release"', $form);
    }

    public function test_batch_results_filters_by_form_type(): void
    {
        $clearance = $this->makeBatch('approved');
        $assessment = $this->makeBatch('approved', null, ['form_type' => 'assessment', 'reason' => 'ojt']);

        $this->assertSame([$clearance->id], $this->resultIds(['results_form' => 'clearance']));
        $this->assertSame([$assessment->id], $this->resultIds(['results_form' => 'assessment']));

        $this->tracking()->assertSeeInOrder(['All form types', 'Medical Clearance', 'Medical Assessment Form']);
    }

    public function test_batch_results_filters_by_status_and_the_pager_counts_only_the_matches(): void
    {
        $scheduled = $this->makeBatch('approved', null, ['scheduled_date' => '2026-09-10']);
        $this->addStudent($scheduled, 'booked', 'Ana Booked');
        $completed = $this->makeBatch('approved', null, ['scheduled_date' => '2026-09-07']);
        $this->addStudent($completed, 'Fit', 'Ben Done');

        $response = $this->tracking(['results_status' => 'completed'])->assertOk();

        $this->assertSame([$completed->id], $response->viewData('approvedBatches')->pluck('id')->all());
        $this->assertSame(1, $response->viewData('approvedBatches')->total());
        $this->assertSame([$scheduled->id], $this->resultIds(['results_status' => 'scheduled']));

        $this->tracking()->assertSeeInOrder([
            'All statuses', 'Scheduled', 'In progress', 'Still in progress', 'Completed', 'Done / Incomplete', 'No one attended',
        ]);
    }

    public function test_a_status_filtered_list_pages_at_ten_and_keeps_every_filter(): void
    {
        foreach (range(1, 12) as $day) {
            $this->makeBatch('approved', null, ['scheduled_date' => sprintf('2026-10-%02d', $day)]);
        }
        $this->makeBatch('approved', $this->coe, ['scheduled_date' => '2026-10-20']);
        $this->makeBatch('rejected');

        $query = ['results_status' => 'scheduled', 'results_college' => $this->ccs->id, 'rejected_month' => '2026-09'];

        $first = $this->tracking($query)->viewData('approvedBatches');
        $this->assertCount(10, $first);
        $this->assertSame(12, $first->total());

        $next = $first->nextPageUrl();
        $this->assertStringContainsString('results_page=2', $next);
        $this->assertStringContainsString('results_status=scheduled', $next);
        $this->assertStringContainsString('rejected_month=2026-09', $next);

        $second = $this->tracking([...$query, 'results_page' => 2])->viewData('approvedBatches');
        $this->assertCount(2, $second);
        // Newest clinic date first, as unfiltered: page 2 holds October 2 and 1.
        $this->assertSame(
            ['2026-10-02', '2026-10-01'],
            $second->getCollection()->map(fn (BatchRequest $batch) => $batch->scheduled_date->toDateString())->all(),
        );
    }

    public function test_batch_results_filters_by_the_latest_release_request(): void
    {
        $none = $this->makeBatch('approved');
        $pending = $this->makeBatch('approved');
        $this->addReleaseRequest($pending, 'pending');
        $approved = $this->makeBatch('approved');
        $this->addReleaseRequest($approved, 'approved');
        $rejected = $this->makeBatch('approved');
        $this->addReleaseRequest($rejected, 'rejected');
        // Rejected once, then asked again: its latest request is the pending one.
        $askedAgain = $this->makeBatch('approved');
        $this->addReleaseRequest($askedAgain, 'rejected');
        $this->addReleaseRequest($askedAgain, 'pending');

        $this->assertSame([$none->id], $this->resultIds(['results_release' => 'none']));
        $this->assertEqualsCanonicalizing([$pending->id, $askedAgain->id], $this->resultIds(['results_release' => 'pending']));
        $this->assertSame([$approved->id], $this->resultIds(['results_release' => 'approved']));
        $this->assertSame([$rejected->id], $this->resultIds(['results_release' => 'rejected']));

        $this->tracking()->assertSeeInOrder(['All release requests', 'None', 'Waiting for review', 'Approved', 'Rejected']);
    }

    public function test_the_new_filters_combine_with_the_college_dropdown_and_batch_id_search(): void
    {
        $assessment = ['form_type' => 'assessment', 'reason' => 'ojt'];
        $target = $this->makeBatch('approved', $this->coe, [...$assessment, 'scheduled_date' => '2026-10-05']);
        $this->addReleaseRequest($target, 'approved');
        // Each of these misses exactly one filter.
        $wrongCollege = $this->makeBatch('approved', null, [...$assessment, 'scheduled_date' => '2026-10-05']);
        $this->addReleaseRequest($wrongCollege, 'approved');
        $wrongForm = $this->makeBatch('approved', $this->coe, ['scheduled_date' => '2026-10-05']);
        $this->addReleaseRequest($wrongForm, 'approved');
        $wrongMonth = $this->makeBatch('approved', $this->coe, [...$assessment, 'scheduled_date' => '2026-09-10']);
        $this->addReleaseRequest($wrongMonth, 'approved');
        $noRelease = $this->makeBatch('approved', $this->coe, [...$assessment, 'scheduled_date' => '2026-10-06']);
        $wrongStatus = $this->makeBatch('approved', $this->coe, [...$assessment, 'scheduled_date' => '2026-10-07']);
        $this->addStudent($wrongStatus, 'Fit', 'Cara Done');
        $this->addReleaseRequest($wrongStatus, 'approved');

        $query = [
            'results_college' => $this->coe->id,
            'results_month' => '2026-10',
            'results_form' => 'assessment',
            'results_status' => 'scheduled',
            'results_release' => 'approved',
        ];

        $this->assertSame([$target->id], $this->resultIds($query));
        $this->assertSame([$target->id], $this->resultIds([...$query, 'results_q' => $target->reference_no]));
        $this->assertSame([], $this->resultIds([...$query, 'results_q' => 'BR-1999']));
    }

    public function test_invalid_d130_values_read_as_all(): void
    {
        $approved = $this->makeBatch('approved');
        $rejected = $this->makeBatch('rejected');

        $response = $this->actingAs($this->director)
            ->get(route('director.tracking.index').'?results_month=13-2026&results_status=x&results_form=dental'
                .'&results_release[]=a&rejected_month=2026-13')
            ->assertOk();

        $this->assertSame([$approved->id], $response->viewData('approvedBatches')->pluck('id')->all());
        $this->assertSame([$rejected->id], $response->viewData('closedBatches')->pluck('id')->all());
        $this->assertFalse($response->viewData('resultsFilters')->isActive());
        $this->assertFalse($response->viewData('rejectedFilters')->isActive());
    }

    public function test_one_cards_form_keeps_the_other_cards_new_filters(): void
    {
        $this->makeBatch('approved');
        $this->makeBatch('rejected');

        $response = $this->tracking(['results_status' => 'scheduled', 'results_month' => '2026-09', 'rejected_month' => '2026-09']);

        $rejectedForm = $this->cardForm($response, 'rejected');
        $this->assertStringContainsString('<input type="hidden" name="results_status" value="scheduled">', $rejectedForm);
        $this->assertStringContainsString('<input type="hidden" name="results_month" value="2026-09">', $rejectedForm);

        $resultsForm = $this->cardForm($response, 'results');
        $this->assertStringContainsString('<input type="hidden" name="rejected_month" value="2026-09">', $resultsForm);
        $this->assertStringNotContainsString('type="hidden" name="results_status"', $resultsForm);

        // Clear resets this card's new filters too, and keeps the other card's.
        $clear = $this->clearLink($resultsForm);
        $this->assertStringNotContainsString('results_status=', $clear);
        $this->assertStringNotContainsString('results_month=', $clear);
        $this->assertStringContainsString('rejected_month=2026-09', $clear);
    }

    /** The href of a filter form's Clear link. */
    private function clearLink(string $form): string
    {
        $found = preg_match('/<a href="([^"]*)"[^>]*>\s*Clear/', $form, $match);
        $this->assertSame(1, $found, 'The form has no Clear link.');

        return html_entity_decode($match[1]);
    }

    public function test_the_clinic_and_college_admin_cards_get_no_new_filters(): void
    {
        $upcoming = $this->makeBatch('approved', null, ['scheduled_date' => '2026-09-10']);
        $this->addStudent($upcoming, 'booked', 'Ana Booked');
        $past = $this->makeBatch('approved', null, ['scheduled_date' => '2026-09-07']);
        $this->addStudent($past, 'Fit', 'Ben Done');

        $pages = [
            [$this->nurse, route('nurse.batches.index'), 'upcoming'],
            [$this->nurse, route('nurse.batch-results'), 'past'],
            [$this->ccsAdmin, route('admin.batches.index'), null],
        ];

        foreach ($pages as [$user, $url, $prefix]) {
            $response = $this->actingAs($user)->get($url)->assertOk();

            if ($prefix !== null) {
                // The D-114 filters are still there…
                $response->assertSee('name="'.$prefix.'_college"', false);
            }

            // …and none of D-130's.
            foreach (['All months', 'All form types', 'All statuses', 'All release requests'] as $label) {
                $response->assertDontSee($label);
            }
        }
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
        // Not Fit: a Completed batch is "settled" for the D-120 badge, which
        // then skips working its status out — fewer queries for a reason that
        // has nothing to do with the student count. Waiting keeps the batch
        // Still in progress before AND after the students below are added.
        $this->addStudent($batch, 'in_clinic', 'Ana Waiting');
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
