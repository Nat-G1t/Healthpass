<?php

declare(strict_types=1);

namespace App\Http\Requests\Director;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reassigning a College Admin's managed college (FR-AUTH-10, D-47) — used when
 * an admin moves units, so the Director does not have to create a second
 * account for the same person.
 *
 * `required` (not `nullable`) is deliberate: clearing the college would leave an
 * account that `college.scope` refuses on every /admin route (FR-AUTH-06), i.e.
 * a silently broken login. Deactivation is how an account is taken out of use.
 */
class UpdateStaffCollegeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware ['auth', 'role:director'] gates access; the controller
        // additionally refuses any target that is not a college_admin.
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'managed_college_id' => ['required', 'integer', Rule::exists('colleges', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'managed_college_id.required' => 'Pick a college — an admin cannot be left without one.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['managed_college_id' => 'college'];
    }
}
