<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentRelease;

use App\Models\DocumentReleaseRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DocumentReleaseFixture;
use Tests\TestCase;

/**
 * D-113 (FR-ADM-15) — a College Admin asks the Director to release a finished
 * batch's documents: a reason (10–500 characters). D-123: no attachment any
 * more (the Director reviews the batch's signed memo), and an optional
 * "printed copies" tick.
 *
 * Only a batch whose students are all final may be asked for — Completed or
 * Finished incomplete — and only one request may wait at a time; once
 * released, there is nothing left to ask for. A rejected request may be sent
 * again, and stays as history.
 */
class ReleaseRequestStoreTest extends TestCase
{
    use DocumentReleaseFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRelease();
    }

    private function storeUrl(int $batchId): string
    {
        return route('admin.batches.release-requests.store', $batchId);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'reason' => 'Our OJT partners need the official forms before deployment.',
            ...$overrides,
        ];
    }

    public function test_an_admin_requests_the_release_of_a_completed_batch(): void
    {
        $batch = $this->completedBatch();

        $this->actingAs($this->admin)
            ->post($this->storeUrl($batch->id), $this->payload())
            ->assertRedirect(route('admin.batches.results', $batch->id))
            ->assertSessionHas('status', 'Release requested — the Director will review it.');

        $release = DocumentReleaseRequest::sole();
        $this->assertSame($batch->id, $release->batch_request_id);
        $this->assertSame($this->admin->id, $release->requested_by);
        $this->assertSame('pending', $release->status);
        $this->assertSame('Our OJT partners need the official forms before deployment.', $release->reason);
        $this->assertNull($release->reviewed_by);

        // D-123: no file, and the box left unticked means no printed copies.
        $this->assertFalse($release->hasAttachment());
        $this->assertFalse($release->wants_printed_copies);
    }

    public function test_ticking_printed_copies_is_stored(): void
    {
        $batch = $this->completedBatch();

        $this->actingAs($this->admin)
            ->post($this->storeUrl($batch->id), $this->payload(['wants_printed_copies' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertTrue(DocumentReleaseRequest::sole()->wants_printed_copies);
    }

    public function test_a_posted_attachment_is_ignored_and_never_stored(): void
    {
        $batch = $this->completedBatch();

        $this->actingAs($this->admin)
            ->post($this->storeUrl($batch->id), $this->payload([
                'attachment' => UploadedFile::fake()->image('signed-request.png', 600, 800),
            ]))
            ->assertSessionHasNoErrors();

        $release = DocumentReleaseRequest::sole();
        $this->assertNull($release->attachment_path);
        $this->assertNull($release->attachment_original_name);
        $this->assertNull($release->attachment_mime);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_finished_incomplete_batch_can_be_asked_for_too(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit');
        $this->addStudent($batch, 'booked');   // a no-show: Unattended after 8 PM

        $this->assertSame('finished_incomplete', $batch->fresh()->resultsStatus());

        $this->actingAs($this->admin)->post($this->storeUrl($batch->id), $this->payload())
            ->assertSessionHas('status');

        $this->assertSame(1, DocumentReleaseRequest::count());
    }

    public function test_a_batch_still_in_its_clinic_day_cannot_be_asked_for(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'Fit');
        $this->addStudent($batch, 'booked');

        // Back to the clinic day itself, mid-morning: In progress.
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 10:30:00'));
        $this->assertSame('in_progress', $batch->fresh()->resultsStatus());

        $this->actingAs($this->admin)->post($this->storeUrl($batch->id), $this->payload())
            ->assertRedirect(route('admin.batches.results', $batch->id))
            ->assertSessionHas('error', 'Documents can be requested once the batch is completed.');

        $this->assertSame(0, DocumentReleaseRequest::count());
    }

    public function test_a_batch_no_one_attended_cannot_be_asked_for(): void
    {
        $batch = $this->makeBatch();
        $this->addStudent($batch, 'booked');
        $this->addStudent($batch, 'booked');

        $this->assertSame('no_one_attended', $batch->fresh()->resultsStatus());

        $this->actingAs($this->admin)->post($this->storeUrl($batch->id), $this->payload())
            ->assertSessionHas('error');

        $this->assertSame(0, DocumentReleaseRequest::count());
    }

    public function test_only_one_request_may_wait_at_a_time(): void
    {
        $batch = $this->completedBatch();
        $this->release($batch, 'pending');

        $this->actingAs($this->admin)->post($this->storeUrl($batch->id), $this->payload())
            ->assertSessionHas('error', 'A release request for this batch is already waiting for the Director.');

        $this->assertSame(1, DocumentReleaseRequest::count());
    }

    public function test_a_released_batch_cannot_be_asked_for_again(): void
    {
        $batch = $this->completedBatch();
        $this->release($batch, 'approved');

        $this->actingAs($this->admin)->post($this->storeUrl($batch->id), $this->payload())
            ->assertSessionHas('error', 'The Director has already released this batch’s documents.');

        $this->assertSame(1, DocumentReleaseRequest::count());
    }

    public function test_after_a_rejection_the_college_can_ask_again_and_the_old_request_stays(): void
    {
        $batch = $this->completedBatch();
        $rejected = $this->release($batch, 'rejected');

        $this->actingAs($this->admin)->post($this->storeUrl($batch->id), $this->payload())
            ->assertSessionHas('status');

        $this->assertSame(['rejected', 'pending'], $batch->releaseRequests()->orderBy('id')->pluck('status')->all());
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame('pending', $batch->fresh()->latestReleaseRequest->status);
    }

    public function test_another_colleges_batch_or_a_batch_that_is_not_approved_is_a_404(): void
    {
        $foreign = $this->completedBatch(college: $this->coe);
        $pending = $this->makeBatch(status: 'pending');
        $rejected = $this->makeBatch(status: 'rejected');

        foreach ([$foreign, $pending, $rejected] as $batch) {
            $this->actingAs($this->admin)->post($this->storeUrl($batch->id), $this->payload())->assertNotFound();
        }

        $this->assertSame(0, DocumentReleaseRequest::count());
    }

    public function test_the_reason_is_required_and_between_10_and_500_characters(): void
    {
        $batch = $this->completedBatch();

        // 'Too short' is 9 characters.
        foreach (['', 'Too short', Str::repeat('a', 501)] as $reason) {
            $this->actingAs($this->admin)
                ->post($this->storeUrl($batch->id), $this->payload(['reason' => $reason]))
                ->assertSessionHasErrors('reason');
        }

        // Exactly 10 is enough.
        $this->actingAs($this->admin)
            ->post($this->storeUrl($batch->id), $this->payload(['reason' => 'Ten chars!']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DocumentReleaseRequest::count());
    }

    public function test_printed_copies_must_be_a_yes_or_no(): void
    {
        $batch = $this->completedBatch();

        $this->actingAs($this->admin)
            ->post($this->storeUrl($batch->id), $this->payload(['wants_printed_copies' => 'please']))
            ->assertSessionHasErrors('wants_printed_copies');

        $this->assertSame(0, DocumentReleaseRequest::count());
    }

    public function test_other_roles_cannot_request_a_release(): void
    {
        $batch = $this->completedBatch();
        $student = User::factory()->create(['role' => 'student']);

        // Like every /admin page, another role is sent to its own dashboard (FR-AUTH-03).
        foreach ([$this->director, $this->nurse, $student] as $user) {
            $this->actingAs($user)->post($this->storeUrl($batch->id), $this->payload())->assertRedirect();
        }

        auth()->logout();
        $this->post($this->storeUrl($batch->id), $this->payload())->assertRedirect(route('login'));

        $this->assertSame(0, DocumentReleaseRequest::count());
    }
}
