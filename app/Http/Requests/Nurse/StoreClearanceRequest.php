<?php

declare(strict_types=1);

namespace App\Http\Requests\Nurse;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\MedicalAssessment;
use App\Models\VitalSigns;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-NRS-04 / BR-16 — the Save & Close payload.
 *
 * `result` (Fit/Unfit) is required, and since D-65 so are the **seven vitals**
 * the clinic confirms on the Vital Signs card: the six the kiosk pre-fills plus
 * the respiratory rate, which the clinic measures and types. The bounds are the
 * FR-KSK-08 plausibility ranges from `config/healthpass.php` — the same source
 * the kiosk validates against, so the two screens can never drift apart. No
 * number is written here.
 *
 * There is deliberately NO purpose rule (D-62): the purpose is the batch
 * reason, which EncodeController copies from the batch itself. A posted
 * `purpose` is not validated, so it never reaches validated() and is ignored.
 * A posted `bmi` is ignored the same way — BMI is always recomputed
 * server-side (FR-KSK-09).
 */
class StoreClearanceRequest extends FormRequest
{
    /** Role is already enforced by the nurse route group middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $bounds = config('healthpass.validation');

        $rules = [
            'result' => ['required', Rule::in(ClearanceRecord::RESULTS)],
            // max keeps runaway notes from breaking the one-page print (FR-PRT).
            'nurse_notes' => ['nullable', 'string', 'max:2000'],
            // Set to 1 by the encode screen once Preview & Print has fired, so
            // Save & Close can stamp printed_at (FR-NRS-05) — the record row
            // doesn't exist yet at pre-save print time.
            'printed' => ['nullable', 'boolean'],

            // The vitals the clinic confirms (D-65). Height, weight and
            // temperature carry a decimal; the rest are whole numbers.
            'height_cm' => ['required', 'numeric'],
            'weight_kg' => ['required', 'numeric'],
            'temperature_c' => ['required', 'numeric'],
            'bp_systolic' => ['required', 'integer', 'gt:bp_diastolic'],
            'bp_diastolic' => ['required', 'integer'],
            'heart_rate_bpm' => ['required', 'integer'],
            'respiratory_rate' => ['required', 'integer'],
        ];

        // One min/max pair per vital, straight from FR-KSK-08's config bounds.
        foreach (ClearanceRecord::ENCODED_VITALS as $field => $vital) {
            $rules[$field][] = "min:{$bounds[$vital['bounds']]['min']}";
            $rules[$field][] = "max:{$bounds[$vital['bounds']]['max']}";
        }

        // D-69: the two forms diverge from here. A Medical Assessment visit
        // prints the STUDENT'S OWN physical-signs answers, so the clinic never
        // re-encodes them — no ps_* rules, and a posted one is dropped.
        if ($this->formType() === 'assessment') {
            return [...$rules, ...$this->assessmentRules()];
        }

        // Physical-signs exam findings (D-22): each row optional — an
        // unanswered radio pair simply isn't in the payload, leaving the
        // column NULL (prints as blank bubbles).
        foreach (array_keys(ClearanceRecord::PHYSICAL_SIGNS) as $column) {
            $rules[$column] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    /**
     * Which official form this visit follows (D-62) — read from the SERVER's
     * own resolution on the bound visit, never from the request body, exactly
     * as the kiosk does. The route model binding already loaded the visit.
     */
    private function formType(): string
    {
        $visit = $this->route('visit');

        return $visit instanceof ClinicVisit ? $visit->formType() : 'clearance';
    }

