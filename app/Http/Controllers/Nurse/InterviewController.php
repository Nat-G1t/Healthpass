<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Nurse\SubmitInterviewRequest;
use App\Models\ClinicVisit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * D-126 — the nurse's "Submit to physician".
 *
 * After the kiosk takes the vital signs, the nurse interviews a Medical
 * Assessment student: confirms the vitals, measures the respiratory rate and
 * fills every section of the form (Q2: "ALL BELONGS TO THE NURSE"). Submitting
 * hands the student to the physician, who alone decides Fit / Unfit
 * (EncodeController). A Medical Clearance student never comes here — they go
 * straight to the physician's queue.
 *
 * Who may post is decided by SubmitInterviewRequest::mayActOn() before any
 * field is validated: only a nurse, only a captured Assessment visit (or an
 * already-interviewed one, so a double submit is a friendly no-op).
 */
class InterviewController extends Controller
{
    /**
     * One transaction with the visit row LOCKED (lockForUpdate() — a second
     * request for the same row waits until this one finishes), so the status
     * is re-checked after any colleague's Remove or double click:
     *  1. write the `medical_assessments` row: the sections + the confirmed
     *     vitals as `interview_vitals`;
     *  2. write the respiratory rate + its flag to `vital_signs` (D-65/D-66);
     *  3. flip the visit to `interviewed`, stamping who and when — which moves
     *     it from the nurse's queue to the physician's.
     */
    public function store(SubmitInterviewRequest $request, ClinicVisit $visit): RedirectResponse
    {
        try {
            $submitted = DB::transaction(function () use ($request, $visit): bool {
                $locked = ClinicVisit::whereKey($visit->id)->lockForUpdate()->first();

                if ($locked === null || $locked->status !== 'captured' || $locked->formType() !== 'assessment') {
                    return false;
                }

                $vitals = $request->encodedVitals();

                $locked->medicalAssessment()->create([
                    ...$request->medicalAssessmentAttributes(),
                    'interview_vitals' => $vitals,
                ]);

                $locked->vitalSigns?->recordRespiratoryRate($vitals['respiratory_rate']);

                $locked->update([
                    'status' => 'interviewed',
                    'interviewed_by' => $request->user()->id,
                    'interviewed_at' => now(),
                ]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // The UNIQUE on medical_assessments.clinic_visit_id: a second
            // submit of the same visit in the same instant. Same as below.
            $submitted = false;
        }

        if (! $submitted) {
            return $this->notSubmitted($visit);
        }

        // left_queue_visit_id: the queue animates the row out once (see
        // QueueController::index).
        return redirect()->route('nurse.queue')
            ->with('status', "Submitted to the physician — {$visit->reference_no}.")
            ->with('left_queue_visit_id', $visit->id);
    }

    /**
     * Nothing was written: either it was already submitted (a double click or
     * a stale tab — a friendly no-op) or it left the queue meanwhile.
     */
    private function notSubmitted(ClinicVisit $visit): RedirectResponse
    {
        $fresh = $visit->fresh();

        if ($fresh?->status === 'interviewed' || $fresh?->status === 'encoded') {
            return redirect()->route('nurse.queue')
                ->with('status', "{$visit->reference_no} was already submitted to the physician.");
        }

        return redirect()->route('nurse.queue')
            ->with('error', 'That student is no longer waiting for an interview, so nothing was submitted.');
    }
}
