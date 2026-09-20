<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\StudentRecordFixture;
use Tests\TestCase;

/**
 * FR-STU-15 / D-73 — the student's own Save as PDF: the SAME document the
 * clinic prints (D-67/D-71), as ONE file. One Letter page for a Medical
 * Clearance; both Legal pages together for a Medical Assessment Form, so a
 * print shop can run it back-to-back.
 *
 * dompdf is a pure-PHP renderer, so these tests really do lay out and produce
 * a PDF; nothing is mocked.
 */
class RecordPdfTest extends TestCase
{
    use RefreshDatabase;
    use StudentRecordFixture;

    /** dompdf writes one `/Type /Page` object per page (the page TREE is `/Type /Pages`). */
    private function pageCount(string $pdf): int
    {
        return preg_match_all('~/Type\s*/Page(?![s])~', $pdf);
    }

    // ── 1. The download ──────────────────────────────────────────────────────

    public function test_a_student_downloads_their_clearance_as_a_pdf(): void
    {
        $student = $this->makeStudent();
        $visit = $this->encodedVisit($student, 'clearance');

        $response = $this->actingAs($student)
            ->get(route('student.records.pdf', $visit))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringContainsString(
            "attachment; filename={$visit->reference_no}-medical-clearance.pdf",
            (string) $response->headers->get('content-disposition')
        );
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_a_student_downloads_their_assessment_as_a_pdf(): void
    {
        $student = $this->makeStudent();
        $visit = $this->encodedVisit($student, 'assessment');

        $response = $this->actingAs($student)
            ->get(route('student.records.pdf', $visit))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringContainsString(
            "attachment; filename={$visit->reference_no}-medical-assessment.pdf",
            (string) $response->headers->get('content-disposition')
        );
    }

    /** FR-PRT-05: one Letter page. D-71: both Legal pages in ONE file. */
    public function test_the_clearance_is_one_page_and_the_assessment_is_two(): void
    {
        $student = $this->makeStudent();

        $clearance = $this->encodedVisit($student, 'clearance');
        $assessment = $this->encodedVisit($student, 'assessment');

        $this->assertSame(1, $this->pageCount(
            $this->actingAs($student)->get(route('student.records.pdf', $clearance))->assertOk()->getContent()
        ));

        $this->assertSame(2, $this->pageCount(
            $this->actingAs($student)->get(route('student.records.pdf', $assessment))->assertOk()->getContent()
        ));
    }

    /** `printed_at` is the CLINIC's print record (FR-NRS-05), not the student's download. */
    public function test_a_student_download_does_not_stamp_printed_at(): void
    {
        $student = $this->makeStudent();
        $visit = $this->encodedVisit($student);

        $this->actingAs($student)->get(route('student.records.pdf', $visit))->assertOk();

        $this->assertNull($visit->clearanceRecord->fresh()->printed_at);
    }

    // ── 2. The same ownership rules as the page ──────────────────────────────

    public function test_another_students_pdf_is_not_found(): void
    {
        $visit = $this->encodedVisit($this->makeStudent());

        $this->actingAs(User::factory()->create(['role' => 'student']))
            ->get(route('student.records.pdf', $visit))
            ->assertNotFound();
    }

    public function test_a_captured_visit_has_no_pdf(): void
    {
        $student = $this->makeStudent();
        $visit = $this->makeVisit($student, 'clearance', 'captured');

        $this->actingAs($student)
            ->get(route('student.records.pdf', $visit))
            ->assertNotFound();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $visit = $this->encodedVisit($this->makeStudent());

        $this->get(route('student.records.pdf', $visit))->assertRedirect(route('login'));
    }

    // ── 3. Throttle ──────────────────────────────────────────────────────────

    /**
     * throttle:10,1,record-pdf — rendering a PDF is the most expensive thing a
     * student can ask for, so it gets its own bucket. The 3rd argument is the
     * bucket prefix: without it this counter would be shared with every other
     * student route.
     */
    public function test_the_pdf_route_is_throttled(): void
    {
        RateLimiter::clear('record-pdf');

        $student = $this->makeStudent();
        $visit = $this->encodedVisit($student);

        // The throttle runs BEFORE the controller, so a 404 costs a hit just
        // like a download does. Spending the ten on an unknown id keeps this
        // test from laying out ten real PDFs to prove a counter.
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($student)
                ->get(route('student.records.pdf', 999000 + $i))
                ->assertNotFound();
        }

        $this->actingAs($student)
            ->get(route('student.records.pdf', $visit))
            ->assertStatus(429);
    }
}
