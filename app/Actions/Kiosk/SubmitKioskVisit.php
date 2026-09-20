<?php

declare(strict_types=1);

namespace App\Actions\Kiosk;

use App\Models\Appointment;
use App\Models\ClinicVisit;
use App\Models\ScreeningResponse;
use App\Models\StudentProfile;
use App\Models\VitalSigns;
use App\Services\ReferenceNumberService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The kiosk submit use case (FR-KSK-12).
 *
 * An "Action" is just a single-purpose service class — one public `handle()`
 * that does one job. Here it writes a complete kiosk session as three linked
 * rows inside ONE database transaction (clinic_visits + its 1:1 vital_signs +
 * its 1:1 screening_responses), so the visit is either fully recorded or not
 * at all — a half-written visit can never reach the nurse queue.
 *
 * Three things are authoritative on the SERVER, never trusted from the browser:
 *   • BMI is recomputed from height + weight.
 *   • The four flag booleans are derived from config('healthpass.thresholds')
 *     (§7.4, BR-13/14) — the single source of truth shared with the queue and
 *     Director screens. The fifth, is_rr_flagged, is computed at encode
 *     (D-66), because only the clinic measures a respiratory rate (D-65).
 *     Flags are advisory only; they never block submission.
 *   • Whether the student may submit at all (D-61): only a student holding a
 *     `scheduled` appointment today. There are no walk-ins, and the kiosk's
 *     "No Clinic Schedule Today" screen is only a courtesy — this is the gate.
 *   • Which official form this visit follows (D-68). It is re-resolved here
 *     from that same appointment, so a payload claiming the other form cannot
 *     make a Medical Clearance visit store a Personal / Social History.
 */
final class SubmitKioskVisit
{
    /**
     * The refusal /kiosk/rest gets when the recomputed flags say nothing needs
     * re-taking (D-72). The kiosk falls back to showing Submit to Clinic.
     */
    public const NOTHING_TO_RECHECK_MESSAGE = 'Nothing needs re-taking — please submit your visit.';

    /** The refusal a student with no appointment today gets (D-61). */
    public const NO_SCHEDULE_MESSAGE = "You don't have a clinic schedule today. Clearances are scheduled "
        .'through your college, so please ask your college office to include you in a batch request.';

    public function __construct(private ReferenceNumberService $references) {}

    /**
     * @param  array  $data  The validated payload from KioskSubmitRequest, plus
     *                       the SERVER-bound studentUserId, loginMethod and
     *                       bpReading (the claimed Bluetooth reading or null, D-58).
     */
    public function handle(array $data): ClinicVisit
    {
        return $this->write($data, resting: false);
    }

    /**
     * D-72 — the same write, parked as a `resting` visit instead of submitted.
     *
     * The student's temperature, blood pressure or heart rate came out high, so
     * they sit down for a few minutes and re-take it before the clinic ever
     * hears about them. Everything they already answered (consent, the twelve
     * Physical Signs rows, the social history) is stored NOW so the re-check
     * pass only has to ask for the flagged reading again — but the visit is
     * `resting`, which keeps it out of the queue and out of every count
     * (ClinicVisit::scopeSubmitted).
     *
     * Refused with a 422 when the SERVER's own recomputed flags say nothing
     * needs re-taking: the kiosk decides the button from its display flags, but
     * this is the decision that counts.
     */
    public function rest(array $data): ClinicVisit
    {
        return $this->write($data, resting: true);
    }

