<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\BatchSeen;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\DocumentReleaseRequest;
use App\Models\User;
use App\Services\ClinicScheduleService;
use App\Support\BatchResults;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * DEV/DEMO ONLY — the batch side of a full HealthPass demo, for CCS and CIT
 * (rewritten for the 2026-10-05 re-seed; it was six small CCS batches).
 *
 * Every date is RELATIVE to the day it runs, so re-seeding before a demo
 * keeps it current. Seed on the demo morning: "today's" batch reads In
 * progress from its first hour (8 AM) to the 8 PM cutoff.
 *
 *   Director pipeline (Batch Approvals, Batch Tracking)
 *     BR-2026-941  CCS  pending    45  Medical Clearance, field trip   — yesterday
 *     BR-2026-942  CIT  pending   120  Medical Assessment, OJT         — yesterday
 *     BR-2026-943  CCS  pending    12  Medical Assessment, sports      — today
 *     BR-2026-944  CIT  pending    25  Medical Clearance, outbound     — today
 *     BR-2026-951  CIT  rejected   30  (reason not opened yet → the admin's dot)
 *     BR-2026-952  CCS  rejected   15  (reason already read)
 *     BR-2026-961  CCS  cancelled  10  by the college while pending
 *     BR-2026-962  CIT  cancelled  20  by the college after approval
 *
 *   Finished (Batch Results, results pages, document release)
 *     BR-2026-911  CCS  14 days ago   40  Completed — documents RELEASED
 *     BR-2026-912  CIT   9 days ago   60  Finished incomplete — 5 missed, 3 came
 *                                         on their 2nd chance; release PENDING
 *     BR-2026-913  CCS   6 days ago    5  No one attended (2nd chance missed too)
 *     BR-2026-914  CIT   3 days ago   40  Still in progress — 3 booked for a
 *                                         2nd chance in 2 days (D-110)
 *     BR-2026-915  CCS   5 days ago   20  Completed — release REJECTED
 *
 *   Today (Clinic Dashboard, both Live Queues, kiosk)
 *     BR-2026-921  CCS  8 AM   30  Medical Assessment: 12 encoded, 4 waiting
 *                                  for the nurse's interview, 2 interviewed
 *                                  and waiting for the physician (D-126), 2
 *                                  resting (D-72), 10 not here yet
 *     BR-2026-922  CIT  10 AM  20  nobody here yet
 *     BR-2026-923  CIT  9 AM    5  Medical Clearance: 3 waiting for the
 *                                  physician (they skip the nurse, D-126), 2
 *                                  not here yet
 *
 *   Upcoming (calendar rings, Upcoming Batches)
 *     BR-2026-931  CCS  +2 days  9 AM   50
 *     BR-2026-932  CIT  +4 days  8 AM  100
 *     BR-2026-933  CCS  +8 days  1 PM   20
 *     BR-2026-934  CIT  +11 days 8 AM  160  — a FULL day (the whole ring)
 *
 * Notification dots (D-120): the history from DemoClinicVisitSeeder and the
 * older batches here are marked SEEN by the Director and their college's
 * admin, so the dots that remain are a realistic handful to demo.
 *
 * Juan Santos and Maria Reyes stay off every roster here: DemoClinicVisitSeeder
 * gives them today's and yesterday's resting visits, and a student holds one
 * schedule per day (BR-25). Every other CCS / CIT student is active and can be
 * put on a new batch.
 *
 * Extends DemoClinicVisitSeeder only to reuse its vitals / encode helpers,
 * like HistoricalClearanceSeeder. Deterministic — no randomness.
 *
 * Reference bands: BR-2026-9xx, APT-2026-6xxx, HP-2026-6xxx — clear of
 * DemoClinicVisitSeeder's 7xx/8xx, 84xx and 9xxx bands.
 */
class DemoBatchSeeder extends DemoClinicVisitSeeder
{
    private ClinicScheduleService $schedule;

    private College $ccs;

    private College $cit;

    private User $nurse;

    private User $physician;

    /** @var Collection<int, User> CCS students, minus Juan and Maria */
    private Collection $ccsStudents;

    /** @var Collection<int, User> */
    private Collection $citStudents;

    private int $appointmentSeq = 6000;

    private int $visitSeq = 6000;

    /** Free arrival times per "date slot", handed out in order (D-96). @var array<string, list<string>> */
    private array $freeTimes = [];

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        if (BatchRequest::where('reference_no', 'BR-2026-911')->exists()) {
            $this->command?->info('DemoBatchSeeder: demo batches already exist, skipping.');

            return;
        }

        $this->schedule = app(ClinicScheduleService::class);
        $this->ccs = College::where('code', 'CCS')->firstOrFail();
        $this->cit = College::where('code', 'CIT')->firstOrFail();
        $this->director = User::where('role', 'director')->orderBy('id')->firstOrFail();
        $this->nurse = User::where('role', 'nurse')->orderBy('id')->firstOrFail();
        $this->physician = User::where('role', 'physician')->orderBy('id')->firstOrFail();
        $this->admins = User::where('role', 'college_admin')->orderByDesc('id')->get()->keyBy('managed_college_id');

        $this->ccsStudents = $this->studentsOf($this->ccs)
            ->reject(fn (User $student): bool => in_array($student->email, ['juan.santos@psu.edu.ph', 'maria.reyes@psu.edu.ph'], true))
            ->values();
        $this->citStudents = $this->studentsOf($this->cit);

        if ($this->ccsStudents->count() < 190 || $this->citStudents->count() < 200) {
            throw new RuntimeException('DemoBatchSeeder needs the 200-student CCS and CIT rosters — run StudentSeeder first.');
        }

        DB::transaction(function (): void {
            $this->seedFinished();
            $this->seedToday();
            $this->seedUpcoming();
            $this->seedPipeline();
            $this->markHistorySeen();
        });

        $this->command?->info('DemoBatchSeeder: seeded the CCS / CIT demo batches (BR-2026-911…962).');
    }

    /** @return Collection<int, User> */
    private function studentsOf(College $college): Collection
    {
        return User::where('role', 'student')
            ->where('status', 'active')
            ->whereHas('studentProfile', fn ($query) => $query->where('college_id', $college->id))
            ->with('studentProfile')
            ->orderBy('id')
            ->get();
    }

    // ── Scenarios ────────────────────────────────────────────────────────────

    private function seedFinished(): void
    {
        $directorSeen = [];

        // 911 — Completed, documents released to CCS, printed copies asked for (D-123).
        $date = $this->day(-14);
        $batch = $this->approved('911', $this->ccs, 'clearance', 'fieldtrip', $date, '08:00:00', $this->ccsStudents->slice(0, 40));
        foreach ($this->seatsOf($batch) as $i => $seat) {
            $this->encodeVisit($seat, $batch, $i, $i % 10 === 9 ? 'Unfit' : 'Fit');
        }
        $this->releaseRequest($batch, 'approved', $this->day(-10)->setTime(9, 30), $this->day(-9)->setTime(15, 0), wantsPrintedCopies: true);
        $directorSeen[] = [$batch, $this->day(-9)->setTime(16, 0)];

        // 912 — Finished incomplete: 5 missed day 1, 3 of them came on their
        // 2nd chance two days later, 2 missed that too. Release pending, with
        // printed copies (D-123).
        $date = $this->day(-9);
        $batch = $this->approved('912', $this->cit, 'assessment', 'ojt', $date, '08:00:00', $this->citStudents->slice(0, 60));
        $seats = $this->seatsOf($batch);
        foreach ($seats as $i => $seat) {
            if ($i < 55) {
                $this->encodeVisit($seat, $batch, $i, $i % 12 === 7 ? 'Unfit' : 'Fit');
            }
        }
        foreach ($this->secondChances($batch, $seats->slice(55), $this->day(-7), '13:00:00') as $i => $second) {
            if ($i < 3) {
                $this->encodeVisit($second, $batch, 100 + $i, 'Fit');
            }
        }
        $this->releaseRequest($batch, 'pending', $this->day(-1)->setTime(14, 10), wantsPrintedCopies: true);
        $directorSeen[] = [$batch, $this->day(-6)->setTime(9, 0)];

        // 913 — No one attended: 5 missed, and missed their 2nd chance too.
        $batch = $this->approved('913', $this->ccs, 'clearance', 'outbound', $this->day(-6), '13:00:00', $this->ccsStudents->slice(40, 5));
        $this->secondChances($batch, $this->seatsOf($batch), $this->day(-4), '08:00:00');

        // 914 — Still in progress: 37 encoded, 3 missed and booked for a 2nd
        // chance in two days.
        $batch = $this->approved('914', $this->cit, 'clearance', 'sports', $this->day(-3), '09:00:00', $this->citStudents->slice(60, 40));
        $seats = $this->seatsOf($batch);
        foreach ($seats as $i => $seat) {
            if ($i < 37) {
                $this->encodeVisit($seat, $batch, $i, 'Fit');
            }
        }
        $this->secondChances($batch, $seats->slice(37), $this->day(2), '08:00:00');

        // 915 — Completed; the college asked for the documents and was told
        // its reason was not specific enough.
        $batch = $this->approved('915', $this->ccs, 'assessment', 'rle', $this->day(-5), '10:00:00', $this->ccsStudents->slice(45, 20));
        foreach ($this->seatsOf($batch) as $i => $seat) {
            $this->encodeVisit($seat, $batch, $i, $i === 4 ? 'Unfit' : 'Fit');
        }
        $this->releaseRequest($batch, 'rejected', $this->day(-3)->setTime(10, 0), $this->day(-2)->setTime(11, 30));
        // The Director opened it after turning the request down — no dot for them.
        $directorSeen[] = [$batch, $this->day(-2)->setTime(12, 0)];

        // Opened by the Director and the college while they read as now —
        // a release request or decision after that is what stays news.
        foreach ($directorSeen as [$seenBatch, $at]) {
            $this->seen($this->director, $seenBatch, $at);
        }
        $this->seen($this->admins[$this->ccs->id], BatchRequest::where('reference_no', 'BR-2026-911')->sole(), $this->day(-8));
        $this->seen($this->admins[$this->cit->id], BatchRequest::where('reference_no', 'BR-2026-912')->sole(), $this->day(-6));
        $this->seen($this->admins[$this->ccs->id], BatchRequest::where('reference_no', 'BR-2026-915')->sole(), $this->day(-4));
        $this->seen($this->director, BatchRequest::where('reference_no', 'BR-2026-913')->sole(), $this->day(-1));
    }

    /** Today's clinic: one CCS batch in full swing, one CIT batch still to come. */
    private function seedToday(): void
    {
        // Carlo Cruz and Angel Garcia (named CCS demo students) lead the
        // roster, so their My Records show today's Pending and Fit.
        $roster = $this->ccsStudents->slice(0, 2)->concat($this->ccsStudents->slice(65, 28));
        $batch = $this->approved('921', $this->ccs, 'assessment', 'ojt', $this->day(0), '08:00:00', $roster);

        foreach ($this->seatsOf($batch) as $i => $seat) {
            match (true) {
                $i === 0 => $this->captureVisit($seat, $batch, $i),               // Carlo — waiting for the nurse
                $i <= 12 => $this->encodeVisit($seat, $batch, $i, $i % 6 === 5 ? 'Unfit' : 'Fit'),
                $i <= 15 => $this->captureVisit($seat, $batch, $i),               // the nurse's queue
                $i <= 17 => $this->interviewVisit($seat, $batch, $i),             // the physician's queue (D-126)
                $i <= 19 => $this->restVisit($seat, $batch, $i),                  // D-72
                default => null,                                                  // not here yet
            };
        }

        $this->approved('922', $this->cit, 'clearance', 'fieldtrip', $this->day(0), '10:00:00', $this->citStudents->slice(100, 20));

        // D-126: a Medical Clearance never passes through the nurse — these
        // land straight in the physician's queue, beside 921's interviewed two.
        $clearance = $this->approved('923', $this->cit, 'clearance', 'sports', $this->day(0), '09:00:00', $this->citStudents->slice(120, 5));
        foreach ($this->seatsOf($clearance) as $i => $seat) {
            if ($i < 3) {
                $this->captureVisit($seat, $clearance, $i);
            }
        }
    }

    private function seedUpcoming(): void
    {
        $this->approved('931', $this->ccs, 'clearance', 'fieldtrip', $this->day(2), '09:00:00', $this->ccsStudents->slice(93, 50));
        $this->approved('932', $this->cit, 'assessment', 'ojt', $this->day(4), '08:00:00', $this->citStudents->slice(100, 100));
        $this->approved('933', $this->ccs, 'assessment', 'rle', $this->day(8), '13:00:00', $this->ccsStudents->slice(143, 20));
        $this->approved('934', $this->cit, 'clearance', 'outbound', $this->day(11), '08:00:00', $this->citStudents->slice(0, $this->schedule->maxBatchSize()));
    }

    /** What waits on the Director, and what the Director and colleges turned down. */
    private function seedPipeline(): void
    {
        $this->pending('941', $this->ccs, 'clearance', 'fieldtrip', $this->ccsStudents->slice(0, 45), $this->day(-1)->setTime(9, 15));
        $this->pending('942', $this->cit, 'assessment', 'ojt', $this->citStudents->slice(0, 120), $this->day(-1)->setTime(13, 40));
        $this->pending('943', $this->ccs, 'assessment', 'sports', $this->ccsStudents->slice(150, 12), $this->day(0)->setTime(0, 5));
        $this->pending('944', $this->cit, 'clearance', 'outbound', $this->citStudents->slice(150, 25), $this->day(0)->setTime(0, 10));

        $rejected = $this->pending('951', $this->cit, 'assessment', 'rle', $this->citStudents->slice(30, 30), $this->day(-4)->setTime(10, 0));
        $this->reject($rejected, $this->day(-2)->setTime(16, 20),
            'The clinic is running the nursing licensure medicals that week. Please resubmit for any date after the 20th and we will take the whole class in one morning.');

        $read = $this->pending('952', $this->ccs, 'clearance', 'outbound', $this->ccsStudents->slice(170, 15), $this->day(-12)->setTime(9, 0));
        $this->reject($read, $this->day(-11)->setTime(10, 0),
            'The signed memo is from last semester. Please attach the memo for this term and submit again.');
        $this->seen($this->admins[$this->ccs->id], $read, $this->day(-10), 'rejected');

        $cancelled = $this->pending('961', $this->ccs, 'clearance', 'fieldtrip', $this->ccsStudents->slice(185, 10), $this->day(-2)->setTime(8, 0));
        $this->cancel($cancelled, $this->day(-1)->setTime(17, 0), 'The field trip was moved to next semester by the department.');

        $approvedThenCancelled = $this->approved('962', $this->cit, 'clearance', 'sports', $this->day(6), '13:00:00', $this->citStudents->slice(180, 20));
        $this->cancel($approvedThenCancelled, $this->day(-1)->setTime(18, 30), 'The intramurals were postponed, so the team no longer needs clearance this month.');
        Appointment::where('batch_request_id', $approvedThenCancelled->id)->update(['status' => 'cancelled']);
    }

    /**
     * DemoClinicVisitSeeder's history (BR-2026-7xx / 8xx) has been opened by
     * the Director and by each batch's college admin long ago — otherwise every
     * one of those 60-odd batches would carry a dot.
     */
    private function markHistorySeen(): void
    {
        $history = BatchRequest::query()
            ->where('status', 'approved')
            ->where(fn ($query) => $query->where('reference_no', 'like', 'BR-2026-7%')->orWhere('reference_no', 'like', 'BR-2026-8%'))
            ->with(BatchResults::statusRelations())
            ->get();

        foreach ($history as $batch) {
            $status = $batch->resultsStatus();
            $this->seen($this->director, $batch, now(), $status);
            $this->seen($this->admins[$batch->college_id], $batch, now(), $status);
        }
    }

    // ── Builders ─────────────────────────────────────────────────────────────

    /** $offset days from today, at midnight. */
    private function day(int $offset): Carbon
    {
        return Carbon::today()->addDays($offset);
    }

    /**
     * An approved batch, every student seated from $slot onward (hourly
     * capacity per hour) with their D-96 arrival time — what approval writes.
     * Submitted five days and approved three days before its clinic day (or
     * yesterday, for one that close).
     *
     * @param  Collection<int, User>  $students
     */
    private function approved(string $seq, College $college, string $formType, string $reason, Carbon $date, string $slot, Collection $students): BatchRequest
    {
        $submittedAt = $date->copy()->subDays(5)->setTime(10, 0)->min(now()->subDays(2));
        $approvedAt = $date->copy()->subDays(3)->setTime(14, 0)->min(now()->subDay());

        $batch = $this->newBatch($seq, $college, $formType, $reason, $submittedAt, [
            'scheduled_date' => $date->toDateString(),
            'scheduled_time' => $slot,
            'scheduled_blocks' => $this->schedule->blocksFor($students->count()),
            'status' => 'approved',
            'reviewed_by' => $this->director->id,
            'reviewed_at' => $approvedAt,
        ]);

        $span = $this->schedule->span($slot, $this->schedule->blocksFor($students->count()));
        $perHour = $this->schedule->hourlyCapacity();

        foreach ($students->values() as $i => $student) {
            $this->seatOn($batch, $student, $date, $span[intdiv($i, $perHour)], $approvedAt);
        }

        return $batch;
    }

    /** @param  Collection<int, User>  $students */
    private function pending(string $seq, College $college, string $formType, string $reason, Collection $students, Carbon $submittedAt): BatchRequest
    {
        $batch = $this->newBatch($seq, $college, $formType, $reason, $submittedAt, ['status' => 'pending']);

        foreach ($students as $student) {
            BatchRequestStudent::create(['batch_request_id' => $batch->id, 'student_id' => $student->id, 'appointment_id' => null]);
        }

        return $batch;
    }

    /** @param  array<string, mixed>  $attributes */
    private function newBatch(string $seq, College $college, string $formType, string $reason, Carbon $submittedAt, array $attributes): BatchRequest
    {
        $reference = 'BR-2026-'.$seq;

        $batch = new BatchRequest([
            'reference_no' => $reference,
            'college_id' => $college->id,
            'requested_by' => $this->admins[$college->id]->id,
            'form_type' => $formType,
            'reason' => $reason,
            'service_type' => 'medical',
            ...$this->memo($reference),
            ...$attributes,
        ]);
        $batch->created_at = $submittedAt;
        $batch->updated_at = $attributes['reviewed_at'] ?? $submittedAt;
        $batch->save();

        return $batch;
    }

    private function reject(BatchRequest $batch, Carbon $at, string $reason): void
    {
        $batch->forceFill([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by' => $this->director->id,
            'reviewed_at' => $at,
            'updated_at' => $at,
        ])->save();
    }

    private function cancel(BatchRequest $batch, Carbon $at, string $reason): void
    {
        $batch->forceFill([
            'status' => 'cancelled',
            'cancelled_at' => $at,
            'cancelled_by' => $batch->requested_by,
            'cancellation_reason' => $reason,
            'updated_at' => $at,
        ])->save();
    }

    /** One seat (appointment + roster row) at the slot's next free arrival time. */
    private function seatOn(BatchRequest $batch, User $student, Carbon $date, string $slot, Carbon $createdAt, ?Appointment $missed = null): Appointment
    {
        $key = $date->toDateString().' '.$slot;
        $this->freeTimes[$key] ??= $this->schedule->freeArrivalTimes($date->toDateString(), $slot);
        $arrival = array_shift($this->freeTimes[$key]) ?? throw new RuntimeException("DemoBatchSeeder: {$key} is over capacity.");

        $appointment = new Appointment([
            'reference_no' => 'APT-2026-'.$this->appointmentSeq++,
            'student_id' => $student->id,
            'service_type' => 'medical',
            'scheduled_date' => $date->toDateString(),
            'scheduled_time' => $slot,
            'arrival_time' => $arrival,
            'status' => 'scheduled',
            'source' => 'batch',
            'batch_request_id' => $batch->id,
            'rescheduled_from_id' => $missed?->id,
            'created_by' => $missed === null ? $this->director->id : null,
        ]);
        $appointment->created_at = $createdAt;
        $appointment->updated_at = $createdAt;
        $appointment->save();

        if ($missed === null) {
            BatchRequestStudent::create(['batch_request_id' => $batch->id, 'student_id' => $student->id, 'appointment_id' => $appointment->id]);
        } else {
            // D-110: the roster row follows the student to the new day.
            BatchRequestStudent::where('appointment_id', $missed->id)->update(['appointment_id' => $appointment->id]);
        }

        return $appointment;
    }

    /**
     * D-110: the students on $missed get their one automatic 2nd chance on
     * $date from $slot — booked the evening they missed, like the job does.
     *
     * @param  Collection<int, Appointment>  $missed
     * @return Collection<int, Appointment>
     */
    private function secondChances(BatchRequest $batch, Collection $missed, Carbon $date, string $slot): Collection
    {
        return $missed->values()->map(fn (Appointment $original): Appointment => $this->seatOn(
            $batch,
            $original->student,
            $date,
            $slot,
            $original->scheduled_date->copy()->setTime(20, 5),
            $original,
        ));
    }

    /** @return Collection<int, Appointment> the batch's seats in roster order */
    private function seatsOf(BatchRequest $batch): Collection
    {
        return Appointment::where('batch_request_id', $batch->id)->with('student.studentProfile')->orderBy('id')->get();
    }

    /** A kiosk visit at the seat's arrival time, with vitals and answers. */
    private function kioskVisit(Appointment $seat, BatchRequest $batch, int $seq, string $status): ClinicVisit
    {
        $checkedIn = Carbon::parse($seat->scheduled_date->toDateString().' '.$seat->arrival_time)->addMinute();

        $visit = new ClinicVisit([
            'reference_no' => 'HP-2026-'.$this->visitSeq++,
            'student_id' => $seat->student_id,
            'college_id' => $batch->college_id,
            'course' => $seat->student->studentProfile?->course,
            'appointment_id' => $seat->id,
            'login_method' => $seq % 3 === 0 ? 'email' : 'qr',
            'status' => $status,
            'privacy_consent_at' => $checkedIn->copy()->subMinutes(2),
            'checked_in_at' => $checkedIn,
        ]);
        $visit->created_at = $checkedIn;
        $visit->updated_at = $checkedIn;
        $visit->save();

        $this->createSpreadVitalsAndScreening($visit, $seq + $batch->id, $batch->form_type);

        return $visit;
    }

    private function captureVisit(Appointment $seat, BatchRequest $batch, int $seq): ClinicVisit
    {
        return $this->kioskVisit($seat, $batch, $seq, 'captured');
    }

    /** D-72: high BP on the first pass — sent to rest, re-check still to come. */
    private function restVisit(Appointment $seat, BatchRequest $batch, int $seq): void
    {
        $visit = $this->kioskVisit($seat, $batch, $seq, 'resting');
        $visit->update(['resting_until' => $visit->checked_in_at->copy()->addMinutes((int) config('healthpass.kiosk.recheck_rest_minutes'))]);
        $visit->vitalSigns->update(['bp_systolic' => 148, 'bp_diastolic' => 96, 'is_bp_flagged' => true]);
    }

    /**
     * D-126: a Medical Assessment visit the nurse interviewed a quarter of an
     * hour after the kiosk — sections + confirmed vitals saved, waiting in the
     * physician's queue.
     */
    private function interviewVisit(Appointment $seat, BatchRequest $batch, int $seq, string $status = 'interviewed'): ClinicVisit
    {
        $visit = $this->kioskVisit($seat, $batch, $seq, $status);
        $vitals = $this->encodedVitals($visit, 14 + $seq % 5)['encoded_vitals'];

        $visit->update([
            'interviewed_by' => $this->nurse->id,
            'interviewed_at' => $visit->checked_in_at->copy()->addMinutes(15),
        ]);
        $this->medicalAssessment($visit, $batch->form_type, $seq, $vitals); // D-69 / D-126

        return $visit;
    }

    /**
     * Encoded by the physician half an hour after the kiosk (D-126: only the
     * physician decides Fit / Unfit, so the name and license always print). A
     * Medical Assessment visit went through the nurse's interview first, so it
     * carries an interviewer; a Medical Clearance went straight to the
     * physician.
     */
    private function encodeVisit(Appointment $seat, BatchRequest $batch, int $seq, string $result): void
    {
        $visit = $batch->form_type === 'assessment'
            ? $this->interviewVisit($seat, $batch, $seq, 'encoded')
            : $this->kioskVisit($seat, $batch, $seq, 'encoded');

        ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $this->physician->id,
            ...$visit->batchPurpose(),              // D-62
            // The physician confirms the same vitals the nurse did.
            ...$this->encodedVitals($visit, 14 + $seq % 5),
            'result' => $result,
            'nurse_notes' => $result === 'Unfit'
                ? 'Blood pressure above threshold on two readings. Advised to consult before clearance is re-issued.'
                : null,
            ...ClearanceRecord::physicianBlockFor($this->physician), // D-64
            'encoded_at' => $visit->checked_in_at->copy()->addMinutes(30),
        ]);

        $seat->update(['status' => 'completed']);
    }

    /**
     * D-113: the college's request for the documents, decided or not. D-123:
     * no attachment (the Director reviews the batch's memo), and maybe a
     * request for printed copies.
     */
    private function releaseRequest(BatchRequest $batch, string $status, Carbon $requestedAt, ?Carbon $decidedAt = null, bool $wantsPrintedCopies = false): void
    {
        $request = new DocumentReleaseRequest([
            'batch_request_id' => $batch->id,
            'requested_by' => $batch->requested_by,
            'reason' => 'The partner companies need each student\'s signed form before the deployment starts.',
            'wants_printed_copies' => $wantsPrintedCopies,
            'status' => $status,
            'reviewed_by' => $decidedAt === null ? null : $this->director->id,
            'reviewed_at' => $decidedAt,
            'rejection_message' => $status === 'rejected' ? 'Please name the partner hospital and the RLE dates the forms are needed for.' : null,
        ]);
        $request->created_at = $requestedAt;
        $request->updated_at = $decidedAt ?? $requestedAt;
        $request->save();
    }

    /** @return array{memo_path: string, memo_original_name: string, memo_mime: string} the D-109 signed memo */
    private function memo(string $reference): array
    {
        $path = "memos/demo-{$reference}.pdf";
        Storage::disk('local')->put($path, $this->pdf("Signed memo — {$reference}"));

        return ['memo_path' => $path, 'memo_original_name' => 'signed-memo.pdf', 'memo_mime' => 'application/pdf'];
    }

    /** A one-page demo PDF, so the memo viewer has something real to open. */
    private function pdf(string $title): string
    {
        return Pdf::loadHTML('<h2 style="font-family: sans-serif">'.e($title).'</h2>'
            .'<p style="font-family: sans-serif">Demo document generated by DemoBatchSeeder.</p>')->output();
    }

    /** D-120: $user opened $batch at $at, seeing $status (its status now, if not given). */
    private function seen(User $user, BatchRequest $batch, Carbon $at, ?string $status = null): void
    {
        if ($status === null) {
            $batch->load(BatchResults::statusRelations());
            $status = $batch->resultsStatus();
        }

        BatchSeen::updateOrCreate(
            ['user_id' => $user->id, 'batch_request_id' => $batch->id],
            ['seen_status' => $status, 'seen_at' => $at],
        );
    }
}
