<?php

declare(strict_types=1);

namespace App\Http\Requests\Kiosk;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Server-side re-validation of a full kiosk session at submit (FR-KSK-12).
 *
 * A Form Request is Laravel's place for input validation: the `rules()` here
 * run BEFORE the controller, and a failure returns a 422 with the first error
 * in `message` (which the kiosk front-end shows inline). We re-check EVERYTHING
 * the browser already checked — ranges, completeness, consent — because the
 * kiosk is a public endpoint and client checks can be bypassed (NFR security).
 *
 * Plausibility bounds come from config/healthpass.php (FR-KSK-08), the SAME
 * source the front-end reads, so the two never drift. The authoritative flag
 * booleans are NOT computed here — they are derived from the stored values in
 * SubmitKioskVisit (§7.4); this class only guarantees the values are sane.
 */
final class KioskSubmitRequest extends FormRequest
{
    /** The nine body-system booleans (PRD data dictionary §6 / state-machine SYSTEMS). */
    private const SYSTEMS = [
        'vision', 'hearing', 'nose', 'skin', 'respiratory',
        'heart', 'digestive', 'bones', 'nervous',
    ];

    /** The kiosk is public; identity was established earlier in the flow. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $bounds = config('healthpass.validation');

        $rules = [
            // NOTE: studentUserId + loginMethod are intentionally NOT validated
            // here. Identity is bound to the server session at scan/login and
            // read from there in KioskController@submit — never from the request
            // body — so a tampered payload cannot attach a visit to another
            // student. The active-student re-check lives there too.
            'privacyConsentAt' => ['required', 'date'], // consent must be present (FR-KSK-04)

            // Provenance of each captured step; the roll-up to sensor/manual/mixed
            // happens server-side in the action (FR-KSK-06).
            'vitalMethods' => ['required', 'array', 'min:1'],
            'vitalMethods.*' => [Rule::in(['sensor', 'manual'])],

            // Vitals — ranges mirror config/healthpass.php (FR-KSK-08). BMI is
            // intentionally absent: it is recomputed server-side from height+weight.
            'vitals' => ['required', 'array'],
            'vitals.height' => ['required', 'numeric', "min:{$bounds['height_cm']['min']}", "max:{$bounds['height_cm']['max']}"],
            'vitals.weight' => ['required', 'numeric', "min:{$bounds['weight_kg']['min']}", "max:{$bounds['weight_kg']['max']}"],
            'vitals.temperature' => ['required', 'numeric', "min:{$bounds['temperature_c']['min']}", "max:{$bounds['temperature_c']['max']}"],
            'vitals.systolic' => ['required', 'integer', "min:{$bounds['bp_systolic']['min']}", "max:{$bounds['bp_systolic']['max']}"],
            'vitals.diastolic' => ['required', 'integer', "min:{$bounds['bp_diastolic']['min']}", "max:{$bounds['bp_diastolic']['max']}"],
            'vitals.heartRate' => ['required', 'integer', "min:{$bounds['heart_rate']['min']}", "max:{$bounds['heart_rate']['max']}"],

            // Screening — all nine systems answered (true/false), plus pregnancy.
            'screening' => ['required', 'array'],
            'screening.isPregnant' => ['required', 'boolean'],
            // LMP required only when pregnant, never in the future (FR-KSK-10).
            'screening.lastMenstrualPeriod' => ['nullable', 'required_if:screening.isPregnant,true', 'date', 'before_or_equal:today'],
        ];

        foreach (self::SYSTEMS as $system) {
            $rules["screening.{$system}"] = ['required', 'boolean'];
        }

        return $rules;
    }
}
