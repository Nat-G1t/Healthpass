<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BatchRequest;
use App\Models\College;
use App\Support\BatchNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\DocumentReleaseFixture;
use Tests\TestCase;

/**
 * D-131 — on every table with notification dots (D-120, D-125), Previous /
 * Next carry the same dot when an earlier / later page holds a row with one,
 * under the card's current filters.
 *
 * Every card here orders its rows newest first with `id` breaking ties, and
 * the fixture's batches share a clinic day and a decision time — so the batch
 * made FIRST sits on the LAST page.
 */
class PagerDotsTest extends TestCase
{
    use DocumentReleaseFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRelease();
    }

    /** Which of the page's pager buttons carry a dot. */
    private function pagerDotsIn(string $html): array
    {
        return [
            'previous' => str_contains($html, 'data-pager-dot="previous"'),
            'next' => str_contains($html, 'data-pager-dot="next"'),
        ];
    }

    /**
     * $count approved batches, each already opened by $viewers at the status
     * it shows — so none carries a dot until a test gives one news.
     *
     * @return list<BatchRequest>
     */
    private function seenBatches(int $count, array $viewers, ?College $college = null): array
    {
        $batches = [];

        for ($i = 0; $i < $count; $i++) {
            $batch = $this->makeBatch(college: $college);

            foreach ($viewers as $viewer) {
                BatchNotifications::markSeen($viewer, $batch, $batch->resultsStatus());
            }

            $batches[] = $batch;
        }

        // News must come strictly after the last look.
        $this->travel(1)->minutes();

        return $batches;
    }

    private function directorResults(array $query = []): array
    {
        $html = $this->actingAs($this->director)
            ->get(route('director.tracking.index', $query))
            ->assertOk()
            ->getContent();

        return $this->pagerDotsIn($html);
    }

    // ── The component ───────────────────────────────────────────────────────

    public function test_the_pager_draws_a_dot_and_hidden_text_on_each_button_asked_for(): void
    {
        $page = new LengthAwarePaginator(range(1, 10), 30, 10, 2);

        $view = $this->blade('<x-hp.pager :paginator="$page" :previous-dot="true" :next-dot="true" />', ['page' => $page]);

        $view->assertSee('data-pager-dot="previous"', false)
            ->assertSee('data-pager-dot="next"', false);
        $this->assertSame(2, substr_count((string) $view, '<span class="sr-only"> — has updates</span>'));
    }

    public function test_the_pager_draws_no_dot_by_default(): void
    {
        $page = new LengthAwarePaginator(range(1, 10), 30, 10, 2);

        $view = $this->blade('<x-hp.pager :paginator="$page" />', ['page' => $page]);

        $view->assertDontSee('data-pager-dot', false)->assertDontSee('has updates');
    }

    public function test_a_disabled_previous_or_next_never_draws_a_dot(): void
    {
        $first = new LengthAwarePaginator(range(1, 10), 20, 10, 1);
        $last = new LengthAwarePaginator(range(1, 10), 20, 10, 2);
        $blade = '<x-hp.pager :paginator="$page" :previous-dot="true" :next-dot="true" />';

        $this->blade($blade, ['page' => $first])
            ->assertDontSee('data-pager-dot="previous"', false)
            ->assertSee('data-pager-dot="next"', false);
        $this->blade($blade, ['page' => $last])
            ->assertSee('data-pager-dot="previous"', false)
            ->assertDontSee('data-pager-dot="next"', false);
    }

    // ── Director: Batch Results ─────────────────────────────────────────────

    public function test_next_points_at_a_dot_on_any_later_page_and_not_at_one_on_this_page(): void
    {
        // 25 batches: page 1 = the newest ten, page 3 = the five made first.
        $batches = $this->seenBatches(25, [$this->director]);
        $this->release($batches[0]);   // the college asks for its documents — a Director dot, page 3

        $this->assertSame(['previous' => false, 'next' => true], $this->directorResults());
        $this->assertSame(['previous' => false, 'next' => true], $this->directorResults(['results_page' => 2]));
        $this->assertSame(['previous' => false, 'next' => false], $this->directorResults(['results_page' => 3]));
    }

    public function test_previous_points_at_a_dot_on_an_earlier_page(): void
    {
        $batches = $this->seenBatches(11, [$this->director]);
        $this->release($batches[10]);  // the newest — page 1

        $this->assertSame(['previous' => true, 'next' => false], $this->directorResults(['results_page' => 2]));
    }

    public function test_a_dot_on_a_batch_the_filter_hides_puts_no_dot_on_the_pager(): void
    {
        // The COE batch is made first, so unfiltered it sits on page 2.
        [$hidden] = $this->seenBatches(1, [$this->director], $this->coe);
        $this->seenBatches(20, [$this->director]);
        $this->release($hidden);

        $this->assertTrue($this->directorResults()['next']);
        $this->assertSame(
            ['previous' => false, 'next' => false],
            $this->directorResults(['results_college' => $this->ccs->id]),
        );
    }

    public function test_a_status_filtered_card_reads_its_own_matching_list(): void
    {
        // D-130's status filter pages in PHP; the pager dots read that list.
        $batches = $this->seenBatches(11, [$this->director]);
        $this->release($batches[0]);
        $status = $batches[0]->resultsStatus();

        $this->assertSame(['previous' => false, 'next' => true], $this->directorResults(['results_status' => $status]));
    }

    public function test_opening_the_batch_clears_the_pager_dot_too(): void
    {
        $batches = $this->seenBatches(11, [$this->director]);
        $this->release($batches[0]);
        $this->assertTrue($this->directorResults()['next']);

        $this->actingAs($this->director)->get(route('director.tracking.results', $batches[0]))->assertOk();

        $this->assertFalse($this->directorResults()['next']);
    }

    // ── Director: Rejected and Cancelled ────────────────────────────────────

    public function test_a_college_cancellation_on_the_next_page_dots_next(): void
    {
        // Made first, so it is the oldest decision: page 2. Rejections are the
        // Director's own act — no dot.
        $this->makeBatch(status: 'cancelled');
        for ($i = 0; $i < 10; $i++) {
            $this->makeBatch(status: 'rejected');
        }

        $this->assertSame(['previous' => false, 'next' => true], $this->directorResults());
    }

    // ── College Admin ───────────────────────────────────────────────────────

    public function test_the_admins_batch_results_card_dots_next_for_a_decided_release_on_page_2(): void
    {
        $batches = $this->seenBatches(11, [$this->admin]);
        $this->release($batches[0], 'approved');   // the Director's answer — news to the college

        $html = $this->actingAs($this->admin)->get(route('admin.batches.index'))->assertOk()->getContent();

        // Both cards page these 11 batches; only Batch Results dots them.
        $this->assertSame(1, substr_count($html, 'data-pager-dot="next"'));
        $this->assertStringNotContainsString('data-pager-dot="previous"', $html);
    }

    public function test_the_admins_batch_requests_card_dots_next_for_a_rejection_on_page_2(): void
    {
        $this->makeBatch(status: 'rejected');   // its reason not opened yet — a dot, page 2
        for ($i = 0; $i < 10; $i++) {
            $this->makeBatch(status: 'pending');
        }

        $html = $this->actingAs($this->admin)->get(route('admin.batches.index'))->assertOk()->getContent();

        $this->assertSame(['previous' => false, 'next' => true], $this->pagerDotsIn($html));
    }

    public function test_the_admins_batch_requests_card_ignores_an_approved_batchs_news(): void
    {
        // An approved batch with news on page 2 of Batch requests: its dot
        // belongs on the Batch Results card (one page here), not this pager.
        [$approved] = $this->seenBatches(1, [$this->admin]);
        $this->release($approved, 'approved');
        for ($i = 0; $i < 10; $i++) {
            $this->makeBatch(status: 'pending');
        }

        $html = $this->actingAs($this->admin)->get(route('admin.batches.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-notify-dot', $html);   // the Batch Results row
        $this->assertStringNotContainsString('data-pager-dot', $html);
    }

    // ── Clinic: Completed batches ───────────────────────────────────────────

    public function test_the_clinics_completed_card_dots_next_for_a_print_request_on_page_2(): void
    {
        $batches = $this->seenBatches(11, [$this->nurse]);
        $this->release($batches[0], 'approved', ['wants_printed_copies' => true]);

        $html = $this->actingAs($this->nurse)->get(route('nurse.batch-results'))->assertOk()->getContent();

        $this->assertSame(['previous' => false, 'next' => true], $this->pagerDotsIn($html));
    }

    // ── Cost ────────────────────────────────────────────────────────────────

    public function test_a_cards_pager_dots_cost_a_fixed_number_of_queries(): void
    {
        // The day before the clinic day, never opened: every batch's status
        // must be worked out from its roster — the most expensive path.
        $this->travelTo(Carbon::parse('2026-09-09 10:00:00'));
        for ($i = 0; $i < 25; $i++) {
            $this->addStudent($this->makeBatch(), 'Fit');
        }

        $query = BatchRequest::where('status', 'approved')->orderByDesc('scheduled_date')->orderByDesc('id');
        $page = $query->clone()->paginate(10);
        $director = $this->director->fresh();

        DB::flushQueryLog();
        DB::enableQueryLog();
        BatchNotifications::pagerDots($director, $query, $page);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // ids, batches, cancellers, seen rows, release times, and the unsure
        // batches + statusRelations()' four — never one per batch.
        $this->assertLessThanOrEqual(10, $queries);
    }
}
