<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\BatchRequest;
use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Director Batch Approvals — the queue (FR-DIRA-01/05, D-108): every college's
 * PENDING requests, oldest first, the oldest highlighted as Next like the
 * clinic's Live Queue, and a Review button per row. Decided batches leave.
 */
class BatchApprovalsPageTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $cea;

    private User $director;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->cea = College::create(['code' => 'CEA', 'name' => 'College of Engineering and Architecture']);

        $this->director = User::factory()->create(['role' => 'director']);
        $this->admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
    }

    /** A batch submitted $daysAgo days ago (the queue orders on created_at). */
    private function makeBatch(College $college, int $daysAgo = 0, array $overrides = []): BatchRequest
    {
        static $seq = 500;

        $batch = BatchRequest::create(array_merge([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $college->id,
            'requested_by' => $this->admin->id,
            'form_type' => 'assessment',   // D-62: 'ojt' is an Assessment purpose
            'reason' => 'ojt',
            'service_type' => 'medical',
            'status' => 'pending',
        ], $overrides));

        $batch->created_at = now()->subDays($daysAgo);
        $batch->save();

        return $batch;
    }

    private function queue(string $query = ''): TestResponse
    {
        return $this->actingAs($this->director)->get('/director/batches'.$query)->assertOk();
    }

    /**
     * The table's rows as HTML, in order — so a test can ask which row
     * carries the Next marker without matching the page's own CSS.
     *
     * @return list<string>
     */
    private function rows(TestResponse $response): array
    {
        preg_match('/<tbody>(.*?)<\/tbody>/s', $response->getContent(), $body);
        preg_match_all('/<tr\b[^>]*>.*?<\/tr>/s', $body[1] ?? '', $rows);

        return $rows[0];
    }

    /** @return list<string> the rows holding the Next marker */
    private function nextRows(TestResponse $response): array
    {
        return array_values(array_filter($this->rows($response), fn (string $row) => str_contains($row, 'data-next')));
    }

    public function test_only_pending_requests_are_listed_oldest_first(): void
    {
        $newer = $this->makeBatch($this->ccs, daysAgo: 1);
        $oldest = $this->makeBatch($this->cea, daysAgo: 3);
        $approved = $this->makeBatch($this->ccs, daysAgo: 5, overrides: ['status' => 'approved']);
        $rejected = $this->makeBatch($this->ccs, daysAgo: 5, overrides: ['status' => 'rejected']);
        $cancelled = $this->makeBatch($this->ccs, daysAgo: 5, overrides: ['status' => 'cancelled']);

        $this->queue()
            ->assertSeeInOrder([$oldest->reference_no, $newer->reference_no])
            ->assertDontSee($approved->reference_no)
            ->assertDontSee($rejected->reference_no)
            ->assertDontSee($cancelled->reference_no);
    }

    public function test_a_same_second_tie_goes_to_the_lower_id(): void
    {
        $first = $this->makeBatch($this->ccs, daysAgo: 2);
        $second = $this->makeBatch($this->cea, daysAgo: 2);
        $second->created_at = $first->created_at;
        $second->save();

        $this->queue()->assertSeeInOrder([$first->reference_no, $second->reference_no]);
    }

    public function test_the_first_row_is_marked_next(): void
    {
        $this->makeBatch($this->ccs, daysAgo: 1);
        $oldest = $this->makeBatch($this->cea, daysAgo: 4);

        $response = $this->queue();
        $next = $this->nextRows($response);

        $this->assertCount(1, $next);
        $this->assertStringContainsString($oldest->reference_no, $next[0]);
        $this->assertStringContainsString('Next', $next[0]);
        $this->assertSame($next[0], $this->rows($response)[0]);
    }

    public function test_only_the_first_row_of_page_one_is_next(): void
    {
        foreach (range(1, 12) as $i) {
            $this->makeBatch($i % 2 ? $this->ccs : $this->cea, daysAgo: 20 - $i);
        }

        $this->assertCount(1, $this->nextRows($this->queue()));
        $this->assertCount(0, $this->nextRows($this->queue('?page=2')));
    }

    /** The highlight belongs to the oldest in the whole queue, not in a search. */
    public function test_a_search_that_skips_the_oldest_shows_no_next(): void
    {
        $this->makeBatch($this->ccs, daysAgo: 5, overrides: ['reference_no' => 'BR-2026-111']);
        $this->makeBatch($this->cea, daysAgo: 1, overrides: ['reference_no' => 'BR-2026-777']);

        $response = $this->queue('?q=777');

        $response->assertSee('BR-2026-777')->assertDontSee('BR-2026-111');
        $this->assertCount(0, $this->nextRows($response));
    }

    public function test_the_queue_pages_at_ten_and_keeps_its_order_across_pages(): void
    {
        $batches = collect(range(1, 12))->map(fn (int $i) => $this->makeBatch($this->ccs, daysAgo: 30 - $i));

        $this->queue()->assertSee('Showing 1&ndash;10 of 12', false)
            ->assertSee($batches[9]->reference_no)
            ->assertDontSee($batches[10]->reference_no);

        $this->queue('?page=2')
            ->assertSeeInOrder([$batches[10]->reference_no, $batches[11]->reference_no])
            ->assertDontSee($batches[0]->reference_no);
    }

    public function test_the_columns_are_batch_id_college_form_type_purpose(): void
    {
        $this->makeBatch($this->ccs);

        $this->queue()
            ->assertSeeInOrder(['Batch ID', 'College', 'Form Type', 'Purpose'])
            ->assertSee('Medical Assessment Form')
            ->assertSee('On-the-job Training')
            ->assertDontSee('Requested Date')
            ->assertDontSee('Clinic Hours')
            ->assertDontSee('>Submitted<', false)
            ->assertDontSee('>Students<', false);
    }

    public function test_every_row_links_to_its_review_page(): void
    {
        $batch = $this->makeBatch($this->ccs);

        $this->queue()->assertSee(route('director.batches.review', $batch));
    }

    public function test_phones_get_one_card_per_request_with_everything_its_row_shows(): void
    {
        // Below md the table is hidden and each request is a stacked card, so a
        // phone never has to scroll sideways to reach Purpose or Review.
        $oldest = $this->makeBatch($this->ccs, 1);
        $newer = $this->makeBatch($this->ccs);

        $html = $this->queue()->getContent();

        preg_match('/<ul[^>]*data-queue-cards[^>]*>(.*?)<\/ul>/s', $html, $list);
        preg_match_all('/<li\b[^>]*>.*?<\/li>/s', $list[1] ?? '', $cards);
        $this->assertCount(2, $cards[0]);
        $this->assertStringContainsString('md:hidden', $list[0]);

        foreach ([$oldest, $newer] as $i => $batch) {
            $card = $cards[0][$i];
            foreach ([$batch->reference_no, 'College', 'CCS', 'Form Type', 'Medical Assessment Form', 'Purpose', 'On-the-job Training', route('director.batches.review', $batch)] as $text) {
                $this->assertStringContainsString($text, $card);
            }
        }

        // The Next request is marked on its card, and only there.
        $this->assertStringContainsString('data-next-card', $cards[0][0]);
        $this->assertStringContainsString('Next', $cards[0][0]);
        $this->assertStringNotContainsString('data-next-card', $cards[0][1]);

        // The table shows from md up, inside a `relative` scroll box — that is
        // what keeps its screen-reader-only header label from widening the page.
        $this->assertMatchesRegularExpression('/<div class="relative hidden overflow-x-auto md:block">\s*(\{\{--.*?--\}\}\s*)?<table/s', $html);
    }

    public function test_no_decision_controls_live_on_the_queue(): void
    {
        $batch = $this->makeBatch($this->ccs);

        $this->queue()
            ->assertDontSee(route('director.batches.approve', $batch))
            ->assertDontSee(route('director.batches.reject', $batch));
    }

    public function test_the_batch_id_search_filters_on_the_server(): void
    {
        $this->makeBatch($this->ccs, overrides: ['reference_no' => 'BR-2026-111']);
        $this->makeBatch($this->cea, overrides: ['reference_no' => 'BR-2026-222']);

        $this->queue('?q=222')->assertSee('BR-2026-222')->assertDontSee('BR-2026-111');
        $this->queue('?q=999')->assertSee('No waiting batch request matches');
    }

    public function test_an_empty_queue_says_so(): void
    {
        $this->makeBatch($this->ccs, overrides: ['status' => 'approved']);

        $this->queue()->assertSee('No batch requests are waiting.');
    }

    public function test_the_queue_is_refused_for_other_roles_and_guests(): void
    {
        foreach (['student', 'nurse', 'physician', 'college_admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/director/batches')->assertRedirect();
        }

        auth()->logout();
        $this->get('/director/batches')->assertRedirect('/login');
    }
}
