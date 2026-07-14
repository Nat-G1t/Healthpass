<?php

declare(strict_types=1);

namespace App\Http\Requests\Director;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Director's Approve confirmation (FR-DIRA-02, D-29): the only input is the
 * chosen appointment date. Past dates are disallowed server-side — the
 * modal's `min` attribute is just a convenience, never a guarantee.
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
        return [
            'scheduled_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'scheduled_date.after_or_equal' => 'The appointment date cannot be in the past.',
        ];
    }
}
