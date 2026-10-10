<?php

declare(strict_types=1);

namespace App\Http\Requests\Nurse;

use App\Actions\Nurse\SubmitManualScreening;
use App\Http\Requests\Concerns\ValidatesScreening;
use App\Models\Appointment;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\ScreeningResponse;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;

/**
 * D-133 — the Manual Screening payload: everything the kiosk collects (Q11),
 * held to the KIOSK's own rules (ValidatesScreening), plus — on a Medical
 * Assessment — the nurse's interview, held to the 06 interview's rules
 * (VisitFormRequest: every Assessment section and the respiratory rate).
 *
 * There is no visit yet on this page, only the route-bound {appointment}. So
 * the two server-side facts the rules depend on — which form, and whether the
 * student is female — are read from THAT appointment's batch and student.
 * Nothing in the form names the student; BMI and every flag are computed on
 * the server, so a posted `bmi` or `is_*_flagged` has no rule and never
 * reaches validated().
 */
class ManualScreeningRequest extends VisitFormRequest
{
    use ValidatesScreening;

    /**
     * The kiosk's vital keys (the posted `vitals[...]`) → the clinic's encoded
     * field of the same reading, whose label, unit, step and bounds the page
     * shows (ClearanceRecord::ENCODED_VITALS).
     */
    public const VITAL_FIELDS = [
        'height' => 'height_cm',
        'weight' => 'weight_kg',
        'temperature' => 'temperature_c',
        'systolic' => 'bp_systolic',
        'diastolic' => 'bp_diastolic',
        'heartRate' => 'heart_rate_bpm',
    ];

    /** Whether the appointment's student is female — read once per request. */
    private ?bool $isFemale = null;

    /**
     * Only an appointment Manual Screening may still take (today's, open, and
     * the student has no visit today — Appointment::scopeManuallyScreenable).
     * The save re-checks it under the row lock; this early check spares the
     * nurse a page of validation errors about a student already screened.
     * The `role:nurse` route middleware has already turned other roles away.
     */
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment && $appointment->isManuallyScreenable();
    }

    /** Back to the search with the reason, never a 403 page. */
    protected function failedAuthorization(): void
    {
        $appointment = $this->route('appointment');

        throw new HttpResponseException(redirect()->route('nurse.manual.index')->with('error', $appointment instanceof Appointment
            ? SubmitManualScreening::refusalFor($appointment)
            : 'That appointment could not be found.'));
    }

    /** Never reached: authorize() above decides, as there is no visit yet. */
    protected function mayActOn(ClinicVisit $visit, User $user): bool
    {
        return false;
    }

    /** The kiosk's D-56 detail cleaning and D-68 / D-79 removals. */
    protected function prepareForValidation(): void
    {
        $this->prepareScreeningInput();
    }

    /** D-62 — the appointment's batch names the form; the body never does. */
    protected function formType(): string
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment ? $appointment->formType() : 'clearance';
    }

    /** D-79 / D-70 — the appointment's student, from their profile. */
    protected function studentIsFemale(): bool
    {
        if ($this->isFemale !== null) {
            return $this->isFemale;
        }

        $appointment = $this->route('appointment');

        return $this->isFemale = $appointment instanceof Appointment
            && (bool) StudentProfile::where('user_id', $appointment->student_id)->first(['sex'])?->isFemale();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            // The kiosk's consent screen (FR-KSK-04): the nurse confirms the
            // student agreed. The stored time is the server's.
            'privacy_consent' => ['accepted'],
            ...$this->screeningVitalsRules(),
            ...$this->screeningRules(),
            ...$this->socialHistoryRules(),
        ];

        if ($this->formType() === 'assessment') {
            $rules['respiratory_rate'] = $this->vitalsRules()['respiratory_rate'];
            $rules = [...$rules, ...$this->assessmentRules()];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            ...$this->screeningMessages(),
            'privacy_consent.accepted' => 'Confirm that the student agreed to the Data Privacy notice.',
            'respiratory_rate.required' => 'Measure and enter the respiratory rate before submitting.',
        ];
    }

    /**
     * Name each field in an error exactly as the page labels it ("The Height
     * field must be at least 50."), not by its posted key.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = parent::attributes();

        foreach (self::VITAL_FIELDS as $posted => $field) {
            $attributes["vitals.{$posted}"] = ClearanceRecord::ENCODED_VITALS[$field]['label'];
        }

        foreach (ScreeningResponse::QUESTIONS as $question => ['label' => $label]) {
            $attributes["screening.{$question}"] = $label;
            $attributes["screening.details.{$question}"] = "{$label} details";
        }

        return [
            ...$attributes,
            'screening.isPregnant' => 'Currently pregnant',
            'screening.lastMenstrualPeriod' => 'Last menstrual period',
        ];
    }

    /**
     * What SubmitKioskVisit::recordVisit() stores, in the kiosk's own shape.
     *
     * @return array<string, mixed>
     */
    public function screeningData(): array
    {
        return Arr::only($this->validated(), ['vitals', 'screening', 'socialHistory']);
    }

    /**
     * The nurse's interview (D-126) for a Medical Assessment, or null for a
     * Medical Clearance, in the shape SubmitManualScreening writes.
     *
     * @return ?array{sections: array<string, mixed>, vitals: array<string, int|float>}
     */
    public function interviewData(): ?array
    {
        if ($this->formType() !== 'assessment') {
            return null;
        }

        return ['sections' => $this->medicalAssessmentAttributes(), 'vitals' => $this->encodedVitals()];
    }

    /**
     * `medical_assessments.interview_vitals` in encoded_vitals' shape (D-65):
     * the vitals typed on this page + the respiratory rate, BMI recomputed —
     * the same one shape the 06 interview stores.
     *
     * @return array<string, int|float>
     */
    public function encodedVitals(): array
    {
        $vitals = $this->validated('vitals');
        $height = (float) $vitals['height'];
        $weight = (float) $vitals['weight'];

        return [
            'height_cm' => $height,
            'weight_kg' => $weight,
            // Never taken from the request — recomputed here (FR-KSK-09).
            'bmi' => VitalSigns::computeBmi($height, $weight),
            'temperature_c' => (float) $vitals['temperature'],
            'bp_systolic' => (int) $vitals['systolic'],
            'bp_diastolic' => (int) $vitals['diastolic'],
            'heart_rate_bpm' => (int) $vitals['heartRate'],
            'respiratory_rate' => (int) $this->validated('respiratory_rate'),
        ];
    }
}
