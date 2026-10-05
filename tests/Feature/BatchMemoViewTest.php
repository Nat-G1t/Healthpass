<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Who can open a batch's signed memo, and how it is shown (D-109).
 *
 *   GET /director/batches/{batch}/memo  — the Director, any batch
 *   GET /admin/batches/{batch}/memo     — that batch's OWN college admins;
 *                                         another college's admin gets 404
 *
 * Every other role is turned away by the route group's role middleware, which
 * (like every role-scoped page here, FR-AUTH-03) sends them back to their own
 * dashboard — they never receive the file.
 */
class BatchMemoViewTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $cea;

    private User $director;

    private User $admin;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->cea = College::create(['code' => 'CEA', 'name' => 'College of Engineering and Architecture']);
        $this->director = User::factory()->create(['role' => 'director']);
        $this->admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
        $this->otherAdmin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->cea->id]);
    }

    /** A pending CCS batch of two students; pass memo columns through $overrides. */
    private function makeBatch(array $overrides = []): BatchRequest
    {
        static $seq = 500;

        $batch = BatchRequest::create(array_merge([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'service_type' => 'medical',
            'status' => 'pending',
        ], $overrides));

        foreach (StudentProfile::factory()->count(2)->forCollege($this->ccs)->create() as $profile) {
            BatchRequestStudent::create(['batch_request_id' => $batch->id, 'student_id' => $profile->user_id]);
        }

        return $batch;
    }

    /** A batch whose memo file really sits on the (fake) private disk. */
    private function batchWithMemo(string $mime = 'image/png', string $name = 'signed-memo.png'): BatchRequest
    {
        $path = 'memos/'.Str::random(40).($mime === 'application/pdf' ? '.pdf' : '.png');
        Storage::disk('local')->put($path, 'memo-bytes');

        return $this->makeBatch(['memo_path' => $path, 'memo_original_name' => $name, 'memo_mime' => $mime]);
    }

    private function directorUrl(BatchRequest $batch): string
    {
        return route('director.batches.memo', $batch);
    }

    private function adminUrl(BatchRequest $batch): string
    {
        return route('admin.batches.memo', $batch);
    }

    // ── Serving ──────────────────────────────────────────────────────────────

    public function test_the_director_opens_the_memo_inline_with_its_stored_type(): void
    {
        $batch = $this->batchWithMemo('image/png', 'signed-memo.png');

        $response = $this->actingAs($this->director)->get($this->directorUrl($batch))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('signed-memo.png', $response->headers->get('Content-Disposition'));
        $this->assertSame('memo-bytes', $response->streamedContent());
    }

    public function test_a_pdf_memo_is_served_as_a_pdf(): void
    {
        $batch = $this->batchWithMemo('application/pdf', 'memo.pdf');

        $this->actingAs($this->director)->get($this->directorUrl($batch))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_batchs_own_college_admin_can_open_it(): void
    {
        $batch = $this->batchWithMemo();

        $response = $this->actingAs($this->admin)->get($this->adminUrl($batch))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertSame('memo-bytes', $response->streamedContent());
    }

    public function test_another_colleges_admin_gets_a_404(): void
    {
        $batch = $this->batchWithMemo();

        $this->actingAs($this->otherAdmin)->get($this->adminUrl($batch))->assertNotFound();
    }

    public function test_other_roles_are_turned_away_from_both_routes(): void
    {
        $batch = $this->batchWithMemo();

        $homes = ['student' => '/student/dashboard', 'nurse' => '/nurse/dashboard', 'physician' => '/nurse/dashboard'];

        foreach ($homes as $role => $home) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get($this->directorUrl($batch))->assertRedirect($home);
            $this->actingAs($user)->get($this->adminUrl($batch))->assertRedirect($home);
        }

        // Neither role reaches the other's route either.
        $this->actingAs($this->admin)->get($this->directorUrl($batch))->assertRedirect('/admin/dashboard');
        $this->actingAs($this->director)->get($this->adminUrl($batch))->assertRedirect('/director/dashboard');
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $batch = $this->batchWithMemo();

        $this->get($this->directorUrl($batch))->assertRedirect('/login');
        $this->get($this->adminUrl($batch))->assertRedirect('/login');
    }

    public function test_a_batch_with_no_memo_is_a_404_on_both_routes(): void
    {
        $batch = $this->makeBatch();

        $this->actingAs($this->director)->get($this->directorUrl($batch))->assertNotFound();
        $this->actingAs($this->admin)->get($this->adminUrl($batch))->assertNotFound();
    }

    public function test_a_memo_whose_file_is_missing_is_a_404(): void
    {
        $batch = $this->makeBatch(['memo_path' => 'memos/gone.png', 'memo_original_name' => 'gone.png', 'memo_mime' => 'image/png']);

        $this->actingAs($this->director)->get($this->directorUrl($batch))->assertNotFound();
    }

    // ── Card 3 on the Director's Review page ─────────────────────────────────

    public function test_the_review_page_shows_an_image_memo_as_a_thumbnail(): void
    {
        $batch = $this->batchWithMemo('image/png', 'signed-memo.png');

        $this->actingAs($this->director)->get(route('director.batches.review', $batch))
            ->assertOk()
            ->assertSeeInOrder(['Students in this batch', 'Signed memo', 'Schedule'])
            ->assertSee('data-memo-thumbnail', false)
            ->assertDontSee('data-memo-pdf-tile', false)
            ->assertSee('src="'.$this->directorUrl($batch).'"', false)
            ->assertSee('signed-memo.png')
            // The full-screen viewer and its controls.
            ->assertSee('data-file-viewer', false)
            ->assertSee('aria-label="Zoom in"', false)
            ->assertSee('aria-label="Zoom out"', false)
            ->assertSee('aria-label="Reset zoom"', false)
            ->assertSee('aria-label="Close"', false);
    }

    public function test_the_review_page_shows_a_pdf_memo_as_a_tile(): void
    {
        $batch = $this->batchWithMemo('application/pdf', 'Signed memo.pdf');

        $this->actingAs($this->director)->get(route('director.batches.review', $batch))
            ->assertOk()
            ->assertSee('data-memo-pdf-tile', false)
            ->assertDontSee('data-memo-thumbnail', false)
            ->assertSee('Signed memo.pdf')
            ->assertSee('PDF · 1 KB')
            // The browser's own PDF viewer, in a frame that loads the Director route.
            ->assertSee('<iframe src="'.$this->directorUrl($batch).'"', false)
            ->assertDontSee('aria-label="Zoom in"', false);
    }

    public function test_the_review_page_explains_a_batch_from_before_memos(): void
    {
        $batch = $this->makeBatch();

        $this->actingAs($this->director)->get(route('director.batches.review', $batch))
            ->assertOk()
            ->assertSee('No memo attached — submitted before signed memos were required.')
            ->assertDontSee('data-file-viewer', false);
    }

    // ── The College Admin's pages ────────────────────────────────────────────

    public function test_the_submitted_and_roster_pages_link_to_the_memo(): void
    {
        $batch = $this->batchWithMemo();

        foreach (['admin.batches.confirmation', 'admin.batches.show'] as $page) {
            $this->actingAs($this->admin)->get(route($page, $batch))
                ->assertOk()
                ->assertSee('Signed memo')
                ->assertSee('href="'.$this->adminUrl($batch).'" target="_blank"', false);
        }
    }

    public function test_the_admin_pages_say_when_a_batch_has_no_memo(): void
    {
        $batch = $this->makeBatch();

        foreach (['admin.batches.confirmation', 'admin.batches.show'] as $page) {
            $this->actingAs($this->admin)->get(route($page, $batch))
                ->assertOk()
                ->assertSee('None — submitted before signed memos were required')
                ->assertDontSee($this->adminUrl($batch), false);
        }
    }
}
