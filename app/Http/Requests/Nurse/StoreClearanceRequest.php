<?php

declare(strict_types=1);

namespace App\Http\Requests\Nurse;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * FR-NRS-04 / BR-16 — the Save payload: the physician's Fit / Unfit (only the
 * physician encodes since D-126).
 *
 * `result` (Fit/Unfit) is required, and since D-65 so are the **seven vitals**
 * the clinic confirms on the Vital Signs card. Those rules, and the Medical
 * Assessment Form's own sections, live in VisitFormRequest, which the nurse's
 * interview (SubmitInterviewRequest) shares.
 *
 * There is deliberately NO purpose rule (D-62): the purpose is the batch
 * reason, which EncodeController copies from the batch itself. A posted
 * `purpose` is not validated, so it never reaches validated() and is ignored.
 * A posted `bmi` is ignored the same way — BMI is always recomputed
 * server-side (FR-KSK-09).
 */
class StoreClearanceRequest extends VisitFormRequest
{
    /**
     * D-126 — only the physician encodes, and only a visit in the physician's
     * queue (a captured Medical Clearance or an interviewed Assessment). An
     * already-encoded visit passes so the controller can show the friendly
     * read-only landing for a double submit.
     */
    protected function mayActOn(ClinicVisit $visit, User $user): bool
    {
        return $visit->status === 'encoded'
            || ($user->isPhysician() && $visit->isInQueueOf($user));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'result' => ['required', Rule::in(ClearanceRecord::RESULTS)],
            ...$this->vitalsRules(),
        ];

        // D-69: the two forms diverge from here. A Medical Assessment visit
        // prints the STUDENT'S OWN physical-signs answers, so the clinic never
        // re-encodes them — no ps_* rules, and a posted one is dropped.
        if ($this->formType() === 'assessment') {
            return [...$rules, ...$this->assessmentRules()];
        }

        // Physical-signs exam findings (D-22): each row optional — an
        // unanswered radio pair simply isn't in the payload, leaving the
        // column NULL (prints as blank bubbles).
        foreach (array_keys(ClearanceRecord::PHYSICAL_SIGNS) as $column) {
            $rules[$column] = ['nullable', 'boolean'];
        }

        // Student remarks (D-76; the column stays nurse_notes) print under the
        // clearance's REMARKS. Clearance only, like ps_* above: the Medical
        // Assessment Form has no such line, so there a posted one is dropped
        // and the column stays NULL. ClearanceDocument::remarks() fits
        // whatever is typed to the one-page print (FR-PRT-05).
        $rules['nurse_notes'] = ['nullable', 'string', 'max:2000'];

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'result.required' => 'Select Fit or Unfit before saving.',
            'result.in' => 'Result must be Fit or Unfit.',
        ];
    }
}
