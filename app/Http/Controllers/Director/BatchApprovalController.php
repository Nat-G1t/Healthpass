<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Http\Requests\Director\ApproveBatchRequest;
use App\Http\Requests\Director\RejectBatchRequest;
use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Services\ReferenceNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Batch Approvals (Module DIRA).
 *
 * Unlike the /admin pages there is NO college scope here — the Director
 * reviews every college's requests on one screen (FR-DIRA-01).
 *
 * capacity() feeds the approve modal's warning line (FR-DIRA-06): the
 * Director is WARNED when the requested date is at/over the daily cap, but
 * never blocked — an approved cohort may exceed the self-booking capacity.
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
    /** All colleges' batch requests, newest first (FR-DIRA-01). */
    public function index(): View
    {
        $batchRequests = BatchRequest::with('college')
            ->withCount('batchRequestStudents')
            ->latest()
            ->get();

        return view('director.batches.index', compact('batchRequests'));
    }

    /**
     * JSON: non-cancelled appointment count on one date vs the daily cap.
     * Polled by the approve modal whenever the Director picks a date.
     */
    public function capacity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        // Same counting rule as self-booking (BR-02): cancelled slots are free.
        $booked = Appointment::whereDate('scheduled_date', $validated['date'])
            ->where('status', '!=', 'cancelled')
            ->count();

        return response()->json([
            'booked' => $booked,
            'capacity' => (int) config('healthpass.daily_capacity'),
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
     * Capacity is deliberately not checked here (FR-DIRA-06): batch
     * approval may exceed the daily cap; the modal only warns.
     */
    public function approve(
        ApproveBatchRequest $request,
        BatchRequest $batch,
        ReferenceNumberService $refService,
    ): RedirectResponse {
        $director = $request->user();

        // 'approved' | 'already_decided' | 'no_requested_date' | 'stale_requested_date'
        $outcome = DB::transaction(function () use ($batch, $director, $refService): string {
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

            $scheduledDate = $locked->requested_date->toDateString();

            $locked->update([
                'status' => 'approved',
                'scheduled_date' => $scheduledDate,
                'reviewed_by' => $director->id,
                'reviewed_at' => now(),
            ]);

            // BR-08 fan-out. Downstream these are indistinguishable from
            // self-booked appointments (FR-DIRA-03) apart from source/creator.
            foreach ($locked->batchRequestStudents as $pivotRow) {
                $appointment = Appointment::create([
                    'reference_no' => $refService->generateAppointmentRef(),
                    'student_id' => $pivotRow->student_id,
                    'service_type' => $locked->service_type,
                    'scheduled_date' => $scheduledDate,
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
                ->with('error', "{$batch->reference_no} has already been decided — nothing was changed.");
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

        $studentCount = $batch->batchRequestStudents()->count();

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
                ->with('error', "{$batch->reference_no} has already been decided — nothing was changed.");
        }

        return redirect()->route('director.batches.index')
            ->with('status', "{$batch->reference_no} rejected — no appointments were created.");
    }
}
