<?php

declare(strict_types=1);

namespace App\Http\Requests\Nurse;

use App\Models\ClearanceRecord;
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

        // Physical-signs exam findings (D-22): each row optional — an
        // unanswered radio pair simply isn't in the payload, leaving the
        // column NULL (prints as blank bubbles).
        foreach (array_keys(ClearanceRecord::PHYSICAL_SIGNS) as $column) {
            $rules[$column] = ['nullable', 'boolean'];
        }

        return $rules;
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
