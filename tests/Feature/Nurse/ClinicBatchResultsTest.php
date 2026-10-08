<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClinicVisit;
use App\Models\DocumentReleaseRequest;
use App\Models\User;
use App\Support\BatchNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\DocumentReleaseFixture;
use Tests\TestCase;

/**
 * D-125 — the clinic's Batch Results page (FR-NRS-13), nurse and physician:
 *
 *   - one card, "Completed batches" — D-114's old Past card, moved here
 *     unchanged (same rows, order, search, college filter and pager);
 *   - "Print Requested" under a Batch ID while the batch's approved release
 *     asks for printed copies, "Printed" once the clinic presses Mark as
 *     printed (a locked, one-time stamp on the batch's results page);
 *   - a per-user row dot when such an approval is newer than the user's last
 *     View of the batch — nothing else makes a clinic dot, and there is no
 *     sidebar badge (O4);
 *   - printing follows the release (O2): before the Director approves one,
 *     the batch page's Print / PDF / download are disabled and every clinic
 *     print route refuses the batch's visits.
 *
 * The fixture's clock: "now" is Friday Sep 11, 2026, 10 AM; its batches ran
 * on Thursday Sep 10, so they are Completed-card batches unless moved.
 */
class ClinicBatchResultsTest extends TestCase
{
    use DocumentReleaseFixture;
    use RefreshDatabase;

    private User $physician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRelease();

        $this->physician = User::factory()->create(['role' => 'physician', 'name' => 'Rey Physician']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function completedPage(User $user, array $query = []): TestResponse
    {
        return $this->actingAs($user)->get(route('nurse.batch-results', $query))->assertOk();
    }

    /** @return list<string> the Batch IDs the Completed batches card lists, in order */
    private function listed(TestResponse $response): array
    {
        return $response->viewData('completedBatches')->pluck('reference_no')->all();
    }

    private function dotFor(User $user, BatchRequest $batch): bool
    {
        return $this->completedPage($user)->viewData('dots')[$batch->id] ?? false;
    }

    private function openBatch(User $user, BatchRequest $batch): TestResponse
    {
        return $this->actingAs($user)->get(route('nurse.batches.results', ['batch' => $batch, 'from' => 'completed']))->assertOk();
    }

    private function markPrinted(User $user, BatchRequest $batch): TestResponse
    {
        return $this->actingAs($user)->post(route('nurse.batches.copies-printed', ['batch' => $batch, 'from' => 'completed']));
    }

    /** A release the Director approved that asks the clinic for printed copies. */
    private function copiesRequest(BatchRequest $batch): DocumentReleaseRequest
    {
        return $this->release($batch, 'approved', ['wants_printed_copies' => true]);
    }

    // ── Nav and access ───────────────────────────────────────────────────────

    public function test_nurse_and_physician_get_batch_results_right_after_upcoming_batches(): void
    {
        foreach ([$this->nurse, $this->physician] as $user) {
            $this->actingAs($user)->get(route('nurse.dashboard'))
                ->assertOk()
                ->assertSee('Batch Results')
                ->assertSeeInOrder([
                    route('nurse.batches.index'),
                    route('nurse.batch-results'),
                    route('nurse.kiosk-devices'),
                ], false);

            $this->completedPage($user)
                ->assertSee('Batch Results')
                ->assertSee('Completed batches')
                ->assertSee('data-batches-card="past"', false);
        }
    }

    /** Like every /nurse page: a guest goes to login, another role to its own dashboard. */
    public function test_other_roles_are_refused(): void
    {
        $student = $this->addStudent($this->makeBatch(), 'booked')->student;
        $others = [
            [$this->admin, '/admin/dashboard'],
            [$this->director, '/director/dashboard'],
            [$student, '/student/dashboard'],
        ];

        $this->get(route('nurse.batch-results'))->assertRedirect(route('login'));

        foreach ($others as [$user, $home]) {
            $this->actingAs($user)->get(route('nurse.batch-results'))->assertRedirect($home);
            $this->actingAs($user)->get($home)->assertDontSee(route('nurse.batch-results'));
        }
    }

    // ── Completed batches (D-114's Past card, ported) ────────────────────────

    public function test_it_lists_the_approved_batches_whose_clinic_days_are_over_newest_first(): void
    {
        $older = $this->batchOn('2026-09-01');
        $newest = $this->batchOn('2026-09-10');
        $middle = $this->batchOn('2026-09-05');
        $this->batchOn('2026-09-12');   // upcoming — not here

        // A 2nd chance that is itself over keeps the batch here.
        $secondChanceOver = $this->batchOn('2026-09-03');
        $original = $secondChanceOver->appointments()->first();
        Appointment::factory()->create([
            'student_id' => $original->student_id,
            'scheduled_date' => '2026-09-04',
            'scheduled_time' => '09:00:00',
            'source' => 'batch',
            'batch_request_id' => $secondChanceOver->id,
            'rescheduled_from_id' => $original->id,
        ]);

        $this->assertSame([
            $newest->reference_no,
            $middle->reference_no,
            $secondChanceOver->reference_no,
            $older->reference_no,
        ], $this->listed($this->completedPage($this->physician)));
    }

    public function test_only_approved_batches_are_ever_listed(): void
    {
        $approved = $this->batchOn('2026-09-01');

        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $this->makeBatch(status: $status)->update(['scheduled_date' => '2026-09-01', 'scheduled_time' => '09:00:00']);
        }

        $this->assertSame([$approved->reference_no], $this->listed($this->completedPage($this->nurse)));
    }

