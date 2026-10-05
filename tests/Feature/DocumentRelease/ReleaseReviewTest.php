<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentRelease;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DocumentReleaseFixture;
use Tests\TestCase;

/**
 * D-113 (FR-DIRA-11) — the Director reviews a college's request to release a
 * batch's documents: opens the attached file (the Director ONLY), then
 * approves — the college may download, for good — or rejects with a written
 * message (10–500 characters) the college reads on its results page.
 *
 * Both decisions are terminal and re-read the request under a lock: a second
 * click, or a decision on an already-decided request, changes nothing.
 */
class ReleaseReviewTest extends TestCase
{
    use DocumentReleaseFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRelease();
    }

    // ── Approve ──────────────────────────────────────────────────────────────

    public function test_the_director_approves_a_pending_request(): void
    {
        $batch = $this->completedBatch();
        $release = $this->release($batch);

        $this->actingAs($this->director)
            ->post(route('director.release-requests.approve', $release))
            ->assertRedirect(route('director.tracking.results', $batch))
            ->assertSessionHas('status');

        $release->refresh();
        $this->assertSame('approved', $release->status);
        $this->assertSame($this->director->id, $release->reviewed_by);
        $this->assertTrue($release->reviewed_at->equalTo(now()));
        $this->assertTrue($batch->hasReleasedDocuments());
    }

    public function test_approving_twice_changes_nothing(): void
    {
        $batch = $this->completedBatch();
        $release = $this->release($batch);

        $this->actingAs($this->director)->post(route('director.release-requests.approve', $release));
        $firstReview = $release->fresh()->reviewed_at;

        $this->travel(5)->minutes();

        $this->actingAs($this->director)
            ->post(route('director.release-requests.approve', $release))
            ->assertSessionHas('error', 'This request has already been decided — nothing was changed.');

        $this->assertTrue($release->fresh()->reviewed_at->equalTo($firstReview));
    }

    // ── Reject ───────────────────────────────────────────────────────────────

    public function test_the_director_rejects_with_a_message(): void
    {
        $batch = $this->completedBatch();
        $release = $this->release($batch);

        $this->actingAs($this->director)
            ->post(route('director.release-requests.reject', $release), [
                'rejection_message' => 'The memo must be signed by the dean, not the adviser.',
            ])
            ->assertRedirect(route('director.tracking.results', $batch))
            ->assertSessionHas('status');

        $release->refresh();
        $this->assertSame('rejected', $release->status);
        $this->assertSame('The memo must be signed by the dean, not the adviser.', $release->rejection_message);
        $this->assertSame($this->director->id, $release->reviewed_by);
        $this->assertFalse($batch->hasReleasedDocuments());
    }

    public function test_the_rejection_message_is_required_and_between_10_and_500_characters(): void
    {
        $release = $this->release($this->completedBatch());

        foreach (['', 'Too short', Str::repeat('a', 501)] as $message) {
            $this->actingAs($this->director)
                ->post(route('director.release-requests.reject', $release), ['rejection_message' => $message])
                ->assertSessionHasErrors('rejection_message');
        }

        $this->assertSame('pending', $release->fresh()->status);
    }

    public function test_a_decided_request_cannot_be_rejected_and_keeps_its_outcome(): void
    {
        $approved = $this->release($this->completedBatch(), 'approved');

        $this->actingAs($this->director)
            ->post(route('director.release-requests.reject', $approved), [
                'rejection_message' => 'Changed my mind about this one.',
            ])
            ->assertSessionHas('error');

        $approved->refresh();
        $this->assertSame('approved', $approved->status);
        $this->assertNull($approved->rejection_message);
    }

    public function test_the_college_reads_the_message_and_asks_again_then_the_director_approves(): void
    {
        $batch = $this->completedBatch();
        $first = $this->release($batch);

        $this->actingAs($this->director)->post(route('director.release-requests.reject', $first), [
            'rejection_message' => 'Please attach the memo <b>signed</b> by the dean.',
        ]);

        // The admin sees the Director's words — escaped — and "Request again".
        $this->actingAs($this->admin)->get(route('admin.batches.results', $batch->id))
            ->assertOk()
            ->assertSee('Not released:')
            ->assertSee('Please attach the memo &lt;b&gt;signed&lt;/b&gt; by the dean.', false)
            ->assertDontSee('<b>signed</b>', false)
            ->assertSee('Request again');

        $this->actingAs($this->admin)->post(route('admin.batches.release-requests.store', $batch->id), [
            'reason' => 'Re-sending with the dean-signed memo attached.',
            'attachment' => UploadedFile::fake()->image('dean-memo.jpg'),
        ])->assertSessionHas('status');

        $second = $batch->fresh()->latestReleaseRequest;
        $this->assertNotSame($first->id, $second->id);

        $this->actingAs($this->director)->post(route('director.release-requests.approve', $second))
            ->assertSessionHas('status');

        $this->actingAs($this->admin)->get(route('admin.batches.results', $batch->id))
            ->assertSee('Documents released by the Director on Sep 11, 2026.')
            ->assertSee('data-document-tools="enabled"', false)
            ->assertDontSee('Not released:');
    }

    // ── The attachment: the Director only ────────────────────────────────────

    public function test_the_director_opens_the_attachment_inline(): void
    {
        $release = $this->release($this->completedBatch());

        $response = $this->actingAs($this->director)
            ->get(route('director.release-requests.attachment', $release))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('signed-request.png', $response->headers->get('Content-Disposition'));
        $this->assertSame('fake image bytes', $response->streamedContent());
    }

    public function test_nobody_else_can_open_the_attachment(): void
    {
        $release = $this->release($this->completedBatch());
        $url = route('director.release-requests.attachment', $release);
        $student = User::factory()->create(['role' => 'student']);

        // Another role on a /director route is sent to its own dashboard
        // (FR-AUTH-03) — the file is never served. That includes the college
        // admin who uploaded it.
        foreach ([$this->admin, $this->nurse, $student] as $user) {
            $response = $this->actingAs($user)->get($url)->assertRedirect();
            $this->assertNotSame('image/png', $response->headers->get('Content-Type'));
        }

        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));

        // …and no /admin route leads back to it.
        $adminRoutes = collect(Route::getRoutes()->getRoutesByName())
            ->keys()
            ->filter(fn (string $name): bool => str_starts_with($name, 'admin.') && str_contains($name, 'attachment'));
        $this->assertSame([], $adminRoutes->values()->all());
    }

    public function test_a_missing_attachment_file_is_a_404(): void
    {
        $release = $this->release($this->completedBatch());
        Storage::disk('local')->delete($release->attachment_path);

        $this->actingAs($this->director)
            ->get(route('director.release-requests.attachment', $release))
            ->assertNotFound();
    }

    public function test_other_roles_cannot_decide_a_request(): void
    {
        $release = $this->release($this->completedBatch());
        $student = User::factory()->create(['role' => 'student']);

        foreach ([$this->admin, $this->nurse, $student] as $user) {
            $this->actingAs($user)->post(route('director.release-requests.approve', $release))->assertRedirect();
            $this->actingAs($user)->post(route('director.release-requests.reject', $release), [
                'rejection_message' => 'Trying to reject this request.',
            ])->assertRedirect();
        }

        $this->assertSame('pending', $release->fresh()->status);
    }
}
