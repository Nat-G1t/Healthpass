<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentRelease;

use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\BatchResults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Support\DocumentReleaseFixture;
use Tests\TestCase;

/**
 * D-136 — printed copies asked for ON the batch request:
 *
 *   - the College Admin ticks it on New Batch, the Director answers it in the
 *     approve popup (asked AND ticked, on the locked row);
 *   - approved, the clinic (nurse and physician) may PRINT the batch's encoded
 *     forms at once — before the batch is finished and before any release —
 *     while Save as PDF and every download still wait for the release;
 *   - the clinic sees it on both Live Queues, the encode page, Upcoming
 *     Batches and Batch Results, and closes it with Mark as printed;
 *   - the release popup no longer offers printed copies for such a batch.
 *
 * The fixture's clock: "now" is Friday Sep 11, 2026, 10 AM; its batches ran
 * on Thursday Sep 10.
 */
class EarlyPrintedCopiesTest extends TestCase
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

    /** An approved batch whose printed copies the Director approved with it. */
    private function earlyBatch(string $formType = 'clearance'): BatchRequest
    {
        $batch = $this->makeBatch($formType);
        $batch->update(['wants_printed_copies' => true, 'printed_copies_approved' => true]);

        return $batch;
    }

    /** A pending batch of two students, asking for printed copies or not. */
    private function pendingBatch(bool $wantsCopies): BatchRequest
    {
        $batch = $this->makeBatch(status: 'pending');
        $batch->update(['wants_printed_copies' => $wantsCopies]);

        User::factory()->count(2)->create(['role' => 'student'])->each(
            fn (User $student) => BatchRequestStudent::create(['batch_request_id' => $batch->id, 'student_id' => $student->id]),
        );

        return $batch;
    }

    private function approve(BatchRequest $batch, array $extra = []): TestResponse
    {
        return $this->actingAs($this->director)->post(route('director.batches.approve', $batch), [
            'scheduled_date' => '2026-09-18',
            'start_time' => '08:00:00',
            ...$extra,
        ]);
    }

    // ── Asking and deciding ──────────────────────────────────────────────────

    public function test_new_batch_offers_the_checkbox_and_stores_the_ask(): void
    {
        $this->actingAs($this->admin)->get(route('admin.batches.create'))
            ->assertOk()
            ->assertSee('data-batch-printed-copies', false)
            ->assertSee('The college needs printed copies right after the clinic');

        $payload = fn (array $extra = []): array => [
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'students' => StudentProfile::factory()->count(2)->forCollege($this->ccs)->create()->pluck('id')->all(),
            'memo' => UploadedFile::fake()->image('memo.jpg'),
            ...$extra,
        ];

        $this->actingAs($this->admin)->post(route('admin.batches.store'), $payload(['wants_printed_copies' => '1']))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.batches.store'), $payload())
            ->assertSessionHasNoErrors();

        $batches = BatchRequest::orderBy('id')->get();
        $this->assertTrue($batches[0]->wants_printed_copies);
        $this->assertFalse($batches[1]->wants_printed_copies);
        // Only the ask: nothing is approved until the Director decides.
        $this->assertFalse($batches[0]->printed_copies_approved);

        $this->actingAs($this->admin)->get(route('admin.batches.confirmation', $batches[0]))
            ->assertSee('data-early-copies="requested"', false);
        $this->actingAs($this->admin)->get(route('admin.batches.confirmation', $batches[1]))
            ->assertDontSee('data-early-copies', false);
    }

    public function test_the_review_page_shows_the_ask_and_a_ticked_approve_checkbox(): void
    {
        $asked = $this->pendingBatch(wantsCopies: true);
        $plain = $this->pendingBatch(wantsCopies: false);

        $this->actingAs($this->director)->get(route('director.batches.review', $asked))
            ->assertOk()
            ->assertSee('data-review-printed-copies', false)
            ->assertSee('Approve printed copies')
            ->assertSee('name="approve_printed_copies" value="1" checked', false);

        $this->actingAs($this->director)->get(route('director.batches.review', $plain))
            ->assertOk()
            ->assertDontSee('data-review-printed-copies', false)
            ->assertDontSee('name="approve_printed_copies"', false);
    }

    public function test_approval_keeps_the_copies_only_when_asked_and_ticked(): void
    {
        $askedTicked = $this->pendingBatch(wantsCopies: true);
        $askedUnticked = $this->pendingBatch(wantsCopies: true);
        $neverAsked = $this->pendingBatch(wantsCopies: false);

        $this->approve($askedTicked, ['approve_printed_copies' => '1']);
        $this->approve($askedUnticked);
        // A forged tick on a batch that never asked is ignored.
        $this->approve($neverAsked, ['approve_printed_copies' => '1']);

        foreach ([$askedTicked, $askedUnticked, $neverAsked] as $batch) {
            $this->assertSame('approved', $batch->fresh()->status);
        }

        $this->assertTrue($askedTicked->fresh()->printed_copies_approved);
        $this->assertFalse($askedUnticked->fresh()->printed_copies_approved);
        $this->assertFalse($neverAsked->fresh()->printed_copies_approved);
    }

    public function test_the_college_admin_reads_the_directors_answer(): void
    {
        $approved = $this->earlyBatch();
        $refused = $this->makeBatch();
        $refused->update(['wants_printed_copies' => true]);

        $this->actingAs($this->admin)->get(route('admin.batches.show', $approved))
            ->assertSee('data-early-copies="approved"', false);
        $this->actingAs($this->admin)->get(route('admin.batches.results', $approved))
            ->assertSee('data-early-copies="approved"', false);

        $this->actingAs($this->admin)->get(route('admin.batches.show', $refused))
            ->assertSee('data-early-copies="not_approved"', false);
    }

    // ── The clinic prints — paper only ───────────────────────────────────────

    public function test_the_clinic_prints_an_early_copies_batch_before_it_is_finished(): void
    {
        $batch = $this->earlyBatch();
        $visit = $this->visitOf($this->addStudent($batch, 'Fit'));
        // Another student is still waiting for the clinic: not finished, so
        // no release could even be asked for.
        $this->addStudent($batch, 'in_clinic');
        $this->assertFalse($batch->fresh()->load(BatchResults::statusRelations())->isReadyForRelease());

        foreach ([$this->nurse, $this->physician] as $user) {
            $this->actingAs($user)->get(route('nurse.visits.print', $visit))->assertOk();
            $this->actingAs($user)->post(route('nurse.visits.print.reprint', $visit))->assertOk();

            // Files wait for the release.
            $this->actingAs($user)->getJson(route('nurse.visits.pdf', $visit))->assertForbidden();
            $this->actingAs($user)->postJson(route('nurse.batches.documents', $batch), ['visits' => [$visit->id]])->assertForbidden();
        }

        $this->assertNotNull($visit->clearanceRecord->fresh()->printed_at);
    }

    public function test_a_batch_without_early_copies_still_waits_for_the_release(): void
    {
        $batch = $this->makeBatch();
        $batch->update(['wants_printed_copies' => true]);   // asked, not approved
        $visit = $this->visitOf($this->addStudent($batch, 'Fit'));

        $this->actingAs($this->nurse)->getJson(route('nurse.visits.print', $visit))->assertForbidden();
        $this->actingAs($this->nurse)->postJson(route('nurse.visits.print.reprint', $visit))->assertForbidden();
        $this->actingAs($this->nurse)->getJson(route('nurse.visits.pdf', $visit))->assertForbidden();
    }

    public function test_once_released_the_pdf_opens_too(): void
    {
        $batch = $this->earlyBatch();
        $visit = $this->visitOf($this->addStudent($batch, 'Fit'));
        $this->release($batch, 'approved');

        $this->actingAs($this->nurse)->get(route('nurse.visits.pdf', $visit))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_encode_page_says_to_print_and_offers_print_but_not_pdf(): void
    {
        $visit = $this->visitOf($this->addStudent($this->earlyBatch(), 'Fit'));

        $this->actingAs($this->nurse)->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('data-encode-print-copies', false)
            ->assertSee(route('nurse.visits.print.reprint', $visit))
            ->assertDontSee(route('nurse.visits.pdf', $visit))
            ->assertSee('data-pdf-disabled-hint', false);

        // A batch with no early copies: no note, and still no print (D-125).
        $plain = $this->visitOf($this->addStudent($this->makeBatch(), 'Fit'));

        $this->actingAs($this->nurse)->get(route('nurse.visits.encode', $plain))
            ->assertOk()
            ->assertDontSee('data-encode-print-copies', false)
            ->assertSee('data-print-disabled-hint', false);
    }

    public function test_both_live_queues_tag_the_student(): void
    {
        // A Medical Clearance student waits for the physician, an Assessment one for the nurse (D-126).
        $this->addStudent($this->earlyBatch('clearance'), 'in_clinic');
        $this->addStudent($this->earlyBatch('assessment'), 'in_clinic');
        $this->addStudent($this->makeBatch('clearance'), 'in_clinic');

        foreach ([$this->physician, $this->nurse] as $user) {
            $this->actingAs($user)->get(route('nurse.queue'))
                ->assertOk()
                ->assertSee('data-cell="print-copies"', false);
        }

        $physicianFeed = $this->actingAs($this->physician)->getJson(route('nurse.queue.feed'))->assertOk()->json('visits');
        $this->assertSame([true, false], array_column($physicianFeed, 'print_copies'));

        $nurseFeed = $this->actingAs($this->nurse)->getJson(route('nurse.queue.feed'))->assertOk()->json('visits');
        $this->assertSame([true], array_column($nurseFeed, 'print_copies'));
    }

    // ── The clinic's batch pages ─────────────────────────────────────────────

    public function test_upcoming_batches_tags_the_batch_from_approval(): void
    {
        $early = $this->earlyBatch();
        $this->addStudent($early, 'booked');
        $this->moveTo($early, '2026-09-14');

        $plain = $this->makeBatch();
        $this->addStudent($plain, 'booked');
        $this->moveTo($plain, '2026-09-15');

        $page = $this->actingAs($this->nurse)->get(route('nurse.batches.index'))->assertOk();
        // Blade renders a bare attribute as data-print-requested="data-print-requested".
        $this->assertSame(1, substr_count($page->getContent(), 'data-print-requested="'));
        $page->assertSeeInOrder([$early->reference_no, 'Print Requested', $plain->reference_no]);
    }

    public function test_the_results_page_opens_row_print_but_not_row_pdf_or_download(): void
    {
        $batch = $this->earlyBatch();
        $this->addStudent($batch, 'Fit');

        $this->actingAs($this->nurse)->get(route('nurse.batches.results', $batch))
            ->assertOk()
            ->assertSee('data-copies-banner="requested"', false)
            ->assertSee('data-copies-source="batch"', false)
            ->assertSee('data-row-print', false)
            ->assertDontSee('data-row-pdf>', false)
            ->assertSee('data-disabled-row-pdf', false);
    }

    public function test_mark_as_printed_stamps_the_batch(): void
    {
        $batch = $this->earlyBatch();
        $this->addStudent($batch, 'Fit');

        $this->actingAs($this->nurse)->post(route('nurse.batches.copies-printed', $batch))
            ->assertRedirect(route('nurse.batches.results', $batch))
            ->assertSessionHas('status', 'Printed copies marked done.');

        $batch->refresh();
        $this->assertNotNull($batch->copies_printed_at);
        $this->assertSame($this->nurse->id, $batch->copies_printed_by);

        // A second press changes nothing.
        $stamp = $batch->copies_printed_at;
        $this->actingAs($this->physician)->post(route('nurse.batches.copies-printed', $batch))
            ->assertSessionHas('status', 'The printed copies were already marked done.');
        $this->assertEquals($stamp, $batch->fresh()->copies_printed_at);

        $this->actingAs($this->nurse)->get(route('nurse.batches.results', $batch))
            ->assertSee('data-copies-banner="printed"', false)
            ->assertSee('Printed copies marked done by '.$this->nurse->name);
    }

    public function test_mark_as_printed_is_refused_without_any_copies(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit');

        $this->actingAs($this->nurse)->post(route('nurse.batches.copies-printed', $batch))
            ->assertSessionHas('error', 'This batch has no approved request for printed copies.');
        $this->assertNull($batch->fresh()->copies_printed_at);
    }

    public function test_the_dashboard_reads_print_only_and_reprint_works(): void
    {
        $visit = $this->visitOf($this->addStudent($this->earlyBatch(), 'Fit'));

        $this->actingAs($this->nurse)->get(route('nurse.dashboard'))
            ->assertOk()
            ->assertSee('data-release-status="print"', false)
            ->assertSee('Print only')
            ->assertSee(route('nurse.visits.print.reprint', $visit));
    }

    // ── The release popup ────────────────────────────────────────────────────

    public function test_the_release_popup_drops_the_checkbox_and_ignores_a_tick(): void
    {
        $early = $this->earlyBatch();
        $this->addStudent($early, 'Fit');
        $plain = $this->completedBatch();

        $this->actingAs($this->admin)->get(route('admin.batches.results', $early))
            ->assertSee('data-release-early-copies', false)
            ->assertDontSee('name="wants_printed_copies"', false);
        $this->actingAs($this->admin)->get(route('admin.batches.results', $plain))
            ->assertSee('name="wants_printed_copies"', false);

        $this->actingAs($this->admin)->post(route('admin.batches.release-requests.store', $early->id), [
            'reason' => 'The OJT partner companies need the forms.',
            'wants_printed_copies' => '1',
        ])->assertSessionHas('status');

        $this->assertFalse($early->releaseRequests()->sole()->wants_printed_copies);
    }

    public function test_the_directors_review_popup_says_copies_were_already_approved(): void
    {
        $early = $this->earlyBatch();
        $this->addStudent($early, 'Fit');
        $this->release($early, 'pending');

        $this->actingAs($this->director)->get(route('director.tracking.results', $early))
            ->assertOk()
            ->assertSee('data-early-copies="approved"', false)
            ->assertSee('Already approved with the batch on');
    }
}
