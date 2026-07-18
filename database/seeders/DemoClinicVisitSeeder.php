<?php

namespace Database\Seeders;

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
 *       - HP-2026-9001  Juan Santos     Fit, no case category
 *       - HP-2026-9002  Juan Santos     Unfit, Cardiovascular System, BP flagged
 *       - HP-2026-9003  Maria Reyes     Fit, Alimentary System category
 *   • 3 captured visits (no clearance record) → shows Pending, gated View
 *       - HP-2026-9004  Juan Santos     normal vitals
 *       - HP-2026-9005  Maria Reyes     temperature flagged (verifies flag display)
 *       - HP-2026-9006  Maria Reyes     normal vitals
 *
 * Plus an ANALYTICS SPREAD (HP-2026-9101…) — encoded visits with case
 * categories across all 12 colleges, all 8 medical systems, and both sexes,
 * so the Director analytics charts (FR-ANL-02/03/04/08) have meaningful data
 * during development. Fully deterministic — no randomness, so re-seeding a
 * fresh DB always produces the same chart.
 *
 * Plus MONTHLY BANDS (HP-2026-9200…) — encoded single-category visits placed
 * inside Apr, May and Jun 2026 (40 / 60 / 100 cases) so the Director's month
 * picker has three clearly-distinct months to switch between.
 *
 * Reference band HP-2026-9xxx is reserved for synthetic data and will not
 * collide with real sequences (which start from HP-2026-0001).
 *
 * DELETE this seeder (and its call in DatabaseSeeder) once the real kiosk
 * starts writing clinic_visits rows directly.
 */
class DemoClinicVisitSeeder extends Seeder
{
    /**
     * Encoded-visit volume per college for the analytics spread. Deliberately
     * uneven so the "sorted by volume" ordering in FR-ANL-02 is visible.
     * Keys must match colleges.code; values sum to 88.
     */
    private const ANALYTICS_VOLUME = [
        'CCS' => 14, 'COE' => 12, 'CEA' => 11, 'CBS' => 9,
        'CAS' => 8, 'CSSP' => 7, 'CHTM' => 6, 'CIT' => 6,
        'LAW' => 5, 'GS' => 4, 'SHS' => 3, 'LHS' => 3,
    ];

    /**
     * Per-month case volume for Apr–Jun 2026, so the Director's month picker
     * has clearly-distinct months to switch between (each visit here carries
     * exactly one category → cases == visits). Deliberately rising toward
     * June, whose 100 total is a clean, recognizable demo figure. Keys must
     * match colleges.code.
     */
    private const MONTHLY_ANALYTICS_BANDS = [
        '2026-04' => [ // 40 cases
            'CCS' => 7, 'COE' => 5, 'CEA' => 5, 'CBS' => 4, 'CAS' => 4, 'CSSP' => 3,
            'CHTM' => 3, 'CIT' => 2, 'LAW' => 2, 'GS' => 2, 'SHS' => 2, 'LHS' => 1,
        ],
        '2026-05' => [ // 60 cases
            'CCS' => 10, 'COE' => 8, 'CEA' => 7, 'CBS' => 6, 'CAS' => 6, 'CSSP' => 5,
            'CHTM' => 4, 'CIT' => 4, 'LAW' => 3, 'GS' => 3, 'SHS' => 2, 'LHS' => 2,
        ],
        '2026-06' => [ // 100 cases
            'CCS' => 18, 'COE' => 14, 'CEA' => 12, 'CBS' => 11, 'CAS' => 10, 'CSSP' => 8,
            'CHTM' => 7, 'CIT' => 6, 'LAW' => 5, 'GS' => 4, 'SHS' => 3, 'LHS' => 2,
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $this->seedRecordsPageVisits();
        $this->seedAnalyticsSpread();
        $this->seedMonthlyBands();
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

        // ── Encoded visit 1 — Juan Santos, Fit, no case category ──────────────
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

        // ── Encoded visit 2 — Juan Santos, Unfit, Cardiovascular, BP flagged ──
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
        // Multi-category case (D-23): the questionnaire flagged both
        // respiratory and heart — the encoded case spans both systems.
        ClearanceRecord::create([
            'clinic_visit_id' => $v2->id,
            'encoded_by' => $nurse->id,
            'result' => 'Unfit',
            'physician_name' => 'REYNALDO S. ALIPIO, MD',
            'physician_license_no' => '60252',
            'encoded_at' => Carbon::parse('2026-03-05 10:45:00'),
        ])->caseCategories()->createMany([
            ['case_category' => 'Cardiovascular System'],
            ['case_category' => 'Respiratory System'],
        ]);

        // ── Encoded visit 3 — Maria Reyes, Fit, Alimentary System category ────
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
        ])->caseCategories()->create(['case_category' => 'Alimentary System']);

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
     * Analytics spread (HP-2026-9101…): encoded visits + case categories
     * across all 12 colleges so FR-ANL-02/03/04/08 charts have real shape.
     */
    private function seedAnalyticsSpread(): void
    {
        // Own idempotence guard so this band can still be added to dev DBs
        // that were seeded before it existed.
        if (ClinicVisit::where('reference_no', 'like', 'HP-2026-91%')->exists()) {
            $this->command->info('DemoClinicVisitSeeder: analytics spread already exists, skipping.');

            return;
        }

        $nurse = User::where('email', 'nurse@healthpass.test')->firstOrFail();
        $colleges = College::all()->keyBy('code');

        $seq = 0;
        $collegeIndex = 0;

        // All-or-nothing: a mid-loop failure must not leave a partial band
        // behind, or the guard above would skip the re-run forever.
        DB::transaction(function () use ($colleges, $nurse, &$seq, &$collegeIndex): void {
            foreach (self::ANALYTICS_VOLUME as $code => $visitCount) {
                $college = $colleges[$code];

                // The college's students take turns, so both sexes end up with
                // encoded visits in every unit (feeds the By-Sex donut, FR-ANL-04).
                $students = User::where('role', 'student')
                    ->whereHas('studentProfile', fn ($q) => $q->where('college_id', $college->id))
                    ->orderBy('id')
                    ->get();

                for ($i = 0; $i < $visitCount; $i++) {
                    $this->createEncodedAnalyticsVisit(
                        seq: $seq++,
                        collegeSeq: $i,
                        collegeIndex: $collegeIndex,
                        student: $students[$i % $students->count()],
                        college: $college,
                        nurse: $nurse,
                    );
                }

                $collegeIndex++;
            }
        });

        $last = 9101 + $seq - 1;
        $this->command->info("DemoClinicVisitSeeder: {$seq} encoded analytics visits created (HP-2026-9101 – HP-2026-{$last}).");
    }

