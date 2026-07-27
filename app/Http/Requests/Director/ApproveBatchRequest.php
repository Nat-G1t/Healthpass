<?php

declare(strict_types=1);

namespace App\Http\Requests\Director;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Director's Approve confirmation (FR-DIRA-02, D-36).
 *
 * D-36 made approval **confirm-only**: the batch is approved on the date the
 * College Admin already requested, so this request carries NO input at all —
 * `rules()` is deliberately empty. The date is re-read from the locked
 * `batch_requests` row inside the controller's transaction; a `scheduled_date`
 * posted by a client is simply never looked at.
 *
 * A Form Request with no rules still earns its keep: it is the one place that
 * documents "this endpoint takes nothing", and it keeps the controller
 * signature honest if a rule is ever needed again.
 *
 * NOTE: capacity is deliberately NOT validated here (FR-DIRA-06) — an
 * approved cohort may exceed the self-booking daily cap; the UI only warns.
 */
class ApproveBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware ('role:director') already gates access.
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        // D-36: nothing is accepted from the client on approve.
        return [];
    }
}
