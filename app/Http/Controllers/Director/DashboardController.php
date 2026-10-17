<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use Illuminate\View\View;

/**
 * Director Dashboard (FR-ANL-01): four KPI cards plus the Pending Batch
 * Approvals preview panel (→ Batch Approvals) with a "View all →" link.
 * D-135 removed the Flagged Anomalies panel with its page; the Flagged
 * Visits KPI card stays.
 *
 * Query budget: 4 KPI counts + 1 preview fetch, eager-loaded — the view
 * never triggers a per-row query (no N+1).
 */
class DashboardController extends Controller
{
    /** How many rows the preview panel shows before "View all →". */
    private const PREVIEW_ROWS = 3;

    public function __invoke(): View
    {
        $stats = [
            // Every clearance_records row IS an encoded result (FR-NRS-04) —
            // and analytics only ever count encoded records (FR-ANL-07).
            'clearances' => ClearanceRecord::count(),
            'pendingBatches' => BatchRequest::where('status', 'pending')->count(),
            // Same counting rule as clinic capacity (BR-02):
            // cancelled appointments don't occupy the day.
            'todaysAppointments' => Appointment::whereDate('scheduled_date', today())
                ->where('status', '!=', 'cancelled')
                ->count(),
            // Flags surface from capture (FR-ANL-07) — encoded or not.
            'flaggedVisits' => ClinicVisit::flagged()->count(),
        ];

        // Newest pending batches; id breaks same-second created_at ties so
        // the preview order is deterministic.
        $pendingBatches = BatchRequest::where('status', 'pending')
            ->with('college:id,name')
            ->withCount('batchRequestStudents')
            ->latest()
            ->latest('id')
            ->take(self::PREVIEW_ROWS)
            ->get();

        return view('director.dashboard', compact('stats', 'pendingBatches'));
    }
}
