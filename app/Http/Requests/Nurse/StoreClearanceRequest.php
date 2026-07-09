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
        return [
            'result' => ['required', Rule::in(['Fit', 'Unfit'])],
            'case_category' => ['nullable', Rule::in(ClearanceRecord::CASE_CATEGORIES)],
            'purpose' => ['nullable', Rule::in(ClearanceRecord::PURPOSES)],
            // max keeps runaway notes from breaking the one-page print (FR-PRT).
            'nurse_notes' => ['nullable', 'string', 'max:2000'],
        ];
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