    /**
     * One encoded visit with unflagged vitals and 0–2 case categories.
     * Everything derives from the counters — deterministic, no randomness.
     */
    private function createEncodedAnalyticsVisit(
        int $seq,
        int $collegeSeq,
        int $collegeIndex,
        User $student,
        College $college,
        User $nurse,
    ): void {
        // Visits spread over AY 2025–2026 (Sep 2025 → May 2026).
        $checkedIn = Carbon::parse('2025-09-01 09:00:00')
            ->addDays($seq * 3)
            ->addMinutes(($seq % 8) * 7);

        $visit = ClinicVisit::create([
            'reference_no' => sprintf('HP-2026-%d', 9101 + $seq),
            'student_id' => $student->id,
            'college_id' => $college->id, // capture-time snapshot (FR-STU-09, D-17)
            'login_method' => 'qr',
            'status' => 'encoded',
            'privacy_consent_at' => $checkedIn->copy()->subMinutes(5),
            'checked_in_at' => $checkedIn,
        ]);

        $this->createNormalVitalsAndScreening($visit, $seq);

        $record = ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $nurse->id,
            'result' => $seq % 6 === 5 ? 'Unfit' : 'Fit',
            'physician_name' => 'REYNALDO S. ALIPIO, MD',
            'physician_license_no' => '60252',
            'encoded_at' => $checkedIn->copy()->addHours(2),
        ]);

        // Case categories (D-23): every 9th visit per college is screened-only
        // (no case — the donut intentionally counts more people than cases,
        // FR-ANL AC). The college index offsets the 8-system cycle so each
        // college gets a different mix; every 5th visit is multi-category.
        if ($collegeSeq % 9 !== 8) {
            $systems = ClearanceRecord::CASE_CATEGORIES;
            $record->caseCategories()->create([
                'case_category' => $systems[($collegeIndex + $collegeSeq) % count($systems)],
            ]);

            if ($collegeSeq % 5 === 4) {
                $record->caseCategories()->create([
                    'case_category' => $systems[($collegeIndex + $collegeSeq + 3) % count($systems)],
                ]);
            }
        }
    }

    /**
     * Monthly bands (HP-2026-92xx): encoded, single-category visits placed
     * squarely inside Apr, May and Jun 2026, so the Director's month picker
     * shows three clearly-different months (40 / 60 / 100 cases). Each
     * visit's checked_in_at — the date the month scope keys on — lands in
     * its band's month.
     */
    private function seedMonthlyBands(): void
    {
        // Own idempotence guard so this band can be added to dev DBs seeded
        // before it existed, without disturbing the other bands.
        if (ClinicVisit::where('reference_no', 'like', 'HP-2026-92%')->exists()) {
            $this->command->info('DemoClinicVisitSeeder: monthly analytics bands already exist, skipping.');

            return;
        }

        $nurse = User::where('email', 'nurse@healthpass.test')->firstOrFail();
        $colleges = College::all()->keyBy('code');
        $systems = ClearanceRecord::CASE_CATEGORIES;

        $seq = 0;
        $systemIndex = 0;

        // All-or-nothing: a mid-loop failure must not leave a partial band
        // behind, or the guard above would skip the re-run forever.
        DB::transaction(function () use ($colleges, $nurse, $systems, &$seq, &$systemIndex): void {
            foreach (self::MONTHLY_ANALYTICS_BANDS as $yearMonth => $volumeByCollege) {
                $monthStart = Carbon::createFromFormat('Y-m-d', $yearMonth.'-01')->startOfMonth();

                foreach ($volumeByCollege as $code => $visitCount) {
                    $college = $colleges[$code];

                    // Both sexes take turns per college → feeds the donut.
                    $students = User::where('role', 'student')
                        ->whereHas('studentProfile', fn ($q) => $q->where('college_id', $college->id))
                        ->orderBy('id')
                        ->get();

                    if ($students->isEmpty()) {
                        continue; // no students seeded for this unit — skip
                    }

                    for ($i = 0; $i < $visitCount; $i++) {
                        // Spread across the first ~4 weeks; always in-month.
                        $checkedIn = $monthStart->copy()
                            ->addDays($i % 27)
                            ->setTime(9, 0)
                            ->addMinutes(($seq % 8) * 7);

                        $this->createMonthlyBandVisit(
                            seq: $seq,
                            student: $students[$i % $students->count()],
                            college: $college,
                            nurse: $nurse,
                            checkedIn: $checkedIn,
                            category: $systems[$systemIndex % count($systems)],
                        );

                        $seq++;
                        $systemIndex++;
                    }
                }
            }
        });

        $this->command->info("DemoClinicVisitSeeder: {$seq} monthly analytics visits created for Apr–Jun 2026 (HP-2026-92xx).");
    }

    /**
     * One encoded, single-category monthly-band visit with unflagged vitals.
     */
    private function createMonthlyBandVisit(
        int $seq,
        User $student,
        College $college,
        User $nurse,
        Carbon $checkedIn,
        string $category,
    ): void {
        $visit = ClinicVisit::create([
            'reference_no' => sprintf('HP-2026-%d', 9200 + $seq),
            'student_id' => $student->id,
            'college_id' => $college->id, // capture-time snapshot (FR-STU-09, D-17)
            'login_method' => 'qr',
            'status' => 'encoded',
            'privacy_consent_at' => $checkedIn->copy()->subMinutes(5),
            'checked_in_at' => $checkedIn,
        ]);

        $this->createNormalVitalsAndScreening($visit, $seq);

        ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $nurse->id,
            'result' => $seq % 6 === 5 ? 'Unfit' : 'Fit',
            'physician_name' => 'REYNALDO S. ALIPIO, MD',
            'physician_license_no' => '60252',
            'encoded_at' => $checkedIn->copy()->addHours(2),
        ])->caseCategories()->create(['case_category' => $category]);
    }

    /**
     * Plausible in-range vitals (nothing flagged — Flagged Anomalies is
     * already demoed by HP-2026-9002/9005) and an all-clear questionnaire.
     */
    private function createNormalVitalsAndScreening(ClinicVisit $visit, int $seq): void
    {
        $heightCm = 158 + ($seq % 18);
        $bmi = 19.0 + ($seq % 6);                 // 19–24 → never BMI-flagged
        $weightKg = round($bmi * ($heightCm / 100) ** 2, 1);

        VitalSigns::create([
            'clinic_visit_id' => $visit->id,
            'height_cm' => $heightCm,
            'weight_kg' => $weightKg,
            'bmi' => $bmi,
            'temperature_c' => 36.2 + ($seq % 6) / 10, // ≤ 36.7 → no fever flag
            'heart_rate_bpm' => 66 + ($seq % 24),
            'bp_systolic' => 105 + ($seq % 20),        // ≤ 124/79 → no BP flag
            'bp_diastolic' => 65 + ($seq % 15),
            'entry_method' => $seq % 2 === 0 ? 'manual' : 'sensor',
            'is_bmi_flagged' => false,
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
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
