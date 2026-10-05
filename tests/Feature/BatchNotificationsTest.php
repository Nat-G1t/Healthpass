<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BatchRequest;
use App\Models\BatchSeen;
use App\Support\BatchNotifications;
use App\Support\NavBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\DocumentReleaseFixture;
use Tests\TestCase;

/**
 * D-120 — the notification dots on Batch Tracking rows and the Batch Tracking
 * badge (App\Support\BatchNotifications).
 *
 * College Admin: a dot when a Batch Results row's status changes or its
 * release request is decided, and on a rejected request until its reason is
 * opened; an approval raises the badge until the page is opened. Director: a
 * dot when a Batch Results row's status changes or its college asks for the
 * documents, and when a college cancels a batch. A row's View clears its dot.
 *
 * The fixture's batch is on Thursday Sep 10, 2026 at 9 AM; these tests move
 * the clock around that day.
 */
class BatchNotificationsTest extends TestCase
{
    use DocumentReleaseFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRelease();
    }

    private function badge(string $role): int
    {
        $user = $role === 'admin' ? $this->admin : $this->director;
        $key = $role === 'admin' ? 'admin.batches.index' : 'director.tracking.index';

        return NavBadges::forUser($user->fresh())[$key];
    }

    /** How many dots a Batch Tracking page draws. */
    private function dotsOn(string $role): int
    {
        $response = $role === 'admin'
            ? $this->actingAs($this->admin)->get(route('admin.batches.index'))
            : $this->actingAs($this->director)->get(route('director.tracking.index'));

        return substr_count($response->assertOk()->getContent(), 'data-notify-dot');
    }

    // ── Director ─────────────────────────────────────────────────────────────

    public function test_a_status_change_is_a_dot_until_the_director_opens_the_batch(): void
    {
        // The morning of the clinic day, before the 9 AM hour: Scheduled, which
        // is what an approved batch starts out "seen" as — no dot.
        $this->travelTo(Carbon::parse('2026-09-10 08:00:00'));
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'booked');
        $this->assertSame(0, $this->badge('director'));

        // 9:30 — In progress.
        $this->travelTo(Carbon::parse('2026-09-10 09:30:00'));
        $this->assertSame(1, $this->badge('director'));
        $this->assertSame(1, $this->dotsOn('director'));

        $this->actingAs($this->director)->get(route('director.tracking.results', $batch))->assertOk();
        $this->assertSame(0, $this->badge('director'));
        $this->assertSame(0, $this->dotsOn('director'));
        $this->assertSame('in_progress', BatchSeen::first()->seen_status);

        // The next morning the student never came — No one attended: news again.
        $this->travelTo(Carbon::parse('2026-09-11 10:00:00'));
        $this->assertSame(1, $this->badge('director'));
    }

    public function test_a_college_cancellation_is_a_dot_but_the_directors_own_rejection_is_not(): void
    {
        $this->makeBatch(status: 'rejected');
        $cancelled = $this->makeBatch(status: 'cancelled');

        $this->assertSame(1, $this->badge('director'));
        $this->assertSame(1, $this->dotsOn('director'));

        $this->actingAs($this->director)->get(route('director.tracking.show', $cancelled))->assertOk();

        $this->assertSame(0, $this->badge('director'));
        $this->assertSame(0, $this->dotsOn('director'));
    }

    public function test_a_batch_seen_settled_after_its_last_day_is_never_worked_out_again(): void
    {
        $batch = $this->completedBatch(3);
        $this->actingAs($this->director)->get(route('director.tracking.results', $batch))->assertOk();
        $this->assertSame('completed', BatchSeen::first()->seen_status);

        // Settled: the count reads only the batch row and the seen row — no
        // roster, appointment or visit query for a Completed batch.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame(0, BatchNotifications::count($this->director->fresh()));
        $queries = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        $this->assertStringNotContainsString('from "batch_request_students"', $queries);
        $this->assertStringNotContainsString('from "clinic_visits"', $queries);
    }

    // ── College Admin ────────────────────────────────────────────────────────

    public function test_an_approval_raises_the_admin_badge_until_the_page_is_opened_without_a_dot(): void
    {
        $this->travelTo(Carbon::parse('2026-09-08 09:00:00'));
        $this->actingAs($this->admin)->get(route('admin.batches.index'))->assertOk();

        $this->travel(1)->minutes();
        $batch = $this->makeBatch(status: 'pending');
        $this->addStudent($batch, 'booked');
        $batch->update(['status' => 'approved', 'reviewed_by' => $this->director->id, 'reviewed_at' => now(),
            'scheduled_date' => self::CLINIC_DAY, 'scheduled_time' => '09:00:00', 'scheduled_blocks' => 1]);

        $this->assertSame(1, $this->badge('admin'));
        // Opening the page is what clears it — the approved row has no dot.
        $this->assertSame(0, $this->dotsOn('admin'));
        $this->assertSame(0, $this->badge('admin'));
    }

    public function test_a_rejection_is_a_dot_until_its_reason_is_opened(): void
    {
        $rejected = $this->makeBatch(status: 'rejected');
        $rejected->update(['rejection_reason' => 'The clinic is closed that week.']);

        $this->assertSame(1, $this->badge('admin'));
        $this->assertSame(1, $this->dotsOn('admin'));

        // The reason popup posts here in the background.
        $this->actingAs($this->admin)->post(route('admin.batches.seen', $rejected->id))->assertNoContent();

        $this->assertSame(0, $this->badge('admin'));
        $this->assertSame(0, $this->dotsOn('admin'));
    }

    public function test_the_seen_endpoint_takes_only_this_colleges_rejected_batches(): void
    {
        $approved = $this->makeBatch();
        $foreign = $this->makeBatch(college: $this->coe, status: 'rejected');

        $this->actingAs($this->admin)->post(route('admin.batches.seen', $approved->id))->assertNotFound();
        $this->actingAs($this->admin)->post(route('admin.batches.seen', $foreign->id))->assertNotFound();
        // Another role is turned away by the role middleware (sent to its own dashboard).
        $this->actingAs($this->director)->post(route('admin.batches.seen', $foreign->id))->assertRedirect();
        $this->assertSame(0, BatchSeen::count());
    }

    public function test_a_status_change_and_a_release_decision_are_dots_until_the_batch_is_opened(): void
    {
        $batch = $this->completedBatch();

        // Completed since the morning after — never opened, so a dot.
        $this->assertSame(1, $this->badge('admin'));
        $this->actingAs($this->admin)->get(route('admin.batches.results', $batch->id))->assertOk();
        $this->assertSame(0, $this->badge('admin'));

        // The admin's OWN request is not news to them …
        $this->travel(1)->minutes();
        $request = $this->release($batch, 'pending');
        $this->assertSame(0, $this->badge('admin'));

        // … the Director's answer is.
        $this->travel(1)->minutes();
        $request->update(['status' => 'approved', 'reviewed_by' => $this->director->id, 'reviewed_at' => now()]);
        $this->assertSame(1, $this->badge('admin'));
        $this->assertSame(1, $this->dotsOn('admin'));

        $this->actingAs($this->admin)->get(route('admin.batches.results', $batch->id))->assertOk();
        $this->assertSame(0, $this->badge('admin'));
    }

    public function test_one_viewer_opening_a_batch_does_not_clear_anothers_dot(): void
    {
        $batch = $this->completedBatch();

        $this->actingAs($this->director)->get(route('director.tracking.results', $batch))->assertOk();

        $this->assertSame(0, $this->badge('director'));
        $this->assertSame(1, $this->badge('admin'));
    }

    public function test_another_colleges_batches_never_reach_the_admin(): void
    {
        $this->completedBatch(college: $this->coe);
        $this->makeBatch(college: $this->coe, status: 'rejected');

        $this->assertSame(0, $this->badge('admin'));
        $this->assertInstanceOf(BatchRequest::class, BatchRequest::where('college_id', $this->coe->id)->first());
    }
}