    public function test_the_card_keeps_its_search_and_college_dropdown(): void
    {
        $this->batchOn('2026-09-01');

        $this->completedPage($this->nurse)
            ->assertSee('data-card-filters="past"', false)
            ->assertSee('name="past_q"', false)
            ->assertSee('name="past_college"', false)
            ->assertSee('action="'.route('nurse.batch-results').'"', false)
            ->assertSeeInOrder(['All colleges', 'CCS', 'COE'], false);
    }

    public function test_the_batch_id_search_and_college_dropdown_filter_it(): void
    {
        $ccs = $this->batchOn('2026-09-01');
        $coe = $this->batchOn('2026-09-02', college: $this->coe);

        $this->assertSame([$ccs->reference_no], $this->listed($this->completedPage($this->nurse, ['past_q' => $ccs->reference_no])));
        $this->assertSame([$coe->reference_no], $this->listed($this->completedPage($this->nurse, ['past_college' => $this->coe->id])));

        $this->completedPage($this->nurse, ['past_q' => 'BR-1999'])
            ->assertSee('No batch here matches these filters.');
    }

    public function test_it_pages_at_ten_under_past_page(): void
    {
        foreach (range(1, 11) as $i) {
            $this->batchOn('2026-09-01', students: 0);
        }

        $response = $this->completedPage($this->nurse);
        $this->assertCount(10, $response->viewData('completedBatches'));
        $this->assertSame(11, $response->viewData('completedBatches')->total());
        $response->assertSee('past_page=2', false);

        $this->assertCount(1, $this->completedPage($this->nurse, ['past_page' => 2])->viewData('completedBatches'));
    }

