<?php

declare(strict_types=1);

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the student's self-service profile edit (FR-STU-09).
 *
 * Only the self-editable fields are accepted here. Student number, college,
 * and sex are deliberately absent — they are display-only on the page and
 * must never be changed by the student.
 *
 * Rules mirror the registration Step 2 (StoreRegistrationInfoRequest) for the
 * shared fields so a profile edit can't bypass the constraints registration
 * enforced.
 */
class UpdateStudentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already behind auth + role:student; a student only ever
        // edits their own profile (resolved from the authenticated user).
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
            'course' => ['required', 'string', 'max:120'],
            'year_level' => ['required', 'string', 'in:1,2,3,4,5'],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:'.now()->subYears(100)->toDateString()],
            'place_of_birth' => ['required', 'string', 'max:120'],
            'civil_status' => ['required', 'in:Single,Married,Widowed,Separated'],
            'address' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email address is already registered.',
            'year_level.in' => 'Please select a valid year level (1–5).',
            'civil_status.in' => 'Please select a valid civil status.',
            'date_of_birth.before' => 'Date of birth must be in the past.',
        ];
    }
}
