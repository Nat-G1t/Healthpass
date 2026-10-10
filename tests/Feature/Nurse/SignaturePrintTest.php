<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\BatchRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\User;
use App\Support\VisitDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DocumentReleaseFixture;
use Tests\Support\MakesSignatures;
use Tests\TestCase;
use ZipArchive;

/**
 * D-134 — the printed forms carry the signer's CURRENT uploaded signature as
 * a base64 data URI (App\Support\Signature), in the browser print, the PDF
 * and the batch ZIP alike:
 *
 *   - Medical Clearance: the encoding physician's, above their name;
 *   - Medical Assessment: the interviewing nurse's above "Interviewed/Assessed
 *     by", the encoding physician's above "University Physician"; a record
 *     from before D-126 (no interviewer) carries its encoder's, only where
 *     the encoder's name prints.
 *
 * With none on file the blank line prints, and each form still fits its pages.
 */
class SignaturePrintTest extends TestCase
{
    use DocumentReleaseFixture;
    use MakesSignatures;
    use RefreshDatabase;

    private User $physician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRelease();   // fakes the `local` disk, so signatures land there too

        $this->physician = User::factory()->physician('Reynaldo S. Alipio', '60252')->create();
        $this->nurse->update(['name' => 'Jane Dela Cruz']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * One encoded student of a released batch of $formType. By default the
     * physician encoded it; $interviewer (Assessment) is the D-126 nurse.
     */
    private function encodedVisit(string $formType, ?User $encoder = null, ?User $interviewer = null, ?BatchRequest $batch = null): ClinicVisit
    {
        $encoder ??= $this->physician;
        $batch ??= $this->makeBatch($formType);
        $visit = $this->visitOf($this->addStudent($batch, 'Fit'));

        $visit->clearanceRecord->update([
            'encoded_by' => $encoder->id,
            ...ClearanceRecord::physicianBlockFor($encoder),
        ]);

        if ($interviewer !== null) {
            $visit->update(['interviewed_by' => $interviewer->id, 'interviewed_at' => $visit->checked_in_at]);
        }

        if (! $batch->hasReleasedDocuments()) {
            $this->release($batch, 'approved');
        }

        return $visit->fresh();
    }

    private function printHtml(ClinicVisit $visit, ?string $side = null): string
    {
        $url = route('nurse.visits.print', $visit).($side ? "?side={$side}" : '');

        return $this->actingAs($this->nurse)->get($url)->assertOk()->getContent();
    }

    /** The signature image immediately above the name line that reads $name. */
    private function signatureAbove(string $b64, string $name): string
    {
        return '~<img src="data:image/png;base64,'.preg_quote($b64, '~').'"[^>]*>\s*</td>\s*</tr>\s*</table>\s*(</div>\s*)?'
            .'<div class="name">'.preg_quote($name, '~').'</div>~u';
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('~/Type\s*/Page(?![s])~', $pdf);
    }

    /** How many images dompdf embedded (logos, plus a signature and its alpha mask). */
    private function imageCount(string $pdf): int
    {
        return preg_match_all('~/Subtype\s*/Image~', $pdf);
    }

    // ── 1. Medical Clearance ─────────────────────────────────────────────────

    public function test_the_clearance_prints_the_encoding_physicians_signature_in_the_physician_block(): void
    {
        $b64 = $this->giveSignature($this->physician);
        $visit = $this->encodedVisit('clearance');

        $html = $this->printHtml($visit);

        $this->assertMatchesRegularExpression($this->signatureAbove($b64, 'REYNALDO S. ALIPIO, MD'), $html);
        $this->assertSame(1, substr_count($html, 'alt="Signature"'));
    }

    public function test_without_a_signature_the_clearance_prints_no_image_there(): void
    {
        $visit = $this->encodedVisit('clearance');

        $html = $this->printHtml($visit);

        $this->assertStringNotContainsString('alt="Signature"', $html);
        // The blank space is still there for a wet signature.
        $this->assertStringContainsString('<div class="sig-space">', $html);
    }

    /** A nurse's pre-D-126 record prints no physician name, so no signature either. */
    public function test_a_nurse_encoded_clearance_carries_no_signature(): void
    {
        $this->giveSignature($this->nurse);
        $visit = $this->encodedVisit('clearance', encoder: $this->nurse);

        $this->assertStringNotContainsString('alt="Signature"', $this->printHtml($visit));
    }

    /** Always the CURRENT signature: a replaced one prints on the next print. */
    public function test_a_reprint_uses_the_current_signature(): void
    {
        $old = $this->giveSignature($this->physician, shade: 10);
        $visit = $this->encodedVisit('clearance');
        $this->assertStringContainsString($old, $this->printHtml($visit));

        $new = $this->giveSignature($this->physician, shade: 99);
        $html = $this->printHtml($visit);

        $this->assertStringContainsString($new, $html);
        $this->assertStringNotContainsString($old, $html);
    }

    // ── 2. Medical Assessment ────────────────────────────────────────────────

    public function test_the_assessment_prints_the_nurses_and_the_physicians_signatures_each_in_its_spot(): void
    {
        $nurseB64 = $this->giveSignature($this->nurse, shade: 10);
        $physicianB64 = $this->giveSignature($this->physician, shade: 99);
        $visit = $this->encodedVisit('assessment', interviewer: $this->nurse);

        $html = $this->printHtml($visit, 'back');

        $this->assertMatchesRegularExpression($this->signatureAbove($nurseB64, 'Jane Dela Cruz'), $html);
        $this->assertMatchesRegularExpression($this->signatureAbove($physicianB64, 'REYNALDO S. ALIPIO, MD'), $html);
        $this->assertSame(2, substr_count($html, 'alt="Signature"'));
    }

    public function test_only_the_signer_with_a_signature_on_file_gets_one(): void
    {
        $physicianB64 = $this->giveSignature($this->physician);   // the nurse has none
        $visit = $this->encodedVisit('assessment', interviewer: $this->nurse);

        $html = $this->printHtml($visit, 'back');

        $this->assertMatchesRegularExpression($this->signatureAbove($physicianB64, 'REYNALDO S. ALIPIO, MD'), $html);
        $this->assertSame(1, substr_count($html, 'alt="Signature"'));
    }

    /** Pre-D-126: no interviewer — the encoder's signature, only where their name prints. */
    public function test_an_old_record_without_an_interviewer_carries_only_the_encoders_signature(): void
    {
        $nurseB64 = $this->giveSignature($this->nurse, shade: 10);
        $this->giveSignature($this->physician, shade: 99);
        $visit = $this->encodedVisit('assessment', encoder: $this->nurse);

        $html = $this->printHtml($visit, 'back');

        $this->assertMatchesRegularExpression($this->signatureAbove($nurseB64, 'Jane Dela Cruz'), $html);
        // The University Physician line is blank (a nurse encoded it), so no signature there.
        $this->assertMatchesRegularExpression('~</table>\s*<div class="name"></div>\s*<div class="center">University Physician</div>~u', $html);
        $this->assertSame(1, substr_count($html, 'alt="Signature"'));
    }

    public function test_the_front_page_carries_no_clinic_signature(): void
    {
        $this->giveSignature($this->nurse, shade: 10);
        $this->giveSignature($this->physician, shade: 99);
        $visit = $this->encodedVisit('assessment', interviewer: $this->nurse);

        $this->assertStringNotContainsString('alt="Signature"', $this->printHtml($visit, 'front'));
    }

    // ── 3. PDF (dompdf, the same templates) ──────────────────────────────────

    public function test_the_clearance_pdf_renders_the_signature_and_stays_one_page(): void
    {
        $visit = $this->encodedVisit('clearance');
        $unsigned = $this->actingAs($this->nurse)->get(route('nurse.visits.pdf', $visit))->assertOk()->getContent();

        $this->giveSignature($this->physician);
        $signed = $this->actingAs($this->nurse)->get(route('nurse.visits.pdf', $visit))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->getContent();

        $this->assertSame(1, $this->pageCount($signed));
        $this->assertGreaterThan($this->imageCount($unsigned), $this->imageCount($signed));
    }

    public function test_the_assessment_pdf_renders_both_signatures_and_stays_two_pages(): void
    {
        $visit = $this->encodedVisit('assessment', interviewer: $this->nurse);
        $unsigned = $this->actingAs($this->nurse)->get(route('nurse.visits.pdf', $visit))->assertOk()->getContent();

        $this->giveSignature($this->nurse, shade: 10);
        $physicianSigned = $this->actingAs($this->nurse)->get(route('nurse.visits.pdf', $visit))->assertOk()->getContent();
        $this->giveSignature($this->physician, shade: 99);
        $bothSigned = $this->actingAs($this->nurse)->get(route('nurse.visits.pdf', $visit))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->getContent();

        $this->assertSame(2, $this->pageCount($bothSigned));
        $this->assertGreaterThan($this->imageCount($unsigned), $this->imageCount($physicianSigned));
        $this->assertGreaterThan($this->imageCount($physicianSigned), $this->imageCount($bothSigned));
    }

    /** The widest signature the upload allows still fits its slot and the page. */
    public function test_an_extreme_wide_signature_still_fits_one_page(): void
    {
        $this->giveSignature($this->physician, 3000, 50);
        $visit = $this->encodedVisit('clearance');

        $html = $this->printHtml($visit);
        $this->assertMatchesRegularExpression('~alt="Signature"\s*style="width: 144pt; height: 2\.4pt;"~', $html);

        $pdf = $this->actingAs($this->nurse)->get(route('nurse.visits.pdf', $visit))->assertOk()->getContent();
        $this->assertSame(1, $this->pageCount($pdf));
    }

    // ── 4. The batch ZIP ─────────────────────────────────────────────────────

    public function test_a_form_in_the_downloaded_zip_carries_the_signature(): void
    {
        $this->giveSignature($this->physician);
        $batch = $this->makeBatch('clearance');
        $signed = $this->encodedVisit('clearance', batch: $batch);
        $unsigned = $this->encodedVisit('clearance', encoder: $this->nurse, batch: $batch);

        $response = $this->actingAs($this->director)
            ->post(route('director.tracking.documents', $batch), ['visits' => [$signed->id, $unsigned->id]])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $pdfs = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $pdfs[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();

        $nameOf = fn (ClinicVisit $visit): string => VisitDocument::filename($visit);
        $signedPdf = $pdfs[$nameOf($signed)];
        $unsignedPdf = $pdfs[$nameOf($unsigned)];

        $this->assertSame(1, $this->pageCount($signedPdf));
        $this->assertGreaterThan($this->imageCount($unsignedPdf), $this->imageCount($signedPdf));
    }
}
