<?php

declare(strict_types=1);

namespace App\Http\Requests\Director;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the Director provisioning a staff account (FR-AUTH-10, D-47).
 *
 * A Form Request is Laravel's validation-in-a-class: the framework runs these
 * rules BEFORE the controller method is entered and redirects back with errors
 * if any fail, so the controller only ever sees data that has already passed.
 *
 * The role whitelist below is the anti-privilege-escalation guard. It lives
 * HERE, not only in the view's <select>, because a dropdown is client-side
 * decoration — anyone can post `role=director` with curl or devtools. This rule
 * is what actually refuses it.
 */
class StoreStaffAccountRequest extends FormRequest
{
    /**
     * The ONLY roles the Director may create (D-47). Never `director` (that
     * would let one Director mint another and is the classic privilege-
     * escalation path), never `student` (students self-register — FR-REG).
     *
     * @var list<string>
     */
    public const PROVISIONABLE_ROLES = ['college_admin', 'nurse'];

    public function authorize(): bool
    {
        // Route middleware ['auth', 'role:director'] already gates this endpoint.
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(self::PROVISIONABLE_ROLES)],
            'name' => ['required', 'string', 'max:120'],
            // max:191 matches the users.email column width.
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            // A college_admin MUST have a college: `college.scope` 403s an admin
            // with a null managed_college_id on every /admin route (FR-AUTH-06),
            // so creating one without a college would create a dead account.
            // A nurse must NOT have one — nurses are clinic-wide.
            'managed_college_id' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('role') === 'college_admin'),
                Rule::prohibitedIf(fn (): bool => $this->input('role') === 'nurse'),
                'integer',
                Rule::exists('colleges', 'id'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'role.in' => 'You can only create College Admin and Nurse accounts.',
            'managed_college_id.required' => 'Pick the college this admin will manage — an admin without one cannot open any Admin page.',
            'managed_college_id.prohibited' => 'A nurse is clinic-wide and has no managed college.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['managed_college_id' => 'college'];
    }
}
