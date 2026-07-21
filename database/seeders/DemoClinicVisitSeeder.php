<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DEV/DEMO ONLY — synthetic kiosk data.
 *
 * Seeds 6 clinic visits across two demo students so that every UI state on the
 * My Records page is visible during development without needing a real kiosk:
 *
 *   • 3 encoded visits (with clearance records) → shows Fit/Unfit result + modal
 *       - HP-2026-9001  Juan Santos     Fit
 *       - HP-2026-9002  Juan Santos     Unfit, BP flagged
 *       - HP-2026-9003  Maria Reyes     Fit
 *   • 3 captured visits (no clearance record) → shows Pending, gated View
 *       - HP-2026-9004  Juan Santos     normal vitals
 *       - HP-2026-9005  Maria Reyes     temperature flagged (verifies flag display)
 *       - HP-2026-9006  Maria Reyes     normal vitals
 *
 * Plus an ANALYTICS SPREAD (HP-2026-9101…) — visits across SIX months
 * (Feb–Jul 2026), all 12 colleges and both sexes, feeding every card of
 * the rescoped Director analytics (FR-ANL-09..13, D-32/D-33):
 *
 *   • medical visits with varied purposes (linked APT-2026-9xxx medical
 *     appointments) plus walk-ins (no appointment — the
 *     "Walk-in / not specified" bucket);
 *   • a deterministic sprinkle of BP / fever / BMI flags (FR-ANL-10);
 *   • BMI values across all four FR-ANL-12 buckets;
 *   • a few CAPTURED (un-encoded) July visits — these still count
 *     (FR-ANL-07 as rewritten);
 *   • dental appointments in mixed states: completed ones count toward
 *     the charts (D-33), scheduled ones must not.
 *
 * Fully deterministic — no randomness, so re-seeding a fresh DB always
 * produces the same charts. Reference bands HP-2026-9xxx / APT-2026-9xxx
 * are reserved for synthetic data and will not collide with real
 * sequences (which start from 0001).
 *
 * DELETE this seeder (and its call in DatabaseSeeder) once the real kiosk
 * starts writing clinic_visits rows directly.
 */
class DemoClinicVisitSeeder extends Seeder
{
    /**
     * Relative visit volume per college. Deliberately uneven so the
     * "sorted by volume" ordering in FR-ANL-09 is visible. Keys must match
     * colleges.code; values sum to 88 (the round-robin slot count).
     */
    private const COLLEGE_WEIGHTS = [
        'CCS' => 14, 'COE' => 12, 'CEA' => 11, 'CBS' => 9,
        'CAS' => 8, 'CSSP' => 7, 'CHTM' => 6, 'CIT' => 6,
        'LAW' => 5, 'GS' => 4, 'SHS' => 3, 'LHS' => 3,
    ];

    /**
     * Medical (kiosk) visits per month — six months so the Visits-per-Month
     * trend (FR-ANL-11) has a real shape, rising toward June with July
     * still in progress.
     */
    private const MEDICAL_VOLUME = [
        '2026-02' => 24, '2026-03' => 32, '2026-04' => 40,
        '2026-05' => 48, '2026-06' => 64, '2026-07' => 40,
    ];

    /** Completed dental appointments per month (count toward charts, D-33). */
    private const DENTAL_COMPLETED = [
        '2026-02' => 6, '2026-03' => 8, '2026-04' => 10,
        '2026-05' => 12, '2026-06' => 16, '2026-07' => 12,
    ];

