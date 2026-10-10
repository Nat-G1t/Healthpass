<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\ScreeningResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * The screening rule sets — vitals, the twelve Physical Signs rows, pregnancy
 * and the Personal / Social History — shared by the kiosk's submit
 * (KioskSubmitRequest) and the nurse's Manual Screening (D-133,
 * ManualScreeningRequest), so a student typed in by the nurse is held to
 * exactly the rules the kiosk applies.
 *
 * A "trait" is a block of methods PHP copies into every class that `use`s it —
 * here, two Form Requests that cannot share a parent class. The class supplies
 * the two server-side facts the rules depend on, `formType()` and
 * `studentIsFemale()`, each resolved from its own trusted source (the kiosk
 * session, or the route-bound appointment) — never from the request body.
 *
 * Plausibility bounds come from config/healthpass.php (FR-KSK-08), the SAME
 * source the kiosk front-end reads, so the two never drift.
 */
trait ValidatesScreening
{
    /** Answer values the `boolean` rule reads as YES. */
    private const YES_VALUES = [true, 1, '1'];

    /** Which official form this screening follows (D-68), from the server. */
    abstract protected function formType(): string;

    /** Whether the student is female (D-79), from their profile on the server. */
    abstract protected function studentIsFemale(): bool;

    /**
     * Reshape the screening input before the rules run — call it from
     * prepareForValidation() (a Form Request hook that runs first):
     *   • D-56: YES details are cleaned (see cleanDetails());
     *   • D-68: `socialHistory` is removed unless this is a Medical Assessment;
     *   • D-79: the pregnancy pair is removed unless the student is female.
     */
    protected function prepareScreeningInput(): void
    {
        $this->cleanDetails();
        $this->dropSocialHistoryUnlessAssessment();
        $this->dropPregnancyUnlessFemale();
    }

    /**
     * D-79: the pregnancy question is asked of female students only. For a
     * male student, `isPregnant` and `lastMenstrualPeriod` are removed from
     * the request HERE, before the rules run, so whatever the browser posted
     * never reaches validated(). SubmitKioskVisit stores false / NULL for him.
     */
    private function dropPregnancyUnlessFemale(): void
    {
        $screening = $this->input('screening');

        if ($this->studentIsFemale() || ! is_array($screening)) {
            return;
        }

        $this->merge(['screening' => Arr::except($screening, ['isPregnant', 'lastMenstrualPeriod'])]);
    }

    /**
     * D-68: only a Medical Assessment Form visit has a Personal / Social
     * History. On a Medical Clearance visit the four questions were never
     * asked, so anything posted under `socialHistory` is removed from the
     * request HERE — before the rules run — and can therefore never reach
     * validated(), the action, or the database. The decision comes from the
     * server-resolved form type; a `formType` in the body is never read.
     */
    private function dropSocialHistoryUnlessAssessment(): void
    {
        if ($this->formType() !== 'assessment') {
            $this->replace(Arr::except($this->all(), ['socialHistory']));
        }
    }

    /**
     * Clean the optional YES details (D-56). The browser is never trusted, so
     * whatever it sent:
     *   • a detail is kept only for one of the twelve known questions (D-63) — unknown
     *     keys are dropped;
     *   • and only when that question was answered YES — a NO drops it;
     *   • control characters (NUL, tab, newline, …) are stripped, then trimmed;
     *   • nothing left → `details` becomes null.
     * The 120-character cap is a RULE below, so an over-long detail is refused
     * with a 422 rather than silently cut.
     */
    private function cleanDetails(): void
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

    /**
     * Vitals — ranges mirror config/healthpass.php (FR-KSK-08). BMI is
     * intentionally absent: it is recomputed server-side from height + weight.
     *
     * @return array<string, mixed>
     */
    protected function screeningVitalsRules(): array
    {
        $bounds = config('healthpass.validation');

        return [
            'vitals' => ['required', 'array'],
            'vitals.height' => ['required', 'numeric', "min:{$bounds['height_cm']['min']}", "max:{$bounds['height_cm']['max']}"],
            'vitals.weight' => ['required', 'numeric', "min:{$bounds['weight_kg']['min']}", "max:{$bounds['weight_kg']['max']}"],
            'vitals.temperature' => ['required', 'numeric', "min:{$bounds['temperature_c']['min']}", "max:{$bounds['temperature_c']['max']}"],
            'vitals.systolic' => ['required', 'integer', "min:{$bounds['bp_systolic']['min']}", "max:{$bounds['bp_systolic']['max']}"],
            'vitals.diastolic' => ['required', 'integer', "min:{$bounds['bp_diastolic']['min']}", "max:{$bounds['bp_diastolic']['max']}"],
            'vitals.heartRate' => ['required', 'integer', "min:{$bounds['heart_rate']['min']}", "max:{$bounds['heart_rate']['max']}"],
        ];
    }

