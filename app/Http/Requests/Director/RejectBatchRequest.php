<?php

declare(strict_types=1);

namespace App\Http\Requests\Director;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Director's Reject decision (FR-DIRA-04, D-36): a written reason is now
 * mandatory. Since D-36 removed the Director's ability to move the date,
 * rejection is the only way to push back on a batch — so the reason is what
 * tells the College Admin what to change before resubmitting, and it is the
 * audit trail for why a cohort was turned down.
 *
 * A Form Request is Laravel's validation-in-a-class: the framework validates
 * the incoming data before the controller method runs and redirects back with
 * errors if it fails, so the controller only ever sees valid input.
 *
 * min:10 keeps "no" / "nope" out of the record; max:500 bounds a TEXT column
 * that is rendered back to another user.
 */
class RejectBatchRequest extends FormRequest
{
    /** Bounds for the written reason — also used by the modal's counter. */
    public const REASON_MIN = 10;

    public const REASON_MAX = 500;

    public function authorize(): bool
    {
        // Route middleware ('role:director') already gates access.
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'min:'.self::REASON_MIN, 'max:'.self::REASON_MAX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'Give the college a reason for the rejection.',
            'rejection_reason.min' => 'The reason must be at least :min characters — the college admin has to act on it.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['rejection_reason' => 'reason'];
    }
}
