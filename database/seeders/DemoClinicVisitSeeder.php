<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\MedicalAssessment;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;
use App\Services\ClinicScheduleService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
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
 * (Feb–Jul 2026), all 11 colleges and both sexes, feeding every card of
 * the rescoped Director analytics (FR-ANL-09..13, D-32/D-33):
 *
 *   • every visit linked to an APT-2026-9xxx appointment on an APPROVED
 *     college batch (BR-2026-707…) — since D-61 that is the only way a
 *     visit happens: no self-bookings, no walk-ins. A student holds one seat
 *     per batch, so a student's second visit in a month goes on their
 *     college's second batch of that month, and so on. The batches cycle
 *     through both D-62 forms and their reasons, and the batch reason is
 *     the purpose Visits by Purpose counts;
 *   • a deterministic sprinkle of BP / fever / BMI flags (FR-ANL-10);
 *   • BMI values across all four FR-ANL-12 buckets;
 *   • a few CAPTURED (un-encoded) July visits — these still count
 *     (FR-ANL-07 as rewritten);
 *   • every visit carries the student's PROGRAM snapshot (D-43),
 *     so the per-program reporting built in later prompts has data.
 *
 * Fully deterministic — no randomness, so re-seeding a fresh DB always
 * produces the same charts. Reference bands HP-2026-9xxx / APT-2026-9xxx /
 * APT-2026-84xx / BR-2026-7xx are reserved for synthetic data and will not
 * collide with real sequences (the next real number is always one past the
 * highest seeded one). The six My-Records visits sit on their own one-student
 * CCS batches, BR-2026-701…706 with APT-2026-8401…8406 — deliberately OUTSIDE
 * APT-2026-9xxx, whose existence makes the analytics spread skip itself.
 *
 * DELETE this seeder (and its call in DatabaseSeeder) once the real kiosk
 * starts writing clinic_visits rows directly.
 */
class DemoClinicVisitSeeder extends Seeder
{
    /**
     * Relative visit volume per college. Deliberately uneven so the
     * "sorted by volume" ordering in FR-ANL-09 is visible. Keys must match
     * colleges.code; values sum to 85 (the round-robin slot count).
     *
     * Was 88 across 12 units — Senior High School's 3 went with the unit in
     * D-43. The strides that walk this list (7, 11 and 31) stay coprime with
     * 85, so every college is still hit.
     */
    private const COLLEGE_WEIGHTS = [
        'CCS' => 14, 'COE' => 12, 'CEA' => 11, 'CBS' => 9,
        'CAS' => 8, 'CSSP' => 7, 'CHTM' => 6, 'CIT' => 6,
        'LAW' => 5, 'GS' => 4, 'LHS' => 3,
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

    /** BR-2026-701…706 are the My-Records batches; the analytics spread starts here. */
    private const FIRST_SPREAD_BATCH = 707;

    /**
     * [form type, reason] pairs the demo batches cycle through — both D-62
     * forms, so the Live Queue badge and Visits by Purpose show a mix
     * ('others' needs detail text, so it is left out).
     */
    private const DEMO_REASONS = [
        ['assessment', 'ojt'], ['clearance', 'fieldtrip'], ['assessment', 'rle'],
        ['clearance', 'outbound'], ['assessment', 'sports'], ['assessment', 'off_campus'],
    ];

    /** The Director whose approval every demo batch carries. */
    private User $director;

    /** Each college's administrator, keyed by college id — the batch's requester. @var Collection<int, User> */
    private Collection $admins;

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $this->director = User::where('role', 'director')->orderBy('id')->firstOrFail();
        // keyBy() keeps the LAST row per key, so descending id order leaves the
        // lowest-id (seeded) admin of each college.
        $this->admins = User::where('role', 'college_admin')->orderByDesc('id')->get()->keyBy('managed_college_id');

        $this->seedRecordsPageVisits();
        $this->seedAnalyticsSpread();
    }

