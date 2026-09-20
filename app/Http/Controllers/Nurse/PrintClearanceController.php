<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Nurse\StoreClearanceRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\MedicalAssessment;
use App\Support\AssessmentDocument;
use App\Support\VisitDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\View\View;

/**
 * Module PRT (FR-PRT-01..07, BR-17) + FR-NRS-05 — the clinic's official
 * printed document, reached four ways:
 *
 *  - GET  show    — encoded visits, no side effects (a plain view of the form)
 *  - POST preview — captured visits: the encode form posts its UNSAVED field
 *    values here (into the hidden print iframe) so the nurse can Preview &
 *    Print BEFORE Save & Close, per the E2E flow. Renders the form from a
 *    transient ClearanceRecord — nothing is written to the database.
 *  - POST reprint — encoded visits: re-stamps `printed_at` and returns the
 *    form; the Reprint button targets this at the same hidden iframe
 *    (reprints allowed, FR-NRS-05).
 *  - GET  pdf     — Save as PDF (FR-PRT-06): the SAME template rendered by
 *    dompdf and sent as a download. It does NOT stamp `printed_at` —
 *    saving a copy is not printing one.
 *
 * WHICH document is decided by the visit's SERVER-resolved form type (D-62),
 * never by anything the request body says:
 *
 *  - `clearance`  → Medical Clearance, PSU-QSP-OSS-004-FO002-R04 (D-67),
 *    one Letter page, built by App\Support\ClearanceDocument;
 *  - `assessment` → Medical Assessment Form, PSU-QSP-OSS-004-FO010-R00
 *    (D-71), TWO Legal pages printed back-to-back, built by
 *    App\Support\AssessmentDocument.
 *
 * Because clinic printers rarely duplex, the Assessment's HTML prints take a
 * `side` of `front` or `back` and render just that page; the PDF always
 * carries both, for a printer that CAN do two-sided.
 *
 * Either way the view data is built in ONE place, so the printed page and the
 * saved PDF cannot disagree about a single field.
 */
class PrintClearanceController extends Controller
{
    public function show(Request $request, ClinicVisit $visit): View
    {
        abort_unless($visit->status === 'encoded', 404);

        return $this->render($visit, side: $this->side($request));
    }

    /**
     * Preview & Print from the editable encode screen (FR-NRS-05). The visit
     * is still `captured` — no clearance record exists yet — so the form is
     * rendered from the posted assessment fields on a transient, never-saved
     * record. Validation reuses the Save & Close rules: what previews is
     * exactly what would save. `printed_at` cannot be stamped here (there is
     * no row); the encode form flags the print and Save & Close stamps it.
     */
    public function preview(StoreClearanceRequest $request, ClinicVisit $visit): View
    {
        abort_unless($visit->status === 'captured', 404);

        // `printed` is a screen flag and the seven vitals (D-65) are their own
        // JSON column — neither is a plain clearance_records field.
        $validated = Arr::except($request->validated(), [
            'printed',
            ...array_keys(ClearanceRecord::ENCODED_VITALS),
            // D-69/D-70: an Assessment visit also posts the form's own
            // sections — they live in `medical_assessments`, not on this
            // record, so they are rebuilt below as their own transient row.
            'medical_history',
            'immunizations',
            'family_planning_access',
            'surgical_history',
            'menstrual_history',
            'ob_history',
            'physical_exam',
        ]);

        $record = new ClearanceRecord([
            ...$validated,
            // D-65: the vitals exactly as Save & Close would store them, so the
            // preview prints what the nurse is looking at, not the kiosk's copy.
            'encoded_vitals' => $request->encodedVitals(),
            // D-62: the batch reason, exactly as Save & Close will store it.
            ...$visit->batchPurpose(),
            // D-64: the same physician-block rule Save & Close applies, for the
            // signed-in user who will be the encoder.
            ...ClearanceRecord::physicianBlockFor($request->user()),
            'encoded_at' => now(),
        ]);

        // "Interviewed/Assessed by" on the Assessment's back page (D-71). The
        // transient record has no `encoded_by` row to read the relation from,
        // so hand it the signed-in user — who IS the encoder Save & Close will
        // record. setRelation() fills a relationship in memory without a query.
        $record->setRelation('encoder', $request->user());

        // D-71: the Assessment's own sections, built by the SAME request
        // method Save & Close uses, so the preview prints what would save.
        $sections = $visit->formType() === 'assessment'
            ? new MedicalAssessment($request->medicalAssessmentAttributes())
            : null;

        return $this->render($visit, $record, $sections, $this->side($request));
    }

    /**
     * Reprint from the read-only encode screen: stamp `printed_at` (every
     * print re-stamps — the column answers "when was this last printed"),
     * then return the form for the iframe to print.
     */
    public function reprint(Request $request, ClinicVisit $visit): View
    {
        abort_unless($visit->status === 'encoded', 404);

        $view = $this->render($visit, side: $this->side($request));

        $visit->clearanceRecord->update(['printed_at' => now()]);

        return $view;
    }

    /**
     * Save as PDF (FR-PRT-06) — the encoded visit's document as a downloaded
     * file, rendered by dompdf from the same Blade template the browser
     * prints. `Pdf::loadView()` renders the view to HTML and lays it out in
     * PHP; `download()` returns it with a Content-Disposition attachment
     * header so the browser saves rather than displays it.
     *
     * An Assessment PDF is ONE file carrying BOTH Legal pages (D-71) — a
     * printer that can duplex prints it with "Two-sided" on, which is why
     * there is no third print button.
     *
     * No `printed_at` stamp: FR-NRS-05's column records when the document was
     * PRINTED, and saving a copy is a different act.
     */
    public function pdf(ClinicVisit $visit): Response
    {
        abort_unless($visit->status === 'encoded', 404);

        return Pdf::loadView(VisitDocument::template($visit), VisitDocument::data($visit))
            ->setPaper(VisitDocument::paper($visit))
            ->download(VisitDocument::filename($visit));
    }

    /** Render the official document for the browser's print frame. */
    private function render(
        ClinicVisit $visit,
        ?ClearanceRecord $preview = null,
        ?MedicalAssessment $previewSections = null,
        ?string $side = null,
    ): View {
        return view(
            VisitDocument::template($visit),
            VisitDocument::data($visit, $preview, $previewSections, $side),
        );
    }

    /**
     * Which page of the Medical Assessment Form to render (D-71): `front`,
     * `back`, or null for both. Anything else the request carries is ignored
     * rather than trusted — an unknown value prints the whole document.
     */
    private function side(Request $request): ?string
    {
        $side = $request->input('side');

        return in_array($side, AssessmentDocument::SIDES, true) ? $side : null;
    }
}