    /**
     * The form's twelve Physical Signs rows (D-63), their YES details (D-56 /
     * D-75) and, for a female student, pregnancy (D-79).
     *
     * @return array<string, mixed>
     */
    protected function screeningRules(): array
    {
        $rules = [
            // Screening — the form's twelve rows (D-63) answered (true/false),
            // plus pregnancy for a female student (just below, D-79).
            'screening' => ['required', 'array'],

            // YES details (D-56), already cleaned by prepareScreeningInput().
            // Optional here; required on a Medical Clearance just below (D-75).
            'screening.details' => ['nullable', 'array'],
            'screening.details.*' => ['string', 'max:'.ScreeningResponse::DETAIL_MAX_LENGTH],
        ];

        foreach (array_keys(ScreeningResponse::QUESTIONS) as $question) {
            $rules["screening.{$question}"] = ['required', 'boolean'];
        }

        // D-79: pregnancy is asked of female students only. For a male the
        // pair was already removed by prepareScreeningInput(), so no rule.
        if ($this->studentIsFemale()) {
            $rules['screening.isPregnant'] = ['required', 'boolean'];
            // LMP required only when pregnant, never in the future (FR-KSK-10).
            $rules['screening.lastMenstrualPeriod'] = ['nullable', 'required_if:screening.isPregnant,true', 'date', 'before_or_equal:today'];
        }

        // D-75: on a Medical Clearance, a YES must carry details — the paper
        // says "If YES, give details under Remarks". `required_if_accepted`
        // makes the detail required only when its question was answered YES.
        // The text was already trimmed above, so whitespace-only counts as
        // empty. A Medical Assessment keeps the details optional (D-56).
        // A rule on an exact key REPLACES the `screening.details.*` rule for
        // that key, so string + max are repeated here, not inherited.
        if ($this->formType() === 'clearance') {
            foreach (array_keys(ScreeningResponse::QUESTIONS) as $question) {
                $rules["screening.details.{$question}"] = [
                    "required_if_accepted:screening.{$question}",
                    'string',
                    'min:'.ScreeningResponse::DETAIL_MIN_LENGTH,
                    'max:'.ScreeningResponse::DETAIL_MAX_LENGTH,
                ];
            }
        }

        return $rules;
    }

    /**
     * Personal / Social History — Medical Assessment Form only (D-68). On a
     * Medical Clearance visit no rule is added at all, because
     * prepareScreeningInput() has already removed the whole block.
     *
     * @return array<string, mixed>
     */
    protected function socialHistoryRules(): array
    {
        if ($this->formType() !== 'assessment') {
            return [];
        }

        $rules = ['socialHistory' => ['required', 'array']];

        foreach (['smoking', 'alcohol', 'illicitDrugs'] as $habit) {
            $rules["socialHistory.{$habit}"] = ['required', Rule::in(ScreeningResponse::SOCIAL_HISTORY_VALUES)];
        }

        $rules['socialHistory.sexuallyActive'] = ['required', 'boolean'];

        return $rules;
    }

    /**
     * Plain wording for the four Personal / Social History rows (D-68), and
     * for a Medical Clearance YES left without details (D-75), naming the
     * question so the reader knows which row to go back to.
     *
     * @return array<string, string>
     */
    protected function screeningMessages(): array
    {
        $messages = [
            'socialHistory.required' => 'Please answer the Personal / Social History questions.',
            'socialHistory.*.required' => 'Please answer all four Personal / Social History questions.',
            'socialHistory.*.in' => 'Please answer Yes, No or Quit.',
        ];

        foreach (ScreeningResponse::QUESTIONS as $question => ['label' => $label]) {
            $messages["screening.details.{$question}.required_if_accepted"] = "You answered Yes to {$label}. Please add details.";
            $messages["screening.details.{$question}.min"] = "Please add a little more detail for {$label} (at least :min characters).";
        }

        return $messages;
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