    /**
     * D-69 — the Medical Assessment Form's own sections. EVERY field here is
     * optional: only the Result and the seven vitals are required (Nat,
     * 2026-09-18), because a student may simply have nothing to report.
     *
     * The checkbox keys are checked against the model's constants, so a forged
     * value is rejected rather than stored; `array:` does the same for the
     * specify map's KEYS, which only the rows with a text box may use.
     *
     * @return array<string, mixed>
     */
    private function assessmentRules(): array
    {
        $conditions = MedicalAssessment::conditionKeys();

        return [
            'medical_history' => ['nullable', 'array:patient,family,specify'],
            'medical_history.patient' => ['nullable', 'array'],
            'medical_history.patient.*' => [Rule::in($conditions)],
            'medical_history.family' => ['nullable', 'array'],
            'medical_history.family.*' => [Rule::in($conditions)],
            'medical_history.specify' => ['nullable', 'array:'.implode(',', MedicalAssessment::specifyKeys())],
            'medical_history.specify.*' => ['nullable', 'string', 'max:'.MedicalAssessment::SPECIFY_MAX_LENGTH],

            'immunizations' => ['nullable', 'array:given,others'],
            'immunizations.given' => ['nullable', 'array'],
            'immunizations.given.*' => [Rule::in(MedicalAssessment::immunizationKeys())],
            'immunizations.others' => ['nullable', 'string', 'max:'.MedicalAssessment::IMMUNIZATION_OTHERS_MAX_LENGTH],

            'family_planning_access' => ['nullable', 'boolean'],

            'surgical_history' => ['nullable', 'array:procedures,date_done'],
            'surgical_history.procedures' => ['nullable', 'string', 'max:'.MedicalAssessment::PROCEDURES_MAX_LENGTH],
            'surgical_history.date_done' => ['nullable', 'string', 'max:'.MedicalAssessment::DATE_DONE_MAX_LENGTH],
        ];
    }

    /**
     * The `medical_assessments` row an Assessment encode writes (D-69), built
     * from the validated payload only — the keys are re-filtered against the
     * constants here too, so nothing unknown can reach a JSON column even if a
     * rule is ever loosened.
     *
     * @return array<string, mixed>
     */
    public function medicalAssessmentAttributes(): array
    {
        $history = $this->validated('medical_history') ?? [];
        $specify = array_intersect_key(
            array_filter($history['specify'] ?? [], fn (?string $text): bool => filled($text)),
            array_flip(MedicalAssessment::specifyKeys()),
        );

        return [
            'medical_history' => [
                'patient' => $this->conditionKeys($history['patient'] ?? []),
                'family' => $this->conditionKeys($history['family'] ?? []),
                'specify' => $specify,
            ],
            'immunizations' => [
                'given' => array_values(array_intersect(
                    $this->validated('immunizations.given') ?? [],
                    MedicalAssessment::immunizationKeys(),
                )),
                'others' => $this->validated('immunizations.others') ?: null,
            ],
            // NULL when neither box was ticked — "not answered", not "No".
            'family_planning_access' => $this->has('family_planning_access')
                ? $this->boolean('family_planning_access')
                : null,
            'surgical_history' => [
                'procedures' => $this->validated('surgical_history.procedures') ?: null,
                'date_done' => $this->validated('surgical_history.date_done') ?: null,
            ],
        ];
    }

    /**
     * The given keys, keeping only real conditions and the form's own order.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function conditionKeys(array $keys): array
    {
        return array_values(array_intersect(MedicalAssessment::conditionKeys(), $keys));
    }

    /**
     * The confirmed vitals as `clearance_records.encoded_vitals` stores them
     * (D-65): the seven validated values plus a server-recomputed BMI. Shared
     * by Save & Close and the pre-save Preview & Print, so what previews is
     * exactly what would save.
     *
     * @return array<string, int|float>
     */
    public function encodedVitals(): array
    {
        $height = (float) $this->validated('height_cm');
        $weight = (float) $this->validated('weight_kg');

        return [
            'height_cm' => $height,
            'weight_kg' => $weight,
            // Never taken from the request — recomputed here (FR-KSK-09).
            'bmi' => VitalSigns::computeBmi($height, $weight),
            'temperature_c' => (float) $this->validated('temperature_c'),
            'bp_systolic' => (int) $this->validated('bp_systolic'),
            'bp_diastolic' => (int) $this->validated('bp_diastolic'),
            'heart_rate_bpm' => (int) $this->validated('heart_rate_bpm'),
            'respiratory_rate' => (int) $this->validated('respiratory_rate'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'result.required' => 'Select Fit or Unfit before saving.',
            'result.in' => 'Result must be Fit or Unfit.',
            'bp_systolic.gt' => 'The systolic reading must be higher than the diastolic.',
            'respiratory_rate.required' => 'Measure and enter the respiratory rate before saving.',
        ];
    }

    /**
     * Name each vital in an error message exactly as its box is labelled on the
     * card ("The BP Systolic field must be at least 60."), so the nurse knows
     * which one to fix.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return collect(ClearanceRecord::ENCODED_VITALS)
            ->map(fn (array $vital): string => $vital['label'])
            ->all();
    }
}
