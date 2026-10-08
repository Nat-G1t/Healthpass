<?php

declare(strict_types=1);

namespace App\Http\Requests\Nurse;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\MedicalAssessment;
use App\Models\User;
use App\Models\VitalSigns;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * D-126 — what the nurse's interview and the physician's encode share: the
 * seven vitals the clinic confirms (D-65) and the Medical Assessment Form's own
 * sections (D-69/D-70), plus the methods that turn them into the stored shapes.
 *
 * `abstract` means this class is never used on its own — it is the common
 * half of two Form Requests (Laravel classes that validate a request before
 * the controller runs): StoreClearanceRequest (the physician's Fit / Unfit)
 * and SubmitInterviewRequest (the nurse's Submit to physician). Keeping the
 * rules here means the two screens can never validate a field differently.
 *
 * The bounds are the FR-KSK-08 plausibility ranges from `config/healthpass.php`
 * — the same source the kiosk validates against. No number is written here.
 */
abstract class VisitFormRequest extends FormRequest
{
    /**
     * D-126 — may the signed-in clinic user act on this visit? A Form
     * Request's authorize() runs BEFORE its rules, so a nurse posting Fit /
     * Unfit (or a physician posting an interview) is turned away before any
     * field is even looked at. Each subclass says who may act and when.
     */
    public function authorize(): bool
    {
        $visit = $this->route('visit');

        return $visit instanceof ClinicVisit
            && $this->user() !== null
            && $this->mayActOn($visit, $this->user());
    }

    /** D-126 — the role + lifecycle rule for this request (see authorize()). */
    abstract protected function mayActOn(ClinicVisit $visit, User $user): bool;

    /**
     * D-126 — a refusal goes back to the Live Queue with a plain sentence,
     * never a 403 page: it is almost always a stale tab or the other role's
     * link. HttpResponseException ends the request with the response it carries.
     */
    protected function failedAuthorization(): void
    {
        $visit = $this->route('visit');

        throw new HttpResponseException(redirect()->route('nurse.queue')->with('error', $visit instanceof ClinicVisit
            ? $visit->queueRefusal($this->user())
            : 'That visit could not be found.'));
    }

    /**
     * The vitals the clinic confirms (D-65). Height, weight and temperature
     * carry a decimal; the rest are whole numbers. A posted `bmi` has no rule,
     * so it never reaches validated() — BMI is recomputed (FR-KSK-09).
     *
     * @return array<string, mixed>
     */
    protected function vitalsRules(): array
    {
        $bounds = config('healthpass.validation');

        $rules = [
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

        return $rules;
    }

    /**
     * Which official form this visit follows (D-62) — read from the SERVER's
     * own resolution on the bound visit, never from the request body, exactly
     * as the kiosk does. The route model binding already loaded the visit.
     */
    protected function formType(): string
    {
        $visit = $this->route('visit');

        return $visit instanceof ClinicVisit ? $visit->formType() : 'clearance';
    }

    /**
     * D-69 — the Medical Assessment Form's own sections. EVERY field here is
     * optional: only the Result and the seven vitals are required (Nat,
     * 2026-09-18), because a student may simply have nothing to report.
     * Since D-126 the nurse fills them at the interview and the physician may
     * correct them at encode — both requests use these same rules.
     *
     * The checkbox keys are checked against the model's constants, so a forged
     * value is rejected rather than stored; `array:` does the same for the
     * specify map's KEYS, which only the rows with a text box may use.
     *
     * @return array<string, mixed>
     */
    protected function assessmentRules(): array
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

            // D-70 — the Pertinent Physical Examination, open to every
            // student. The wildcard `*` applies one rule to every group at
            // once, whatever group key was posted; the group and its finding
            // keys are checked against the constants when the row is built,
            // where anything unrecognised is simply dropped.
            'physical_exam' => ['nullable', 'array'],
            'physical_exam.*' => ['array:findings,others'],
            'physical_exam.*.findings' => ['nullable', 'array'],
            'physical_exam.*.findings.*' => ['string'],
            'physical_exam.*.others' => ['nullable', 'string', 'max:'.MedicalAssessment::SPECIFY_MAX_LENGTH],

            // Sections V and VI exist only for a female student (D-70). For
            // anyone else no rule is added, so the posted values never reach
            // validated() and the two columns are written NULL.
            ...$this->femaleRules(),
        ];
    }

    /**
     * D-70 — V. Menstrual History and VI. OB/Pregnancy History, female
     * students only. Every field is optional; a value that IS given has to be
     * possible, and the ranges come from the model's constants so the number
     * inputs' min/max and these rules cannot drift apart.
     *
     * @return array<string, mixed>
     */
    private function femaleRules(): array
    {
        if (! $this->studentIsFemale()) {
            return [];
        }

        $rules = [
            'menstrual_history' => ['nullable', 'array:'.implode(',', [
                ...array_keys(MedicalAssessment::MENSTRUAL_RANGES), 'lmp', 'contraceptive', 'menopause',
            ])],
            // A period cannot have started in the future.
            'menstrual_history.lmp' => ['nullable', 'date', 'before_or_equal:today'],
            'menstrual_history.contraceptive' => ['nullable', 'string', 'max:'.MedicalAssessment::SPECIFY_MAX_LENGTH],
            'menstrual_history.menopause' => ['nullable', 'boolean'],

            'ob_history' => ['nullable', 'array:'.implode(',', [
                ...array_keys(MedicalAssessment::OB_RANGES), 'delivery_type', 'pih',
            ])],
            'ob_history.delivery_type' => ['nullable', 'string', 'max:'.MedicalAssessment::SPECIFY_MAX_LENGTH],
            'ob_history.pih' => ['nullable', 'boolean'],
        ];

        $ranges = [
            'menstrual_history' => MedicalAssessment::MENSTRUAL_RANGES,
            'ob_history' => MedicalAssessment::OB_RANGES,
        ];

        foreach ($ranges as $section => $fields) {
            foreach ($fields as $field => [$min, $max]) {
                $rules["{$section}.{$field}"] = ['nullable', 'integer', "min:{$min}", "max:{$max}"];
            }
        }

        return $rules;
    }

    /** D-70 — the female gate, decided on the server from the bound visit. */
    private function studentIsFemale(): bool
    {
        $visit = $this->route('visit');

        return $visit instanceof ClinicVisit && $visit->studentIsFemale();
    }

    /**
     * The `medical_assessments` row an Assessment interview (D-126) or encode
     * writes (D-69), built
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
            // D-70. NULL for anyone but a female student: no rule validated
            // these, so there is nothing to store even if they were posted.
            'menstrual_history' => $this->menstrualHistory(),
            'ob_history' => $this->obHistory(),
            'physical_exam' => $this->physicalExam(),
        ];
    }

    /**
     * V. Menstrual History (D-70), or NULL for a student who is not female.
     * A field nobody filled in is null, not 0 — and 0 pads a day is a real
     * answer, which is why this reads "was it given?" rather than "is it
     * truthy?".
     *
     * @return ?array<string, mixed>
     */
    private function menstrualHistory(): ?array
    {
        if (! $this->studentIsFemale()) {
            return null;
        }

        return [
            'menarche_age' => $this->intOrNull('menstrual_history.menarche_age'),
            'first_intercourse_age' => $this->intOrNull('menstrual_history.first_intercourse_age'),
            // Normalised to Y-m-d, whatever shape the date input posted.
            'lmp' => ($lmp = $this->validated('menstrual_history.lmp'))
                ? Carbon::parse($lmp)->toDateString()
                : null,
            'period_days' => $this->intOrNull('menstrual_history.period_days'),
            'pads_per_day' => $this->intOrNull('menstrual_history.pads_per_day'),
            'cycle_days' => $this->intOrNull('menstrual_history.cycle_days'),
            'contraceptive' => $this->validated('menstrual_history.contraceptive') ?: null,
            // NULL = neither box ticked, which is not a No.
            'menopause' => $this->boolOrNull('menstrual_history.menopause'),
            'menopause_age' => $this->intOrNull('menstrual_history.menopause_age'),
        ];
    }

    /** VI. OB/Pregnancy History (D-70), or NULL for a non-female student. */
    private function obHistory(): ?array
    {
        if (! $this->studentIsFemale()) {
            return null;
        }

        $counts = [];

        foreach (array_keys(MedicalAssessment::OB_RANGES) as $field) {
            $counts[$field] = $this->intOrNull("ob_history.{$field}");
        }

        return [
            ...$counts,
            'delivery_type' => $this->validated('ob_history.delivery_type') ?: null,
            'pih' => $this->boolOrNull('ob_history.pih'),
        ];
    }

    /**
     * The Pertinent Physical Examination (D-70): all eight groups, always, in
     * the paper's order. Each group keeps only the findings that belong to
     * THAT group — an unknown group, or a finding key borrowed from another
     * group, is dropped rather than stored.
     *
     * @return array<string, array{findings: list<string>, others: ?string}>
     */
    private function physicalExam(): array
    {
        $posted = $this->validated('physical_exam') ?? [];
        $exam = [];

        foreach (MedicalAssessment::physicalExamGroups() as $group) {
            $findings = (array) ($posted[$group]['findings'] ?? []);

            $exam[$group] = [
                'findings' => array_values(array_intersect(
                    MedicalAssessment::findingKeys($group),
                    $findings,
                )),
                'others' => ($posted[$group]['others'] ?? null) ?: null,
            ];
        }

        return $exam;
    }

    /** A validated number, or null when the box was left empty. */
    private function intOrNull(string $key): ?int
    {
        $value = $this->validated($key);

        return $value === null ? null : (int) $value;
    }

    /** A validated Yes/No radio, or null when neither box was ticked. */
    private function boolOrNull(string $key): ?bool
    {
        $value = $this->validated($key);

        return $value === null ? null : (bool) $value;
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
     * by Save & Close, the pre-save Preview & Print and — as
     * `medical_assessments.interview_vitals` — the nurse's Submit to physician
     * (D-126), so every copy has one shape.
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
