<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validates Step 2 (Personal Information) of the student registration wizard.
 * FR-REG-03 fields. No user row is created here — data is staged in session.
 */
class StoreRegistrationInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guest middleware + consent guard in controller
    }

    public function rules(): array
    {
        return [
            'first_name'     => ['required', 'string', 'max:80'],
            'middle_name'    => ['nullable', 'string', 'max:80'],
            'last_name'      => ['required', 'string', 'max:80'],
            'student_number' => ['required', 'string', 'max:20', 'regex:/^\d+$/', 'unique:student_profiles,student_number'],
            'college_id'     => ['required', 'integer', 'exists:colleges,id'],
            'sex'            => ['required', 'in:M,F'],
            'course'         => ['required', 'string', 'max:120'],
            'year_level'     => ['required', 'string', 'in:1,2,3,4,5'],
            'date_of_birth'  => ['required', 'date', 'before:today', 'after:' . now()->subYears(100)->toDateString()],
            'place_of_birth' => ['required', 'string', 'max:120'],
            'civil_status'   => ['required', 'in:Single,Married,Widowed,Separated'],
            'address'        => ['required', 'string', 'max:500'],
            'email'          => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password'       => ['required', 'confirmed', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return [
            'student_number.unique' => 'This student number is already registered.',
            'email.unique'          => 'This email address is already registered.',
            'sex.in'                => 'Please select a sex.',
            'year_level.in'         => 'Please select a valid year level (1–5).',
            'civil_status.in'       => 'Please select a valid civil status.',
            'date_of_birth.before'  => 'Date of birth must be in the past.',
        ];
    }
}