    /**
     * D-65 — what the clinic confirmed at encode: the kiosk's reading with the
     * respiratory rate it measured, and any value it corrected. Writes the
     * respiratory rate onto the vitals row and returns the `encoded_vitals`
     * attribute, exactly the pair Save & Close writes.
     *
     * @param  array<string, int|float>  $corrections  clinic-corrected vitals
     * @return array{encoded_vitals: array<string, int|float>}
     */
    private function encodedVitals(ClinicVisit $visit, int $respiratoryRate, array $corrections = []): array
    {
        $vs = $visit->vitalSigns()->firstOrFail();
        // D-66: the flag comes from the SAME helper the encode controller uses,
        // so seeded demo rows can never disagree with a real encode.
        $vs->update([
            'respiratory_rate' => $respiratoryRate,
            'is_rr_flagged' => VitalSigns::isRespiratoryRateFlagged($respiratoryRate),
        ]);

        $confirmed = [
            'height_cm' => (float) $vs->height_cm,
            'weight_kg' => (float) $vs->weight_kg,
            'temperature_c' => (float) $vs->temperature_c,
            'bp_systolic' => $vs->bp_systolic,
            'bp_diastolic' => $vs->bp_diastolic,
            'heart_rate_bpm' => $vs->heart_rate_bpm,
            'respiratory_rate' => $respiratoryRate,
            ...$corrections,
        ];

        return ['encoded_vitals' => [
            ...$confirmed,
            // Always recomputed, never copied (FR-KSK-09) — a corrected height
            // or weight must move the BMI with it.
            'bmi' => VitalSigns::computeBmi((float) $confirmed['height_cm'], (float) $confirmed['weight_kg']),
        ]];
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
        // D-64: visit 2 is encoded by the physician, so My Records and the
        // print show both physician blocks — printed name vs. blank line.
        $physician = User::where('email', 'physician@healthpass.test')->firstOrFail();

        // Both demo students are CCS. clinic_visits.college_id became NOT NULL
        // with the D-17 snapshot migration (2026_06_30), so every seeded visit
        // must freeze it explicitly — without this, a fresh --seed fails.
        $ccs = College::where('code', 'CCS')->firstOrFail();

        // The program snapshot (D-43) — copied off each student's profile, the
        // way SubmitKioskVisit copies it at a real kiosk submit.
        $juanCourse = $juan->studentProfile->course;
        $mariaCourse = $maria->studentProfile->course;

        // ── Encoded visit 1 — Juan Santos, Fit ───────────────────────────────
        $v1 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9001',
            'student_id' => $juan->id,
            'college_id' => $ccs->id,
            'course' => $juanCourse,
            'appointment_id' => $this->recordsPageSeat(1, $juan, $ccs, '2026-01-10 09:02:00', 'completed', ['clearance', 'fieldtrip']),
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
            'is_hr_flagged' => VitalSigns::isHeartRateFlagged(74),
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v1->id,
            ...$this->screening(),
            ...$this->socialHistory($v1->formType()), // D-68: clearance -> NULLs
            'is_pregnant' => false,
        ]);
        ClearanceRecord::create([
            'clinic_visit_id' => $v1->id,
            'encoded_by' => $nurse->id,
            ...$v1->batchPurpose(),   // D-62: as the real encode copies it
            ...$this->encodedVitals($v1, 16), // D-65: nothing corrected
            'result' => 'Fit',
            ...ClearanceRecord::physicianBlockFor($nurse), // D-64: nurse → blank block
            'encoded_at' => Carbon::parse('2026-01-10 10:30:00'),
        ]);

        // ── Encoded visit 2 — Juan Santos, Unfit, BP flagged ─────────────────
        $v2 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9002',
            'student_id' => $juan->id,
            'college_id' => $ccs->id,
            'course' => $juanCourse,
            'appointment_id' => $this->recordsPageSeat(2, $juan, $ccs, '2026-03-05 09:15:00', 'completed', ['assessment', 'ojt']),
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
            'is_hr_flagged' => VitalSigns::isHeartRateFlagged(88),
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v2->id,
            // Two YES answers — one with a typed detail, one without.
            ...$this->screening([
                'chest_lungs' => 'Dry cough for about a week',
                'heart' => null,
            ]),
            ...$this->socialHistory($v2->formType(), 1), // D-68: assessment
            'is_pregnant' => false,
        ]);
        $r2 = ClearanceRecord::create([
            'clinic_visit_id' => $v2->id,
            'encoded_by' => $physician->id,
            ...$v2->batchPurpose(),   // D-62: as the real encode copies it
            // D-65 demo: the clinic re-took the BP and got a calmer reading, so
            // the printed form carries 138/88 while the kiosk's 145/93 keeps
            // its flag in the analytics. The encode page shows "Kiosk: 145/93".
            // The respiratory rate is outside 12-20, for prompt 07 to flag.
            ...$this->encodedVitals($v2, 24, ['bp_systolic' => 138, 'bp_diastolic' => 88]),
            'result' => 'Unfit',
            ...ClearanceRecord::physicianBlockFor($physician), // D-64: name + license print
            'encoded_at' => Carbon::parse('2026-03-05 10:45:00'),
        ]);
        $this->medicalAssessment($r2, $v2->formType(), 1); // D-69

        // ── Encoded visit 3 — Maria Reyes, Fit ───────────────────────────────
        $v3 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9003',
            'student_id' => $maria->id,
            'college_id' => $ccs->id,
            'course' => $mariaCourse,
            'appointment_id' => $this->recordsPageSeat(3, $maria, $ccs, '2026-02-03 11:00:00', 'completed', ['assessment', 'sports']),
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
            'is_hr_flagged' => VitalSigns::isHeartRateFlagged(79),
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v3->id,
            ...$this->screening(['abdomen' => 'Stomach pain after meals']),
            ...$this->socialHistory($v3->formType(), 2), // D-68: assessment
            'is_pregnant' => false,
        ]);
        $r3 = ClearanceRecord::create([
            'clinic_visit_id' => $v3->id,
            'encoded_by' => $nurse->id,
            ...$v3->batchPurpose(),   // D-62: as the real encode copies it
            ...$this->encodedVitals($v3, 14), // D-65: nothing corrected
            'result' => 'Fit',
            ...ClearanceRecord::physicianBlockFor($nurse), // D-64: nurse → blank block
            'encoded_at' => Carbon::parse('2026-02-03 12:15:00'),
        ]);
        $this->medicalAssessment($r3, $v3->formType(), 2); // D-69

        // ── Captured visit 4 — Juan Santos, Pending, normal vitals ────────────
        $v4 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9004',
            'student_id' => $juan->id,
            'college_id' => $ccs->id,
            'course' => $juanCourse,
            'appointment_id' => $this->recordsPageSeat(4, $juan, $ccs, '2026-06-15 08:45:00', 'scheduled', ['assessment', 'sports']),
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
            'is_hr_flagged' => VitalSigns::isHeartRateFlagged(76),
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v4->id,
            ...$this->screening(),
            ...$this->socialHistory($v4->formType(), 3), // D-68: assessment
            'is_pregnant' => false,
        ]);

        // ── Captured visit 5 — Maria Reyes, Pending, temperature + HR flagged ─
        $v5 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9005',
            'student_id' => $maria->id,
            'college_id' => $ccs->id,
            'course' => $mariaCourse,
            'appointment_id' => $this->recordsPageSeat(5, $maria, $ccs, '2026-05-10 09:00:00', 'scheduled', ['clearance', 'fieldtrip']),
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
            'heart_rate_bpm' => 112,  // D-66: > 100 → flagged, as a fever often runs
            'bp_systolic' => 125,
            'bp_diastolic' => 82,
            'entry_method' => 'manual',
            'is_bmi_flagged' => false,
            'is_temp_flagged' => true,
            'is_bp_flagged' => false,
            'is_hr_flagged' => VitalSigns::isHeartRateFlagged(112),
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v5->id,
            // Captured, not yet encoded: opening it on the encode screen shows
            // both details pre-filled into Nurse Notes (D-56).
            ...$this->screening([
                'throat' => 'Sore throat since Monday',
                'chest_lungs' => 'Cough at night',
            ]),
            ...$this->socialHistory($v5->formType()), // D-68: clearance -> NULLs
            'is_pregnant' => false,
        ]);

        // ── Captured visit 6 — Maria Reyes, Pending, normal vitals ───────────
        $v6 = ClinicVisit::create([
            'reference_no' => 'HP-2026-9006',
            'student_id' => $maria->id,
            'college_id' => $ccs->id,
            'course' => $mariaCourse,
            'appointment_id' => $this->recordsPageSeat(6, $maria, $ccs, '2026-06-20 08:30:00', 'scheduled', ['clearance', 'outbound']),
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
            'is_hr_flagged' => VitalSigns::isHeartRateFlagged(77),
        ]);
        ScreeningResponse::create([
            'clinic_visit_id' => $v6->id,
            ...$this->screening(),
            ...$this->socialHistory($v6->formType()), // D-68: clearance -> NULLs
            'is_pregnant' => false,
        ]);

        $this->command->info('DemoClinicVisitSeeder: 6 demo visits created (HP-2026-9001 – HP-2026-9006).');
    }

    /**
     * The multi-month analytics spread (HP-2026-9101… / APT-2026-9001… /
     * BR-2026-707…):
     * six months of clinic visits feeding every card of the rescoped
     * analytics. Replaces the pre-D-32 single-month
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
        $physician = User::where('email', 'physician@healthpass.test')->firstOrFail();
        $colleges = College::all()->keyBy('code');

        // One round-robin slot per weight unit — walking this list with a
        // coprime stride hits every college in rough proportion, varied
        // order. Students per college take turns, so both sexes appear.
        $slots = [];
        foreach (self::COLLEGE_WEIGHTS as $code => $weight) {
            $slots = array_merge($slots, array_fill(0, $weight, $code));
        }

        // studentProfile is eager-loaded because every spread visit copies the
        // student's program onto its D-43 snapshot — without `with()` that is
        // one extra query per visit, ~250 of them.
        $studentsByCollege = $colleges->map(
            fn (College $college) => User::where('role', 'student')
                ->whereHas('studentProfile', fn ($q) => $q->where('college_id', $college->id))
                ->with('studentProfile:id,user_id,course')
                ->orderBy('id')
                ->get()
        );

        // All-or-nothing: a mid-loop failure must not leave a partial band
        // behind, or the marker above would skip the re-run forever.
        DB::transaction(function () use ($colleges, $nurse, $physician, $slots, $studentsByCollege): void {
            $this->purgeStaleAnalyticsBands();

            $visitSeq = 0;
            $aptSeq = 0;

            // ── Medical visits, month by month ───────────────────────────
            // D-61: every visit sits on an approved college batch, and a
            // student holds ONE seat per batch — so a student's second visit
            // in a month goes on their college's second batch that month (its
            // "round"), and so on. Plan the month first, so each batch knows
            // its roster size when it is created.
            $collegeCodes = array_keys(self::COLLEGE_WEIGHTS);
            $monthIndex = 0;
            $batchSeq = self::FIRST_SPREAD_BATCH;
            foreach (self::MEDICAL_VOLUME as $yearMonth => $visitCount) {
                $monthIndex++;
                $plan = [];
                $visitsThisMonth = []; // student id => visits planned so far

                for ($i = 0; $i < $visitCount; $i++) {
                    $code = $slots[($i * 7 + $monthIndex * 13) % count($slots)];
                    $students = $studentsByCollege[$code];

                    if ($students->isEmpty()) {
                        continue; // no students seeded for this unit — skip
                    }

                    $student = $students[$i % $students->count()];
                    $round = $visitsThisMonth[$student->id] ?? 0;
                    $visitsThisMonth[$student->id] = $round + 1;
                    $plan[] = ['i' => $i, 'code' => $code, 'student' => $student, 'round' => $round, 'batch' => "{$code}|{$round}"];
                }

                $rosterSizes = array_count_values(array_column($plan, 'batch'));
                $batches = [];

                foreach ($plan as $visit) {
                    $collegeIndex = array_search($visit['code'], $collegeCodes, true);

                    $batches[$visit['batch']] ??= $this->approvedBatch(
                        reference: sprintf('BR-2026-%03d', $batchSeq),
                        college: $colleges[$visit['code']],
                        // Days 21–28: clear of every My-Records visit date, and a
                        // different day per round, so a student's batches never overlap.
                        date: Carbon::parse(sprintf('%s-%02d', $yearMonth, 21 + (($visit['round'] * 3 + $monthIndex + $collegeIndex) % 8))),
                        slot: sprintf('%02d:00:00', 8 + (($collegeIndex + $visit['round']) % 8)),
                        studentCount: $rosterSizes[$visit['batch']],
                        reason: self::DEMO_REASONS[$batchSeq++ % count(self::DEMO_REASONS)],
                    );

                    $this->createSpreadMedicalVisit(
                        visitSeq: $visitSeq++,
                        aptSeq: $aptSeq,
                        batch: $batches[$visit['batch']],
                        student: $visit['student'],
                        college: $colleges[$visit['code']],
                        // D-64: every third record is the physician's own encode.
                        encoder: $visitSeq % 3 === 1 ? $physician : $nurse,
                        // A few July visits stay captured — the nurse hasn't encoded yet.
                        isCaptured: $yearMonth === '2026-07' && $visit['i'] % 5 === 0,
                    );
                }
            }

            $batchCount = $batchSeq - self::FIRST_SPREAD_BATCH;
            $this->command->info(
                "DemoClinicVisitSeeder: {$visitSeq} clinic visits + {$aptSeq} batch appointments on {$batchCount} approved batches seeded across Feb–Jul 2026."
            );
        });
    }

    /**
     * Purge stale pre-rework demo bands (single-month 91xx spread and the
     * Apr–Jun 9200–9399 bands) so re-seeding an old dev DB starts clean. Only
     * touches the synthetic HP-2026-91xx/92xx reference band — never real
     * visits. medical_assessments restricts clearance_record deletes and
     * clearance_records restricts visit deletes, so they go innermost-first;
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

        // D-69: medical_assessments restricts clearance_record deletes the
        // same way clearance_records restricts visit deletes — so the chain
        // unwinds innermost-first.
        $staleRecordIds = ClearanceRecord::whereIn('clinic_visit_id', $staleIds)->pluck('id');
        MedicalAssessment::whereIn('clearance_record_id', $staleRecordIds)->delete();

        ClearanceRecord::whereKey($staleRecordIds->all())->delete();
        ClinicVisit::whereKey($staleIds->all())->delete();

        $this->command->info("DemoClinicVisitSeeder: purged {$staleIds->count()} stale analytics-band visits.");
    }

    /**
     * One spread medical visit. Everything derives from the counters —
     * deterministic, no randomness:
     *
     *   • it sits on $batch — a seat (appointment + roster row) is created
     *     for the student, and the visit is checked in during the batch's
     *     hour on its clinic date (D-61: no self-bookings, no walk-ins);
     *   • a sprinkle of BP / fever / BMI flags (FR-ANL-10);
     *   • July visits are partly CAPTURED (un-encoded) — they still count
     *     (FR-ANL-07 as rewritten).
     *
     * $aptSeq is by-ref: it advances with every appointment created.
     */
    private function createSpreadMedicalVisit(
        int $visitSeq,
        int &$aptSeq,
        BatchRequest $batch,
        User $student,
        College $college,
        User $encoder,
        bool $isCaptured,
    ): void {
        $checkedIn = $batch->scheduled_date->copy()
            ->setTimeFromTimeString($batch->requested_time)
            ->addMinutes(($visitSeq % 12) * 5);

        // The kiosk links the visit but leaves the appointment `scheduled`;
        // only the nurse's encode completes it (Nurse\EncodeController).
        $appointment = $this->seat(
            $batch,
            $student,
            sprintf('APT-2026-%d', 9001 + $aptSeq++),
            $isCaptured ? 'scheduled' : 'completed',
        );

        $visit = ClinicVisit::create([
            'reference_no' => sprintf('HP-2026-%d', 9101 + $visitSeq),
            'student_id' => $student->id,
            'college_id' => $college->id, // capture-time snapshot (FR-STU-09, D-17)
            'course' => $student->studentProfile?->course, // program snapshot (D-43)
            'appointment_id' => $appointment->id,
            'login_method' => $visitSeq % 4 === 0 ? 'email' : 'qr',
            'status' => $isCaptured ? 'captured' : 'encoded',
            'privacy_consent_at' => $checkedIn->copy()->subMinutes(5),
            'checked_in_at' => $checkedIn,
        ]);

        // D-68: the batch's form decides whether the kiosk asked for a
        // Personal / Social History at all.
        $this->createSpreadVitalsAndScreening($visit, $visitSeq, $batch->form_type);

        if (! $isCaptured) {
            $record = ClearanceRecord::create([
                'clinic_visit_id' => $visit->id,
                'encoded_by' => $encoder->id,
                // D-65: 12-20 breaths/min, with roughly one in nineteen above
                // it for prompt 07's flag to find.
                ...$this->encodedVitals($visit, $visitSeq % 19 === 5 ? 26 : 12 + ($visitSeq % 9)),
                'result' => $visitSeq % 6 === 5 ? 'Unfit' : 'Fit',
                ...$visit->batchPurpose(),   // D-62: as the real encode copies it
                ...ClearanceRecord::physicianBlockFor($encoder), // D-64
                'encoded_at' => $checkedIn->copy()->addHours(2),
            ]);

            // D-69: the Assessment form's own sections; a Clearance gets none.
            $this->medicalAssessment($record, $batch->form_type, $visitSeq);
        }
    }

    // ── Batches (D-61: every appointment comes from one) ─────────────────────

    /**
     * One of the six My-Records visits' own batch: a one-student CCS batch,
     * BR-2026-70{n}, with the appointment APT-2026-840{n}. Returns the
     * appointment id for the visit to link.
     */
    private function recordsPageSeat(
        int $n,
        User $student,
        College $college,
        string $checkedIn,
        string $appointmentStatus,
        array $reason,   // [form type, reason] (D-62)
    ): int {
        $checkedInAt = Carbon::parse($checkedIn);

        $batch = $this->approvedBatch(
            reference: 'BR-2026-70'.$n,
            college: $college,
            date: $checkedInAt->copy()->startOfDay(),
            slot: $checkedInAt->format('H').':00:00',
            studentCount: 1,
            reason: $reason,
        );

        return $this->seat($batch, $student, 'APT-2026-840'.$n, $appointmentStatus)->id;
    }

    /**
     * A batch the way a Director's approval leaves it (FR-DIRA-02, BR-08):
     * requested by the college's admin five days before the clinic date,
     * approved two days before, `scheduled_date` = `requested_date` (D-36).
     *
     * The timestamps are set by hand so the Activity Log and Batch Tracking
     * show these as history rather than as today's submissions. Eloquent
     * keeps a created_at/updated_at that was set explicitly instead of
     * stamping "now".
     */
    private function approvedBatch(
        string $reference,
        College $college,
        Carbon $date,
        string $slot,
        int $studentCount,
        array $reason,   // [form type, reason] (D-62)
    ): BatchRequest {
        $submittedAt = $date->copy()->subDays(5)->setTime(10, 0);
        $approvedAt = $date->copy()->subDays(2)->setTime(14, 0);

        $batch = new BatchRequest([
            'reference_no' => $reference,
            'college_id' => $college->id,
            'requested_by' => $this->admins[$college->id]->id,
            'form_type' => $reason[0],
            'reason' => $reason[1],
            'service_type' => 'medical',
            'requested_date' => $date->toDateString(),
            'requested_time' => $slot,
            'requested_blocks' => app(ClinicScheduleService::class)->blocksFor($studentCount),
            'scheduled_date' => $date->toDateString(),
            'status' => 'approved',
            'reviewed_by' => $this->director->id,
            'reviewed_at' => $approvedAt,
        ]);
        $batch->created_at = $submittedAt;
        $batch->updated_at = $approvedAt;
        $batch->save();

        return $batch;
    }

    /**
     * One student's seat on an approved batch: the appointment the approval
     * generated plus the roster row that points at it (BR-08), created at the
     * moment of approval.
     */
    private function seat(BatchRequest $batch, User $student, string $reference, string $status): Appointment
    {
        $appointment = new Appointment([
            'reference_no' => $reference,
            'student_id' => $student->id,
            'service_type' => 'medical',
            'scheduled_date' => $batch->scheduled_date->toDateString(),
            'scheduled_time' => $batch->requested_time,
            'status' => $status,
            'source' => 'batch',
            'batch_request_id' => $batch->id,
            'created_by' => $this->director->id,
        ]);
        $appointment->created_at = $batch->reviewed_at;
        $appointment->updated_at = $batch->reviewed_at;
        $appointment->save();

        BatchRequestStudent::create([
            'batch_request_id' => $batch->id,
            'student_id' => $student->id,
            'appointment_id' => $appointment->id,
        ]);

        return $appointment;
    }

    /**
     * Vitals covering every analytics bucket, still rule-consistent with
     * the capture thresholds (§7.4, D-10): the BMI cycle spans all four
     * FR-ANL-12 buckets but only the deliberate flag case reaches ≥ 30
     * (the is_bmi_flagged rule); temperature, BP and heart rate stay unflagged
     * except for their own deliberate flag cases (D-66 added the heart rate).
     * Every flag is derived through the VitalSigns helpers, never asserted.
     * Plus an all-clear questionnaire and, on an `assessment` batch only, a
     * Personal / Social History (D-68).
     */
    private function createSpreadVitalsAndScreening(ClinicVisit $visit, int $seq, string $formType): void
    {
        // Deterministic flag sprinkle (~3–4% each, non-overlapping mostly).
        $bpFlagged = $seq % 29 === 3;
        $tempFlagged = $seq % 31 === 8;
        $bmiFlagged = $seq % 23 === 11;
        $hrFlagged = $seq % 37 === 5;   // D-66

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
            'heart_rate_bpm' => $hrFlagged ? 108 : 66 + ($seq % 24),   // ≤ 89 unless flagged
            'bp_systolic' => $bpFlagged ? 150 : 105 + ($seq % 20),           // ≤ 124 unless flagged
            'bp_diastolic' => $bpFlagged ? 95 : 65 + ($seq % 15),
            'entry_method' => $seq % 2 === 0 ? 'manual' : 'sensor',
            'is_bmi_flagged' => $bmiFlagged,
            'is_temp_flagged' => $tempFlagged,
            'is_bp_flagged' => $bpFlagged,
            'is_hr_flagged' => VitalSigns::isHeartRateFlagged($hrFlagged ? 108 : 66 + ($seq % 24)),
        ]);

        ScreeningResponse::create([
            'clinic_visit_id' => $visit->id,
            ...$this->screening(),
            ...$this->socialHistory($formType, $seq), // D-68
            'is_pregnant' => false,
        ]);
    }

    /**
     * The twelve form answers for a demo visit (D-63): every row NO unless named
     * in $yes. A YES may carry the detail the student typed at the kiosk, or
     * null for a YES with nothing typed.
     *
     * @param  array<string, ?string>  $yes  question key => typed detail
     * @return array<string, mixed>
     */
    private function screening(array $yes = []): array
    {
        $answers = [];
        foreach (array_keys(ScreeningResponse::QUESTIONS) as $question) {
            $answers[$question] = array_key_exists($question, $yes);
        }

        $details = array_filter($yes, fn (?string $detail) => $detail !== null);

        return [...$answers, 'details' => $details === [] ? null : $details];
    }

    /**
     * D-69 — the Medical Assessment Form's own sections for a demo visit: one
     * `medical_assessments` row per ASSESSMENT encode, and none at all for a
     * Medical Clearance, exactly as Save & Close behaves. $seq varies the
     * answers so the demo screens show ticked and untouched rows alike.
     */
    private function medicalAssessment(ClearanceRecord $record, string $formType, int $seq = 0): void
    {
        if ($formType !== 'assessment') {
            return;
        }

        $conditions = MedicalAssessment::conditionKeys();
        $vaccines = MedicalAssessment::immunizationKeys();

        // Every third demo record reports nothing — an empty section is the
        // common case at the clinic, and the screens must read well that way.
        $reportsNothing = $seq % 3 === 2;

        // Sections V and VI belong to female students only (D-70).
        $isFemale = $record->clinicVisit?->studentIsFemale() ?? false;

        $record->medicalAssessment()->create([
            'medical_history' => $reportsNothing
                ? ['patient' => [], 'family' => [], 'specify' => []]
                : [
                    'patient' => ['asthma'],
                    'family' => ['hypertension', $conditions[$seq % count($conditions)]],
                    'specify' => ['hypertension' => '150/95'],
                ],
            'immunizations' => $reportsNothing
                ? ['given' => ['child_none'], 'others' => null]
                : ['given' => ['bcg', 'measles', $vaccines[$seq % count($vaccines)]], 'others' => null],
            'family_planning_access' => $seq % 2 === 0,
            'surgical_history' => $reportsNothing
                ? ['procedures' => null, 'date_done' => null]
                : ['procedures' => 'Appendectomy', 'date_done' => (string) (2015 + $seq % 8)],
            // D-70: V and VI only for a female student — exactly what Save &
            // Close writes; a male demo record keeps them NULL.
            'menstrual_history' => $isFemale ? [
                'menarche_age' => 12 + $seq % 3,
                'first_intercourse_age' => null,
                'lmp' => now()->subDays(10 + $seq)->toDateString(),
                'period_days' => 4 + $seq % 3,
                'pads_per_day' => 3,
                'cycle_days' => 28,
                'contraceptive' => $reportsNothing ? null : 'None',
                'menopause' => false,
                'menopause_age' => null,
            ] : null,
            'ob_history' => $isFemale ? [
                'gravida' => 0,
                'para' => 0,
                'term' => 0,
                'preterm' => 0,
                'abortion' => 0,
                'living' => 0,
                'delivery_type' => null,
                'pih' => false,
            ] : null,
            // Every Assessment record carries some exam findings, so the demo
            // screens and the D-71 print never render an empty examination.
            'physical_exam' => $this->physicalExam($seq),
        ]);
    }

    /**
     * D-70 — demo Pertinent Physical Examination findings: all eight groups,
     * "Essentially Normal" throughout except one group that shows a real
     * finding and an Others note, so the screens display both states.
     *
     * @return array<string, array{findings: list<string>, others: ?string}>
     */
    private function physicalExam(int $seq): array
    {
        $groups = MedicalAssessment::physicalExamGroups();
        $abnormal = $groups[$seq % count($groups)];
        $exam = [];

        foreach ($groups as $group) {
            $keys = MedicalAssessment::findingKeys($group);

            $exam[$group] = $group === $abnormal
                ? ['findings' => [$keys[1]], 'others' => 'For follow-up']
                : ['findings' => ['normal'], 'others' => null];
        }

        return $exam;
    }

    /**
     * Personal / Social History for a demo visit (D-68): four answers on a
     * Medical Assessment Form visit, four NULLs on a Medical Clearance one —
     * which is what the kiosk itself stores, since a Clearance student is
     * never asked. $seq varies the answers so the demo shows all three boxes.
     *
     * @return array<string, mixed>
     */
    private function socialHistory(string $formType, int $seq = 0): array
    {
        if ($formType !== 'assessment') {
            return ['smoking' => null, 'alcohol' => null, 'illicit_drugs' => null, 'sexually_active' => null];
        }

        $habits = ScreeningResponse::SOCIAL_HISTORY_VALUES; // yes | no | quit

        return [
            'smoking' => $habits[$seq % 3],
            'alcohol' => $habits[($seq + 1) % 3],
            'illicit_drugs' => 'no',
            'sexually_active' => $seq % 2 === 0,
        ];
    }
}
