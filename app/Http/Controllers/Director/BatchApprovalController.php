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
use App\Services\BatchConflictService;
use App\Services\ClinicScheduleService;
use App\Services\ReferenceNumberService;
use App\Services\ScheduleClashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Batch Approvals (Module DIRA).
 *
 * Unlike the /admin pages there is NO college scope here — the Director
 * reviews every college's requests on one screen (FR-DIRA-01).
 *
 * capacity() feeds the approve modal (FR-DIRA-06): since D-37 an hour in the
 * batch's span that is already at the hourly cap BLOCKS approval outright —
 * the old warn-but-allow behaviour is gone. The modal disables its submit and
 * the endpoint refuses the POST; the UI is never the gate.
 *
 * approve() is the real decision flow (FR-DIRA-02, BR-08): one DB
 * transaction that flips the batch, stamps the reviewer fields, fans out
 * one appointment per listed student, and back-writes each appointment_id
 * onto its pivot row. Since D-36 it is **confirm-only** — the date is the
 * College Admin's `requested_date`, read from the locked row, never from the
 * request. reject() (FR-DIRA-04) is the same shape minus the fan-out: stamp
 * the reviewer fields plus the now-mandatory reason, create nothing.
 */
class BatchApprovalController extends Controller
{
    public function __construct(
        private readonly ClinicScheduleService $schedule,
        private readonly ScheduleClashService $clashes,
        private readonly BatchConflictService $conflicts,
    ) {}

    /**
     * All colleges' batch requests, newest first (FR-DIRA-01).
     *
     * D-90: a Batch ID search (`?q=`), applied on the SERVER so it finds a
     * batch on any page — the same shape as the College Admin's Batch Results
     * search (D-86). And for every pending row on the page, the other pending
     * batches it cannot share the clinic with (BatchConflictService), looked
     * up across ALL pending batches, not just the ten on screen. They feed the
     * row's "Conflicts with …" line and, for a batch that came SECOND, the
     * approve popup's warning.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        // FR-UI-06: ten per page; id breaks created_at ties.
        // `canceller` names who cancelled a batch in the D-92 reason popup.
        $batchRequests = BatchRequest::with(['college', 'canceller:id,name'])
            ->withCount('batchRequestStudents')
            ->when($search !== '', fn ($query) => $query->where('reference_no', 'like', '%'.$search.'%'))
            ->latest()
            ->orderByDesc('id')
            ->paginate(config('healthpass.ui.rows_per_page'))
            ->withQueryString();

        $conflicts = $batchRequests->getCollection()
            ->filter(fn (BatchRequest $batch): bool => $batch->status === 'pending')
            ->mapWithKeys(fn (BatchRequest $batch): array => [$batch->id => $this->conflicts->conflictsWith($batch)])
            ->filter(fn (Collection $found): bool => $found->isNotEmpty());

        $conflictPopups = $batchRequests->getCollection()
            ->filter(fn (BatchRequest $batch): bool => $conflicts->has($batch->id))
            ->mapWithKeys(fn (BatchRequest $batch): array => [$batch->id => $this->conflictPopup($batch, $conflicts[$batch->id])])
            ->filter()
            ->all();

        return view('director.batches.index', compact('batchRequests', 'search', 'conflicts', 'conflictPopups'));
    }

    /**
     * The approve popup's conflict warning for $batch (D-90), or null when none
     * of its conflicts was submitted before it: approving the batch that came
     * FIRST is the recommended move and needs no warning.
     *
     * The Director cannot move a batch's hours (D-36, D-37), so "change their
     * time" means rejecting it with a reason that names the start hours still
     * free once the earlier batch is in — pre-filled here, editable in the
     * reject box. It never names the other college: the College Admin reads it.
     *
     * @param  Collection<int, BatchRequest>  $conflicts
     * @return array{submitted: string, earlier: list<array{ref: string, college: string, submitted: string, span: string}>, freeStarts: list<string>, reason: string}|null
     */
    private function conflictPopup(BatchRequest $batch, Collection $conflicts): ?array
    {
        $earlier = $conflicts
            ->filter(fn (BatchRequest $other): bool => $other->submittedBefore($batch))
            ->values();

        if ($earlier->isEmpty()) {
            return null;
        }

        $freeStarts = array_map(
            fn (string $slot): string => $this->schedule->startLabel($slot),
            $this->conflicts->freeStartsAfter($batch, $earlier),
        );

        $date = $batch->requested_date->format('M j, Y');
        $why = 'Another college requested these clinic hours first, and the clinic cannot fit both batches.';

        return [
            'submitted' => $batch->created_at->format('M j, Y · g:i A'),
            'earlier' => $earlier->map(fn (BatchRequest $other): array => [
                'ref' => $other->reference_no,
                'college' => $other->college->code,
                'submitted' => $other->created_at->format('M j, Y · g:i A'),
                'span' => $other->requestedSpanLabel(),
            ])->all(),
            'freeStarts' => $freeStarts,
            'reason' => $freeStarts === []
                ? "{$why} No other start time is free on {$date}, so please resubmit for another date."
                : "{$why} Please resubmit for {$date} starting at ".Arr::join($freeStarts, ', ', ' or ').', or choose another date.',
        ];
    }

