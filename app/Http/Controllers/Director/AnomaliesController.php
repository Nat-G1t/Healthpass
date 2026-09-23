<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Models\ClinicVisit;
use App\Models\VitalSigns;
use Illuminate\View\View;

/**
 * Flagged Anomalies (FR-ANL-05): five stat cards — one per flag type (D-66
 * added heart rate and respiratory rate) — and the flagged-visits table.
 *
 * Scope (FR-ANL-07): flags surface from CAPTURE, so still-captured
 * (un-encoded) visits appear here too. The respiratory-rate flag is the one
 * exception by nature, not by scope: the kiosk cannot measure that vital, so
 * it can only appear once the clinic types the rate at encode (D-65/D-66).
 */
class AnomaliesController extends Controller
{
    public function index(): View
    {
        // One count per flag type. vital_signs is 1:1 with clinic_visits,
        // so counting rows counts visits. A visit tripping two flags counts
        // in both cards — the cards answer "how many of each anomaly", not
        // "how many flagged visits" (that is the table's row count).
        //
        // D-72: the rows of a RESTING visit are excluded. Those flags are
        // exactly why the student is sitting down to re-take the reading, and
        // the clinic has not been told about that visit at all — counting them
        // here would report an anomaly that may not survive the re-check. The
        // table below inherits the same exclusion from scopeFlagged().
        $submitted = fn (string $flag): int => VitalSigns::where($flag, true)
            ->whereHas('clinicVisit', fn ($visit) => $visit->submitted())
            ->count();

        $stats = [
            'bp' => $submitted('is_bp_flagged'),
            'temp' => $submitted('is_temp_flagged'),
            'bmi' => $submitted('is_bmi_flagged'),
            'hr' => $submitted('is_hr_flagged'),
            'rr' => $submitted('is_rr_flagged'),
        ];

        // Newest first — same ordering as the dashboard preview this page
        // is the "View all" of. college = the capture-time snapshot
        // (FR-STU-09, D-17); everything the table shows is eager-loaded,
        // so rendering never triggers per-row queries.
        $visits = ClinicVisit::flagged()
            ->with([
                'student:id,name',
                'college:id,code',
                'vitalSigns',
            ])
            ->latest('checked_in_at')
            ->latest('id')
            // FR-UI-06: ten per page.
            ->paginate(config('healthpass.ui.rows_per_page'))
            ->withQueryString();

        return view('director.anomalies.index', compact('stats', 'visits'));
    }

    /**
     * Record detail behind each row's "View" link. Read-only by design:
     * the Director reviews, only the Nurse encodes (locked roles).
     */
    public function show(ClinicVisit $visit): View
    {
        $visit->load([
            'student.studentProfile',
            'college',
            'vitalSigns',
            'clearanceRecord',
        ]);

        return view('director.anomalies.show', compact('visit'));
    }
}
