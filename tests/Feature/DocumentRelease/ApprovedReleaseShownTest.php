<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentRelease;

use App\Models\BatchRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DocumentReleaseFixture;
use Tests\TestCase;

/**
 * D-124: an approved release is shown. The Director's results page swaps the
 * "Review request of documents" button for "Documents released on …", and
 * both Batch Results cards (the Director's Batch Tracking and the College
 * Admin's) tag the batch "Approved Release" — permanently, and never together
 * with "Release requested".
 */
class ApprovedReleaseShownTest extends TestCase
{
    use DocumentReleaseFixture;
    use RefreshDatabase;

    /** The `disabled` attribute — not the `disabled:` Tailwind variants in the class list. */
    private const DISABLED_ATTRIBUTE = '~\sdisabled(="disabled")?[\s>]~';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRelease();
    }

    private function directorPage(BatchRequest $batch): string
    {
        return $this->actingAs($this->director)->get(route('director.tracking.results', $batch))->assertOk()->getContent();
    }

    private function directorCard(): string
    {
        return $this->actingAs($this->director)->get(route('director.tracking.index'))->assertOk()->getContent();
    }

    private function adminCard(): string
    {
        return $this->actingAs($this->admin)->get(route('admin.batches.index'))->assertOk()->getContent();
    }

    /** The opening tag of the "Review request of documents" button. */
    private function reviewButton(string $html): string
    {
        $this->assertMatchesRegularExpression('~<button[^>]*data-release-review-open[^>]*>~', $html);
        preg_match('~<button[^>]*data-release-review-open[^>]*>~', $html, $match);

        return $match[0];
    }

    /** The table row (its <tr>) that holds $batch's reference number. */
    private function rowOf(string $html, BatchRequest $batch): string
    {
        preg_match_all('~<tr\b.*?</tr>~s', $html, $rows);

        foreach ($rows[0] as $row) {
            if (str_contains($row, $batch->reference_no)) {
                return $row;
            }
        }

        $this->fail("No row for {$batch->reference_no}.");
    }

    // ── The Director's results page ──────────────────────────────────────────

    public function test_an_approved_release_replaces_the_review_button_with_a_dated_note(): void
    {
        $batch = $this->completedBatch();
        $this->release($batch, 'approved');

        $html = $this->directorPage($batch);

        $this->assertStringNotContainsString('data-release-review-open', $html);
        $this->assertStringNotContainsString('Review request of documents', $html);
        $this->assertStringNotContainsString('No release request from the college.', $html);
        $this->assertStringNotContainsString('data-release-review-dialog', $html);
        $this->assertStringContainsString('data-release-state="approved"', $html);
        $this->assertStringContainsString('Documents released on Sep 11, 2026.', $html);
        $this->assertStringNotContainsString('Printed copies requested.', $html);
        // The history stays under the note.
        $this->assertStringContainsString('data-release-history', $html);
        $this->assertStringContainsString('Approved Sep 11, 2026', $html);
    }

    public function test_the_note_says_printed_copies_were_requested_when_they_were(): void
    {
        $batch = $this->completedBatch();
        $this->release($batch, 'approved', ['wants_printed_copies' => true]);

        $this->assertStringContainsString(
            'Printed copies requested.',
            $this->directorPage($batch),
        );
    }

    public function test_a_pending_request_still_enables_the_review_button(): void
    {
        $batch = $this->completedBatch();
        $this->release($batch, 'pending');

        $html = $this->directorPage($batch);

        $this->assertDoesNotMatchRegularExpression(self::DISABLED_ATTRIBUTE, $this->reviewButton($html));
        $this->assertStringContainsString('data-release-review-dialog', $html);
        $this->assertStringNotContainsString('Documents released on', $html);
    }

    public function test_a_rejected_request_leaves_the_review_button_disabled(): void
    {
        $batch = $this->completedBatch();
        $this->release($batch, 'rejected');

        $html = $this->directorPage($batch);

        $this->assertMatchesRegularExpression(self::DISABLED_ATTRIBUTE, $this->reviewButton($html));
        $this->assertStringContainsString('No release request from the college.', $html);
        $this->assertStringNotContainsString('Documents released on', $html);
    }

    // ── The Batch Results cards ──────────────────────────────────────────────

    public function test_the_directors_card_tags_each_batch_by_its_release(): void
    {
        $approved = $this->completedBatch();
        $this->release($approved, 'rejected');
        $this->release($approved, 'approved');
        $pending = $this->completedBatch();
        $this->release($pending, 'pending');
        $rejected = $this->completedBatch();
        $this->release($rejected, 'rejected');
        $none = $this->completedBatch();

        $html = $this->directorCard();

        $row = $this->rowOf($html, $approved);
        $this->assertStringContainsString('Approved Release', $row);
        $this->assertStringNotContainsString('Release requested', $row);

        $row = $this->rowOf($html, $pending);
        $this->assertStringContainsString('Release requested', $row);
        $this->assertStringNotContainsString('Approved Release', $row);

        foreach ([$rejected, $none] as $batch) {
            $row = $this->rowOf($html, $batch);
            $this->assertStringNotContainsString('Approved Release', $row);
            $this->assertStringNotContainsString('Release requested', $row);
        }
    }

    public function test_the_college_admins_card_tags_an_approved_release(): void
    {
        $approved = $this->completedBatch();
        $this->release($approved, 'approved');
        $pending = $this->completedBatch();
        $this->release($pending, 'pending');
        $rejected = $this->completedBatch();
        $this->release($rejected, 'rejected');
        $none = $this->completedBatch();

        $html = $this->adminCard();

        $row = $this->rowOf($html, $approved);
        $this->assertStringContainsString('Approved Release', $row);
        $this->assertStringNotContainsString('Release requested', $row);

        foreach ([$pending, $rejected, $none] as $batch) {
            $row = $this->rowOf($html, $batch);
            $this->assertStringNotContainsString('Approved Release', $row);
            $this->assertStringNotContainsString('Release requested', $row);
        }
    }

    public function test_neither_card_queries_per_row(): void
    {
        foreach (['directorCard', 'adminCard'] as $page) {
            $count = function () use ($page): int {
                $queries = 0;
                DB::listen(function () use (&$queries): void {
                    $queries++;
                });
                $this->{$page}();

                return $queries;
            };

            $batch = $this->completedBatch(1);
            $this->release($batch, 'approved');
            // Warm up: the first request also stamps last_active_at.
            $this->{$page}();
            $withOne = $count();

            foreach (range(1, 3) as $i) {
                $more = $this->completedBatch(1);
                $this->release($more, $i % 2 === 0 ? 'approved' : 'pending');
            }

            $this->assertSame($withOne, $count(), "{$page} grew with the rows.");
        }
    }
}