    /**
     * JSON: how full one date — and, since D-37, one batch's hour span — is.
     * Polled by the approve modal when it opens.
     *
     * `time` + `blocks` are the batch's stored span, and `students` its roster
     * size. When they are supplied the response also carries `full_slots`: the
     * hours in that span without room for the students the batch would put
     * there (D-91). A non-empty list is what makes the modal refuse to submit
     * (FR-DIRA-06 is a HARD BLOCK since D-37) — but this endpoint only informs
     * the UI; approve() re-checks under lock and is the real gate.
     */
    public function capacity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['nullable', 'string'],
            'blocks' => ['nullable', 'integer', 'min:1', 'max:24'],
            'students' => ['required_with:time', 'nullable', 'integer', 'min:1', 'max:'.$this->schedule->maxBatchSize()],
        ]);

        // Same counting rule as every capacity check (BR-02): cancelled slots are free.
        $booked = $this->schedule->bookedOnDate($validated['date']);

        $span = isset($validated['time'], $validated['blocks'])
            ? $this->schedule->span($validated['time'], (int) $validated['blocks'])
            : [];

        return response()->json([
            'booked' => $booked,
            'capacity' => $this->schedule->dailyCapacity(),
            'hourly_capacity' => $this->schedule->hourlyCapacity(),
            'span_label' => $this->schedule->spanLabel($span),
            'full_slots' => array_map(
                fn (string $slot): string => $this->schedule->label($slot),
                $this->schedule->fullSlotsIn($validated['date'], $span, (int) ($validated['students'] ?? 0)),
            ),
            // BR-23: hours of the span that have already ended (today only).
            'elapsed_slots' => array_map(
                fn (string $slot): string => $this->schedule->label($slot),
                $this->schedule->elapsedSlotsIn($validated['date'], $span),
            ),
        ]);
    }

    /**
     * Approve a pending batch (FR-DIRA-02, BR-08) — everything in ONE
     * DB::transaction, so a failure anywhere leaves zero partial state:
     *
     *   1. batch → approved, + reviewed_by / reviewed_at / scheduled_date
     *   2. one appointment per batch_request_students row
     *   3. each new appointment_id back-written onto its pivot row
     *
     * D-36 — CONFIRM-ONLY. The appointment date is the College Admin's
     * `requested_date`, read off the LOCKED row inside the transaction. The
     * request body is never consulted for it (ApproveBatchRequest accepts
     * nothing), so a posted `scheduled_date` is inert. A Director who can't
     * take the requested date rejects with a reason instead — that leaves an
     * audit trail where a silent date move left none.
     *
     * Two cases leave nothing to confirm, and both are refused outright with
     * the Director pointed at reject-and-ask-for-resubmission: a batch
     * submitted before D-29 (`requested_date` NULL), and one whose requested
     * date passed while it sat pending (confirm-only can't move it, and a
     * cohort must never be scheduled into the past).
     *
     * Duplicate-POST guard (FR-DIRA-05): the batch row is re-read WITH a
     * row lock inside the transaction. lockForUpdate() makes a concurrent
     * duplicate wait until the first commit, after which it sees status
     * 'approved' and no-ops — so a double-click can never double-generate.
     * (The modal also disables the submit button on first click, but the
     * server never relies on that.)
     *
     * D-37 — CAPACITY IS NOW A HARD BLOCK (amending FR-DIRA-06, which used to
     * warn and allow). If ANY hour in the batch's span has reached the hourly
     * cap, approval is refused: an over-full hour means more students in the
     * clinic at once than it can process, which a "warning" cannot undo once
     * the appointments exist. Combined with D-36's confirm-only approval, a
     * batch whose slots filled up while it sat pending has exactly one
     * outcome — rejection and resubmission for a new date or start hour. That
     * is the intended workflow, not a bug: the alternatives are approving into
     * a clinic that cannot serve the cohort, or letting the Director silently
     * move the date, which D-36 removed on audit-trail grounds.
     *
     * BR-23 closes the matching hole in TIME. D-36 deliberately still allows a
     * batch requested for *today* to be approved, but between submission and
     * review those hours can simply end — approving a 7 AM cohort at 2 PM
     * would mint appointments for a time that has been and gone. Any elapsed
     * hour in the span is refused on the same reject-and-resubmit terms.
     */
    public function approve(
        ApproveBatchRequest $request,
        BatchRequest $batch,
        ReferenceNumberService $refService,
    ): RedirectResponse {
        $director = $request->user();

        // 'approved' | 'already_decided' | 'no_requested_date'
        // | 'stale_requested_date' | 'no_requested_time'
        // | ['span_elapsed'|'span_full', <offending hour label>]
        // | ['span_clash', <clashing student user ids>]  (D-54)
        $outcome = DB::transaction(function () use ($batch, $director, $refService): string|array {
            $locked = BatchRequest::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            // A decided batch cannot be re-decided (FR-DIRA-05).
            if ($locked->status !== 'pending') {
                return 'already_decided';
            }

            // D-36: the date is the admin's, or there is no approval to make.
            if ($locked->requested_date === null) {
                return 'no_requested_date';
            }

            // D-36: confirm-only means we cannot move a date that has passed,
            // and scheduling a cohort into the past is never right.
            if ($locked->hasStaleRequestedDate()) {
                return 'stale_requested_date';
            }

            // D-37: a pre-D-37 batch has no hour span to confirm, exactly as a
            // pre-D-29 batch had no date — reject-and-resubmit.
            $span = $locked->requestedSpan();

            if ($span === []) {
                return 'no_requested_time';
            }

            $scheduledDate = $locked->requested_date->toDateString();

            // BR-23: refuse if any hour of the span has already gone by. Only
            // reachable for a batch requested for TODAY (a past date is caught
            // by the staleness rule above), which is exactly the case D-36
            // still allows through: submitted for today, reviewed after those
            // hours ended. Fanning out here would create appointments at times
            // that have already passed.
            $elapsedHours = $locked->elapsedSpanHours();

            if ($elapsedHours !== []) {
                return ['span_elapsed', $elapsedHours[0]];
            }

            // D-37 hard block. Read under the same lock as the write, so an
            // hour cannot fill between the check and the fan-out. D-91: it
            // counts the students this batch would ADD to each hour — the
            // same pivot rows the fan-out below assigns.
            $fullSlots = $this->schedule->fullSlotsIn(
                $scheduledDate,
                $span,
                $locked->batchRequestStudents()->count(),
                lock: true,
            );

            if ($fullSlots !== []) {
                return ['span_full', $fullSlots[0]];
            }

            // D-54 / D-77 / BR-25: no student on this batch may already have a
            // clinic schedule that day. Submission refuses such a batch, so this only
            // catches one submitted before D-54 or one that raced a booking —
            // read under the same lock, refused on the same reject-and-resubmit
            // terms. The batch itself is left out, or it would clash with its
            // own students.
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
                'reviewed_by' => $director->id,
                'reviewed_at' => now(),
            ]);

            // BR-08 fan-out — since D-61 the ONLY way an appointment is
            // created (FR-DIRA-03).
            //
            // D-37: students are spread across the span, hourly_capacity per
            // hour, in pivot-row id order — a deterministic assignment, so
            // re-running the same batch would always produce the same roster
            // per hour. The last block takes whatever remainder is left.
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

        // D-54: the clash refusal carries the clashing students' user ids.
        if (is_array($outcome) && $outcome[0] === 'span_clash') {
            return redirect()->route('director.batches.index')
                ->with('error', $this->clashMessage($batch, $outcome[1]));
        }

        // Both span refusals carry the offending hour's slot key alongside the reason.
        if (is_array($outcome)) {
            [$reason, $slot] = $outcome;
            $hourLabel = $this->schedule->label($slot);

            $why = $reason === 'span_elapsed'
                ? "the {$hourLabel} slot in its span has already passed"
                : "the {$hourLabel} slot in its span ".$this->schedule->noRoomReason(
                    $batch->requested_date->toDateString(),
                    $slot,
                );

            return redirect()->route('director.batches.index')
                ->with('error', "{$batch->reference_no} cannot be approved — {$why}. Reject it with a reason so the college can resubmit for another date or start time.");
        }

        if ($outcome === 'already_decided') {
            return redirect()->route('director.batches.index')
                ->with('error', $this->unchangedMessage($batch));
        }

        if ($outcome === 'no_requested_date') {
            return redirect()->route('director.batches.index')
                ->with('error', "{$batch->reference_no} has no requested clinic date to confirm — reject it with the reason “predates the requested-date field — please resubmit”.");
        }

        if ($outcome === 'stale_requested_date') {
            $requested = $batch->requested_date->format('M j, Y');

            return redirect()->route('director.batches.index')
                ->with('error', "{$batch->reference_no} was requested for {$requested}, which has already passed — reject it with a reason asking the college to resubmit for a new date.");
        }

        if ($outcome === 'no_requested_time') {
            return redirect()->route('director.batches.index')
                ->with('error', "{$batch->reference_no} has no clinic hour span to confirm — reject it with the reason “predates the hourly-slot change — please resubmit”.");
        }

        // FR-STU-12 (D-39): tell each student their college booked them in.
        //
        // Deliberately OUT HERE, after DB::transaction() has returned — i.e.
        // after the commit. Queueing from inside would mean an SMTP or queue
        // failure rolling back real appointments, and a worker could pick a job
        // up before the appointment row was visible to its connection. Any
        // early return above (already decided, stale date, full or elapsed
        // hour, or a mid-fan-out exception) never reaches this line, so a batch
        // that was not approved emails nobody.
        //
        // The appointments are re-read rather than carried out of the closure:
        // approval is terminal (a decided batch can never be re-approved), so
        // this query can only ever return the rows the fan-out above just made.
        //
        // One job per student — a single bad address loses one email, not 59.
        $appointments = Appointment::where('batch_request_id', $batch->id)->get();

        foreach ($appointments as $appointment) {
            SendAppointmentScheduledMail::dispatch($appointment);
        }

        $studentCount = $appointments->count();

        return redirect()->route('director.batches.index')
            ->with('status', "{$batch->reference_no} approved — {$studentCount} appointment(s) created.");
    }

    /**
     * Reject a pending batch (FR-DIRA-04): status → rejected + reviewer
     * stamps + the written reason (D-36), and NOTHING else — no
     * appointments, no scheduled_date.
     *
     * The reason is validated by RejectBatchRequest before we get here, so
     * the reject path can never write a blank one. The College Admin reads
     * it back on Batch Tracking (FR-ADM-05).
     *
     * Same lock-and-recheck guard as approve() (FR-DIRA-05): the row is
     * re-read with lockForUpdate() inside the transaction, so a reject
     * racing an approve (or a duplicate reject) waits for the first commit
     * and then no-ops on the non-pending status — which also means a replayed
     * POST can never overwrite the reason already on record. A decision is
     * terminal in both directions.
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
     * "BR-2026-004 cannot be approved — 4 student(s) are already scheduled
     * that day: A, B, C and 1 more. Reject it …" (D-54, D-77).
     *
     * At most three names, so a 60-student clash still reads as one line; the
     * Director only needs enough to explain the rejection, and the College
     * Admin's resubmission shows the full list in its own popup.
     *
     * @param  list<int>  $studentUserIds
     */
    private function clashMessage(BatchRequest $batch, array $studentUserIds): string
    {
        $names = User::whereIn('id', $studentUserIds)->orderBy('name')->pluck('name');
        $more = $names->count() - 3;

        return "{$batch->reference_no} cannot be approved — ".count($studentUserIds)
            .' student(s) are already scheduled that day: '
            .$names->take(3)->implode(', ')
            .($more > 0 ? " and {$more} more" : '')
            .'. Reject it with a reason so the college can resubmit.';
    }

    /**
     * Why a decision POST changed nothing, worded for the state it actually
     * found (D-52).
     *
     * Both approve() and reject() re-read the row under a lock and no-op when
     * it is no longer pending — but "already decided" is only true when a
     * DIRECTOR decided it. Since D-52 a College Admin can cancel their own
     * pending request (FR-ADM-11), and telling the Director they decided that
     * would credit them with an action they never took.
     *
     * refresh() because $batch still holds the row as the Approvals page
     * rendered it — that stale copy is exactly what let the POST through.
     */
    private function unchangedMessage(BatchRequest $batch): string
    {
        return $batch->refresh()->status === 'cancelled'
            ? "{$batch->reference_no} was cancelled by the college — nothing was changed."
            : "{$batch->reference_no} has already been decided — nothing was changed.";
    }
}
