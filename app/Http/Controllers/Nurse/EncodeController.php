<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Models\ClinicVisit;
use Illuminate\View\View;

/**
 * FR-NRS-03 — Encode Result ("Doctor's Assessment", BR-15).
 *
 * One screen, two modes, decided by the visit's status (BR-11):
 *
 *  - `captured` → the editable assessment form (Result required, Category /
 *    Purpose / Notes optional). Save & Close and Preview & Print are wired
 *    in FR-NRS-04/05.
 *  - `encoded`  → the SAME screen read-only, showing the saved clearance
 *    record with a Reprint button. Encoding is one-time (FR-NRS-04), so an
 *    encoded visit can never be edited back into the queue.
 *
 * Everything displayed (vitals, flags, BMI) is the server-frozen capture-time
 * value — never recomputed, same trust rule as the Live Queue.
 */
class EncodeController extends Controller
{
    public function show(ClinicVisit $visit): View
    {
        // Only the two lifecycle states BR-11 defines can be opened here;
        // anything else means a hand-edited URL or bad data — 404, not a crash.
        abort_unless(in_array($visit->status, ['captured', 'encoded'], true), 404);

        $visit->load([
            'student.studentProfile',
            'college',           // capture-time snapshot (FR-STU-09/D-17), NOT the profile's current college
            'vitalSigns',
            'screeningResponse',
            'clearanceRecord.encoder',
        ]);

        return view('nurse.encode', [
            'visit' => $visit,
            'readOnly' => $visit->status === 'encoded',
        ]);
    }
}