    private function write(array $data, bool $resting): ClinicVisit
    {
        $vitals = $data['vitals'];
        $screening = $data['screening'];
        $thresholds = config('healthpass.thresholds');

        $bmi = VitalSigns::computeBmi((float) $vitals['height'], (float) $vitals['weight']);

        // §7.4 flag rules, computed HERE from the posted numbers — the browser's
        // own orange ⚑ are display hints and are never read (BR-14). The rest
        // decision below uses this same one computation.
        $flags = self::flagsFor($vitals, $thresholds, $bmi);

        if ($resting && VitalSigns::recheckStepsFor($flags) === []) {
            throw ValidationException::withMessages(['vitals' => self::NOTHING_TO_RECHECK_MESSAGE]);
        }

        // generateVisitRef() locks its sequence row for the life of this
        // transaction, so the reference number and the INSERT are atomic.
        return DB::transaction(function () use ($data, $vitals, $screening, $bmi, $flags, $resting) {
            // D-61: no walk-ins. Resolved before anything is written, so a
            // refusal leaves no row behind. A ValidationException is Laravel's
            // "the input is not acceptable" error: for the kiosk's JSON request
            // it becomes a 422 whose `message` the Review screen shows — the
            // same shape a failed Form Request produces.
            $appointment = Appointment::todayFor((int) $data['studentUserId']);

            if ($appointment === null) {
                throw ValidationException::withMessages(['appointment' => self::NO_SCHEDULE_MESSAGE]);
            }

            // Freeze the student's college AND program NOW (FR-STU-09 snapshot —
            // D-17 for the college, D-43 for the program): a later transfer or
            // program shift must not re-attribute this visit's flags, cases, or
            // per-program report row.
            $snapshot = $this->studentSnapshot((int) $data['studentUserId']);

            $visit = ClinicVisit::create([
                'reference_no' => $this->references->generateVisitRef(),
                'student_id' => $data['studentUserId'],
                'college_id' => $snapshot['college_id'],
                'course' => $snapshot['course'],
                'appointment_id' => $appointment->id, // always set since D-61 (BR-10)
                'login_method' => $data['loginMethod'],
                // BR-11: resting → captured → encoded (D-72). A rest pass parks
                // here until the student comes back and re-takes the reading.
                'status' => $resting ? 'resting' : 'captured',
                // Consent is captured seconds earlier in the same session; the
                // client asserts it by sending privacyConsentAt (KioskSubmitRequest
                // requires its presence), but the STORED timestamp is stamped
                // server-side. /kiosk/submit is public, so a client-supplied date is
                // forgeable/back-datable — this legal audit field must not be trusted
                // from the browser (FR-KSK-04). Same trust rule as identity + flags.
                'privacy_consent_at' => now(),
                'checked_in_at' => now(),
                // D-72: the SERVER's "come back at" moment — the only clock the
                // Rest screen shows. Null on a normal submit.
                'resting_until' => $resting
                    ? now()->addMinutes((int) config('healthpass.kiosk.recheck_rest_minutes'))
                    : null,
            ]);

            $visit->vitalSigns()->create([
                'height_cm' => $vitals['height'],
                'weight_kg' => $vitals['weight'],
                'bmi' => $bmi,
                'temperature_c' => $vitals['temperature'],
                'heart_rate_bpm' => $vitals['heartRate'],
                'bp_systolic' => $vitals['systolic'],
                'bp_diastolic' => $vitals['diastolic'],
                'entry_method' => $this->entryMethod($data['vitalMethods']),
                // D-58: the Bluetooth monitor's own record of the BP reading
                // (irregular pulse, raw bytes, …), or null.
                'bp_device_reading' => $this->bpDeviceReading($data['bpReading'] ?? null, $vitals),
                // §7.4 flag rules — computed above by flagsFor(), stored as
                // queryable booleans (BR-14).
                ...$flags,
            ]);

            $visit->screeningResponse()->create([
                // The official forms' twelve Physical Signs rows, one boolean column each (D-63).
                ...Arr::only($screening, array_keys(ScreeningResponse::QUESTIONS)),
                // Already cleaned by KioskSubmitRequest: known questions answered
                // YES only, control characters stripped, ≤ 120 chars, null if none.
                'details' => $screening['details'] ?? null,
                // D-68: section I of the Medical Assessment Form, or four
                // NULLs on a Medical Clearance visit. The form type is
                // RE-RESOLVED here from the appointment this action just
                // picked — the browser's copy is never consulted.
                ...$this->socialHistory($appointment->formType(), $data['socialHistory'] ?? null),
                'is_pregnant' => $screening['isPregnant'],
                'last_menstrual_period' => $screening['lastMenstrualPeriod'] ?? null,
            ]);

            return $visit;
        });
    }

