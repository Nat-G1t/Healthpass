<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Nurse\StoreClearanceRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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

    /**
     * FR-NRS-04 — Save & Close. One transaction: create the 1:1 clearance
     * record, flip the visit `captured` → `encoded` (BR-11) and, if the visit
     * came from a booked appointment, mark it `completed` (FR-NRS-07). The
     * next queue poll no longer sees the visit (scopeLiveQueue filters on
     * `captured`), so the row vanishes on its own.
     *
     * Encoding is one-time. Two guards, both needed:
     *  - application: an already-`encoded` visit short-circuits to the
     *    read-only view — the everyday double-click / stale-tab case;
     *  - database: the UNIQUE on clearance_records.clinic_visit_id catches
     *    the race two guards can't see (two nurses saving the same visit in
     *    the same instant) — the loser's transaction rolls back wholesale.
     */
    public function store(StoreClearanceRequest $request, ClinicVisit $visit): RedirectResponse
    {
        if ($visit->status === 'encoded') {
            return $this->alreadyEncoded($visit);
        }

        // Same lifecycle rule as show(): only `captured` visits can be encoded.
        abort_unless($visit->status === 'captured', 404);

        try {
            DB::transaction(function () use ($request, $visit): void {
                // physician_name / physician_license_no are intentionally NOT
                // set here — the column defaults (§7.5: REYNALDO S. ALIPIO, MD
                // / 60252) fill them, keeping the migration the single source.
                ClearanceRecord::create([
                    ...$request->validated(),
                    'clinic_visit_id' => $visit->id,
                    'encoded_by' => $request->user()->id,
                    'encoded_at' => now(),
                ]);

                $visit->update(['status' => 'encoded']);

                $visit->appointment?->update(['status' => 'completed']);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->alreadyEncoded($visit);
        }

        return redirect()->route('nurse.queue')
            ->with('status', "Assessment saved — {$visit->reference_no} encoded.");
    }

    /** Friendly landing for a re-submit: the same screen, now read-only. */
    private function alreadyEncoded(ClinicVisit $visit): RedirectResponse
    {
        return redirect()->route('nurse.visits.encode', $visit)
            ->with('status', 'This visit has already been encoded — the assessment below is read-only.');
    }
}
