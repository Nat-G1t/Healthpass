<?php

declare(strict_types=1);

namespace App\Http\Requests\Director;

use App\Services\ClinicScheduleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Director's Approve decision (FR-DIRA-02, D-108).
 *
 * Since D-108 the Director SCHEDULES the batch: the Review page posts the
 * clinic date and the start hour the Director picked. D-36's confirm-only
 * approval, which took no input at all, is gone — the college no longer
 * proposes a date or an hour.
 *
 * This class only checks the two values are well-formed: a real date, not in
 * the past, and a start hour that is one of the clinic's slots
 * (ClinicScheduleService::slots(), canonical 'H:i:s'). Whether the batch FITS
 * there — the span ends by closing time, no hour has ended (BR-23), every hour
 * has room (D-91), the day stays within daily_capacity, no student already has
 * a schedule that day (BR-25) — is decided by approve() under a row lock,
 * because those answers can change between this read and the write. The span
 * LENGTH is never posted: it is always derived from the batch's own roster.
 */
class ApproveBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware ('role:director') already gates access.
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'scheduled_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'string', Rule::in(app(ClinicScheduleService::class)->slots())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'scheduled_date.required' => 'Pick the clinic date for this batch.',
            'scheduled_date.date_format' => 'That clinic date is not a valid date.',
            'scheduled_date.after_or_equal' => 'The clinic date cannot be in the past.',
            'start_time.required' => 'Pick the hour this batch starts.',
            'start_time.in' => 'That start hour is not one of the clinic hours.',
        ];
    }
}
