<?php

declare(strict_types=1);

namespace App\Http\Requests\Nurse;

use App\Models\ClearanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-NRS-04 / BR-16 — the Save & Close payload: `result` (Fit/Unfit) is the
 * one required field.
 *
 * There is deliberately NO purpose rule (D-62): the purpose is the batch
 * reason, which EncodeController copies from the batch itself. A posted
 * `purpose` is not validated, so it never reaches validated() and is ignored.
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
