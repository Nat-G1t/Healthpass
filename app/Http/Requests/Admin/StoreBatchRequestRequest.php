<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\BatchRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a New Batch Request submission (FR-ADM-02/03, BR-06, BR-07).
 *
 * The college scope (FR-ADM-06) is enforced HERE too, not just in the UI:
 * every submitted student id must belong to the admin's managed college,
 * so a crafted request cannot smuggle another college's students into a
 * batch. The college id comes from the authenticated user — never from
 * the request body.
 */
class StoreBatchRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The /admin route group already enforces auth + role + college
        // scope (`college.scope` middleware); no extra gate needed here.
        return true;
    }

    /**
     * BR-06: reason_detail only means anything when the reason is "others".
     * Drop stray detail text otherwise (e.g. the admin typed one, then
     * switched back to a listed reason) — server-side, so a crafted request
     * can't smuggle it past the UI. Mirrors StoreAppointmentRequest (D-28).
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('reason') !== BatchRequest::REASON_OTHERS) {
            $this->merge(['reason_detail' => null]);
        }
    }

    public function rules(): array
    {
        // Non-null is guaranteed by the `college.scope` middleware.
        $collegeId = $this->user()->managedCollege->id;

        return [
            'reason' => ['required', Rule::in(array_keys(BatchRequest::REASONS))],
            'reason_detail' => [
                'required_if:reason,'.BatchRequest::REASON_OTHERS,
                'nullable',
                'string',
                'max:500',
            ],
            'service_type' => ['required', 'in:medical,dental'],
            // BR-07: at least one student per batch.
            'students' => ['required', 'array', 'min:1'],
            'students.*' => [
                'integer',
                'distinct',
                Rule::exists('student_profiles', 'id')->where('college_id', $collegeId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Please choose a reason for this batch request.',
            'reason.in' => 'Invalid reason.',
            'reason_detail.required_if' => 'Please specify the reason when choosing "Others".',
            'service_type.required' => 'Please choose a service type.',
            'service_type.in' => 'Invalid service type.',
            'students.required' => 'Select at least one student for this batch.',
            'students.min' => 'Select at least one student for this batch.',
            'students.*.distinct' => 'A student was selected more than once.',
            'students.*.exists' => 'One of the selected students is not in your college.',
            'students.*.integer' => 'One of the selected students is invalid.',
        ];
    }
}