    /**
     * The §7.4 flag booleans for one posted reading (BR-13/14) — the ONE place
     * the kiosk derives them, shared by submit and by the D-72 rest decision so
     * the button the student saw and the row the server writes agree.
     *
     * There is no is_rr_flagged: the kiosk cannot measure a respiratory rate,
     * so encode computes that one (D-65/D-66). A posted flag of any kind is
     * ignored — these come from the numbers themselves.
     *
     * @return array{is_temp_flagged: bool, is_bp_flagged: bool, is_bmi_flagged: bool, is_hr_flagged: bool}
     */
    public static function flagsFor(array $vitals, array $thresholds, float $bmi): array
    {
        return [
            'is_temp_flagged' => (float) $vitals['temperature'] > $thresholds['temperature_max'],
            'is_bp_flagged' => (int) $vitals['systolic'] >= $thresholds['bp_systolic']
                || (int) $vitals['diastolic'] >= $thresholds['bp_diastolic'],
            'is_bmi_flagged' => $bmi >= $thresholds['bmi_obese'],
            'is_hr_flagged' => VitalSigns::isHeartRateFlagged((int) $vitals['heartRate']),
        ];
    }

    /**
     * The student's current college and program, read in ONE query and frozen on
     * the visit as its snapshot (FR-STU-09; D-17 college, D-43 program).
     *
     * $studentId is the SERVER-side bound student from the kiosk session, set at
     * scan/login. /kiosk/submit is public, so nothing here may be sourced from
     * the request body — that is the same trust rule the flags and the consent
     * timestamp follow (CLAUDE.md).
     *
     * @return array{college_id: int, course: ?string}
     */
    private function studentSnapshot(int $studentId): array
    {
        $profile = StudentProfile::where('user_id', $studentId)->first(['college_id', 'course']);

        // Fail loudly rather than (int)-casting a missing value to 0: if the
        // profile row vanished between scan/login and submit (mid-session admin
        // change), college_id=0 would either violate the FK (500) or, worse, save
        // a visit mis-attributed to a non-existent college that no Director scope
        // filter ever matches. Every bound student must still have a college here.
        if ($profile === null || $profile->college_id === null) {
            throw new \RuntimeException("Student {$studentId} has no college profile at kiosk submit.");
        }

        return [
            'college_id' => (int) $profile->college_id,
            // A missing program is NOT fatal — unlike the college, it has nothing
            // to break. Profiles predating the D-42 catalog can carry an empty
            // course, and a visit without one is still a valid visit; it simply
            // reports as "—". blank() catches both null and the empty string that
            // the NOT NULL student_profiles.course column would hold.
            'course' => blank($profile->course) ? null : $profile->course,
        ];
    }

    /**
     * The Personal / Social History columns for this visit (D-68).
     *
     * The FORM TYPE decides, and it was resolved from the appointment the
     * server itself picked — never from the request body, which could name
     * the other form. On a Medical Clearance visit the four questions were
     * never asked, so every column is NULL and whatever the browser posted is
     * discarded here as well as in KioskSubmitRequest.
     *
     * @param  array|null  $answers  The validated socialHistory block, or null.
     * @return array<string, mixed>
     */
    private function socialHistory(string $formType, ?array $answers): array
    {
        if ($formType !== 'assessment' || $answers === null) {
            return ['smoking' => null, 'alcohol' => null, 'illicit_drugs' => null, 'sexually_active' => null];
        }

        return [
            'smoking' => $answers['smoking'],
            'alcohol' => $answers['alcohol'],
            'illicit_drugs' => $answers['illicitDrugs'],
            'sexually_active' => $answers['sexuallyActive'],
        ];
    }

    /**
     * The Bluetooth monitor's record of this visit's BP reading (D-58), or null.
     *
     * $claimed comes from the SERVER session — the reading the kiosk claimed —
     * never from the request body, so a tampered payload can neither invent a
     * device record nor hide an irregular pulse. It is kept only when the
     * submitted numbers ARE that reading's numbers: a BP step retaken by hand
     * after the claim stores nothing device-related.
     */
    private function bpDeviceReading(?array $claimed, array $vitals): ?array
    {
        if ($claimed === null) {
            return null;
        }

        $sameNumbers = (int) $vitals['systolic'] === $claimed['systolic']
            && (int) $vitals['diastolic'] === $claimed['diastolic']
            && (int) $vitals['heartRate'] === $claimed['pulse'];

        if (! $sameNumbers) {
            return null;
        }

        return Arr::only($claimed, ['device_model', 'raw', 'taken_at', 'mean_arterial', 'flags', 'suspect', 'received_at']);
    }

    /** Roll the per-step methods up to one provenance value (FR-KSK-06). */
    private function entryMethod(array $methods): string
    {
        $unique = array_values(array_unique($methods));

        if ($unique === ['sensor']) {
            return 'sensor';
        }
        if ($unique === ['manual']) {
            return 'manual';
        }

        return 'mixed';
    }
}
