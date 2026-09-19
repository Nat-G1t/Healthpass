<?php

declare(strict_types=1);

namespace App\Http\Requests\Kiosk;

use App\Models\ScreeningResponse;
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
    /** Answer values the `boolean` rule reads as YES. */
    private const YES_VALUES = [true, 1, '1'];

    /** The kiosk is public; identity was established earlier in the flow. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Clean the optional YES details before the rules run (D-56).
     * prepareForValidation is a Form Request hook that may reshape the input
     * first. The browser is never trusted, so whatever it sent:
     *   • a detail is kept only for one of the twelve known questions (D-63) — unknown
     *     keys are dropped;
     *   • and only when that question was answered YES — a NO drops it;
     *   • control characters (NUL, tab, newline, …) are stripped, then trimmed;
     *   • nothing left → `details` becomes null.
     * The 120-character cap is a RULE below, so an over-long detail is refused
     * with a 422 rather than silently cut.
     */
    protected function prepareForValidation(): void
    {
        $screening = $this->input('screening');

        // Anything that isn't an array is left alone for the `array` rules to refuse.
        if (! is_array($screening) || ! is_array($screening['details'] ?? null)) {
            return;
        }

        $details = [];

        foreach (array_keys(ScreeningResponse::QUESTIONS) as $question) {
            $text = $screening['details'][$question] ?? null;
            $answeredYes = in_array($screening[$question] ?? null, self::YES_VALUES, true);

            if ($text === null || ! $answeredYes) {
                continue;
            }

            // A non-string is kept as-is so the `string` rule refuses it.
            $text = is_string($text) ? $this->stripControlCharacters($text) : $text;

            if ($text !== '') {
                $details[$question] = $text;
            }
        }

        $this->merge(['screening' => [...$screening, 'details' => $details === [] ? null : $details]]);
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

            // Screening — the form's twelve rows (D-63) answered (true/false), plus pregnancy.
            'screening' => ['required', 'array'],
            'screening.isPregnant' => ['required', 'boolean'],
            // LMP required only when pregnant, never in the future (FR-KSK-10).
            'screening.lastMenstrualPeriod' => ['nullable', 'required_if:screening.isPregnant,true', 'date', 'before_or_equal:today'],

            // Optional YES details (D-56), already cleaned by prepareForValidation().
            'screening.details' => ['nullable', 'array'],
            'screening.details.*' => ['string', 'max:'.ScreeningResponse::DETAIL_MAX_LENGTH],
        ];

        foreach (array_keys(ScreeningResponse::QUESTIONS) as $question) {
            $rules["screening.{$question}"] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * Remove Unicode control characters (\p{Cc}) and trim. preg_replace returns
     * null for invalid UTF-8; that leaves nothing usable, so the detail is dropped.
     */
    private function stripControlCharacters(string $text): string
    {
        return trim((string) preg_replace('/\p{Cc}/u', '', $text));
    }
}
