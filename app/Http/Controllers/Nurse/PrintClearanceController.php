<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Nurse\StoreClearanceRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use Illuminate\View\View;

/**
 * Module PRT (FR-PRT-01..05, BR-17) + FR-NRS-05 — the printable Medical
 * Clearance, a field-for-field reproduction of official form
 * DHVSU-QSP-OSS-004-FO002-R03, reached three ways:
 *
 *  - GET  show    — encoded visits, no side effects (a plain view of the form)
 *  - POST preview — captured visits: the encode form posts its UNSAVED field
 *    values here (into the hidden print iframe) so the nurse can Preview &
 *    Print BEFORE Save & Close, per the E2E flow. Renders the form from a
 *    transient ClearanceRecord — nothing is written to the database.
 *  - POST reprint — encoded visits: re-stamps `printed_at` and returns the
 *    form; the Reprint button targets this at the same hidden iframe
 *    (reprints allowed, FR-NRS-05).
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

        $validated = $request->validated();
        // Child-list / flag fields that aren't clearance_records columns.
        unset($validated['case_categories'], $validated['printed']);

        $record = new ClearanceRecord([
            ...$validated,
            // DB defaults only fill on save — a transient record needs the
            // physician block (FR-PRT-04) and encode date set explicitly.
            'physician_name' => ClearanceRecord::PHYSICIAN_NAME,
            'physician_license_no' => ClearanceRecord::PHYSICIAN_LICENSE_NO,
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
     * Load everything the form prints and render it. $preview substitutes a
     * transient record for the (not yet existing) saved one.
     */
    private function render(ClinicVisit $visit, ?ClearanceRecord $preview = null): View
    {
        $visit->load([
            'student.studentProfile',
            'college',          // capture-time snapshot (FR-STU-09/D-17)
            'vitalSigns',
            'screeningResponse', // shades the Physical Signs + pregnancy fields (D-22)
            'clearanceRecord',
        ]);

        if ($preview !== null) {
            // The view reads $visit->clearanceRecord — hand it the transient
            // record without touching the database.
            $visit->setRelation('clearanceRecord', $preview);
        }

        // `encoded` without its 1:1 record means corrupted data, not a URL
        // someone can reach through the UI — fail closed rather than render
        // a half-empty official form.
        abort_unless($visit->clearanceRecord !== null, 404);

        return view('nurse.print', ['visit' => $visit]);
    }
}
