<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Http\Requests\Director\ApproveBatchRequest;
use App\Http\Requests\Director\RejectBatchRequest;
use App\Jobs\SendAppointmentScheduledMail;
use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\User;
use App\Services\ClinicScheduleService;
use App\Services\ReferenceNumberService;
use App\Services\ScheduleClashService;
use App\Support\SignedMemo;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Batch Approvals (Module DIRA).
 *
 * Unlike the /admin pages there is NO college scope here — the Director
 * reviews every college's requests (FR-DIRA-01).
 *
 * D-108: the Director now SCHEDULES every batch. The college sends only the
 * form, the purpose and the students; the Director picks the clinic date and
 * the start hour. So:
 *
 *   index()     the queue — pending requests only, oldest first; the oldest
 *               is highlighted as Next, like the clinic's Live Queue
 *   review()    one request's Review page: details, students, the signed
 *               memo (D-109), and the calendar + hour picker the Director
 *               schedules it with
 *   calendar()  JSON for that calendar — per day, how full it is and whether
 *               any start hour can take this batch
 *   hours()     JSON for the hour picker — per hour, who is in it and whether
 *               this batch can start there; plus BR-25 clashes that day
 *   approve()   books the batch on the chosen date and hour (FR-DIRA-02)
 *   reject()    turns it down with a written reason (FR-DIRA-04)
 *
 * The calendar and the hour picker only HELP: every rule is decided again by
 * approve() under a row lock, because the counts can change between the page
 * loading and the Director pressing Approve.
 */
class BatchApprovalController extends Controller
{
    public function __construct(
        private readonly ClinicScheduleService $schedule,
        private readonly ScheduleClashService $clashes,
    ) {}

    /**
     * The approval queue (FR-DIRA-01, D-108): pending requests only, first
     * come first — `created_at`, then `id` for two submitted in the same
     * second. Decided batches leave this page.
     *
     * The Batch ID search (`?q=`, D-90) still filters on the SERVER, so it
     * finds a request on any page. `$nextId` is the oldest pending request in
     * the whole queue — not the oldest on this page or in this search — so only
     * that one row is ever highlighted as Next. The highlight is a guide: any
     * row can be reviewed (Q16).
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $pending = fn () => BatchRequest::where('status', 'pending')->oldest()->orderBy('id');

        // FR-UI-06: ten per page.
        $batchRequests = $pending()
            ->with('college')
            ->when($search !== '', fn ($query) => $query->where('reference_no', 'like', '%'.$search.'%'))
            ->paginate(config('healthpass.ui.rows_per_page'))
            ->withQueryString();

        $nextId = $pending()->value('id');

        return view('director.batches.index', compact('batchRequests', 'search', 'nextId'));
    }

    /**
     * One request's Review page (FR-DIRA-07, D-108): batch details, its
     * students, and the Schedule card — calendar, hour picker, Approve and
     * Reject. A request that is no longer pending (decided, or cancelled by the
     * college) goes back to the queue with the reason.
     *
     * The current month's calendar is built here, so the page draws it at once
     * instead of waiting for the first fetch.
     */
    public function review(BatchRequest $batch): View|RedirectResponse
    {
        if (! $batch->isApprovable()) {
            return redirect()->route('director.batches.index')
                ->with('error', $this->unchangedMessage($batch));
        }

        $batch->load(['college', 'requester:id,name']);

        // Pivot-row order — the order approval fills the hours in.
        $students = $batch->batchRequestStudents()
            ->with(['student:id,name', 'student.studentProfile:id,user_id,student_number,course,year_level'])
            ->orderBy('id')
            ->get();

        $today = today();

        return view('director.batches.review', [
            'batch' => $batch,
            'students' => $students,
            // Card 3 (D-109): the signed memo's size, for its tile.
            'memoSize' => SignedMemo::sizeLabel($batch),
            'blocks' => $this->schedule->blocksFor($students->count()),
            'hourlyCapacity' => $this->schedule->hourlyCapacity(),
            // Everything the Schedule card's Alpine component needs. "Today"
            // is the SERVER's date (BR-20/BR-23): the browser never decides it.
            'scheduleData' => [
                'today' => $today->toDateString(),
                'year' => $today->year,
                'month' => $today->month,
                'days' => $this->calendarDays($students->count(), $today->year, $today->month),
                'students' => $students->count(),
                'hourlyCapacity' => $this->schedule->hourlyCapacity(),
                'dailyCapacity' => $this->schedule->dailyCapacity(),
                'calendarUrl' => route('director.batches.calendar', $batch),
                'hoursUrl' => route('director.batches.hours', $batch),
            ],
        ]);
    }

