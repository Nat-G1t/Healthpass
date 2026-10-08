<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * D-113 — a College Admin asks the Director to release a finished batch's
 * documents with a written reason.
 *
 * D-123: no attachment any more (the Director reviews the batch's own signed
 * memo), plus an optional "The college needs printed copies of the forms"
 * tick — a missing value means no. A posted `attachment` has no rule here, so
 * it is never part of validated() and never stored.
 *
 * The batch, its college and whether it may be asked for yet are checked by
 * DocumentReleaseController under a lock; this class only checks the fields.
 */
class StoreReleaseRequest extends FormRequest
{
    /** Bounds for the written reason — also used by the popup's counter. */
    public const REASON_MIN = 10;

    public const REASON_MAX = 500;

    public function authorize(): bool
    {
        // The /admin route group already enforces auth + role + college scope.
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:'.self::REASON_MIN, 'max:'.self::REASON_MAX],
            'wants_printed_copies' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Please give the Director a reason for the release.',
            'reason.min' => 'The reason must be at least :min characters.',
            'reason.max' => 'Please keep the reason to :max characters.',
        ];
    }
}
