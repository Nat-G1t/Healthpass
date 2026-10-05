<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\MedicalAssessment;
use Illuminate\Support\Str;

/**
 * Module PRT / D-73 — the three facts about a visit's document: which
 * template renders it and what paper it is laid out on (both decided by its
 * FORM TYPE, D-62), and what the downloaded file is called (by its batch and
 * student since D-114).
 *
 * Why its own class: the document has several callers — the clinic's print,
 * the pre-save preview, the clinic's Reprint and the clinic's Save as PDF
 * (D-73 added a student Save as PDF; D-106 removed it). If each decided
 * "assessment → two pages, -medical-assessment.pdf" for itself, one copy
 * could one day disagree with another copy of the same visit. One definition
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
     * dompdf's paper size. Both forms are US Letter since D-83: the Medical
     * Assessment Form is two Letter pages (it was Legal under D-71), the
     * Medical Clearance one (FR-PRT-05). Kept as a method so every PDF of a
     * visit shares one definition.
     */
    public static function paper(ClinicVisit $visit): string
    {
        return 'letter';
    }

    /**
     * The downloaded file's name.
     *
     * A batch student's document (D-114): "{Batch ID}-{Surname}-{Student ID}.pdf",
     * e.g. "BR-2026-014-Dela-Cruz-2023-123456.pdf" — the same name from the
     * clinic's PDF button and inside every role's ZIP (BatchDocumentZip), so a
     * college can sort a folder of them by batch, then by name. Each part is
     * made plain ASCII (ñ → n, spaces → -) so every zip tool and operating
     * system shows it the same way; student IDs are unique, so two students'
     * files never collide.
     *
     * A visit with no batch (a pre-D-61 self-booking) has no Batch ID and keeps
     * "HP-2026-0042-medical-clearance.pdf".
     */
    public static function filename(ClinicVisit $visit): string
    {
        $batch = $visit->appointment?->batchRequest;

        if ($batch === null) {
            return sprintf(
                '%s-medical-%s.pdf',
                $visit->reference_no,
                $visit->formType() === 'assessment' ? 'assessment' : 'clearance',
            );
        }

        $profile = $visit->student?->studentProfile;

        $parts = array_map(
            fn (string $part): string => trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii($part)), '-'),
            [$batch->reference_no, (string) $profile?->last_name, (string) $profile?->student_number],
        );

        return implode('-', array_filter($parts, fn (string $part): bool => $part !== '')).'.pdf';
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
