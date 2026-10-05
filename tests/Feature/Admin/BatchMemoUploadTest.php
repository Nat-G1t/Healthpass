<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\BatchRequest;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ReferenceNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * D-109: every new batch request carries a signed memo — one JPG, PNG or PDF,
 * up to 5 MB, stored on the PRIVATE `local` disk under memos/.
 *
 * Storage::fake('local') swaps the disk for a throwaway one, so these tests
 * never write into storage/app/private.
 */
class BatchMemoUploadTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private User $admin;

    /** Real temp files this test made (see realFile()); removed in tearDown. */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /** POST a batch of two CCS students; add the memo (or break something) through $overrides. */
    private function submit(array $overrides = [])
    {
        $students = StudentProfile::factory()->count(2)->forCollege($this->ccs)->create();

        return $this->actingAs($this->admin)->post('/admin/batches', array_merge([
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'students' => $students->pluck('id')->all(),
        ], $overrides));
    }

    /**
     * An upload whose type the server must work out from the BYTES.
     *
     * UploadedFile::fake() reports a MIME type guessed from the file NAME, so a
     * fake "x.pdf" always passes as a PDF whatever it contains. A real temp file
     * in test mode is read by PHP's fileinfo, exactly like a real upload.
     */
    private function realFile(string $name, string $content, string $clientMime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'memo');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, $clientMime, null, true);
    }

    private function assertNothingStored(): void
    {
        $this->assertDatabaseCount('batch_requests', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ── Required ─────────────────────────────────────────────────────────────

    public function test_a_batch_without_a_memo_is_refused(): void
    {
        $this->submit()->assertSessionHasErrors(['memo' => 'Please attach the signed memo.']);

        $this->assertNothingStored();
    }

    // ── Accepted: JPG, PNG, PDF ──────────────────────────────────────────────

    public static function acceptedMemos(): array
    {
        return [
            'a JPG photo' => ['memo.jpg', 'image/jpeg'],
            'a PNG image' => ['scan.png', 'image/png'],
            'a PDF' => ['Signed memo.pdf', 'application/pdf'],
        ];
    }

    #[DataProvider('acceptedMemos')]
    public function test_a_jpg_png_or_pdf_memo_is_stored_privately(string $name, string $mime): void
    {
        $memo = str_ends_with($name, '.pdf')
            ? UploadedFile::fake()->create($name, 300, $mime)
            : UploadedFile::fake()->image($name);

        $this->submit(['memo' => $memo])->assertSessionHasNoErrors();

        $batch = BatchRequest::sole();
        $this->assertTrue($batch->hasMemo());
        $this->assertStringStartsWith('memos/', $batch->memo_path);
        Storage::disk('local')->assertExists($batch->memo_path);
        $this->assertSame($name, $batch->memo_original_name);
        $this->assertSame($mime, $batch->memo_mime);
    }

    public function test_the_stored_type_is_the_servers_reading_not_the_browsers_claim(): void
    {
        // A genuine PDF the browser labelled as something else.
        $pdf = $this->realFile('memo.pdf', "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n", 'application/octet-stream');

        $this->submit(['memo' => $pdf])->assertSessionHasNoErrors();

        $batch = BatchRequest::sole();
        $this->assertSame('application/pdf', $batch->memo_mime);
        Storage::disk('local')->assertExists($batch->memo_path);
    }

    // ── Refused ──────────────────────────────────────────────────────────────

    public function test_a_gif_is_refused(): void
    {
        $this->submit(['memo' => UploadedFile::fake()->image('memo.gif')])
            ->assertSessionHasErrors(['memo' => 'The memo must be a JPG, PNG or PDF.']);

        $this->assertNothingStored();
    }

    public function test_a_text_file_renamed_pdf_is_refused(): void
    {
        $this->submit(['memo' => $this->realFile('memo.pdf', 'not a pdf', 'application/pdf')])
            ->assertSessionHasErrors(['memo' => 'The memo must be a JPG, PNG or PDF.']);

        $this->assertNothingStored();
    }

    public function test_a_file_over_5_mb_is_refused(): void
    {
        $this->submit(['memo' => UploadedFile::fake()->create('memo.pdf', 6 * 1024, 'application/pdf')])
            ->assertSessionHasErrors(['memo' => 'The memo must be 5 MB or smaller.']);

        $this->assertNothingStored();
    }

    // ── No orphan files ──────────────────────────────────────────────────────

    public function test_the_stored_memo_is_deleted_when_the_batch_cannot_be_saved(): void
    {
        // The file is written before the transaction; make the transaction fail.
        $this->mock(ReferenceNumberService::class)
            ->shouldReceive('generateBatchRef')
            ->andThrow(new RuntimeException('Simulated failure while saving the batch.'));

        $this->submit(['memo' => UploadedFile::fake()->image('memo.jpg')])->assertStatus(500);

        $this->assertNothingStored();
        $this->assertDatabaseCount('batch_request_students', 0);
    }

    // ── The New Batch form ───────────────────────────────────────────────────

    public function test_the_new_batch_form_is_multipart_and_has_the_memo_card(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/batches/create')
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('Attach signed memo')
            ->assertSee('A photo or PDF of the signed request memo. The Director reads it before scheduling.')
            // <x-hp.file-capture name="memo">: the two buttons and their inputs.
            ->assertSee('data-file-capture="memo"', false)
            ->assertSee('Take photo')
            ->assertSee('capture="environment"', false)
            ->assertSee('Upload file')
            ->assertSee('accept=".jpg,.jpeg,.png,.pdf"', false)
            ->assertSee('JPG, PNG or PDF, up to 5 MB.')
            // The browser's quick check speaks the server's words.
            ->assertSee('The memo must be a JPG, PNG or PDF.')
            ->assertSee('The memo must be 5 MB or smaller.');
    }

    public function test_a_missing_memo_shows_the_servers_message_on_the_form(): void
    {
        $this->actingAs($this->admin)->from('/admin/batches/create')
            ->followingRedirects()
            ->post('/admin/batches', ['form_type' => 'clearance', 'reason' => 'fieldtrip', 'students' => []])
            ->assertOk()
            ->assertSee('Please attach the signed memo.');
    }

    public function test_after_another_error_the_form_asks_for_the_memo_again(): void
    {
        // A browser never refills a file input, so the memo chosen for a
        // submit that failed on something else is gone when the page returns.
        $this->actingAs($this->admin)->from('/admin/batches/create')
            ->followingRedirects()
            ->post('/admin/batches', [
                'form_type' => 'clearance',
                'reason' => 'fieldtrip',
                'students' => [],
                'memo' => UploadedFile::fake()->image('memo.jpg'),
            ])
            ->assertOk()
            ->assertSee('Please attach the memo again.')
            ->assertDontSee('Please attach the signed memo.');

        $this->assertNothingStored();
    }
}
