<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Nurse\StoreClearanceRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Support\ClearanceDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\View\View;

/**
 * Module PRT (FR-PRT-01..06, BR-17) + FR-NRS-05 — the Medical Clearance
 * document, a field-for-field reproduction of official form
 * PSU-QSP-OSS-004-FO002-R04 (D-67), reached four ways:
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
 * All four build their view data in App\Support\ClearanceDocument, so the
 * printed page and the saved PDF cannot disagree about a single field.
 *
 * TODO (prompt 12 / D-71): an `assessment` visit currently prints this
 * Medical Clearance as an interim. Prompt 12 adds
 * `resources/views/forms/medical-assessment.blade.php` and this controller
 * then picks the template by `$visit->formType()`.
 */
class PrintClearanceController extends Controller
{
    public function show(ClinicVisit $visit): View
    {
        abort_unless($visit->status === 'encoded', 404);

        return $this->render($visit);
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
            // D-69: an Assessment visit also posts the form's own sections —
            // they live in `medical_assessments`, not on this record. Prompt 12
            // is what prints them.
            'medical_history',
            'immunizations',
            'family_planning_access',
            'surgical_history',
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

        return $this->render($visit, $record);
    }

    /**
     * Reprint from the read-only encode screen: stamp `printed_at` (every
     * print re-stamps — the column answers "when was this last printed"),
     * then return the form for the iframe to print.
     */
    public function reprint(ClinicVisit $visit): View
    {
        abort_unless($visit->status === 'encoded', 404);

        $view = $this->render($visit);

        $visit->clearanceRecord->update(['printed_at' => now()]);

        return $view;
    }

    /**
     * Save as PDF (FR-PRT-06, D-67) — the encoded visit's clearance as a
     * downloaded file, rendered by dompdf from the same Blade template the
     * browser prints. `Pdf::loadView()` renders the view to HTML and lays it
     * out in PHP; `download()` returns it with a Content-Disposition
     * attachment header so the browser saves rather than displays it.
     *
     * No `printed_at` stamp: FR-NRS-05's column records when the clearance
     * was PRINTED, and saving a copy is a different act.
     */
    public function pdf(ClinicVisit $visit): Response
    {
        abort_unless($visit->status === 'encoded', 404);

        return Pdf::loadView('forms.medical-clearance', $this->documentData($visit))
            ->setPaper('letter')
            ->download("{$visit->reference_no}-medical-clearance.pdf");
    }

    /** Render the official document for the browser's print frame. */
    private function render(ClinicVisit $visit, ?ClearanceRecord $preview = null): View
    {
        return view('forms.medical-clearance', $this->documentData($visit, $preview));
    }

    /**
     * Everything the document prints. $preview substitutes a transient record
     * for the (not yet existing) saved one.
     *
     * @return array<string, mixed>
     */
    private function documentData(ClinicVisit $visit, ?ClearanceRecord $preview = null): array
    {
        // `encoded` without its 1:1 record means corrupted data, not a URL
        // someone can reach through the UI — fail closed rather than render
        // a half-empty official form.
        abort_unless($preview !== null || $visit->clearanceRecord !== null, 404);

        return ClearanceDocument::for($visit, $preview);
    }
}
