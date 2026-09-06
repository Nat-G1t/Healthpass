<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FR-STU-14 (D-51) — My Appointments: every upcoming appointment the student
 * has, each with its own cancel control.
 *
 * Why this page exists: the cancel RULE
 * (`App\Models\Appointment::isSelfCancellable()`) has always allowed any
 * self-booked future appointment to be cancelled, but the only place that ever
 * drew a cancel button was the dashboard's Next Appointment card, which renders
 * a single row. A student holding three appointments could therefore reach
 * exactly one of them. This page is that missing surface — it changes no rule,
 * it just stops hiding the other rows.
 */
class MyAppointmentsController extends Controller
{
    public function __invoke(Request $request): View
    {
        // Scoped through the authenticated user's own relation, so another
        // student's appointments are not merely filtered out — they are never
        // queried. Same ownership pattern as RecordsController.
        $appointments = $request->user()->appointments()
            // Mirrors the dashboard card's filter so the two surfaces cannot
            // disagree about what counts as an open appointment; they differ
            // only on the date bound below.
            ->where('status', '!=', 'cancelled')
            // Strictly after today. An appointment dated today is no longer
            // cancellable under FR-STU-06 anyway, and the dashboard card is
            // where the student sees the one they are about to attend.
            ->whereDate('scheduled_date', '>', today())
            // Pre-D-37 rows carry scheduled_time NULL and sort first within
            // their day, which is the right place for an unknown hour.
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time')
            // scheduledByLabel() reads the college name off a batch row; without
            // this the list would fire two queries per batch appointment.
            ->with('batchRequest.college')
            ->get();

        return view('student.my-appointments', compact('appointments'));
    }
}
