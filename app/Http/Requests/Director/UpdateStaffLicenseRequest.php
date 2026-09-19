<?php

declare(strict_types=1);

namespace App\Http\Requests\Director;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting a physician's license number (FR-AUTH-10, D-64) — a typo made at
 * creation would otherwise print on every record they encode, with no way to
 * fix it short of a second account.
 *
 * Same digit rule as creation (StoreStaffAccountRequest::LICENSE_RULES). The
 * correction applies to records encoded FROM NOW ON: clearance records copy the
 * license at encode time, so what was already printed stays as it was.
 */
class UpdateStaffLicenseRequest extends FormRequest
{
    /**
     * A named error bag keeps a failed correction's message apart from the
     * "New staff account" form on the same page, which also has a
     * license_number field. The view reads it as $errors->license.
     */
    protected $errorBag = 'license';

    public function authorize(): bool
    {
        // Route middleware ['auth', 'role:director'] gates access; the controller
        // additionally refuses any target that is not a physician.
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'license_number' => ['required', ...StoreStaffAccountRequest::LICENSE_RULES],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'license_number.required' => 'Enter the license number — a physician cannot be left without one.',
            'license_number.digits_between' => 'The license number must be 4 to 10 digits, numbers only.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['license_number' => 'license number'];
    }
}
