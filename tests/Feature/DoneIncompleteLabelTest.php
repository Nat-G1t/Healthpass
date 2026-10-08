<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BatchRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\DocumentReleaseFixture;
use Tests\TestCase;

/**
 * D-121 — the batch status "Finished incomplete" reads "Done / Incomplete".
 *
 * Label only: BatchRequest::resultsStatus() still returns the key
 * `finished_incomplete`, which batch_seen.seen_status stores (D-120). Every
 * page words the key through one partial (batches/results/_status), so each
 * role's card and results page must show the new words.
 */
class DoneIncompleteLabelTest extends TestCase
{
    use DocumentReleaseFixture;
    use RefreshDatabase;

    private BatchRequest $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRelease();

        // The fixture's clock is the morning after the clinic day: the
        // no-show is Unattended, so the batch is finished but incomplete.
        $this->batch = $this->makeBatch();
        $this->addStudent($this->batch, 'Fit');
        $this->addStudent($this->batch, 'booked');

        $this->assertSame('finished_incomplete', $this->batch->fresh()->resultsStatus());
    }

    private function assertReadsDoneIncomplete(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertSee('Done / Incomplete');
        $response->assertDontSee('Finished incomplete');
    }

    public function test_the_college_admins_card_and_results_page_read_done_incomplete(): void
    {
        $this->assertReadsDoneIncomplete($this->actingAs($this->admin)->get(route('admin.batches.index')));
        $this->assertReadsDoneIncomplete($this->actingAs($this->admin)->get(route('admin.batches.results', $this->batch->id)));
    }

    public function test_the_directors_card_and_results_page_read_done_incomplete(): void
    {
        $this->assertReadsDoneIncomplete($this->actingAs($this->director)->get(route('director.tracking.index')));
        $this->assertReadsDoneIncomplete($this->actingAs($this->director)->get(route('director.tracking.results', $this->batch->id)));
    }

    public function test_the_clinics_batch_card_reads_done_incomplete(): void
    {
        $this->assertReadsDoneIncomplete($this->actingAs($this->nurse)->get(route('nurse.batches.index')));
    }

    public function test_the_release_request_is_open_and_its_not_ready_hint_is_hidden(): void
    {
        // "Available when the batch is completed" covers Done / Incomplete
        // too: the button is ready, so the not-ready hint never shows.
        $this->actingAs($this->admin)->get(route('admin.batches.results', $this->batch->id))
            ->assertOk()
            ->assertSee('data-release-state="ready"', false)
            ->assertDontSee('Available when the batch is completed.');
    }
}
