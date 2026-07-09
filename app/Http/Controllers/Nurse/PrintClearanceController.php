<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Models\ClinicVisit;
use Illuminate\View\View;

/**
 * Module PRT (FR-PRT-01..04, BR-17) — the printable Medical Clearance,
 * a field-for-field reproduction of official form DHVSU-QSP-OSS-004-FO002-R03.
 *
 * Encoded visits only: the form prints the nurse-encoded Result, so a visit
 * still in the queue has nothing to print — a hand-edited URL gets a 404.
 * This is a plain GET view with no side effects; stamping `printed_at` is
 * the actual print trigger's job (FR-NRS-05, wired on the encode screen).
 *
 * An "invokable" controller: a controller with a single `__invoke` method,
 * used when a route does exactly one thing — the route registers the class
 * itself instead of a `[class, 'method']` pair.
 */
class PrintClearanceController extends Controller
{
    public function __invoke(ClinicVisit $visit): View
    {
        abort_unless($visit->status === 'encoded', 404);

        $visit->load([
            'student.studentProfile',
            'college',          // capture-time snapshot (FR-STU-09/D-17)
            'vitalSigns',
            'screeningResponse', // shades the Physical Signs + pregnancy fields (D-22)
            'clearanceRecord',
        ]);

        // `encoded` without its 1:1 record means corrupted data, not a URL
        // someone can reach through the UI — fail closed rather than render
        // a half-empty official form.
        abort_unless($visit->clearanceRecord !== null, 404);

        return view('nurse.print', ['visit' => $visit]);
    }
}
