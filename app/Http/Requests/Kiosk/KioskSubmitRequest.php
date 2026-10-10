<?php

declare(strict_types=1);

namespace App\Http\Requests\Kiosk;

use App\Http\Requests\Concerns\ValidatesScreening;
use App\Models\Appointment;
use App\Models\ClinicVisit;
use App\Models\StudentProfile;
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
 * Which questions are required at all depends on the batch's form (D-68),
 * and that form is resolved from the SESSION-bound student's appointment for
 * today — never from the request body, which could name the other one.
 * Likewise, whether the pregnancy question was asked at all depends on the
 * session-bound student's sex (D-79), read from their profile.
 *
 * The screening rule sets themselves live in ValidatesScreening, shared with
 * the nurse's Manual Screening (D-133). The authoritative flag booleans are
 * NOT computed here — they are derived from the stored values in
 * SubmitKioskVisit (§7.4); this class only guarantees the values are sane.
 */
final class KioskSubmitRequest extends FormRequest
{
    use ValidatesScreening;

    /**
     * Which official form today's appointment named (D-68) — resolved ONCE
     * per request from the session-bound student, never from the body.
     * Null until formType() has looked it up.
     */
    private ?string $formType = null;

    /**
     * Whether the SESSION-bound student is female (D-79) — resolved once per
     * request from their profile, never from the body. Null until
     * studentIsFemale() has looked it up.
     */
    private ?bool $isFemale = null;

    /** The kiosk is public; identity was established earlier in the flow. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * prepareForValidation is a Form Request hook that may reshape the input
     * before the rules run: the D-56 detail cleaning and the D-68 / D-79
     * removals (ValidatesScreening::prepareScreeningInput()).
     */
    protected function prepareForValidation(): void
    {
        $this->prepareScreeningInput();
    }

    /**
     * Whether the SESSION-bound student is female (D-79), by the one rule
     * StudentProfile::isFemale(). With no session student or no profile the
     * pregnancy rules stay in force (the stricter, pre-D-79 behaviour); the
     * controller refuses such a submit anyway.
     */
    protected function studentIsFemale(): bool
    {
        if ($this->isFemale !== null) {
            return $this->isFemale;
        }

        $studentId = $this->session()->get('kiosk.student_id');

        if ($studentId === null) {
            return $this->isFemale = true;
        }

        return $this->isFemale = StudentProfile::where('user_id', (int) $studentId)
            ->first(['sex'])?->isFemale() ?? true;
    }

    /**
     * The form type of today's appointment for the SESSION-bound student
     * (D-68). `Appointment::todayFor()` is the same resolution
     * SubmitKioskVisit uses, so the rules below and the row that gets written
     * always agree about which form this visit is.
     *
     * A student with no appointment today falls back to 'clearance': they are
     * about to be refused by the action anyway (D-61), and this way the
     * refusal is the schedule message rather than four confusing 422s about
     * questions they were never shown.
     *
     * FR-KSK-03b: a student who already USED today's appointment (a second
     * submit) is validated against the form that visit followed, for the same
     * reason — an Assessment visit may leave a YES without details, so judging
     * it as a Clearance would answer "please add details" instead of the
     * action's "already completed" refusal.
     */
    protected function formType(): string
    {
        if ($this->formType !== null) {
            return $this->formType;
        }

        $studentId = $this->session()->get('kiosk.student_id');

        if ($studentId === null) {
            return $this->formType = 'clearance';
        }

        return $this->formType = Appointment::todayFor((int) $studentId)?->formType()
            ?? ClinicVisit::submittedTodayFor((int) $studentId)?->formType()
            ?? 'clearance';
    }

    public function rules(): array
    {
        return [
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

            ...$this->screeningVitalsRules(),
            ...$this->screeningRules(),
            ...$this->socialHistoryRules(),
        ];
    }

    public function messages(): array
    {
        return $this->screeningMessages();
    }
}
