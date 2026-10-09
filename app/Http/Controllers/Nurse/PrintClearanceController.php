<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Models\ClinicVisit;
use App\Support\AssessmentDocument;
use App\Support\VisitDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Module PRT (FR-PRT-01..07, BR-17) + FR-NRS-05 — the clinic's official
 * printed document, reached three ways, all for an ENCODED visit (D-127
 * removed the pre-save Preview & Print: the print buttons appear only after
 * the physician saves):
 *
 *  - GET  show    — no side effects (a plain view of the form)
 *  - POST reprint — re-stamps `printed_at` and returns the form; the encode
 *    page's Print buttons target this at a hidden iframe (reprints allowed,
 *    FR-NRS-05).
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
 *    (D-71), TWO Letter pages (D-83) printed back-to-back, built by
 *    App\Support\AssessmentDocument.
 *
 * Because clinic printers rarely duplex, the Assessment's HTML prints take a
 * `side` of `front` or `back` and render just that page; the PDF always
 * carries both, for a printer that CAN do two-sided.
 *
 * Either way the view data is built in ONE place, so the printed page and the
 * saved PDF cannot disagree about a single field.
 *
 * D-125 (O2): show, reprint and pdf work only once the Director has released
 * the visit's batch (ClinicVisit::isReleasedForPrint()) — or, since D-127,
 * for the physician who encoded the visit, on the day they encoded it.
 * Refused, a JSON caller gets a 403 and a page goes back with a plain message.
 */
class PrintClearanceController extends Controller
{
    public function show(Request $request, ClinicVisit $visit): View
    {
        abort_unless($visit->status === 'encoded', 404);
        $this->ensureReleased($request, $visit);

        return $this->render($visit, $this->side($request));
    }

    /**
     * Print from the read-only encode screen: stamp `printed_at` (every
     * print re-stamps — the column answers "when was this last printed"),
     * then return the form for the iframe to print.
     */
    public function reprint(Request $request, ClinicVisit $visit): View
    {
        abort_unless($visit->status === 'encoded', 404);
        $this->ensureReleased($request, $visit);

        $view = $this->render($visit, $this->side($request));

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
     * An Assessment PDF is ONE file carrying BOTH Letter pages (D-71/D-83) — a
     * printer that can duplex prints it with "Two-sided" on, which is why
     * there is no third print button.
     *
     * No `printed_at` stamp: FR-NRS-05's column records when the document was
     * PRINTED, and saving a copy is a different act.
     */
    public function pdf(Request $request, ClinicVisit $visit): Response
    {
        abort_unless($visit->status === 'encoded', 404);
        $this->ensureReleased($request, $visit);

        return Pdf::loadView(VisitDocument::template($visit), VisitDocument::data($visit))
            ->setPaper(VisitDocument::paper($visit))
            ->download(VisitDocument::filename($visit));
    }

    /**
     * D-125 — stop here unless the visit's batch is released (or, D-127, the
     * signed-in physician encoded it today). An HttpResponseException ends
     * the request with the response it carries: a 403 for a JSON caller, else
     * back to the page with the message (a Print posted into the hidden print
     * frame lands that page in the frame, which never prints a page without
     * data-hp-print-doc).
     */
    private function ensureReleased(Request $request, ClinicVisit $visit): void
    {
        if ($visit->isReleasedForPrint($request->user())) {
            return;
        }

        throw new HttpResponseException($request->expectsJson()
            ? response()->json(['message' => ClinicVisit::NOT_RELEASED_HINT], 403)
            : redirect()->back(fallback: route('nurse.dashboard'))->with('error', ClinicVisit::NOT_RELEASED_HINT));
    }

    /** Render the official document for the browser's print frame. */
    private function render(ClinicVisit $visit, ?string $side = null): View
    {
        return view(VisitDocument::template($visit), VisitDocument::data($visit, side: $side));
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
