<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;
use App\Services\ClinicScheduleService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * DEV/DEMO ONLY — synthetic batch requests for the CCS College Admin.
 *
 * Seeds one batch per state so every branch of Batch Tracking (FR-ADM-05), its
 * Batch Results card and popup (FR-ADM-12 / D-55), the batch roster (FR-ADM-07)
 * and the Activity Log (FR-ADM-10) can be clicked through without a live
 * Director, a live kiosk or a live nurse:
 *
 *   BR-2026-901  PENDING    4 students — CANCELLABLE (D-52); Cancel button shown
 *   BR-2026-902  PENDING    2 students — CANCELLABLE; proves the column holds
 *                                        more than one row
 *   BR-2026-903  APPROVED   4 students, clinic day 4 days AGO — the Batch
 *                                        Results popup in full: 1 Fit, 1 Unfit,
 *                                        1 still with the nurse, 1 absent
 *   BR-2026-904  APPROVED   3 students, clinic day in 3 days — 2 not yet
 *                                        attended (both withdrawable), 1 already
 *                                        withdrawn
 *   BR-2026-905  REJECTED   3 students — the Rejection Reason column, which now
 *                                        sits next to the Cancel column
 *   BR-2026-906  CANCELLED  2 students — the D-52 end state: Cancelled badge,
 *                                        the roster notice, the Activity Log row
 *
 * Everything is deterministic — no randomness — so a re-seeded database always
 * produces the same screens.
 *
 * RESERVED REFERENCE BANDS, chosen so nothing collides and no other seeder's
 * idempotency guard is tripped:
 *   BR-2026-90x   batch requests   (nothing else mints BR- refs)
 *   APT-2026-85xx appointments     (DemoClinicVisitSeeder owns APT-2026-9xxx
 *                                   and SKIPS ITSELF if any exists — so this
 *                                   seeder must stay out of that band)
 *   HP-2026-85xx  clinic visits    (likewise: 90xx and 91xx are that seeder's)
 */
class DemoBatchSeeder extends Seeder
{
    /** The reserved batch band, and this seeder's idempotency guard. */
    private const BATCH_REF_PREFIX = 'BR-2026-90';

    private College $ccs;

    private User $admin;

    private User $director;

    private User $nurse;

    /** The four CCS students, in a stable order. @var Collection<int, User> */
    private Collection $students;

    private ClinicScheduleService $schedule;

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        if (BatchRequest::where('reference_no', 'like', self::BATCH_REF_PREFIX.'%')->exists()) {
            $this->command?->info('DemoBatchSeeder: demo batches already exist, skipping.');

            return;
        }

        $this->ccs = College::where('code', 'CCS')->firstOrFail();
        $this->schedule = app(ClinicScheduleService::class);

        // Looked up by ROLE + scope rather than by email: the staff email
        // domain is a config value (config/healthpass.php seed_staff), so
        // hardcoding addresses here would break on a hosted seed.
        $this->admin = User::where('role', 'college_admin')
            ->where('managed_college_id', $this->ccs->id)
            ->orderBy('id')->firstOrFail();

        $this->director = User::where('role', 'director')->orderBy('id')->firstOrFail();
        $this->nurse = User::where('role', 'nurse')->orderBy('id')->firstOrFail();

        $this->students = User::where('role', 'student')
            ->whereHas('studentProfile', fn ($query) => $query->where('college_id', $this->ccs->id))
            ->orderBy('id')->get();

        if ($this->students->count() < 4) {
            $this->command?->warn('DemoBatchSeeder: CCS needs 4 students — run StudentSeeder first. Skipping.');

            return;
        }

        $this->seedPending();
        $this->seedApprovedWithResults();
        $this->seedApprovedUpcoming();
        $this->seedRejected();
        $this->seedCancelled();

        $this->command?->info('DemoBatchSeeder: seeded 6 CCS batches (BR-2026-901…906).');
    }

    // ── The six batches ──────────────────────────────────────────────────────

    /** Two pending batches — the only state D-52 lets the admin cancel. */
    private function seedPending(): void
    {
        $batch = $this->makeBatch('901', 'pending', 'assessment', 'ojt', 4, days: 5, slot: '09:00:00');
        $this->attach($batch, $this->students->take(4));

        $batch = $this->makeBatch('902', 'pending', 'clearance', 'fieldtrip', 2, days: 7, slot: '13:00:00');
        $this->attach($batch, $this->students->take(2));
    }

    /**
     * The results showcase: a clinic day that has already passed, with every
     * outcome the Batch Results popup can render sitting side by side.
     */
    private function seedApprovedWithResults(): void
    {
        $date = now()->subDays(4)->toDateString();
        $batch = $this->makeBatch('903', 'approved', 'assessment', 'rle', 4, days: -4, slot: '08:00:00');

        [$juan, $maria, $carlo, $angel] = $this->students->take(4)->all();

        // Encoded Fit — the clean pass.
        $this->attachOne($batch, $juan, $date, '08:00:00', appointmentStatus: 'completed');
        $this->encode($batch, $juan, $date, 'Fit', bpFlagged: false);

        // Encoded Unfit — the outcome the college actually needs to chase.
        $this->attachOne($batch, $maria, $date, '08:00:00', appointmentStatus: 'completed');
        $this->encode($batch, $maria, $date, 'Unfit', bpFlagged: true);

        // Captured but NOT encoded — the student is at the clinic and the
        // appointment row still reads 'scheduled'. This is the case that
        // proves the status column cannot be read off appointments alone.
        $this->attachOne($batch, $carlo, $date, '08:00:00', appointmentStatus: 'scheduled');
        $this->capture($batch, $carlo, $date);

        // Never turned up, and the clinic day has gone — "Absent" (D-55).
        $this->attachOne($batch, $angel, $date, '08:00:00', appointmentStatus: 'scheduled');
    }

    /** An upcoming approved batch: withdrawable seats plus one already pulled. */
    private function seedApprovedUpcoming(): void
    {
        $date = now()->addDays(3)->toDateString();
        $batch = $this->makeBatch('904', 'approved', 'clearance', 'outbound', 3, days: 3, slot: '10:00:00');

        [$juan, $maria, $carlo] = $this->students->take(3)->all();

        $this->attachOne($batch, $juan, $date, '10:00:00', appointmentStatus: 'scheduled');
        $this->attachOne($batch, $maria, $date, '10:00:00', appointmentStatus: 'scheduled');
        // Withdrawn by the admin (FR-ADM-07) — the seat is already free.
        $this->attachOne($batch, $carlo, $date, '10:00:00', appointmentStatus: 'cancelled');
    }

    /** A Director rejection, so the Reason column renders beside Cancel. */
    private function seedRejected(): void
    {
        $batch = $this->makeBatch('905', 'rejected', 'assessment', 'sports', 3, days: 9, slot: '11:00:00');
        $this->attach($batch, $this->students->take(3));
    }

    /** Already cancelled by the college (D-52) — the end state of FR-ADM-11. */
    private function seedCancelled(): void
    {
        $batch = $this->makeBatch('906', 'cancelled', 'clearance', 'fieldtrip', 2, days: 6, slot: '14:00:00');
        $this->attach($batch, $this->students->take(2));
    }

    // ── Builders ─────────────────────────────────────────────────────────────

    /**
     * One batch_requests row. `$days` is an offset from today so the demo data
     * stays meaningful whenever it is seeded — a hardcoded date would drift
     * into the past and turn every pending batch stale (D-36).
     */
    private function makeBatch(
        string $seq,
        string $status,
        string $formType,   // D-62
        string $reason,
        int $studentCount,
        int $days,
        string $slot,
    ): BatchRequest {
        $date = now()->addDays($days)->toDateString();
        $decided = in_array($status, ['approved', 'rejected'], true);

        return BatchRequest::create([
            'reference_no' => 'BR-2026-'.$seq,
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'form_type' => $formType,
            'reason' => $reason,
            'service_type' => 'medical',
            'requested_date' => $date,
            'requested_time' => $slot,
            'requested_blocks' => $this->schedule->blocksFor($studentCount),
            'scheduled_date' => $status === 'approved' ? $date : null,
            'status' => $status,
            'rejection_reason' => $status === 'rejected'
                ? 'The clinic is running the nursing licensure medicals that whole week. Please resubmit for any date after the 20th and we will take the full cohort in one morning.'
                : null,
            'reviewed_by' => $decided ? $this->director->id : null,
            'reviewed_at' => $decided ? now()->subDay() : null,
            // D-52's two columns. Only a cancelled batch carries them, and they
            // are never backfilled onto anything else.
            'cancelled_at' => $status === 'cancelled' ? now()->subHours(6) : null,
            'cancelled_by' => $status === 'cancelled' ? $this->admin->id : null,
        ]);
    }

    /**
     * Roster rows with no appointment — the shape of every batch the Director
     * has not approved (BR-08).
     *
     * @param  Collection<int, User>  $students
     */
    private function attach(BatchRequest $batch, Collection $students): void
    {
        foreach ($students as $student) {
            BatchRequestStudent::create([
                'batch_request_id' => $batch->id,
                'student_id' => $student->id,
                'appointment_id' => null,
            ]);
        }
    }

    /** One roster row WITH the appointment approval would have generated. */
    private function attachOne(
        BatchRequest $batch,
        User $student,
        string $date,
        string $slot,
        string $appointmentStatus,
    ): void {
        $appointment = Appointment::create([
            'reference_no' => 'APT-2026-'.$this->nextSeq(),
            'student_id' => $student->id,
            'service_type' => 'medical',
            'scheduled_date' => $date,
            'scheduled_time' => $slot,
            'status' => $appointmentStatus,
            'source' => 'batch',
            'batch_request_id' => $batch->id,
            'created_by' => $this->director->id,
        ]);

        BatchRequestStudent::create([
            'batch_request_id' => $batch->id,
            'student_id' => $student->id,
            'appointment_id' => $appointment->id,
        ]);
    }

    /**
     * A kiosk visit CAPTURED but not yet encoded — vitals and screening
     * answers present, no clearance record. The Batch Results popup reads this
     * as "At the clinic", and the Nurse Live Queue picks it up as real work.
     */
    private function capture(BatchRequest $batch, User $student, string $date): ClinicVisit
    {
        $appointment = Appointment::where('batch_request_id', $batch->id)
            ->where('student_id', $student->id)->firstOrFail();

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$this->nextSeq(),
            'student_id' => $student->id,
            'college_id' => $this->ccs->id,
            // Capture-time program snapshot (D-43), exactly as the kiosk does.
            'course' => $student->studentProfile?->course,
            'appointment_id' => $appointment->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => Carbon::parse($date)->setTime(8, 4),
            'checked_in_at' => Carbon::parse($date)->setTime(8, 6),
        ]);

        VitalSigns::create([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 168.0,
            'weight_kg' => 61.0,
            'bmi' => 21.6,
            'temperature_c' => 36.7,
            'heart_rate_bpm' => 78,
            'bp_systolic' => 122,
            'bp_diastolic' => 79,
            'entry_method' => 'manual',
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            'is_bmi_flagged' => false,
            // D-66: derived through the shared helper, like a real capture.
            'is_hr_flagged' => VitalSigns::isHeartRateFlagged(78),
        ]);

        ScreeningResponse::create([
            'clinic_visit_id' => $visit->id,
            // All twelve of the form's Physical Signs rows answered NO (D-63).
            ...array_fill_keys(array_keys(ScreeningResponse::QUESTIONS), false),
            // D-68: the Medical Assessment Form also asks the Personal /
            // Social History; the Medical Clearance does not, so those visits
            // store four NULLs — exactly what the kiosk itself writes.
            ...$this->socialHistory($batch->form_type),
            'is_pregnant' => false,
        ]);

        return $visit;
    }

    /**
     * Personal / Social History for a demo capture (D-68): four answers on a
     * Medical Assessment Form batch, four NULLs on a Medical Clearance one.
     *
     * @return array<string, mixed>
     */
    private function socialHistory(string $formType): array
    {
        if ($formType !== 'assessment') {
            return ['smoking' => null, 'alcohol' => null, 'illicit_drugs' => null, 'sexually_active' => null];
        }

        return ['smoking' => 'no', 'alcohol' => 'quit', 'illicit_drugs' => 'no', 'sexually_active' => false];
    }

    /**
     * A visit the nurse has encoded: the same capture, plus the clearance
     * record whose `result` is the one clinical field the College Admin may
     * see (PRD §6.6 as amended by D-53).
     */
    private function encode(BatchRequest $batch, User $student, string $date, string $result, bool $bpFlagged): void
    {
        $visit = $this->capture($batch, $student, $date);

        $visit->update(['status' => 'encoded']);

        if ($bpFlagged) {
            // BP threshold is locked at 140/90 (CLAUDE.md), so this is a real
            // flag, not a decorative one.
            $visit->vitalSigns->update([
                'bp_systolic' => 148,
                'bp_diastolic' => 94,
                'is_bp_flagged' => true,
            ]);
        }

        // D-65: the clinic confirmed the kiosk's reading unchanged and measured
        // the respiratory rate — the same pair Save & Close writes.
        $vitals = $visit->vitalSigns->fresh();
        // D-66: the flagged demo row's 22 breaths/min is outside 12-20, so the
        // helper raises is_rr_flagged exactly as the encode controller would.
        $respiratoryRate = $bpFlagged ? 22 : 16;
        $vitals->update([
            'respiratory_rate' => $respiratoryRate,
            'is_rr_flagged' => VitalSigns::isRespiratoryRateFlagged($respiratoryRate),
        ]);

        ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $this->nurse->id,
            'result' => $result,
            'encoded_vitals' => [
                'height_cm' => (float) $vitals->height_cm,
                'weight_kg' => (float) $vitals->weight_kg,
                'bmi' => (float) $vitals->bmi,
                'temperature_c' => (float) $vitals->temperature_c,
                'bp_systolic' => $vitals->bp_systolic,
                'bp_diastolic' => $vitals->bp_diastolic,
                'heart_rate_bpm' => $vitals->heart_rate_bpm,
                'respiratory_rate' => $vitals->respiratory_rate,
            ],
            ...$visit->batchPurpose(),   // D-62: as the real encode copies it
            'nurse_notes' => $result === 'Unfit'
                ? 'Blood pressure above threshold on two readings. Advised to consult before clearance is re-issued.'
                : null,
            'encoded_at' => Carbon::parse($date)->setTime(10, 15),
        ]);

        // The nurse's encode is what completes the appointment
        // (Nurse\EncodeController) — the Batch Results popup reads that as
        // "Completed".
        $visit->appointment?->update(['status' => 'completed']);
    }

    /**
     * The next number in this seeder's reserved 85xx band. One counter serves
     * both APT- and HP- refs: they are separate unique indexes, so sharing the
     * sequence costs nothing and keeps the two easy to line up by eye.
     */
    private function nextSeq(): int
    {
        static $seq = 8500;

        return $seq++;
    }
}
