<?php

declare(strict_types=1);

namespace App\Http\Requests\Kiosk;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A blood-pressure reading POSTed by the Bluetooth BP daemon on the Pi (D-58).
 *
 * The payload is the daemon's fixed JSON contract (PRD §11.4). This class does
 * two jobs before the controller runs:
 *   • authorize() checks the shared secret — returning false makes Laravel
 *     answer 403 before any validation happens;
 *   • rules() checks the numbers against the SAME plausibility bounds the kiosk
 *     and KioskSubmitRequest use (config('healthpass.validation'), FR-KSK-08),
 *     so a reading accepted here can never be refused later at submit.
 *
 * The daemon's `user_id` and `entry_method` are deliberately not validated, so
 * they never reach validated(): identity binds only at kiosk scan/login, and the
 * kiosk records the step's provenance itself.
 */
final class StoreBpReadingRequest extends FormRequest
{
    /** The monitor's measurement-status flags that are kept (Bluetooth BP Measurement). */
    public const FLAGS = [
        'body_movement',
        'cuff_too_loose',
        'irregular_pulse',
        'pulse_out_of_range',
        'improper_position',
    ];

    /**
     * Constant-time comparison of the X-Kiosk-Key header with the configured key.
     * Fails CLOSED: with no key configured nothing may post — otherwise an empty
     * header would match an empty key.
     */
    public function authorize(): bool
    {
        $key = (string) config('healthpass.kiosk.device_key');
        $sent = $this->header('X-Kiosk-Key');

        return $key !== '' && is_string($sent) && hash_equals($key, $sent);
    }

    public function rules(): array
    {
        $bounds = config('healthpass.validation');

        $rules = [
            // gt:diastolic — a systolic at or below the diastolic is a misread.
            'systolic' => ['required', 'integer', "min:{$bounds['bp_systolic']['min']}", "max:{$bounds['bp_systolic']['max']}", 'gt:diastolic'],
            'diastolic' => ['required', 'integer', "min:{$bounds['bp_diastolic']['min']}", "max:{$bounds['bp_diastolic']['max']}"],
            // pulse, mean_arterial and taken_at are optional in the Bluetooth spec.
            'pulse' => ['nullable', 'integer', "min:{$bounds['heart_rate']['min']}", "max:{$bounds['heart_rate']['max']}"],
            'mean_arterial' => ['nullable', 'numeric'],
            'taken_at' => ['nullable', 'date'],
            'unit' => ['required', Rule::in(['mmHg'])],
            'device_model' => ['nullable', 'string', 'max:64'],
            // Hex of the original Bluetooth characteristic, kept for debugging.
            'raw' => ['nullable', 'string', 'max:128', 'regex:/^[0-9a-fA-F]*$/'],
            // An empty object when the device sent no measurement status.
            'flags' => ['nullable', 'array'],
            'suspect' => ['required', 'boolean'],
        ];

        foreach (self::FLAGS as $flag) {
            $rules["flags.{$flag}"] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['systolic.gt' => 'The systolic reading must be higher than the diastolic.'];
    }
}
