<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentRelease;

use App\Support\NavBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\DocumentReleaseFixture;
use Tests\TestCase;

/**
 * D-113 on the pages: the College Admin's results page (the release button's
 * states, and the select-and-download tools greyed out until the Director
 * releases the batch), the Director's results page (tools always on, "Review
 * request of documents", the history), Batch Tracking's "Release requested"
 * tag and the sidebar badge (Q18).
 */
class ReleasePagesTest extends TestCase
{
    use DocumentReleaseFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRelease();
    }

    private function adminPage(int $batchId)
    {
        return $this->actingAs($this->admin)->get(route('admin.batches.results', $batchId))->assertOk();
    }

    private function directorPage(int $batchId)
    {
        return $this->actingAs($this->director)->get(route('director.tracking.results', $batchId))->assertOk();
    }

    // ── College Admin ────────────────────────────────────────────────────────

    public function test_before_release_the_admins_tools_are_drawn_but_disabled(): void
    {
        $batch = $this->completedBatch();

        $this->adminPage($batch->id)
            ->assertSee('data-document-tools="disabled"', false)
            ->assertSee('Available after the Director releases the documents.')
            ->assertSee('Select students')
            ->assertSee('Select all')
            ->assertSee('Download documents (0)')
            ->assertSee('action="'.route('admin.batches.documents', $batch->id).'"', false)
            // The batch is Completed and nobody asked yet: the request button works.
            ->assertSee('data-release-state="ready"', false)
            ->assertSee('Request release of documents')
            ->assertSee('Send request')
            // D-123: no attachment — a "printed copies" box, unticked.
            ->assertDontSee('name="attachment"', false)
            ->assertDontSee('multipart/form-data', false)
            ->assertSee('The college needs printed copies of the forms')
            ->assertSee('If the Director approves, the clinic is asked to print them.')
            ->assertSee('name="wants_printed_copies"', false)
            ->assertDontSee('name="wants_printed_copies" value="1" checked', false);
    }

    public function test_a_batch_that_is_not_finished_cannot_be_asked_for_yet(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit');
        $this->addStudent($batch, 'booked');
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 11:00:00'));   // In progress

        $this->adminPage($batch->id)
            ->assertSee('data-release-state="not-ready"', false)
            ->assertSee('Available when the batch is completed.')
            ->assertDontSee('Send request');   // no popup at all
    }

    public function test_a_pending_request_is_waiting_for_the_director(): void
    {
        $batch = $this->completedBatch();
        $this->release($batch, 'pending', ['created_at' => Carbon::parse('2026-09-11 08:00:00')]);

        $this->adminPage($batch->id)
            ->assertSee('data-release-state="pending"', false)
            ->assertSee('Release requested on Sep 11, 2026 — waiting for the Director.')
            ->assertDontSee('Printed copies requested.')
            ->assertSee('data-document-tools="disabled"', false)
            ->assertDontSee('Send request');
    }

    public function test_a_pending_request_for_printed_copies_says_so(): void
    {
        $batch = $this->completedBatch();
        $this->release($batch, 'pending', ['wants_printed_copies' => true]);

        $this->adminPage($batch->id)
            ->assertSee('waiting for the Director.')
            ->assertSee('Printed copies requested.');
    }

    public function test_a_rejected_request_shows_the_message_and_request_again(): void
    {
        $batch = $this->completedBatch();
        $this->release($batch, 'rejected');

        $this->adminPage($batch->id)
            ->assertSee('Not released:')
            ->assertSee('Please attach the memo signed by the dean.')
            ->assertSee('Request again')
            ->assertSee('data-release-state="ready"', false)
            ->assertSee('data-document-tools="disabled"', false);
    }

    public function test_after_release_the_tools_work_and_the_button_is_gone(): void
    {
        $batch = $this->completedBatch();
        $this->release($batch, 'approved');

        $this->adminPage($batch->id)
            ->assertSee('Documents released by the Director on Sep 11, 2026.')
            ->assertSee('data-document-tools="enabled"', false)
            ->assertDontSee('Available after the Director releases the documents.')
            ->assertDontSee('data-release-request', false)
            ->assertDontSee('Send request');
    }

    public function test_only_encoded_rows_carry_a_visit_id_for_select_mode(): void
    {
        $batch = $this->makeBatch();
        $fit = $this->visitOf($this->addStudent($batch, 'Fit', 'Ana Abad'));
        $unfit = $this->visitOf($this->addStudent($batch, 'Unfit', 'Ben Bueno'));
        $this->addStudent($batch, 'in_clinic', 'Cara Cruz');
        $this->addStudent($batch, 'resting', 'Dan Diaz');
        $this->addStudent($batch, 'booked', 'Eva Estrada');

        $rows = collect($this->adminPage($batch->id)->viewData('results')['rows'])->keyBy('name');

        $this->assertSame($fit->id, $rows['Ana Abad']['visit_id']);
        $this->assertSame($unfit->id, $rows['Ben Bueno']['visit_id']);
        $this->assertNull($rows['Cara Cruz']['visit_id']);
        $this->assertNull($rows['Dan Diaz']['visit_id']);
        $this->assertNull($rows['Eva Estrada']['visit_id']);
    }

    public function test_the_page_still_does_not_query_per_student(): void
    {
        $batch = $this->completedBatch(1);
        $this->release($batch, 'approved');

        $count = function () use ($batch): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });
            $this->adminPage($batch->id);

            return $queries;
        };

        // Warm up first: the first request also stamps last_active_at and
        // caches a lookup, which later requests don't repeat.
        $this->adminPage($batch->id);
        $withOne = $count();
        foreach (range(1, 4) as $i) {
            $this->addStudent($batch, 'Fit');
        }

        $this->assertSame($withOne, $count());
    }

    // ── Director ─────────────────────────────────────────────────────────────

    public function test_the_directors_tools_are_always_on_and_review_waits_for_a_request(): void
    {
        $batch = $this->completedBatch();

        $this->directorPage($batch->id)
            ->assertSee('data-document-tools="enabled"', false)
            ->assertSee('action="'.route('director.tracking.documents', $batch).'"', false)
            ->assertSee('Review request of documents')
            ->assertSee('No release request from the college.')
            ->assertDontSee('data-release-review-dialog', false);
    }

    public function test_the_review_popup_shows_who_asked_the_reason_and_the_batch_memo(): void
    {
        $batch = $this->withMemo($this->completedBatch());
        $release = $this->release($batch, 'pending', [
            'reason' => 'For the <i>OJT</i> partners.',
            'wants_printed_copies' => true,
            'created_at' => Carbon::parse('2026-09-11 08:15:00'),
        ]);

        $this->directorPage($batch->id)
            ->assertSee('Carla Admin asked on Sep 11, 2026 · 8:15 AM.')
            ->assertSee('data-release-review-dialog', false)
            ->assertSee('Release request for '.$batch->reference_no)
            ->assertSee('(CCS College Admin)')
            // The college's words, escaped.
            ->assertSee('For the &lt;i&gt;OJT&lt;/i&gt; partners.', false)
            ->assertDontSee('<i>OJT</i>', false)
            ->assertSee('Printed copies: Yes')
            // D-123: the batch's signed memo — a thumbnail opening the shared viewer.
            ->assertSee('data-release-memo-thumbnail', false)
            ->assertSee('src="'.route('director.batches.memo', $batch).'"', false)
            ->assertSee('data-file-viewer', false)
            ->assertDontSee(route('director.release-requests.attachment', $release))
            ->assertSee('action="'.route('director.release-requests.approve', $release).'"', false)
            ->assertSee('action="'.route('director.release-requests.reject', $release).'"', false)
            ->assertSee('Message to the college');
    }

    public function test_a_request_without_printed_copies_says_no(): void
    {
        $batch = $this->withMemo($this->completedBatch());
        $this->release($batch);

        $this->directorPage($batch->id)->assertSee('Printed copies: No');
    }

    public function test_a_pdf_memo_shows_as_a_tile(): void
    {
        $batch = $this->withMemo($this->completedBatch(), 'application/pdf', 'Request memo.pdf');
        $this->release($batch);

        $this->directorPage($batch->id)
            ->assertSee('data-release-memo-pdf-tile', false)
            ->assertDontSee('data-release-memo-thumbnail', false)
            ->assertSee('Request memo.pdf');
    }

    public function test_a_batch_without_a_memo_says_so(): void
    {
        $batch = $this->completedBatch();
        $this->release($batch);

        $this->directorPage($batch->id)
            ->assertSee('No memo on file — submitted before memos were required.')
            ->assertDontSee('data-release-memo-thumbnail', false)
            ->assertDontSee('data-release-memo-pdf-tile', false);
    }

    public function test_the_history_lists_every_request_with_its_outcome(): void
    {
        $batch = $this->completedBatch();
        $this->release($batch, 'rejected', ['created_at' => Carbon::parse('2026-09-11 07:00:00')]);
        $this->release($batch, 'approved', ['created_at' => Carbon::parse('2026-09-11 09:00:00')]);

        $this->directorPage($batch->id)
            ->assertSee('data-release-history', false)
            ->assertSeeInOrder(['Sep 11, 2026 · 7:00 AM', 'Rejected Sep 11, 2026', 'Sep 11, 2026 · 9:00 AM', 'Approved Sep 11, 2026'])
            // D-124: approved, so the dated note stands where the button was.
            ->assertSee('Documents released on Sep 11, 2026.');
    }

    public function test_the_history_marks_printed_copies_and_links_an_old_requests_attachment(): void
    {
        $batch = $this->completedBatch();
        $old = $this->legacyRelease($batch, 'rejected', ['created_at' => Carbon::parse('2026-09-11 07:00:00')]);
        $new = $this->release($batch, 'approved', [
            'wants_printed_copies' => true,
            'created_at' => Carbon::parse('2026-09-11 09:00:00'),
        ]);

        $this->directorPage($batch->id)
            ->assertSeeInOrder(['Rejected Sep 11, 2026', 'attachment', 'Approved Sep 11, 2026', '· printed copies'])
            ->assertSee('href="'.route('director.release-requests.attachment', $old).'"', false)
            ->assertDontSee(route('director.release-requests.attachment', $new));
    }

    public function test_a_refused_rejection_reopens_the_popup_with_the_message(): void
    {
        $batch = $this->completedBatch();
        $release = $this->release($batch);

        $this->actingAs($this->director)
            ->from(route('director.tracking.results', $batch))
            ->post(route('director.release-requests.reject', $release), ['rejection_message' => 'Too short'])
            ->assertRedirect(route('director.tracking.results', $batch));

        // The popup and its message box come back open with what was typed.
        $this->directorPage($batch->id)
            ->assertSee('reviewOpen: true', false)
            ->assertSee('rejecting: true', false)
            ->assertSee('Too short');
    }

    // ── Batch Tracking: tag + badge (Q18) ────────────────────────────────────

    public function test_batch_tracking_tags_rows_with_a_pending_request(): void
    {
        $asked = $this->completedBatch();
        $this->release($asked, 'pending');
        $released = $this->completedBatch();
        $this->release($released, 'approved');
        $this->completedBatch();

        $html = $this->actingAs($this->director)->get(route('director.tracking.index'))
            ->assertOk()
            ->getContent();

        // (Blade writes the bare attribute as data-release-requested="data-release-requested".)
        $this->assertSame(1, substr_count($html, 'data-release-requested='));
        $this->assertMatchesRegularExpression(
            '~'.preg_quote($asked->reference_no, '~').'\s*.{0,300}Release requested~s',
            $html,
        );
    }

    /**
     * D-120 replaced D-113's work count: a release request is a dot on its
     * batch's row (and one on the badge) until the Director opens the batch.
     * The "Release requested" tag stays until the request is decided.
     */
    public function test_a_release_request_is_a_dot_until_the_director_opens_the_batch(): void
    {
        $batch = $this->completedBatch();
        $this->actingAs($this->director)->get(route('director.tracking.results', $batch))->assertOk();
        $this->assertSame(0, NavBadges::forUser($this->director->fresh())['director.tracking.index']);

        $this->travel(1)->minutes();
        $this->release($batch, 'pending');

        $this->assertSame(1, NavBadges::forUser($this->director->fresh())['director.tracking.index']);
        $this->actingAs($this->director)->get(route('director.tracking.index'))
            ->assertSee('data-nav-badge-for="director.tracking.index"', false)
            ->assertSee('data-notify-dot', false);

        $this->actingAs($this->director)->get(route('director.tracking.results', $batch))->assertOk();

        $this->assertSame(0, NavBadges::forUser($this->director->fresh())['director.tracking.index']);
        $this->actingAs($this->director)->get(route('director.tracking.index'))
            ->assertDontSee('data-notify-dot', false)
            ->assertSee('data-release-requested', false);
    }
}
