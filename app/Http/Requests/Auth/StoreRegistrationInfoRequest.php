<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\Programs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates Step 2 (Personal Information) of the student registration wizard.
 * FR-REG-03 fields. No user row is created here — data is staged in session.
 *
 * Program and year level are college-dependent (D-42): both are checked
 * against config/programs.php for the college that was ACTUALLY submitted.
 * The cascading dropdowns on the form are convenience only — this class is
 * what stops a hand-edited POST naming another college's program.
 */
class StoreRegistrationInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guest middleware + consent guard in controller
    }

    public function rules(): array
    {
        $collegeId = (int) $this->input('college_id');

        return [
            'first_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'student_number' => ['required', 'string', 'max:20', 'regex:/^[\d-]+$/', 'unique:student_profiles,student_number'],
            'college_id' => ['required', 'integer', 'exists:colleges,id'],
            'sex' => ['required', 'in:M,F'],
            'course' => ['required', 'string', 'max:120', Rule::in(Programs::forCollege($collegeId))],
            'year_level' => ['required', 'string', Rule::in(Programs::yearLevelKeys($collegeId))],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:'.now()->subYears(100)->toDateString()],
            'place_of_birth' => ['required', 'string', 'max:120'],
            'civil_status' => ['required', 'in:Single,Married,Widowed,Separated'],
            'address' => ['required', 'string', 'max:500'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        // With no college chosen (or a forged one) EVERY program is invalid,
        // so "the selected course is invalid" would point at the wrong field.
        // Name the real problem instead.
        $collegeChosen = Programs::forCollege((int) $this->input('college_id')) !== [];
        $collegeFirst = 'Select your college first, then choose your program.';

        return [
            'student_number.unique' => 'This student number is already registered.',
            'student_number.regex' => 'Student number may only contain digits and dashes (e.g. 2024-00001).',
            'email.unique' => 'This email address is already registered.',
            'sex.in' => 'Please select a sex.',
            'course.required' => $collegeChosen ? 'Please choose your program.' : $collegeFirst,
            'course.in' => $collegeChosen ? 'Please choose a program offered by your college.' : $collegeFirst,
            'year_level.required' => $collegeChosen ? 'Please choose your year level.' : $collegeFirst,
            'year_level.in' => $collegeChosen ? 'Please choose a year level your college offers.' : $collegeFirst,
            'civil_status.in' => 'Please select a valid civil status.',
            'date_of_birth.before' => 'Date of birth must be in the past.',
        ];
    }
}
