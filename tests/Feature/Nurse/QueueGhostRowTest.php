<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\ClinicVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\ClinicQueueFixture;
use Tests\Support\EncodePayload;
use Tests\TestCase;

/**
 * Live Queue "ghost row" (motion pass §6.1b — the reverse-stack flagship).
 *
 * The nurse's Submit to physician (D-126) flashes `left_queue_visit_id` for
 * exactly one request; the nurse's queue page then renders the just-submitted
 * visit ONE last time as a `data-leaving` ghost (static "Submitted ✓" chip,
 * never the NEXT row) so the front-end can animate it out. A plain reload must
 * not show it again, and a bogus, still-waiting or encoded id in the flash
 * slot must fail safe: no ghost, no error. (The physician's Save stays on the
 * encode page since D-127, so it makes no ghost — PhysicianEncodePageTest.)
 */
class QueueGhostRowTest extends TestCase
{
    use ClinicQueueFixture;
    use RefreshDatabase;

    private function submit(User $nurse, ClinicVisit $visit): TestResponse
    {
        return $this->actingAs($nurse)->post(route('nurse.visits.interview', $visit), EncodePayload::vitals());
    }

    public function test_queue_after_submit_shows_ghost_row_exactly_once(): void
    {
        $nurse = $this->nurse();
        $visit = $this->queueVisit('assessment');

        $this->submit($nurse, $visit)
            ->assertRedirect(route('nurse.queue'))
            ->assertSessionHas('left_queue_visit_id', $visit->id);

        // The redirect target renders the ghost: data-leaving, the static chip,
        // and the visit's reference — even though the visit is now
        // `interviewed` and out of the nurse's queue query.
        $page = $this->actingAs($nurse)->get(route('nurse.queue'));
        $page->assertOk();
        $page->assertSee('data-leaving', false);
        $page->assertSee('Submitted ✓');
        $page->assertSee($visit->reference_no);
        $this->assertSame(
            1,
            substr_count($page->getContent(), 'data-leaving'),
            'The ghost row must render exactly once.',
        );
    }

    public function test_ghost_is_never_the_next_row_and_real_queue_keeps_next(): void
    {
        $nurse = $this->nurse();
        $oldest = $this->queueVisit('assessment', checkedInAt: now()->subMinutes(30)); // submitted → ghost
        $waiting = $this->queueVisit('assessment', checkedInAt: now()->subMinutes(10));

        $this->submit($nurse, $oldest)->assertRedirect(route('nurse.queue'));

        $page = $this->actingAs($nurse)->get(route('nurse.queue'));
        $page->assertOk();

        // The ghost row carries data-leaving but never data-next; the first
        // remaining REAL row is the one promoted to NEXT. (Row attributes are
        // matched per-tr — the page's <style> block also mentions data-next.)
        $content = $page->getContent();
        $this->assertSame(1, substr_count($content, 'data-leaving'));
        $this->assertMatchesRegularExpression(
            '/data-visit-id="'.$waiting->id.'"\s+data-next/',
            $content,
            'NEXT must sit on the first real (still-waiting) row.',
        );
        $this->assertMatchesRegularExpression(
            '/data-visit-id="'.$oldest->id.'"\s+data-leaving/',
            $content,
            'The ghost row must carry data-leaving and must not be NEXT.',
        );
    }

    public function test_plain_reload_does_not_show_the_ghost_again(): void
    {
        $nurse = $this->nurse();
        $visit = $this->queueVisit('assessment');

        $this->submit($nurse, $visit);

        // First load consumes the one-request flash…
        $this->actingAs($nurse)->get(route('nurse.queue'))->assertSee('data-leaving', false);

        // …so a reload is a normal queue page: no ghost, no submitted reference.
        $reload = $this->actingAs($nurse)->get(route('nurse.queue'));
        $reload->assertOk();
        $reload->assertDontSee('data-leaving', false);
        $reload->assertDontSee($visit->reference_no);
    }

    public function test_bogus_waiting_or_encoded_flash_id_fails_safe(): void
    {
        $nurse = $this->nurse();

        // Unknown id: page renders normally, no ghost, no error.
        $this->actingAs($nurse)
            ->withSession(['left_queue_visit_id' => 999999])
            ->get(route('nurse.queue'))
            ->assertOk()
            ->assertDontSee('data-leaving', false);

        // An encoded visit is never a ghost (D-127: nothing flashes one).
        $encoded = $this->queueVisit('assessment', 'encoded');
        $this->actingAs($nurse)
            ->withSession(['left_queue_visit_id' => $encoded->id])
            ->get(route('nurse.queue'))
            ->assertOk()
            ->assertDontSee('data-leaving', false);

        // A visit that is still `captured` (never submitted) must not become a
        // ghost either — it is a real queue row, nothing more.
        $visit = $this->queueVisit('assessment');
        $page = $this->actingAs($nurse)
            ->withSession(['left_queue_visit_id' => $visit->id])
            ->get(route('nurse.queue'));
        $page->assertOk();
        $page->assertDontSee('data-leaving', false);
        $page->assertSee($visit->reference_no); // still a normal waiting row
    }
}
