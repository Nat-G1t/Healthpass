<?php

declare(strict_types=1);

namespace App\Http\Requests\Nurse;

use App\Models\ClinicVisit;
use App\Models\User;

/**
 * D-126 — the nurse's Submit to physician payload for a Medical Assessment
 * visit: the seven confirmed vitals (respiratory rate included, D-65) and every
 * Assessment section (Q2: "ALL BELONGS TO THE NURSE"). There is no Result —
 * only the physician decides Fit / Unfit — so a posted `result` has no rule and
 * never reaches validated().
 *
 * The rules are VisitFormRequest's, the same ones the physician's encode uses,
 * so what the nurse can submit is exactly what the physician can save.
 */
class SubmitInterviewRequest extends VisitFormRequest
{
    /**
     * D-126 — only the nurse submits, and only a visit in the nurse's queue (a
     * captured Medical Assessment). An already-interviewed visit passes so the
     * controller can treat a double submit as a friendly no-op.
     */
    protected function mayActOn(ClinicVisit $visit, User $user): bool
    {
        return $user->role === 'nurse'
            && ($visit->status === 'interviewed' || $visit->isInQueueOf($user));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->vitalsRules(), ...$this->assessmentRules()];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'respiratory_rate.required' => 'Measure and enter the respiratory rate before submitting.',
        ];
    }
}
