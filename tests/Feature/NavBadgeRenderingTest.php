<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BatchRequest;
use App\Models\College;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * D-57 — how a sidebar badge looks (FR-UI-05).
 *
 * 1 is a dot with no number, 2–9 the number, 10 or more "9+", 0 nothing at all.
 * Each badged item renders TWO badge elements: one on the label (expanded rail
 * and phone drawer) and one on the icon (collapsed rail) — CSS shows one.
 *
 * The Director's Batch Approvals count drives these cases: it is a plain count
 * of pending requests, so any number is one loop away.
 */
class NavBadgeRenderingTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private User $admin;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
        $this->director = User::factory()->create(['role' => 'director']);
    }

    private function pendingBatches(int $count): void
    {
        static $seq = 700;

        for ($i = 0; $i < $count; $i++) {
            BatchRequest::create([
                'reference_no' => 'BR-2026-'.$seq++,
                'college_id' => $this->ccs->id,
                'requested_by' => $this->admin->id,
                'reason' => 'graduation',
                'service_type' => 'medical',
                'requested_date' => now()->addWeek()->toDateString(),
                'requested_time' => '09:00:00',
                'requested_blocks' => 1,
                'status' => 'pending',
            ]);
        }
    }

    /**
     * The badge elements the sidebar drew for $route, keyed by placement
     * ('icon' / 'label').
     *
     * @return array<string, DOMElement>
     */
    private function badgesFor(TestResponse $response, string $route): array
    {
        $dom = new DOMDocument;
        // libxml only knows HTML4, so <svg> and <template> raise warnings that
        // say nothing about the markup being wrong.
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML((string) $response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $badges = [];
        foreach ((new DOMXPath($dom))->query("//*[@data-nav-badge-for='{$route}']") as $element) {
            $badges[$element->getAttribute('data-nav-badge')] = $element;
        }
        ksort($badges);

        return $badges;
    }

    private function dashboard(): TestResponse
    {
        return $this->actingAs($this->director)->get(route('director.dashboard'))->assertOk();
    }

    public function test_a_count_of_one_is_a_dot_without_a_number(): void
    {
        $this->pendingBatches(1);
        $response = $this->dashboard();

        $badges = $this->badgesFor($response, 'director.batches.index');
        $this->assertSame(['icon', 'label'], array_keys($badges));
        $this->assertSame('', trim($badges['icon']->textContent));
        $this->assertSame('', trim($badges['label']->textContent));
        $response->assertSee('1 unread');
    }

    public function test_a_count_from_two_to_nine_shows_the_number(): void
    {
        $this->pendingBatches(5);
        $response = $this->dashboard();

        $badges = $this->badgesFor($response, 'director.batches.index');
        $this->assertSame(['icon', 'label'], array_keys($badges));
        $this->assertSame('5', trim($badges['icon']->textContent));
        $this->assertSame('5', trim($badges['label']->textContent));
        $response->assertSee('5 unread');

        // The icon badge is the collapsed rail's; this class is what hides it
        // everywhere else.
        $this->assertStringContainsString('hp-nav-badge-icon', $badges['icon']->getAttribute('class'));
    }

    public function test_ten_or_more_shows_nine_plus(): void
    {
        $this->pendingBatches(12);
        $response = $this->dashboard();

        $badges = $this->badgesFor($response, 'director.batches.index');
        $this->assertSame('9+', trim($badges['icon']->textContent));
        $this->assertSame('9+', trim($badges['label']->textContent));
        // Screen readers still get the real number.
        $response->assertSee('12 unread');
    }

    public function test_a_count_of_zero_renders_no_badge(): void
    {
        $response = $this->dashboard();

        $this->assertSame([], $this->badgesFor($response, 'director.batches.index'));
        // The screen-reader count is not drawn either. (Matched as markup: the
        // bare word also appears in the sidebar's CSS comment.)
        $response->assertDontSee('unread</span>', false);
    }

    public function test_the_badge_stays_solid_orange_on_the_active_item(): void
    {
        $this->pendingBatches(3);
        $response = $this->actingAs($this->director)->get(route('director.batches.index'))->assertOk();

        foreach ($this->badgesFor($response, 'director.batches.index') as $badge) {
            $this->assertStringContainsString('bg-hp-orange', $badge->getAttribute('class'));
        }
    }
}