    /**
     * JSON for the Review page's calendar (FR-DIRA-08): one entry per day of
     * the month — `{date, booked, capacity, fits, why}`. `booked` drives the
     * day's ring (booked ÷ daily capacity); `fits` is false when the day is
     * past, the clinic takes no batches that weekday, or no start hour can
     * take this whole batch; `why` says which, for the day's tooltip.
     */
    public function calendar(Request $request, BatchRequest $batch): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:'.today()->year, 'max:'.(today()->year + 2)],
            'month' => ['required', 'integer', 'between:1,12'],
        ]);

        return response()->json([
            'days' => $this->calendarDays(
                $batch->batchRequestStudents()->count(),
                (int) $validated['year'],
                (int) $validated['month'],
            ),
        ]);
    }

    /**
     * The calendar's days for one month, from ONE bookings query.
     *
     * "Booked" is every non-cancelled appointment that day, the same rule as
     * bookedOnDate() (BR-02). A day "fits" when it is today or later (server
     * clock) and, if today, before closing (BR-20), is a booking weekday (`healthpass.booking_days`, which the
     * college's old calendar honoured too), still has room under
     * daily_capacity, and has at least one start hour the whole batch fits
     * from (ClinicScheduleService::fittingStarts()).
     *
     * @return list<array{date: string, booked: int, capacity: int, fits: bool, why: ?string}>
     */
    private function calendarDays(int $studentCount, int $year, int $month): array
    {
        $bookings = $this->schedule->bookingsForMonth($year, $month);
        $bookingDays = (array) config('healthpass.booking_days');
        $closedToday = $this->schedule->cutoffDaysForMonth($year, $month);   // BR-20
        $first = Carbon::create($year, $month, 1);
        $today = today()->toDateString();
        $noHourFits = 'No hour can fit '.$studentCount.' '.Str::plural('student', $studentCount);
        $days = [];

        foreach (CarbonPeriod::create($first, $first->copy()->endOfMonth()) as $day) {
            $date = $day->toDateString();
            $load = $bookings[$date] ?? ['total' => 0, 'bySlot' => []];

            // 'Y-m-d' strings sort as dates, so < means "before today".
            $why = match (true) {
                $date < $today => 'Past',
                in_array($day->day, $closedToday, true) => 'Closed for today',
                ! in_array($day->dayOfWeek, $bookingDays, true) => 'No clinic on this day',
                ! $this->schedule->dayHasRoomFor($load['total'], $studentCount),
                $this->schedule->fittingStarts($date, $studentCount, $load['bySlot']) === [] => $noHourFits,
                default => null,
            };

            $days[] = [
                'date' => $date,
                'booked' => $load['total'],
                'capacity' => $this->schedule->dailyCapacity(),
                'fits' => $why === null,
                'why' => $why,
            ];
        }

        return $days;
    }

    /**
     * JSON for the Review page's hour picker (FR-DIRA-08), for one date.
     *
     * One entry per clinic hour — `{slot, label, booked, colleges, selectable,
     * why}`. `colleges` names who already holds seats there ("CCS 8"), and an
     * hour is `selectable` when THIS batch can start there (Q17): an hour other
     * colleges use is still selectable while the whole span fits. `clash` lists
     * this batch's students who already have a clinic schedule that day
     * (BR-25) — while there are any, the page keeps Approve disabled.
     */
    public function hours(Request $request, BatchRequest $batch): JsonResponse
    {
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];

        $studentUserIds = $batch->batchRequestStudents()->pluck('student_id')->all();
        $studentCount = count($studentUserIds);

        $bookedBySlot = $this->schedule->bookedBySlot($date);
        $colleges = $this->schedule->collegesBySlot($date);
        $bookedOnDay = $this->schedule->bookedOnDate($date);
        $dayHasRoom = $this->schedule->dayHasRoomFor($bookedOnDay, $studentCount);

        $hours = [];

        foreach ($bookedBySlot as $slot => $booked) {
            $why = $dayHasRoom
                ? $this->startBlockerWhy($date, $slot, $studentCount, $bookedBySlot)
                : 'the day has only '.max(0, $this->schedule->dailyCapacity() - $bookedOnDay).' places left';

            $hours[] = [
                'slot' => $slot,
                'label' => $this->schedule->label($slot),
                'booked' => $booked,
                'colleges' => $colleges[$slot] ?? [],
                'selectable' => $why === null,
                'why' => $why,
            ];
        }

        $clashing = array_keys($this->clashes->clashesForBatch($studentUserIds, $date, exceptBatchId: $batch->id));
        $names = User::whereIn('id', $clashing)->orderBy('name')->pluck('name')->all();

        return response()->json([
            'hours' => $hours,
            'hourly_capacity' => $this->schedule->hourlyCapacity(),
            'clash' => ['count' => count($names), 'names' => $names],
        ]);
    }

    /**
     * Why this batch cannot start at $slot, worded for the picker's option
     * ("— already passed"), or null when it can.
     *
     * @param  array<string, int>  $bookedBySlot
     */
    private function startBlockerWhy(string $date, string $slot, int $studentCount, array $bookedBySlot): ?string
    {
        return match ($this->schedule->startBlocker($date, $slot, $studentCount, $bookedBySlot)) {
            'overrun' => 'runs past '.$this->closingLabel(),
            'elapsed' => 'already passed',
            'full' => 'no room at '.$this->schedule->startLabel(
                $this->schedule->slotsWithoutRoom($this->schedule->spanForStudents($slot, $studentCount), $studentCount, $bookedBySlot)[0],
            ),
            default => null,
        };
    }

    /** "5:00 PM" — the end of the last clinic hour. */
    private function closingLabel(): string
    {
        $slots = $this->schedule->slots();

        return Carbon::createFromFormat(ClinicScheduleService::SLOT_FORMAT, end($slots))->addHour()->format('g:i A');
    }

    /**
     * Approve a pending batch on the date and start hour the Director picked
     * (FR-DIRA-02, BR-08, D-108) — everything in ONE DB::transaction, so a
     * failure anywhere leaves zero partial state:
     *
     *   1. batch → approved, + reviewer stamps + scheduled_date / _time / _blocks
     *   2. one appointment per batch_request_students row
     *   3. each new appointment_id back-written onto its pivot row
     *
     * ApproveBatchRequest has already checked the date is a real one, not
     * past, and the start hour is a clinic slot. The span LENGTH is never
     * posted: it comes from the roster read here, under the lock.
     *
     * Inside the lock, in order — each refusal sends the Director back to the
     * Review page with a message naming what to change:
     *   1. the span fits before the clinic day ends;
     *   2. no hour of it has already ended (BR-23 — a same-day batch, D-36's
     *      spirit, may only take hours still to come);
     *   3. every hour has room for the seats this batch puts there (D-91,
     *      FR-DIRA-06's hard block), and the day stays within daily_capacity
     *      (BR-02, the one counter that sees pre-D-37 rows);
     *   4. no student on the batch already has a clinic schedule that day
     *      (BR-25).
     *
     * Duplicate-POST guard (FR-DIRA-05): the row is re-read WITH a lock, so a
     * double-click waits for the first commit, then finds 'approved' and
     * no-ops. The same check refuses a batch the college cancelled meanwhile.
     */
    public function approve(
        ApproveBatchRequest $request,
        BatchRequest $batch,
        ReferenceNumberService $refService,
    ): RedirectResponse {
        $director = $request->user();
        $scheduledDate = $request->validated('scheduled_date');
        $startTime = $request->validated('start_time');

        // 'approved' | 'already_decided'
        // | ['span_overrun', <student count>]
        // | ['span_elapsed'|'span_full', <offending slot>]
        // | ['day_full', <student count>]
        // | ['span_clash', <clashing student user ids>]
        $outcome = DB::transaction(function () use ($batch, $director, $refService, $scheduledDate, $startTime): string|array {
            $locked = BatchRequest::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            // A decided (or cancelled) batch cannot be re-decided (FR-DIRA-05).
            if ($locked->status !== 'pending') {
                return 'already_decided';
            }

            $studentCount = $locked->batchRequestStudents()->count();

            // (1) The whole span must end by closing time.
            $span = $this->schedule->spanForStudents($startTime, $studentCount);

            if ($span === []) {
                return ['span_overrun', $studentCount];
            }

            // (2) BR-23: never into an hour that has already gone by. Only
            // today can have elapsed hours. No lock needed — nothing can race
            // the clock — but it is checked here so the order matches the page.
            $elapsedHours = $this->schedule->elapsedSlotsIn($scheduledDate, $span);

            if ($elapsedHours !== []) {
                return ['span_elapsed', $elapsedHours[0]];
            }

            // (3) D-37 hard block, D-91 seats: read under the same lock as the
            // write, so an hour cannot fill between the check and the fan-out.
            $fullSlots = $this->schedule->fullSlotsIn($scheduledDate, $span, $studentCount, lock: true);

            if ($fullSlots !== []) {
                return ['span_full', $fullSlots[0]];
            }

            if (! $this->schedule->dayHasRoomFor($this->schedule->bookedOnDate($scheduledDate, lock: true), $studentCount)) {
                return ['day_full', $studentCount];
            }

            // (4) BR-25: one clinic schedule per student per day. The batch
            // itself is left out, or it would clash with its own students.
            $clashes = $this->clashes->clashesForBatch(
                $locked->batchRequestStudents()->pluck('student_id')->all(),
                $scheduledDate,
                exceptBatchId: $locked->id,
                lock: true,
            );

            if ($clashes !== []) {
                return ['span_clash', array_keys($clashes)];
            }

            $locked->update([
                'status' => 'approved',
                'scheduled_date' => $scheduledDate,
                'scheduled_time' => $startTime,
                'scheduled_blocks' => count($span),
                'reviewed_by' => $director->id,
                'reviewed_at' => now(),
            ]);

            // BR-08 fan-out — since D-61 the ONLY way an appointment is
            // created (FR-DIRA-03).
            //
            // D-37: students are spread across the span, hourly_capacity per
            // hour, in pivot-row id order — a deterministic assignment. The
            // last block takes whatever remainder is left.
            //
            // D-96: inside its hour, each student takes the next arrival time
            // still FREE there (other batches may already hold some) — read
            // once per hour, under the capacity lock above. The locked check
            // guarantees there are enough; `?? null` only guards a cap
            // lowered after earlier times were handed out.
            $perHour = $this->schedule->hourlyCapacity();
            $pivotRows = $locked->batchRequestStudents()->orderBy('id')->get();
            $freeTimesBySlot = [];

            foreach ($pivotRows->values() as $index => $pivotRow) {
                $slot = $span[intdiv($index, $perHour)];
                $freeTimesBySlot[$slot] ??= $this->schedule->freeArrivalTimes($scheduledDate, $slot);

                $appointment = Appointment::create([
                    'reference_no' => $refService->generateAppointmentRef(),
                    'student_id' => $pivotRow->student_id,
                    'service_type' => $locked->service_type,
                    'scheduled_date' => $scheduledDate,
                    'scheduled_time' => $slot,
                    'arrival_time' => $freeTimesBySlot[$slot][$index % $perHour] ?? null,
                    'status' => 'scheduled',
                    'source' => 'batch',
                    'batch_request_id' => $locked->id,
                    'created_by' => $director->id,
                ]);

                $pivotRow->update(['appointment_id' => $appointment->id]);
            }

            return 'approved';
        });

        if ($outcome === 'already_decided') {
            return redirect()->route('director.batches.index')
                ->with('error', $this->unchangedMessage($batch));
        }

        if (is_array($outcome)) {
            return redirect()->route('director.batches.review', $batch)
                ->withInput()
                ->with('error', $this->refusalMessage($batch, $scheduledDate, $startTime, $outcome));
        }

        // FR-STU-12 (D-39): tell each student their college booked them in.
        //
        // Deliberately OUT HERE, after DB::transaction() has returned — i.e.
        // after the commit. Queueing from inside would mean an SMTP or queue
        // failure rolling back real appointments, and a worker could pick a job
        // up before the appointment row was visible to its connection. Any
        // early return above (already decided, a refused hour, or a
        // mid-fan-out exception) never reaches this line, so a batch that was
        // not approved emails nobody.
        //
        // The appointments are re-read rather than carried out of the closure:
        // approval is terminal, so this query can only ever return the rows the
        // fan-out above just made. One job per student — a single bad address
        // loses one email, not 59.
        $appointments = Appointment::where('batch_request_id', $batch->id)->get();

        foreach ($appointments as $appointment) {
            SendAppointmentScheduledMail::dispatch($appointment);
        }

        $batch->refresh();
        $studentCount = $appointments->count();

        return redirect()->route('director.batches.index')
            ->with('status', "{$batch->reference_no} approved for {$this->dateLabel($scheduledDate)}, "
                .$this->schedule->rangeLabel($batch->scheduledSpan())
                ." — {$studentCount} ".Str::plural('appointment', $studentCount).' created.');
    }

    /**
     * Why approve() refused the Director's choice, naming what to change —
     * the hour, or the day. Worded with the existing helpers (label(),
     * noRoomReason(), clashMessage()).
     *
     * @param  array{0: string, 1: mixed}  $outcome
     */
    private function refusalMessage(BatchRequest $batch, string $date, string $startTime, array $outcome): string
    {
        [$reason, $detail] = $outcome;
        $ref = $batch->reference_no;
        $day = $this->dateLabel($date);

        return match ($reason) {
            'span_overrun' => "{$ref} needs ".$this->schedule->blocksFor($detail).' clinic hours for '
                ."{$detail} students — starting at ".$this->schedule->startLabel($startTime)
                .' it would run past '.$this->closingLabel().'. Pick an earlier start hour.',
            'span_elapsed' => "{$ref} cannot be scheduled then — the ".$this->schedule->label($detail)
                ." slot on {$day} has already passed. Pick a later hour or another day.",
            'span_full' => "{$ref} cannot be scheduled then — the ".$this->schedule->label($detail)
                ." slot in its span on {$day} ".$this->schedule->noRoomReason($date, $detail)
                .'. Pick another hour or day.',
            'day_full' => "{$ref} cannot be scheduled on {$day} — the day has only "
                .max(0, $this->schedule->dailyCapacity() - $this->schedule->bookedOnDate($date))
                ." places left for its {$detail} students. Pick another day.",
            default => $this->clashMessage($batch, $day, $detail),
        };
    }

    /** "Tue, Oct 6, 2026" */
    private function dateLabel(string $date): string
    {
        return Carbon::parse($date)->format('D, M j, Y');
    }

    /**
     * Reject a pending batch (FR-DIRA-04): status → rejected + reviewer
     * stamps + the written reason (D-36), and NOTHING else — no
     * appointments, no schedule.
     *
     * The reason is validated by RejectBatchRequest before we get here (a
     * failure goes back to the Review page the modal was on). The College
     * Admin reads it back on Batch Tracking (FR-ADM-05).
     *
     * Same lock-and-recheck guard as approve() (FR-DIRA-05): a reject racing
     * an approve (or a duplicate reject) waits for the first commit and then
     * no-ops on the non-pending status — so a replayed POST can never
     * overwrite the reason already on record.
     */
    public function reject(RejectBatchRequest $request, BatchRequest $batch): RedirectResponse
    {
        $director = $request->user();
        $reason = $request->validated('rejection_reason');

        $wasRejected = DB::transaction(function () use ($batch, $director, $reason): bool {
            $locked = BatchRequest::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            // A decided batch cannot be re-decided (FR-DIRA-05).
            if ($locked->status !== 'pending') {
                return false;
            }

            $locked->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'reviewed_by' => $director->id,
                'reviewed_at' => now(),
            ]);

            return true;
        });

        if (! $wasRejected) {
            return redirect()->route('director.batches.index')
                ->with('error', $this->unchangedMessage($batch));
        }

        return redirect()->route('director.batches.index')
            ->with('status', "{$batch->reference_no} rejected — no appointments were created.");
    }

    /**
     * "BR-2026-004 cannot be scheduled on Tue, Oct 6, 2026 — 4 student(s)
     * already have a clinic schedule that day: A, B, C and 1 more. Pick
     * another day." (D-54, D-77, D-108)
     *
     * At most three names, so a 60-student clash still reads as one line; the
     * Review page's clash note lists them before Approve is even pressed.
     *
     * @param  list<int>  $studentUserIds
     */
    private function clashMessage(BatchRequest $batch, string $day, array $studentUserIds): string
    {
        $names = User::whereIn('id', $studentUserIds)->orderBy('name')->pluck('name');
        $more = $names->count() - 3;

        return "{$batch->reference_no} cannot be scheduled on {$day} — ".count($studentUserIds)
            .' student(s) already have a clinic schedule that day: '
            .$names->take(3)->implode(', ')
            .($more > 0 ? " and {$more} more" : '')
            .'. Pick another day.';
    }

    /**
     * Why a request is no longer open to a decision, worded for the state it
     * actually is in (D-52): decided by a Director, or cancelled by its
     * college. Used when a decision POST changes nothing and when the Review
     * page is opened for a request that has left the queue.
     *
     * refresh() because $batch may still hold the row as an earlier page
     * rendered it — that stale copy is exactly what let the request through.
     */
    private function unchangedMessage(BatchRequest $batch): string
    {
        // D-129: the Director can cancel an approved batch too.
        return match (true) {
            $batch->refresh()->status !== 'cancelled' => "{$batch->reference_no} has already been decided — nothing was changed.",
            $batch->wasCancelledByDirector() => "{$batch->reference_no} was cancelled by the Clinic Director — nothing was changed.",
            default => "{$batch->reference_no} was cancelled by the college — nothing was changed.",
        };
    }
}