    /** Still-scheduled July dental appointments — must NOT count anywhere. */
    private const DENTAL_SCHEDULED = 8;

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $this->seedRecordsPageVisits();
        $this->seedAnalyticsSpread();
    }

    /**
     * The original 6 My-Records demo visits (HP-2026-9001–9006).
     */
    private function seedRecordsPageVisits(): void
    {
        // Idempotent: skip if these demo visits already exist. Guard is 90%
        // (not 9%) so it stays independent of the analytics band (91xx).
        if (ClinicVisit::where('reference_no', 'like', 'HP-2026-90%')->exists()) {
            $this->command->info('DemoClinicVisitSeeder: demo visits already exist, skipping.');

            return;
        }

        $juan = User::where('email', 'juan.santos@psu.edu.ph')->firstOrFail();
        $maria = User::where('email', 'maria.reyes@psu.edu.ph')->firstOrFail();
        $nurse = User::where('email', 'nurse@healthpass.test')->firstOrFail();

        // Both demo students are CCS. clinic_visits.college_id became NOT NULL
        // with the D-17 snapshot migration (2026_06_30), so every seeded visit
        // must freeze it explicitly — without this, a fresh --seed fails.
        $ccs = College::where('code', 'CCS')->firstOrFail();

        // ── Encoded visit 1 — Juan Santos, Fit ───────────────────────────────
        $v1 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9001',
            'student_id' => $juan->id,
            'college_id' => $ccs->id,
            'login_method' => 'qr',
            'status' => 'encoded',
            'privacy_consent_at' => Carbon::parse('2026-01-10 08:55:00'),
            'checked_in_at' => Carbon::parse('2026-01-10 09:02:00'),
        ]);
        VitalSigns::create([
            'clinic_visit_id' => $v1->id,
            'height_cm' => 172.0,
            'weight_kg' => 68.5,
            'bmi' => 23.2,
            'temperature_c' => 36.6,
            'heart_rate_bpm' => 74,
            'bp_systolic' => 118,
            'bp_diastolic' => 76,
            'entry_method' => 'manual',
            'is_bmi_flagged' => false,
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v1->id,
            'vision' => false,
            'hearing' => false,
            'nose' => false,
            'skin' => false,
            'respiratory' => false,
            'heart' => false,
            'digestive' => false,
            'bones' => false,
            'nervous' => false,
            'is_pregnant' => false,
        ]);
        ClearanceRecord::create([
            'clinic_visit_id' => $v1->id,
            'encoded_by' => $nurse->id,
            'result' => 'Fit',
            'physician_name' => 'REYNALDO S. ALIPIO, MD',
            'physician_license_no' => '60252',
            'encoded_at' => Carbon::parse('2026-01-10 10:30:00'),
        ]);

        // ── Encoded visit 2 — Juan Santos, Unfit, BP flagged ─────────────────
        $v2 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9002',
            'student_id' => $juan->id,
            'college_id' => $ccs->id,
            'login_method' => 'qr',
            'status' => 'encoded',
            'privacy_consent_at' => Carbon::parse('2026-03-05 09:10:00'),
            'checked_in_at' => Carbon::parse('2026-03-05 09:15:00'),
        ]);
        VitalSigns::create([
            'clinic_visit_id' => $v2->id,
            'height_cm' => 172.0,
            'weight_kg' => 71.0,
            'bmi' => 24.0,
            'temperature_c' => 36.8,
            'heart_rate_bpm' => 88,
            'bp_systolic' => 145,   // above 140 threshold → flagged
            'bp_diastolic' => 93,    // above 90 threshold → flagged
            'entry_method' => 'manual',
            'is_bmi_flagged' => false,
            'is_temp_flagged' => false,
            'is_bp_flagged' => true,
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v2->id,
            'vision' => false,
            'hearing' => false,
            'nose' => false,
            'skin' => false,
            'respiratory' => true,  // flagged questionnaire answer
            'heart' => true,  // flagged questionnaire answer
            'digestive' => false,
            'bones' => false,
            'nervous' => false,
            'is_pregnant' => false,
        ]);
        ClearanceRecord::create([
            'clinic_visit_id' => $v2->id,
            'encoded_by' => $nurse->id,
            'result' => 'Unfit',
            'physician_name' => 'REYNALDO S. ALIPIO, MD',
            'physician_license_no' => '60252',
            'encoded_at' => Carbon::parse('2026-03-05 10:45:00'),
        ]);

        // ── Encoded visit 3 — Maria Reyes, Fit ───────────────────────────────
        $v3 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9003',
            'student_id' => $maria->id,
            'college_id' => $ccs->id,
            'login_method' => 'qr',
            'status' => 'encoded',
            'privacy_consent_at' => Carbon::parse('2026-02-03 10:50:00'),
            'checked_in_at' => Carbon::parse('2026-02-03 11:00:00'),
        ]);
        VitalSigns::create([
            'clinic_visit_id' => $v3->id,
            'height_cm' => 158.5,
            'weight_kg' => 52.0,
            'bmi' => 20.7,
            'temperature_c' => 36.4,
            'heart_rate_bpm' => 79,
            'bp_systolic' => 112,
            'bp_diastolic' => 70,
            'entry_method' => 'sensor',
            'is_bmi_flagged' => false,
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v3->id,
            'vision' => false,
            'hearing' => false,
            'nose' => false,
            'skin' => false,
            'respiratory' => false,
            'heart' => false,
            'digestive' => true,  // flagged questionnaire answer
            'bones' => false,
            'nervous' => false,
            'is_pregnant' => false,
        ]);
        ClearanceRecord::create([
            'clinic_visit_id' => $v3->id,
            'encoded_by' => $nurse->id,
            'result' => 'Fit',
            'physician_name' => 'REYNALDO S. ALIPIO, MD',
            'physician_license_no' => '60252',
            'encoded_at' => Carbon::parse('2026-02-03 12:15:00'),
        ]);

        // ── Captured visit 4 — Juan Santos, Pending, normal vitals ────────────
        $v4 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9004',
            'student_id' => $juan->id,
            'college_id' => $ccs->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => Carbon::parse('2026-06-15 08:40:00'),
            'checked_in_at' => Carbon::parse('2026-06-15 08:45:00'),
        ]);
        VitalSigns::create([
            'clinic_visit_id' => $v4->id,
            'height_cm' => 172.0,
            'weight_kg' => 69.0,
            'bmi' => 23.3,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 76,
            'bp_systolic' => 120,
            'bp_diastolic' => 78,
            'entry_method' => 'manual',
            'is_bmi_flagged' => false,
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v4->id,
            'vision' => false,
            'hearing' => false,
            'nose' => false,
            'skin' => false,
            'respiratory' => false,
            'heart' => false,
            'digestive' => false,
            'bones' => false,
            'nervous' => false,
            'is_pregnant' => false,
        ]);

        // ── Captured visit 5 — Maria Reyes, Pending, temperature flagged ──────
        $v5 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9005',
            'student_id' => $maria->id,
            'college_id' => $ccs->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => Carbon::parse('2026-05-10 08:55:00'),
            'checked_in_at' => Carbon::parse('2026-05-10 09:00:00'),
        ]);
        VitalSigns::create([
            'clinic_visit_id' => $v5->id,
            'height_cm' => 158.5,
            'weight_kg' => 52.5,
            'bmi' => 20.9,
            'temperature_c' => 38.1,  // fever → flagged
            'heart_rate_bpm' => 95,
            'bp_systolic' => 125,
            'bp_diastolic' => 82,
            'entry_method' => 'manual',
            'is_bmi_flagged' => false,
            'is_temp_flagged' => true,
            'is_bp_flagged' => false,
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v5->id,
            'vision' => false,
            'hearing' => false,
            'nose' => true,   // flagged answer
            'skin' => false,
            'respiratory' => true,   // flagged answer
            'heart' => false,
            'digestive' => false,
            'bones' => false,
            'nervous' => false,
            'is_pregnant' => false,
        ]);

        // ── Captured visit 6 — Maria Reyes, Pending, normal vitals ───────────
        $v6 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9006',
            'student_id' => $maria->id,
            'college_id' => $ccs->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => Carbon::parse('2026-06-20 08:25:00'),
            'checked_in_at' => Carbon::parse('2026-06-20 08:30:00'),
        ]);
        VitalSigns::create([
            'clinic_visit_id' => $v6->id,
            'height_cm' => 158.5,
            'weight_kg' => 51.5,
            'bmi' => 20.5,
            'temperature_c' => 36.3,
            'heart_rate_bpm' => 77,
            'bp_systolic' => 110,
            'bp_diastolic' => 70,
            'entry_method' => 'sensor',
            'is_bmi_flagged' => false,
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v6->id,
            'vision' => false,
            'hearing' => false,
            'nose' => false,
            'skin' => false,
            'respiratory' => false,
            'heart' => false,
            'digestive' => false,
            'bones' => false,
            'nervous' => false,
            'is_pregnant' => false,
        ]);

        $this->command->info('DemoClinicVisitSeeder: 6 demo visits created (HP-2026-9001 – HP-2026-9006).');
    }

    /**
     * The multi-month analytics spread (HP-2026-9101… / APT-2026-9001…):
     * six months of medical visits + dental appointments feeding every
     * card of the rescoped analytics. Replaces the pre-D-32 single-month
     * spread and Apr–Jun bands — any of those stale rows are purged first
     * so old demo data can't pollute the new charts.
     */
    private function seedAnalyticsSpread(): void
    {
        // New-band marker: demo APPOINTMENTS only exist since this rework,
        // so their presence means the multi-month spread is already seeded.
        if (Appointment::where('reference_no', 'like', 'APT-2026-9%')->exists()) {
            $this->command->info('DemoClinicVisitSeeder: analytics spread already exists, skipping.');

            return;
        }

        $nurse = User::where('email', 'nurse@healthpass.test')->firstOrFail();
        $colleges = College::all()->keyBy('code');

        // One round-robin slot per weight unit — walking this list with a
        // coprime stride hits every college in rough proportion, varied
        // order. Students per college take turns, so both sexes appear.
        $slots = [];
        foreach (self::COLLEGE_WEIGHTS as $code => $weight) {
            $slots = array_merge($slots, array_fill(0, $weight, $code));
        }

        $studentsByCollege = $colleges->map(
            fn (College $college) => User::where('role', 'student')
                ->whereHas('studentProfile', fn ($q) => $q->where('college_id', $college->id))
                ->orderBy('id')
                ->get()
        );

        // All-or-nothing: a mid-loop failure must not leave a partial band
        // behind, or the marker above would skip the re-run forever.
        DB::transaction(function () use ($colleges, $nurse, $slots, $studentsByCollege): void {
            $this->purgeStaleAnalyticsBands();

            $visitSeq = 0;
            $aptSeq = 0;

            // ── Medical visits, month by month ───────────────────────────
            $monthIndex = 0;
            foreach (self::MEDICAL_VOLUME as $yearMonth => $visitCount) {
                $monthIndex++;

                for ($i = 0; $i < $visitCount; $i++) {
                    $code = $slots[($i * 7 + $monthIndex * 13) % count($slots)];
                    $students = $studentsByCollege[$code];

                    if ($students->isEmpty()) {
                        continue; // no students seeded for this unit — skip
                    }

                    $this->createSpreadMedicalVisit(
                        visitSeq: $visitSeq++,
                        aptSeq: $aptSeq,
                        student: $students[$i % $students->count()],
                        college: $colleges[$code],
                        nurse: $nurse,
                        yearMonth: $yearMonth,
                        dayIndex: $i,
                    );
                }
            }

            // ── Dental appointments: completed count, scheduled don't ────
            foreach (self::DENTAL_COMPLETED as $yearMonth => $dentalCount) {
                for ($i = 0; $i < $dentalCount; $i++) {
                    // Different stride than medical so dental volume has its
                    // own college mix.
                    $code = $slots[($i * 31 + 5) % count($slots)];
                    $students = $studentsByCollege[$code];

                    if ($students->isEmpty()) {
                        continue;
                    }

                    Appointment::create([
                        'reference_no' => sprintf('APT-2026-%d', 9001 + $aptSeq++),
                        'student_id' => $students[$i % $students->count()]->id,
                        'service_type' => 'dental',
                        'scheduled_date' => sprintf('%s-%02d', $yearMonth, ($i % 17) + 2),
                        'status' => 'completed',
                        'source' => 'self',
                    ]);
                }
            }

            // Still-scheduled late-July dental — proves FR-ANL-09/11 only
            // count COMPLETED dental appointments.
            for ($i = 0; $i < self::DENTAL_SCHEDULED; $i++) {
                $code = $slots[($i * 11 + 3) % count($slots)];
                $students = $studentsByCollege[$code];

                if ($students->isEmpty()) {
                    continue;
                }

                Appointment::create([
                    'reference_no' => sprintf('APT-2026-%d', 9001 + $aptSeq++),
                    'student_id' => $students[$i % $students->count()]->id,
                    'service_type' => 'dental',
                    'scheduled_date' => sprintf('2026-07-%02d', 20 + $i),
                    'status' => 'scheduled',
                    'source' => 'self',
                ]);
            }

            $this->command->info(
                "DemoClinicVisitSeeder: {$visitSeq} medical visits + {$aptSeq} appointments seeded across Feb–Jul 2026."
            );
        });
    }

    /**
     * Purge stale pre-rework demo bands (single-month 91xx spread and the
     * Apr–Jun 9200–9399 bands) so re-seeding an old dev DB starts clean. Only
     * touches the synthetic HP-2026-91xx/92xx reference band — never real
     * visits. clearance_records restricts visit deletes, so it goes first;
     * vital_signs / screening_responses cascade with the visit.
     */
    private function purgeStaleAnalyticsBands(): void
    {
        // Everything in the synthetic 9xxx band EXCEPT the 90xx My-Records
        // visits — the old monthly bands ran 9200–9399, so a prefix list
        // would miss their tail.
        $staleIds = ClinicVisit::where('reference_no', 'like', 'HP-2026-9%')
            ->where('reference_no', 'not like', 'HP-2026-90%')
            ->pluck('id');

        if ($staleIds->isEmpty()) {
            return;
        }

        ClearanceRecord::whereIn('clinic_visit_id', $staleIds)->delete();
        ClinicVisit::whereKey($staleIds->all())->delete();

        $this->command->info("DemoClinicVisitSeeder: purged {$staleIds->count()} stale analytics-band visits.");
    }

    /**
     * One spread medical visit. Everything derives from the counters —
     * deterministic, no randomness:
     *
     *   • ~2/3 are linked to a purposeful medical appointment (the Visits
     *     by Purpose buckets), the rest are walk-ins;
     *   • a sprinkle of BP / fever / BMI flags (FR-ANL-10);
     *   • July visits are partly CAPTURED (un-encoded) — they still count
     *     (FR-ANL-07 as rewritten).
     *
     * $aptSeq is by-ref: it advances only when a linked appointment is
     * actually created.
     */
    private function createSpreadMedicalVisit(
        int $visitSeq,
        int &$aptSeq,
        User $student,
        College $college,
        User $nurse,
        string $yearMonth,
        int $dayIndex,
    ): void {
        $checkedIn = Carbon::createFromFormat('Y-m-d', sprintf('%s-%02d', $yearMonth, ($dayIndex % 17) + 1))
            ->setTime(8 + ($dayIndex % 8), ($visitSeq % 12) * 5);

        // A few July visits stay captured — the nurse hasn't encoded yet.
        $isCaptured = $yearMonth === '2026-07' && $dayIndex % 5 === 0;

        // 2/3 booked ahead (with a purpose), 1/3 walk-in (no appointment).
        $appointmentId = null;
        if ($visitSeq % 3 !== 0) {
            $purposes = [...ClearanceRecord::PURPOSES, ClearanceRecord::PURPOSE_OTHERS];
            $purpose = $purposes[$visitSeq % count($purposes)];

            $appointmentId = Appointment::create([
                'reference_no' => sprintf('APT-2026-%d', 9001 + $aptSeq++),
                'student_id' => $student->id,
                'service_type' => 'medical',
                'purpose' => $purpose,
                'purpose_other' => $purpose === ClearanceRecord::PURPOSE_OTHERS ? 'University Intramurals' : null,
                'scheduled_date' => $checkedIn->toDateString(),
                'status' => $isCaptured ? 'checked_in' : 'completed',
                'source' => 'self',
            ])->id;
        }

        $visit = ClinicVisit::create([
            'reference_no' => sprintf('HP-2026-%d', 9101 + $visitSeq),
            'student_id' => $student->id,
            'college_id' => $college->id, // capture-time snapshot (FR-STU-09, D-17)
            'appointment_id' => $appointmentId,
            'login_method' => $visitSeq % 4 === 0 ? 'email' : 'qr',
            'status' => $isCaptured ? 'captured' : 'encoded',
            'privacy_consent_at' => $checkedIn->copy()->subMinutes(5),
            'checked_in_at' => $checkedIn,
        ]);

        $this->createSpreadVitalsAndScreening($visit, $visitSeq);

        if (! $isCaptured) {
            ClearanceRecord::create([
                'clinic_visit_id' => $visit->id,
                'encoded_by' => $nurse->id,
                'result' => $visitSeq % 6 === 5 ? 'Unfit' : 'Fit',
                'physician_name' => 'REYNALDO S. ALIPIO, MD',
                'physician_license_no' => '60252',
                'encoded_at' => $checkedIn->copy()->addHours(2),
            ]);
        }
    }

    /**
     * Vitals covering every analytics bucket, still rule-consistent with
     * the capture thresholds (§7.4, D-10): the BMI cycle spans all four
     * FR-ANL-12 buckets but only the deliberate flag case reaches ≥ 30
     * (the is_bmi_flagged rule); temperature and BP stay unflagged except
     * for their own deliberate flag cases. Plus an all-clear questionnaire.
     */
    private function createSpreadVitalsAndScreening(ClinicVisit $visit, int $seq): void
    {
        // Deterministic flag sprinkle (~3–4% each, non-overlapping mostly).
        $bpFlagged = $seq % 29 === 3;
        $tempFlagged = $seq % 31 === 8;
        $bmiFlagged = $seq % 23 === 11;

        // All four BMI buckets: underweight, normal ×3, overweight ×2 …
        $bmiCycle = [17.9, 19.5, 21.2, 22.8, 24.1, 26.4, 28.3, 20.6];
        $bmi = $bmiFlagged ? 31.5 : $bmiCycle[$seq % count($bmiCycle)];

        $heightCm = 158 + ($seq % 18);
        $weightKg = round($bmi * ($heightCm / 100) ** 2, 1);

        VitalSigns::create([
            'clinic_visit_id' => $visit->id,
            'height_cm' => $heightCm,
            'weight_kg' => $weightKg,
            'bmi' => $bmi,
            'temperature_c' => $tempFlagged ? 38.1 : 36.2 + ($seq % 6) / 10, // ≤ 36.7 unless fever
            'heart_rate_bpm' => 66 + ($seq % 24),
            'bp_systolic' => $bpFlagged ? 150 : 105 + ($seq % 20),           // ≤ 124 unless flagged
            'bp_diastolic' => $bpFlagged ? 95 : 65 + ($seq % 15),
            'entry_method' => $seq % 2 === 0 ? 'manual' : 'sensor',
            'is_bmi_flagged' => $bmiFlagged,
            'is_temp_flagged' => $tempFlagged,
            'is_bp_flagged' => $bpFlagged,
        ]);

        ScreeningResponse::create([
            'clinic_visit_id' => $visit->id,
            'vision' => false,
            'hearing' => false,
            'nose' => false,
            'skin' => false,
            'respiratory' => false,
            'heart' => false,
            'digestive' => false,
            'bones' => false,
            'nervous' => false,
            'is_pregnant' => false,
        ]);
    }
}
