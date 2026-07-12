<?php

declare(strict_types=1);

namespace App\Http\Requests\Nurse;

use App\Models\ClearanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-NRS-04 / BR-16 — the Save & Close payload: `result` (Fit/Unfit) is the
 * one required field; category and purpose are optional but must come from
 * the locked PRD lists (the model constants — SQLite in tests doesn't
 * enforce the MySQL enums, so validation is the real gate).
 */
class StoreClearanceRequest extends FormRequest
{
    /** Role is already enforced by the nurse route group middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'result' => ['required', Rule::in(['Fit', 'Unfit'])],
            // A case can span several systems (D-23) — zero or more from the
            // locked list, no duplicates.
            'case_categories' => ['nullable', 'array'],
            'case_categories.*' => ['distinct', Rule::in(ClearanceRecord::CASE_CATEGORIES)],
            'purpose' => ['nullable', Rule::in(ClearanceRecord::PURPOSES)],
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
        ];
    }
}
