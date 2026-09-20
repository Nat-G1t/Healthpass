<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\MedicalAssessment;

/**
 * Module PRT / D-73 — the three facts that depend on a visit's FORM TYPE
 * (D-62) and nothing else: which template renders it, what paper it is laid
 * out on, and what the downloaded file is called.
 *
 * Why its own class: since D-73 the document has FIVE callers — the clinic's
 * print, the pre-save preview, the clinic's Reprint, the clinic's Save as PDF
 * and now the student's own Save as PDF. If each decided "assessment → legal,
 * two pages, -medical-assessment.pdf" for itself, a student's download could
 * one day disagree with the clinic's copy of the same visit. One definition
 * makes that impossible.
 *
 * It holds no state, so every method is static (the same shape as
 * ClearanceDocument / AssessmentDocument, which build the values the templates
 * below actually print).
 */
final class VisitDocument
{
    /** The Blade template for this visit's form type (D-62) — never the request's. */
    public static function template(ClinicVisit $visit): string
    {
        return $visit->formType() === 'assessment'
            ? 'forms.medical-assessment'
            : 'forms.medical-clearance';
    }

    /**
     * dompdf's paper size. The Medical Assessment Form is US Legal and two
     * pages (D-71); the Medical Clearance is US Letter and one (FR-PRT-05).
     */
    public static function paper(ClinicVisit $visit): string
    {
        return $visit->formType() === 'assessment' ? 'legal' : 'letter';
    }

    /** The downloaded file's name, e.g. "HP-2026-0042-medical-clearance.pdf". */
    public static function filename(ClinicVisit $visit): string
    {
        return sprintf(
            '%s-medical-%s.pdf',
            $visit->reference_no,
            $visit->formType() === 'assessment' ? 'assessment' : 'clearance',
        );
    }

    /**
     * Everything the document prints, built by the form type's own data class.
     *
     * $preview / $previewSections substitute transient, never-saved rows for
     * the (not yet existing) saved ones — the clinic's pre-save Preview &
     * Print. $side picks one page of the Assessment's two; null is both.
     *
     * @return array<string, mixed>
     */
    public static function data(
        ClinicVisit $visit,
        ?ClearanceRecord $preview = null,
        ?MedicalAssessment $previewSections = null,
        ?string $side = null,
    ): array {
        // `encoded` without its 1:1 record means corrupted data, not a URL
        // someone can reach through the UI — fail closed rather than render
        // a half-empty official form.
        abort_unless($preview !== null || $visit->clearanceRecord !== null, 404);

        if ($visit->formType() === 'assessment') {
            return AssessmentDocument::for($visit, $preview, $previewSections, $side);
        }

        // The Medical Clearance is one page and has no sides; `side` is
        // simply not part of that document.
        return ClearanceDocument::for($visit, $preview);
    }
}
