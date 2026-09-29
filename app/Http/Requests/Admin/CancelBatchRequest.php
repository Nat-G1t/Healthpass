<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Director\RejectBatchRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * College Admin's Cancel of a batch request (FR-ADM-11, D-92): a written
 * reason is mandatory, pending or approved alike.
 *
 * The Clinic Director reads it on Batch Approvals, and when the batch was
 * already approved every student it cancels is emailed it — so it is the
 * college's official explanation, not a note to itself.
 *
 * Same bounds as the Director's rejection reason (D-36): min 10 keeps "no" /
 * "oops" out of the record, max 500 bounds a TEXT column that is shown to
 * other people.
 */
class CancelBatchRequest extends FormRequest
{
    public const REASON_MIN = RejectBatchRequest::REASON_MIN;

    public const REASON_MAX = RejectBatchRequest::REASON_MAX;

    public function authorize(): bool
    {
        // Route middleware ('role:college_admin') already gates access, and the
        // controller fetches the batch through the admin's own college.
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', 'min:'.self::REASON_MIN, 'max:'.self::REASON_MAX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cancellation_reason.required' => 'Give a reason for cancelling this batch.',
            'cancellation_reason.min' => 'The reason must be at least :min characters — the Clinic Director and your students will read it.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['cancellation_reason' => 'reason'];
    }
}
