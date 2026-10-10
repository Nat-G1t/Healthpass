<?php

declare(strict_types=1);

namespace App\Http\Requests\Director;

use App\Http\Requests\Admin\CancelBatchRequest as AdminCancelBatchRequest;

/**
 * The Clinic Director's Cancel of an approved batch (FR-DIRA-12, D-129).
 *
 * The same field, rules and 10–500 bounds as the College Admin's Cancel
 * (it extends that Form Request), so the two can never drift apart. Only the
 * wording changes: the Director's reason is read by the college and by every
 * student on the batch, not by the Director.
 */
class CancelBatchRequest extends AdminCancelBatchRequest
{
    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cancellation_reason.required' => 'Give a reason for cancelling this batch.',
            'cancellation_reason.min' => 'The reason must be at least :min characters — the college and every student on the batch will read it.',
        ];
    }
}
