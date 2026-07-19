<?php

declare(strict_types=1);

namespace App\Actions\Kiosk;

use App\Models\Appointment;
use App\Models\ClinicVisit;
use App\Models\StudentProfile;
use App\Services\ReferenceNumberService;
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
            $visit = ClinicVisit::create([
                'reference_no' => $this->references->generateVisitRef(),
                'student_id' => $data['studentUserId'],
                // Freeze the student's college NOW (FR-STU-09 snapshot, D-17): a
                // later transfer must not re-attribute this visit's flags/cases.
                'college_id' => $this->studentCollegeId((int) $data['studentUserId']),
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
                'vision' => $screening['vision'],
                'hearing' => $screening['hearing'],
                'nose' => $screening['nose'],
                'skin' => $screening['skin'],
                'respiratory' => $screening['respiratory'],
                'heart' => $screening['heart'],
                'digestive' => $screening['digestive'],
                'bones' => $screening['bones'],
                'nervous' => $screening['nervous'],
                'is_pregnant' => $screening['isPregnant'],
                'last_menstrual_period' => $screening['lastMenstrualPeriod'] ?? null,
            ]);

            return $visit;
        });
    }

    /**
     * The student's current college id — captured as the visit's frozen snapshot
     * (FR-STU-09). Always present: every student profile carries a non-null college.
     */
    private function studentCollegeId(int $studentId): int
    {
        $collegeId = StudentProfile::where('user_id', $studentId)->value('college_id');

        // Fail loudly rather than (int)-casting a missing value to 0: if the
        // profile row vanished between scan/login and submit (mid-session admin
        // change), college_id=0 would either violate the FK (500) or, worse, save
        // a visit mis-attributed to a non-existent college that no Director scope
        // filter ever matches. Every bound student must still have a college here.
        if ($collegeId === null) {
            throw new \RuntimeException("Student {$studentId} has no college profile at kiosk submit.");
        }

        return (int) $collegeId;
    }

    /**
     * Today's non-cancelled appointment for this student, or null (walk-in,
     * BR-10). D-33 (amends D-3): dental appointments now link too, so the
     * nurse-encode step completes them and dental visits get a college
     * snapshot. Medical is tried first — when BOTH are booked today the
     * medical appointment wins the link and the dental one stays `scheduled`
     * (D-33 edge rule). Walk-ins are first-class — they flow through the
     * queue identically.
     */
    private function todaysAppointmentId(int $studentId): ?int
    {
        foreach (['medical', 'dental'] as $serviceType) {
            $id = Appointment::query()
                ->where('student_id', $studentId)
                ->where('service_type', $serviceType)
                ->whereDate('scheduled_date', Carbon::today())
                // Only an OPEN appointment may be linked. Matching "!= cancelled"
                // also caught 'completed', so a student returning to the kiosk after
                // a nurse already encoded their morning visit would re-link the same,
                // already-completed appointment — the nurse then completes it twice
                // and one appointment yields two counted visits. A second visit with
                // no open appointment is a walk-in (appointment_id null, BR-10).
                ->where('status', 'scheduled')
                ->orderBy('id')
                ->value('id');

            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
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
