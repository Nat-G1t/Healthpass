<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FR-STU-07 — My Records: list of the student's clinic visits and a detail
 * modal with kiosk vitals + 9-system questionnaire answers.
 *
 * FR-STU-08 — Fit/Unfit result is gated: it only appears once the visit has
 * a clearance record (status = 'encoded'). Captured visits show "Pending" and
 * have no View action — nothing clinical is shown before nurse encoding.
 */
class RecordsController extends Controller
{
    public function __invoke(Request $request): View
    {
        // Ownership is implicit: scoped through the authenticated user's relation,
        // so no cross-student leakage is possible.
        $visits = $request->user()->clinicVisits()
            ->with([
                'appointment:id,service_type',
                'vitalSigns',
                'screeningResponse',
                'clearanceRecord.caseCategories',  // 0..n per clearance (D-23)
            ])
            ->latest()
            ->get();

        return view('student.records', compact('visits'));
    }
}
