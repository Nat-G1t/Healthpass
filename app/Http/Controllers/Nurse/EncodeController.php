<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Nurse\StoreClearanceRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\VitalSigns;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * FR-NRS-03 — Encode Result ("Doctor's Assessment", BR-15).
 *
 * One screen, two modes, decided by the visit's status (BR-11):
 *
 *  - `captured` → the editable assessment form (Result required, Notes
 *    optional; the Purpose is the batch reason, shown read-only — D-62).
 *    Save & Close and Preview & Print are wired in FR-NRS-04/05.
 *  - `encoded`  → the SAME screen read-only, showing the saved clearance
 *    record with a Reprint button. Encoding is one-time (FR-NRS-04), so an
 *    encoded visit can never be edited back into the queue.
 *
 * The flags shown are the server-frozen capture-time booleans — never
 * recomputed, same trust rule as the Live Queue. The vitals THEMSELVES are
 * editable since D-65: the kiosk's reading pre-fills the card, the clinic
 * corrects what it needs to, and the confirmed copy is saved on the clearance
 * record (`encoded_vitals`) — `vital_signs` keeps what the screening measured.
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
            'appointment.batchRequest', // D-62: form type + the read-only purpose
            'vitalSigns',
            'screeningResponse',
            'clearanceRecord.encoder',
            // D-69: the Assessment sections, shown read-only once encoded.
            'clearanceRecord.medicalAssessment',
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
                // `printed` is a screen flag and the seven vitals are their
                // own JSON column — pull both out before the record create.
                $validated = Arr::except($request->validated(), [
                    'printed',
                    ...array_keys(ClearanceRecord::ENCODED_VITALS),
                    // D-69: the Assessment sections are their own table.
                    'medical_history',
                    'immunizations',
                    'family_planning_access',
                    'surgical_history',
                    // D-70: sections V, VI and the physical examination.
                    'menstrual_history',
                    'ob_history',
                    'physical_exam',
                ]);

                // D-65: the vitals the clinic confirmed on the card, with BMI
                // recomputed server-side. The kiosk's own reading in
                // `vital_signs` is never touched (BR-14) — it is what the
                // screening measured, and the flags still describe it.
                $encodedVitals = $request->encodedVitals();

                $record = ClearanceRecord::create([
                    ...$validated,
                    'encoded_vitals' => $encodedVitals,
                    // D-64: a physician's own encode prints their name and
                    // license; a nurse's leaves the block blank (both NULL).
                    ...ClearanceRecord::physicianBlockFor($request->user()),
                    // D-62: the purpose is the batch reason, never nurse input —
                    // its printed label (+ specify text). NULL with no batch.
                    ...$visit->batchPurpose(),
                    'clinic_visit_id' => $visit->id,
                    'encoded_by' => $request->user()->id,
                    'encoded_at' => now(),
                    // FR-NRS-05: the pre-save Preview & Print happened before
                    // this row existed — the screen flags it and the stamp
                    // lands here. Reprints re-stamp via the print controller.
                    'printed_at' => $request->boolean('printed') ? now() : null,
                ]);

                // D-69: a Medical Assessment Form visit records the paper's
                // own sections (histories, immunizations, family planning) in
                // `medical_assessments` — one row per Assessment encode, and
                // none at all for a Medical Clearance, whose form has no such
                // sections. Same transaction, so a record can never exist
                // without them.
                if ($visit->formType() === 'assessment') {
                    $record->medicalAssessment()->create($request->medicalAssessmentAttributes());
                }

                // D-65: the respiratory rate is a vital like any other — the
                // kiosk simply has no sensor for it, so encode is where it is
                // written. It and its own flag are the ONLY vitals columns
                // encode may change.
                //
                // D-66: is_rr_flagged is derived HERE, from the value being
                // written, through the same VitalSigns helper the kiosk submit
                // and the seeders use — never posted by the form.
                $visit->vitalSigns?->update([
                    'respiratory_rate' => $encodedVitals['respiratory_rate'],
                    'is_rr_flagged' => VitalSigns::isRespiratoryRateFlagged($encodedVitals['respiratory_rate']),
                ]);

                $visit->update(['status' => 'encoded']);

                $visit->appointment?->update(['status' => 'completed']);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->alreadyEncoded($visit);
        }

        // `encoded_visit_id` powers the queue's "reverse-stack" exit animation:
        // a session flash lives for exactly ONE request (and is gone on reload
        // by design), so the queue page can render the just-encoded visit once
        // as a leaving "ghost" row and animate it out.
        return redirect()->route('nurse.queue')
            ->with('status', "Assessment saved — {$visit->reference_no} encoded.")
            ->with('encoded_visit_id', $visit->id);
    }

    /** Friendly landing for a re-submit: the same screen, now read-only. */
    private function alreadyEncoded(ClinicVisit $visit): RedirectResponse
    {
        return redirect()->route('nurse.visits.encode', $visit)
            ->with('status', 'This visit has already been encoded — the assessment below is read-only.');
    }
}
