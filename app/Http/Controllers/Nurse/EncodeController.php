<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Nurse\StoreClearanceRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\MedicalAssessment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * FR-NRS-03 — Encode Result ("Doctor's Assessment", BR-15).
 *
 * One screen, three modes since D-126 (separate nurse and physician queues),
 * decided by the visit's status (BR-11) and who is looking:
 *
 *  - `interview` — the NURSE on a captured Medical Assessment visit: every
 *    field except the Result, and "Submit to physician" (InterviewController).
 *  - `encode`    — the PHYSICIAN on a captured Medical Clearance or an
 *    interviewed Assessment: Fit / Unfit, Back and Save. On an interviewed
 *    visit the nurse's work shows as a summary, with Edit interview to
 *    correct it (D-127; Q3). No print buttons before Save.
 *  - `view`      — anyone on an ENCODED visit: the same screen read-only, with
 *    the print buttons when the print rule allows. Encoding is one-time
 *    (FR-NRS-04). Save lands here (D-127), with Finished back to the queue.
 *
 * A clinic user who opens a visit waiting in the OTHER role's queue is sent
 * back to their Live Queue with a plain sentence — never a 403 or 404 page.
 *
 * The flags shown are the server-frozen capture-time booleans — never
 * recomputed, same trust rule as the Live Queue. The vitals THEMSELVES are
 * editable since D-65: the kiosk's reading pre-fills the card, the clinic
 * corrects what it needs to, and the confirmed copy is saved on the clearance
 * record (`encoded_vitals`) — `vital_signs` keeps what the screening measured.
 */
class EncodeController extends Controller
{
    public function show(Request $request, ClinicVisit $visit): View|RedirectResponse
    {
        // Only the lifecycle states BR-11 lets the clinic open; a resting visit
        // or bad data means a hand-edited URL — 404, not a crash.
        abort_unless(in_array($visit->status, ['captured', 'interviewed', 'encoded'], true), 404);

        // D-126: a waiting visit opens only for the role whose queue it is in.
        // The read-only view of an ENCODED visit stays open to both.
        if ($visit->status !== 'encoded' && ! $visit->isInQueueOf($request->user())) {
            return redirect()->route('nurse.queue')->with('error', $visit->queueRefusal($request->user()));
        }

        $visit->load([
            'student.studentProfile',
            'college',           // capture-time snapshot (FR-STU-09/D-17), NOT the profile's current college
            'appointment.batchRequest', // D-62: form type + the read-only purpose
            'vitalSigns',
            'screeningResponse',
            'clearanceRecord.encoder',
            // D-69/D-126: the Assessment sections, keyed by the visit — the
            // nurse's interview on an interviewed visit, the saved row once
            // encoded — and who interviewed.
            'medicalAssessment',
            'interviewer',
        ]);

        // D-76: what the Student remarks box opens with — a saved record's own
        // value (even an empty one) always wins over the regenerated default,
        // or an edit would be thrown away on the next load. The view puts
        // old() ahead of both. An Assessment visit has no such box.
        $studentRemarks = match (true) {
            $visit->formType() === 'assessment' => null,
            $visit->clearanceRecord !== null => $visit->clearanceRecord->nurse_notes,
            default => $visit->studentRemarks(),
        };

        return view('nurse.encode', [
            'visit' => $visit,
            'mode' => match (true) {
                $visit->status === 'encoded' => 'view',
                $visit->queueRole() === 'nurse' => 'interview',
                default => 'encode',
            },
            'readOnly' => $visit->status === 'encoded',
            // D-125 (O2) + D-127: the read-only page's Print / Print front /
            // back and Save as PDF show only when the print rule allows — a
            // released batch, or the physician who encoded it, today. The
            // same rule the print routes enforce.
            'canPrint' => $visit->isReleasedForPrint($request->user()),
            // D-127: Save lands on this page; Finished takes the physician
            // back to the Live Queue. Not when the record was opened from the
            // Clinic Dashboard's View — there the back link is the way out.
            'showFinished' => $visit->status === 'encoded' && $request->query('from') !== 'dashboard',
            'studentRemarks' => $studentRemarks,
            'back' => $this->backLink($request),
        ]);
    }

