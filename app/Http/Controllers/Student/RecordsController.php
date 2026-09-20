<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FR-STU-07 — My Records: list of the student's clinic visits and a detail
 * modal with kiosk vitals + the student's answers to the official forms' twelve
 * Physical Signs questions, with any details they typed (D-63; details per D-56).
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
                // D-68: batchRequest carries the form type, which decides
                // whether the modal shows a Personal / Social History card.
                'appointment:id,service_type,batch_request_id',
                'appointment.batchRequest:id,form_type',
                'vitalSigns',
                'screeningResponse',
                'clearanceRecord',
            ])
            ->latest()
            ->get();

        return view('student.records', compact('visits'));
    }
}
