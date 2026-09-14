<?php

declare(strict_types=1);

namespace App\Actions\Kiosk;

use App\Models\Appointment;
use App\Models\ClinicVisit;
use App\Models\ScreeningResponse;
use App\Models\StudentProfile;
use App\Services\ReferenceNumberService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The kiosk submit use case (FR-KSK-12).
 *
 * An "Action" is just a single-purpose service class — one public `handle()`
 * that does one job. Here it writes a complete kiosk session as three linked
 * rows inside ONE database transaction (clinic_visits + its 1:1 vital_signs +
 * its 1:1 screening_responses), so the visit is either fully recorded or not
 * at all — a half-written visit can never reach the nurse queue.
 *
 * Two things are authoritative on the SERVER, never trusted from the browser:
 *   • BMI is recomputed from height + weight.
 *   • The three flag booleans are derived from config('healthpass.thresholds')
 *     (§7.4, BR-13/14) — the single source of truth shared with the queue and
 *     Director screens. Flags are advisory only; they never block submission.
 */
final class SubmitKioskVisit
{
    public function __construct(private ReferenceNumberService $references) {}

    /**
     * @param  array  $data  The validated payload from KioskSubmitRequest.
     */
    public function handle(array $data): ClinicVisit
    {
        $vitals = $data['vitals'];
        $screening = $data['screening'];
        $thresholds = config('healthpass.thresholds');

        $bmi = $this->bmi((float) $vitals['height'], (float) $vitals['weight']);

        // generateVisitRef() locks its sequence row for the life of this
        // transaction, so the reference number and the INSERT are atomic.
        return DB::transaction(function () use ($data, $vitals, $screening, $thresholds, $bmi) {
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
                'appointment_id' => $this->todaysAppointmentId((int) $data['studentUserId']), // null = walk-in (BR-10)
                'login_method' => $data['loginMethod'],
                'status' => 'captured', // until the nurse encodes (BR-11)
                // Consent is captured seconds earlier in the same session; the
                // client asserts it by sending privacyConsentAt (KioskSubmitRequest
                // requires its presence), but the STORED timestamp is stamped
                // server-side. /kiosk/submit is public, so a client-supplied date is
                // forgeable/back-datable — this legal audit field must not be trusted
                // from the browser (FR-KSK-04). Same trust rule as identity + flags.
                'privacy_consent_at' => now(),
                'checked_in_at' => now(),
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
                // §7.4 flag rules — computed here, stored as queryable booleans (BR-14).
                'is_temp_flagged' => (float) $vitals['temperature'] > $thresholds['temperature_max'],
                'is_bp_flagged' => (int) $vitals['systolic'] >= $thresholds['bp_systolic']
                    || (int) $vitals['diastolic'] >= $thresholds['bp_diastolic'],
                'is_bmi_flagged' => $bmi >= $thresholds['bmi_obese'],
            ]);

            $visit->screeningResponse()->create([
                // The official form's nine Physical Signs rows, one boolean column each (D-56).
                ...Arr::only($screening, array_keys(ScreeningResponse::QUESTIONS)),
                // Already cleaned by KioskSubmitRequest: known questions answered
                // YES only, control characters stripped, ≤ 120 chars, null if none.
                'details' => $screening['details'] ?? null,
                'is_pregnant' => $screening['isPregnant'],
                'last_menstrual_period' => $screening['lastMenstrualPeriod'] ?? null,
            ]);

            return $visit;
        });
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
     * Today's open appointment for this student, or null (walk-in, BR-10).
     *
     * D-33 (amends D-3) made dental appointments link too, so the nurse-encode
     * step completes them and dental visits get a college snapshot.
     *
     * D-54 replaces D-33's "medical wins" edge rule. A student can now hold,
     * say, a 9 AM batch appointment AND a 2 PM self-booking on the same day, so
     * the link goes to the appointment whose hour STARTS closest to check-in
     * (now()), whatever its service; a tie goes to the earlier hour. A row
     * with no hour (pre-D-37) has nothing to measure, so it links only when no
     * timed appointment exists (lowest id first). Walk-ins are first-class —
     * they flow through the queue identically.
     */
    private function todaysAppointmentId(int $studentId): ?int
    {
        $appointments = Appointment::query()
            ->where('student_id', $studentId)
            ->whereDate('scheduled_date', Carbon::today())
            // Only an OPEN appointment may be linked. Matching "!= cancelled"
            // also caught 'completed', so a student returning to the kiosk after
            // a nurse already encoded their morning visit would re-link the same,
            // already-completed appointment — the nurse then completes it twice
            // and one appointment yields two counted visits. A second visit with
            // no open appointment is a walk-in (appointment_id null, BR-10).
            ->where('status', 'scheduled')
            ->orderBy('id')
            ->get(['id', 'scheduled_time']);

        $timed = $appointments->whereNotNull('scheduled_time');

        if ($timed->isEmpty()) {
            return $appointments->first()?->id;
        }

        $checkIn = now()->getTimestamp();

        // Seconds between check-in and the start of the appointment's hour.
        $distance = fn (Appointment $appointment): int => abs(
            Carbon::parse(Carbon::today()->toDateString().' '.$appointment->scheduled_time)->getTimestamp() - $checkIn
        );

        // Closest first; on a tie, the earlier hour ('H:i:s' keys sort as times).
        return $timed
            ->sort(fn (Appointment $a, Appointment $b): int => [$distance($a), $a->scheduled_time] <=> [$distance($b), $b->scheduled_time])
            ->first()
            ->id;
    }

    /** BMI = weight(kg) ÷ height(m)², 1 decimal — matches the kiosk display (FR-KSK-09). */
    private function bmi(float $heightCm, float $weightKg): float
    {
        $metres = $heightCm / 100;

        return round($weightKg / ($metres * $metres), 1);
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