    /**
     * FR-NRS-09 — back to where the page was opened from: the Clinic
     * Dashboard (View, with its filters and page kept) or the Live Queue.
     *
     * Built only from route('nurse.dashboard') and its four whitelisted keys —
     * never from a posted/queried URL or url()->previous() — so it cannot be
     * turned into an open redirect; the dashboard re-cleans the values itself.
     *
     * @return array{url: string, label: string}
     */
    private function backLink(Request $request): array
    {
        if ($request->query('from') === 'dashboard') {
            return [
                'url' => route('nurse.dashboard', DashboardController::tableState($request)),
                'label' => 'Back to Clinic Dashboard',
            ];
        }

        return ['url' => route('nurse.queue'), 'label' => 'Back to Live Queue'];
    }

    /**
     * FR-NRS-04 — Save, the PHYSICIAN's encode (D-126: only the
     * physician decides Fit / Unfit — StoreClearanceRequest::mayActOn() turns
     * anyone else, or a visit outside the physician's queue, away before
     * validation). One transaction: create the 1:1 clearance record, save the
     * Assessment sections, flip the visit to `encoded` (BR-11) and mark its
     * appointment `completed` (FR-NRS-07). The next queue poll no longer sees
     * the visit, so the row vanishes on its own.
     *
     * D-127: Save does not leave the page — it lands back on this visit's
     * encode page, now read-only, where the print buttons and Finished are.
     *
     * Encoding is one-time. Two guards, both needed:
     *  - application: an already-`encoded` visit short-circuits to the
     *    read-only view — the everyday double-click / stale-tab case;
     *  - database: the UNIQUE on clearance_records.clinic_visit_id catches
     *    the race two guards can't see (two saves of the same visit in the
     *    same instant) — the loser's transaction rolls back wholesale.
     */
    public function store(StoreClearanceRequest $request, ClinicVisit $visit): RedirectResponse
    {
        if ($visit->status === 'encoded') {
            return $this->alreadyEncoded($visit);
        }

        try {
            DB::transaction(function () use ($request, $visit): void {
                // The seven vitals are their own JSON column — pull them out
                // before the record create.
                $validated = Arr::except($request->validated(), [
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

                ClearanceRecord::create([
                    ...$validated,
                    'encoded_vitals' => $encodedVitals,
                    // D-64/D-126: the encoder is always the physician now, so
                    // their name and license always print.
                    ...ClearanceRecord::physicianBlockFor($request->user()),
                    // D-62: the purpose is the batch reason, never form input —
                    // its printed label (+ specify text). NULL with no batch.
                    ...$visit->batchPurpose(),
                    'clinic_visit_id' => $visit->id,
                    'encoded_by' => $request->user()->id,
                    'encoded_at' => now(),
                ]);

                // D-69/D-126: a Medical Assessment visit's own sections. The
                // nurse wrote the row at Submit to physician; the physician
                // may have corrected any of it (Q3), so the posted sections
                // replace it — the nurse's `interview_vitals` stay as they
                // were. updateOrCreate() creates it if there is none (a visit
                // interviewed before D-126 cannot exist, but it costs nothing).
                // A Medical Clearance writes no row: its form has no sections.
                if ($visit->formType() === 'assessment') {
                    MedicalAssessment::updateOrCreate(
                        ['clinic_visit_id' => $visit->id],
                        $request->medicalAssessmentAttributes(),
                    );
                }

                // D-65/D-66: the respiratory rate is a vital like any other —
                // the kiosk simply has no sensor for it — and its flag is
                // derived from the value being written.
                $visit->vitalSigns?->recordRespiratoryRate($encodedVitals['respiratory_rate']);

                $visit->update(['status' => 'encoded']);

                $visit->appointment?->update(['status' => 'completed']);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->alreadyEncoded($visit);
        }

        // D-127: stay on the page — the same visit, now read-only, with the
        // print buttons and Finished. `result` is validated as Fit / Unfit.
        return redirect()->route('nurse.visits.encode', $visit)
            ->with('status', "Saved — {$visit->reference_no} is {$request->validated('result')}.");
    }

    /** Friendly landing for a re-submit: the same screen, now read-only. */
    private function alreadyEncoded(ClinicVisit $visit): RedirectResponse
    {
        return redirect()->route('nurse.visits.encode', $visit)
            ->with('status', 'This visit has already been encoded — the assessment below is read-only.');
    }
}