    /** The tags and dots come from one query each, whatever the batch or roster size. */
    public function test_the_list_does_not_query_per_row_or_per_student(): void
    {
        $first = $this->batchOn('2026-09-01');
        $this->copiesRequest($first);

        $count = function (): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });
            $this->completedPage($this->nurse);

            return $queries;
        };

        $this->completedPage($this->nurse);   // warm up
        $withOne = $count();

        foreach (range(1, 3) as $i) {
            $batch = $this->batchOn('2026-09-02', students: 3);
            $this->copiesRequest($batch);
        }

        $this->assertSame($withOne, $count());
    }

    public function test_view_opens_the_results_page_whose_back_link_returns_here(): void
    {
        $batch = $this->batchOn('2026-09-01');

        $this->completedPage($this->nurse)
            ->assertSee(route('nurse.batches.results', ['batch' => $batch, 'from' => 'completed']), false);

        $this->openBatch($this->nurse, $batch)->assertViewHas('back', [
            'url' => route('nurse.batch-results'),
            'label' => 'Batch Results',
        ]);

        // Anything but the whitelisted value goes back to Upcoming Batches.
        foreach ([[], ['from' => 'https://evil.example'], ['from' => 'dashboard']] as $query) {
            $this->actingAs($this->nurse)->get(route('nurse.batches.results', ['batch' => $batch, ...$query]))
                ->assertViewHas('back', ['url' => route('nurse.batches.index'), 'label' => 'Upcoming Batches']);
        }
    }

    // ── Print Requested / Printed ────────────────────────────────────────────

    public function test_an_approved_copies_request_shows_print_requested(): void
    {
        $this->copiesRequest($this->completedBatch());

        $this->completedPage($this->nurse)
            ->assertSee('data-print-requested', false)
            ->assertSee('Print Requested')
            ->assertDontSee('data-copies-printed', false);
    }

    public function test_after_mark_as_printed_the_tag_reads_printed(): void
    {
        $batch = $this->completedBatch();
        $this->copiesRequest($batch);

        $this->markPrinted($this->nurse, $batch);

        $this->completedPage($this->physician)
            ->assertSee('data-copies-printed', false)
            ->assertSee('Printed')
            ->assertDontSee('Print Requested');
    }

    public function test_no_tag_without_an_approved_copies_request(): void
    {
        $this->release($this->completedBatch(), 'approved', ['wants_printed_copies' => false]);
        $this->release($this->completedBatch(), 'pending', ['wants_printed_copies' => true]);
        $this->release($this->completedBatch(), 'rejected', ['wants_printed_copies' => true]);
        $this->completedBatch();

        $this->completedPage($this->nurse)
            ->assertDontSee('data-print-requested', false)
            ->assertDontSee('data-copies-printed', false);
    }

    // ── Mark as printed ──────────────────────────────────────────────────────

    public function test_the_banner_shows_the_request_with_a_confirm_step(): void
    {
        $batch = $this->completedBatch();
        $this->copiesRequest($batch);

        $this->openBatch($this->nurse, $batch)
            ->assertSee('data-copies-banner="requested"', false)
            ->assertSee('The college asked for printed copies — approved by the Director on Sep 11, 2026.')
            ->assertSee('data-mark-printed', false)
            ->assertSee('data-mark-printed-confirm', false)
            ->assertSee('action="'.route('nurse.batches.copies-printed', ['batch' => $batch, 'from' => 'completed']).'"', false);

        // No copies asked for → no banner.
        $plain = $this->completedBatch();
        $this->release($plain, 'approved');
        $this->openBatch($this->nurse, $plain)->assertDontSee('data-copies-banner', false);
    }

    public function test_mark_as_printed_stamps_who_and_when(): void
    {
        $batch = $this->completedBatch();
        $request = $this->copiesRequest($batch);

        $this->markPrinted($this->physician, $batch)
            ->assertRedirect(route('nurse.batches.results', ['batch' => $batch, 'from' => 'completed']))
            ->assertSessionHas('status', 'Printed copies marked done.');

        $request->refresh();
        $this->assertSame($this->physician->id, $request->copies_printed_by);
        $this->assertTrue($request->copies_printed_at->equalTo(now()));

        $this->openBatch($this->nurse, $batch)
            ->assertSee('data-copies-banner="printed"', false)
            ->assertSee('Printed copies marked done by Rey Physician on Sep 11, 2026.')
            ->assertDontSee('data-mark-printed', false);
    }

    public function test_a_second_press_is_a_friendly_no_op(): void
    {
        $batch = $this->completedBatch();
        $request = $this->copiesRequest($batch);

        $this->markPrinted($this->nurse, $batch);
        $this->travel(5)->minutes();

        $this->markPrinted($this->physician, $batch)
            ->assertRedirect()
            ->assertSessionHas('status', 'The printed copies were already marked done.');

        $request->refresh();
        $this->assertSame($this->nurse->id, $request->copies_printed_by);
        $this->assertTrue($request->copies_printed_at->lt(now()));
    }

    public function test_it_is_refused_without_an_approved_copies_request(): void
    {
        $pending = $this->completedBatch();
        $this->release($pending, 'pending', ['wants_printed_copies' => true]);
        $noCopies = $this->completedBatch();
        $this->release($noCopies, 'approved', ['wants_printed_copies' => false]);
        $none = $this->completedBatch();

        foreach ([$pending, $noCopies, $none] as $batch) {
            $this->markPrinted($this->nurse, $batch)
                ->assertRedirect()
                ->assertSessionHas('error', 'This batch has no approved request for printed copies.');
        }

        $this->assertSame(0, DocumentReleaseRequest::whereNotNull('copies_printed_at')->count());
        $this->markPrinted($this->nurse, $this->makeBatch(status: 'pending'))->assertNotFound();
    }

    public function test_other_roles_cannot_mark_as_printed(): void
    {
        $batch = $this->completedBatch();
        $this->copiesRequest($batch);
        $student = $this->addStudent($this->makeBatch(), 'booked')->student;

        $this->post(route('nurse.batches.copies-printed', $batch))->assertRedirect(route('login'));

        foreach ([[$this->admin, '/admin/dashboard'], [$this->director, '/director/dashboard'], [$student, '/student/dashboard']] as [$user, $home]) {
            $this->markPrinted($user, $batch)->assertRedirect($home);
        }

        $this->assertSame(0, DocumentReleaseRequest::whereNotNull('copies_printed_at')->count());
    }

    // ── Row dots ─────────────────────────────────────────────────────────────

    public function test_the_nurse_gets_a_dot_when_the_director_approves_a_copies_request(): void
    {
        $batch = $this->completedBatch();
        $request = $this->release($batch, 'pending', ['wants_printed_copies' => true]);

        // Opened while the request was still pending: no dot.
        $this->openBatch($this->nurse, $batch);
        $this->assertFalse($this->dotFor($this->nurse, $batch));

        $this->travel(1)->minutes();
        $request->update(['status' => 'approved', 'reviewed_by' => $this->director->id, 'reviewed_at' => now()]);

        $this->assertTrue($this->dotFor($this->nurse, $batch));
        $this->completedPage($this->nurse)->assertSee('data-notify-dot', false);
    }

    public function test_view_clears_the_dot_per_user(): void
    {
        $batch = $this->completedBatch();
        $this->copiesRequest($batch);

        $this->assertTrue($this->dotFor($this->nurse, $batch));
        $this->assertTrue($this->dotFor($this->physician, $batch));

        $this->openBatch($this->nurse, $batch);

        $this->assertFalse($this->dotFor($this->nurse, $batch));
        // The physician has not opened it — their own dot stays.
        $this->assertTrue($this->dotFor($this->physician, $batch));
    }

    public function test_a_new_approval_on_another_batch_is_a_new_dot(): void
    {
        $first = $this->completedBatch();
        $this->copiesRequest($first);
        $this->openBatch($this->nurse, $first);

        $this->travel(1)->minutes();
        $second = $this->completedBatch();
        $this->copiesRequest($second);

        $this->assertFalse($this->dotFor($this->nurse, $first));
        $this->assertTrue($this->dotFor($this->nurse, $second));
    }

    /** Status changes, an approval without copies, a pending request — none is clinic news. No badge either (O4). */
    public function test_nothing_else_makes_a_clinic_dot_and_there_is_no_badge(): void
    {
        $noCopies = $this->completedBatch();
        $this->release($noCopies, 'approved', ['wants_printed_copies' => false]);
        $pending = $this->completedBatch();
        $this->release($pending, 'pending', ['wants_printed_copies' => true]);
        $plain = $this->completedBatch();   // never opened, status Completed

        $dots = $this->completedPage($this->nurse)->viewData('dots');
        foreach ([$noCopies, $pending, $plain] as $batch) {
            $this->assertFalse($dots[$batch->id]);
        }

        $this->copiesRequest($this->completedBatch());
        $this->assertSame(0, BatchNotifications::count($this->nurse));
        $this->assertSame(0, BatchNotifications::count($this->physician));
    }

    // ── Printing follows the release (O2) ────────────────────────────────────

    public function test_before_release_the_batch_pages_tools_are_disabled(): void
    {
        $batch = $this->completedBatch();
        $this->release($batch, 'pending');

        $this->openBatch($this->nurse, $batch)
            ->assertSee('data-document-tools="disabled"', false)
            ->assertSee('data-disabled-row-print', false)
            ->assertSee('data-disabled-row-pdf', false)
            ->assertDontSee('data-row-print', false)
            ->assertDontSee('data-row-pdf', false)
            ->assertSee('Available once the Director releases this batch’s documents.');
    }

    public function test_before_release_every_clinic_print_route_refuses_the_batch(): void
    {
        $batch = $this->makeBatch('assessment');
        $visit = $this->visitOf($this->addStudent($batch, 'Fit'));
        $other = $this->visitOf($this->addStudent($batch, 'Fit'));

        foreach (['pending', 'rejected'] as $status) {
            $this->release($batch, $status);
        }

        $this->assertRefused($visit, $other, $batch);
        $this->assertNull($visit->clearanceRecord->fresh()->printed_at);
    }

    public function test_after_approval_they_work(): void
    {
        $batch = $this->makeBatch('assessment');
        $visit = $this->visitOf($this->addStudent($batch, 'Fit'));
        $other = $this->visitOf($this->addStudent($batch, 'Fit'));
        $this->release($batch, 'approved');

        $this->openBatch($this->physician, $batch)
            ->assertSee('data-document-tools="enabled"', false)
            ->assertSee('data-row-print', false)
            ->assertDontSee('data-disabled-row-print', false);

        $this->actingAs($this->nurse)->get(route('nurse.visits.print', $visit))->assertOk()->assertSee('data-hp-print-doc', false);
        $this->actingAs($this->nurse)->post(route('nurse.visits.print.reprint', $visit), ['side' => 'front'])->assertOk();
        $this->assertNotNull($visit->clearanceRecord->fresh()->printed_at);
        $this->actingAs($this->nurse)->get(route('nurse.visits.pdf', $visit))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->nurse)->post(route('nurse.batches.documents', $batch), ['visits' => [$visit->id, $other->id]])
            ->assertOk()->assertHeader('Content-Type', 'application/zip');
    }

    /** A legacy visit with no batch behind it (pre-D-61) is never released. */
    public function test_a_visit_with_no_batch_is_refused(): void
    {
        $visit = $this->visitOf($this->addStudent($this->makeBatch(), 'Fit'));
        $visit->update(['appointment_id' => null]);

        $this->assertFalse($visit->fresh()->isReleasedForPrint());
        $this->actingAs($this->nurse)->get(route('nurse.visits.pdf', $visit))->assertRedirect();
        $this->actingAs($this->nurse)->getJson(route('nurse.visits.print', $visit))->assertForbidden();
    }

    /** The read-only encode page: greyed-out buttons with the hint, and a refused PDF comes back with a message — never a 500. */
    public function test_the_encode_page_follows_the_same_rule(): void
    {
        $batch = $this->makeBatch();
        $visit = $this->visitOf($this->addStudent($batch, 'Fit'));
        $encodePage = route('nurse.visits.encode', $visit);

        $this->actingAs($this->nurse)->get($encodePage)
            ->assertOk()
            ->assertSee('data-print-disabled', false)
            ->assertSee('Available once the Director releases this batch’s documents.')
            ->assertDontSee(route('nurse.visits.pdf', $visit), false);

        $this->actingAs($this->nurse)->from($encodePage)->get(route('nurse.visits.pdf', $visit))
            ->assertRedirect($encodePage)
            ->assertSessionHas('error', ClinicVisit::NOT_RELEASED_HINT);
        $this->actingAs($this->nurse)->get($encodePage)->assertSee('data-flash-sticky', false);

        $this->release($batch, 'approved');

        $this->actingAs($this->nurse)->get($encodePage)
            ->assertOk()
            ->assertDontSee('data-print-disabled', false)
            ->assertSee(route('nurse.visits.pdf', $visit), false);
    }

    private function assertRefused(ClinicVisit $visit, ClinicVisit $other, BatchRequest $batch): void
    {
        $this->actingAs($this->nurse)->get(route('nurse.visits.print', $visit))
            ->assertRedirect()->assertSessionHas('error', ClinicVisit::NOT_RELEASED_HINT);
        $this->actingAs($this->nurse)->post(route('nurse.visits.print.reprint', $visit), ['side' => 'front'])
            ->assertRedirect()->assertSessionHas('error', ClinicVisit::NOT_RELEASED_HINT);
        $this->actingAs($this->physician)->get(route('nurse.visits.pdf', $visit))
            ->assertRedirect()->assertSessionHas('error', ClinicVisit::NOT_RELEASED_HINT);
        $this->actingAs($this->nurse)->getJson(route('nurse.visits.pdf', $visit))->assertForbidden();

        $this->actingAs($this->nurse)->post(route('nurse.batches.documents', $batch), ['visits' => [$visit->id, $other->id]])
            ->assertForbidden();
        $this->actingAs($this->nurse)->post(route('nurse.batches.documents', $batch), ['visits' => [$visit->id]])
            ->assertForbidden();
    }
}
