<?php

declare(strict_types=1);

namespace App\Http\Requests\Nurse;

use App\Models\ClearanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-NRS-04 / BR-16 — the Save & Close payload: `result` (Fit/Unfit) is the
 * one required field; purpose is optional but must come from the locked PRD
 * list (the model constants — SQLite in tests doesn't enforce the MySQL
 * enums, so validation is the real gate).
 */
class StoreClearanceRequest extends FormRequest
{
    /** Role is already enforced by the nurse route group middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * prepareForValidation runs before the rules: a Form Request hook for
     * normalizing input. Here it drops a stray specify-text unless the
     * purpose actually is Others — e.g. the nurse typed one, then switched
     * back to a listed purpose before saving.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('purpose') !== ClearanceRecord::PURPOSE_OTHERS) {
            $this->merge(['purpose_other' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'result' => ['required', Rule::in(['Fit', 'Unfit'])],
            // The four locked purposes plus the form's "Others, Specify" line;
            // picking Others requires the specify text.
            'purpose' => ['nullable', Rule::in([...ClearanceRecord::PURPOSES, ClearanceRecord::PURPOSE_OTHERS])],
            'purpose_other' => ['nullable', 'required_if:purpose,'.ClearanceRecord::PURPOSE_OTHERS, 'string', 'max:120'],
            // max keeps runaway notes from breaking the one-page print (FR-PRT).
            'nurse_notes' => ['nullable', 'string', 'max:2000'],
            // Set to 1 by the encode screen once Preview & Print has fired, so
            // Save & Close can stamp printed_at (FR-NRS-05) — the record row
            // doesn't exist yet at pre-save print time.
            'printed' => ['nullable', 'boolean'],
        ];

        // Physical-signs exam findings (D-22): each row optional — an
        // unanswered radio pair simply isn't in the payload, leaving the
        // column NULL (prints as blank bubbles).
        foreach (array_keys(ClearanceRecord::PHYSICAL_SIGNS) as $column) {
            $rules[$column] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'result.required' => 'Select Fit or Unfit before saving.',
            'result.in' => 'Result must be Fit or Unfit.',
            'purpose_other.required_if' => 'Specify the event for an "Others" purpose.',
        ];
    }
}
