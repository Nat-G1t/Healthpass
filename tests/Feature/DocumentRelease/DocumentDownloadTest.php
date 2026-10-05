<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentRelease;

use App\Models\BatchRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\DocumentReleaseFixture;
use Tests\TestCase;
use ZipArchive;

/**
 * D-113 — the selected students' official forms as ONE ZIP (Q15): one PDF per
 * student, named "{Batch ID}-{Surname}-{Student ID}.pdf" (D-114 — it was
 * "{Batch ID}-{Student No}-{Last name}.pdf"), inside "{Batch ID}-documents.zip".
 *
 * Two routes, one rule (App\Services\BatchDocumentZip):
 *   - the College Admin's — only after the Director released the batch (403
 *     before), only their own college's batch (404 otherwise)
 *   - the Director's — always, for any approved batch
 * Every posted id must be the ENCODED visit of a held roster row of THIS
 * batch; anything else, or more than the cap, is a 422. The browser's list is
 * never trusted.
 */
class DocumentDownloadTest extends TestCase
{
    use DocumentReleaseFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRelease();
    }

    private function adminDownload(BatchRequest $batch, array $visitIds): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('admin.batches.documents', $batch->id), ['visits' => $visitIds]);
    }

    private function directorDownload(BatchRequest $batch, array $visitIds): TestResponse
    {
        return $this->actingAs($this->director)
            ->post(route('director.tracking.documents', $batch), ['visits' => $visitIds]);
    }

    /**
     * The entries of the ZIP a download response carries, name => bytes.
     *
     * @return array<string, string>
     */
    private function zipEntries(TestResponse $response): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $entries[$name] = $zip->getFromIndex($i);
        }
        $zip->close();

        ksort($entries);

        return $entries;
    }

    // ── College Admin ────────────────────────────────────────────────────────

    public function test_the_admin_cannot_download_before_the_director_releases_the_batch(): void
    {
        $batch = $this->makeBatch();
        $visit = $this->visitOf($this->addStudent($batch, 'Fit'));

        // No request, a pending one, a rejected one: still not released.
        $this->adminDownload($batch, [$visit->id])->assertForbidden();

        $this->release($batch, 'rejected');
        $this->adminDownload($batch, [$visit->id])->assertForbidden();

        $this->release($batch, 'pending');
        $this->adminDownload($batch, [$visit->id])->assertForbidden();
    }

    public function test_after_release_the_admin_gets_one_pdf_per_selected_student(): void
    {
        $batch = $this->makeBatch();
        $cruz = $this->visitOf($this->addStudent($batch, 'Fit', 'Ana Cruz'));
        $delaCruz = $this->visitOf($this->addStudent($batch, 'Unfit', 'Ben Dela Cruz'));
        $penaflor = $this->visitOf($this->addStudent($batch, 'Fit', 'Liza Peñaflor'));
        $this->addStudent($batch, 'Fit', 'Not Selected');
        $this->release($batch, 'approved');

        $response = $this->adminDownload($batch, [$cruz->id, $delaCruz->id, $penaflor->id])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertDownload($batch->reference_no.'-documents.zip');

        $entries = $this->zipEntries($response);

        $number = fn ($visit): string => $visit->student->studentProfile->student_number;
        $this->assertSame([
            "{$batch->reference_no}-Cruz-{$number($cruz)}.pdf",
            "{$batch->reference_no}-Dela-Cruz-{$number($delaCruz)}.pdf",
            "{$batch->reference_no}-Penaflor-{$number($penaflor)}.pdf",
        ], array_keys($entries));

        foreach ($entries as $bytes) {
            $this->assertStringStartsWith('%PDF-', $bytes);
        }
    }

    public function test_a_medical_assessment_batch_downloads_its_own_form(): void
    {
        $batch = $this->makeBatch('assessment');
        $visit = $this->visitOf($this->addStudent($batch, 'Fit'));
        $this->release($batch, 'approved');

        $entries = $this->zipEntries($this->adminDownload($batch, [$visit->id])->assertOk());

        $this->assertCount(1, $entries);
        $this->assertStringStartsWith('%PDF-', array_values($entries)[0]);
    }

    public function test_the_temporary_zip_is_deleted_once_sent(): void
    {
        $batch = $this->completedBatch(1);
        $visit = $batch->appointments()->first()->clinicVisit;
        $this->release($batch, 'approved');

        $response = $this->adminDownload($batch, [$visit->id])->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();

        // It was written under storage/app/private/tmp …
        $this->assertStringContainsString('tmp', $path);
        $this->assertFileExists($path);

        // … and is gone after the response is sent.
        ob_start();
        $response->baseResponse->sendContent();
        ob_end_clean();
        $this->assertFileDoesNotExist($path);
        $this->assertSame([], Storage::disk('local')->allFiles('tmp'));
    }

    public function test_another_colleges_batch_is_a_404_even_when_released(): void
    {
        $foreign = $this->makeBatch(college: $this->coe);
        $visit = $this->visitOf($this->addStudent($foreign, 'Fit'));
        $this->release($foreign, 'approved');

        $this->adminDownload($foreign, [$visit->id])->assertNotFound();
    }

    public function test_the_admin_can_only_download_encoded_visits_of_this_batch(): void
    {
        $batch = $this->makeBatch();
        $encoded = $this->visitOf($this->addStudent($batch, 'Fit'));
        $captured = $this->visitOf($this->addStudent($batch, 'in_clinic'));
        $resting = $this->visitOf($this->addStudent($batch, 'resting'));
        $this->release($batch, 'approved');

        // A visit of another batch (same college, released too).
        $otherBatch = $this->makeBatch();
        $otherVisit = $this->visitOf($this->addStudent($otherBatch, 'Fit'));
        $this->release($otherBatch, 'approved');

        // A roster row withdrawn before D-87 — even with an encoded visit. (The
        // fixture's encode completes the appointment; put the withdrawal back.)
        $withdrawn = $this->addStudent($batch, 'withdrawn');
        $withdrawnVisit = $this->visit($withdrawn, 'Fit');
        $withdrawn->update(['status' => 'cancelled']);

        foreach ([[$captured->id], [$resting->id], [$otherVisit->id], [$withdrawnVisit->id], [$encoded->id, 999999]] as $ids) {
            $this->adminDownload($batch, $ids)->assertStatus(422);
        }

        $this->adminDownload($batch, [$encoded->id])->assertOk();
    }

    public function test_malformed_selections_are_refused(): void
    {
        $batch = $this->makeBatch();
        $visit = $this->visitOf($this->addStudent($batch, 'Fit'));
        $this->release($batch, 'approved');

        $this->actingAs($this->admin)->post(route('admin.batches.documents', $batch->id), [])->assertStatus(422);
        $this->adminDownload($batch, [])->assertStatus(422);
        $this->adminDownload($batch, [$visit->id, $visit->id])->assertStatus(422);
        $this->adminDownload($batch, ['abc'])->assertStatus(422);
        $this->actingAs($this->admin)
            ->post(route('admin.batches.documents', $batch->id), ['visits' => (string) $visit->id])
            ->assertStatus(422);
    }

    public function test_more_than_the_cap_is_refused(): void
    {
        config(['healthpass.documents.max_per_download' => 2]);

        $batch = $this->completedBatch(3);
        $this->release($batch, 'approved');
        $ids = $batch->appointments()->with('clinicVisit')->get()->pluck('clinicVisit.id')->all();

        $this->adminDownload($batch, $ids)->assertStatus(422);
        $this->adminDownload($batch, array_slice($ids, 0, 2))->assertOk();
    }

    // ── Director ─────────────────────────────────────────────────────────────

    public function test_the_director_downloads_without_any_request(): void
    {
        $batch = $this->makeBatch(college: $this->coe);
        $visit = $this->visitOf($this->addStudent($batch, 'Unfit', 'Rico Santos'));

        $this->assertSame(0, $batch->releaseRequests()->count());

        $response = $this->directorDownload($batch, [$visit->id])
            ->assertOk()
            ->assertDownload($batch->reference_no.'-documents.zip');

        $this->assertSame(
            ["{$batch->reference_no}-Santos-{$visit->student->studentProfile->student_number}.pdf"],
            array_keys($this->zipEntries($response)),
        );
    }

    public function test_the_director_is_held_to_the_same_rule(): void
    {
        $batch = $this->makeBatch();
        $captured = $this->visitOf($this->addStudent($batch, 'in_clinic'));
        $otherVisit = $this->visitOf($this->addStudent($this->makeBatch(), 'Fit'));

        $this->directorDownload($batch, [$captured->id])->assertStatus(422);
        $this->directorDownload($batch, [$otherVisit->id])->assertStatus(422);
    }

    public function test_the_director_cannot_download_a_batch_that_is_not_approved(): void
    {
        $pending = $this->makeBatch(status: 'pending');

        $this->directorDownload($pending, [1])->assertNotFound();
    }

    // ── Everyone else ────────────────────────────────────────────────────────

    public function test_students_and_the_clinic_get_no_zip_from_these_routes(): void
    {
        $batch = $this->makeBatch();
        $visit = $this->visitOf($this->addStudent($batch, 'Fit'));
        $this->release($batch, 'approved');
        $student = $visit->student;

        foreach ([$student, $this->nurse] as $user) {
            foreach ([route('admin.batches.documents', $batch->id), route('director.tracking.documents', $batch)] as $url) {
                $response = $this->actingAs($user)->post($url, ['visits' => [$visit->id]])->assertRedirect();
                $this->assertNotSame('application/zip', $response->headers->get('Content-Type'));
            }
        }

        // D-106 regression: the student's own Save as PDF route stays gone.
        $this->actingAs($student)->get("/student/records/{$visit->id}/pdf")->assertNotFound();
    }

    public function test_the_admin_of_a_released_batch_cannot_reach_a_director_route(): void
    {
        $batch = $this->makeBatch();
        $visit = $this->visitOf($this->addStudent($batch, 'Fit'));
        $this->release($batch, 'approved');

        $this->actingAs($this->admin)
            ->post(route('director.tracking.documents', $batch), ['visits' => [$visit->id]])
            ->assertRedirect();
    }
}
