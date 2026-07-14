<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Http\Requests\Director\ApproveBatchRequest;
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
 * Director is WARNED when the chosen date is at/over the daily cap, but
 * never blocked — an approved cohort may exceed the self-booking capacity.
 *
 * approve() is the real decision flow (FR-DIRA-02, BR-08): one DB
 * transaction that flips the batch, stamps the reviewer fields, fans out
 * one appointment per listed student, and back-writes each appointment_id
 * onto its pivot row. reject() is still a STUB — FR-DIRA-04 lands next.
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
        $scheduledDate = $request->validated('scheduled_date');
        $director = $request->user();

        $wasApproved = DB::transaction(function () use ($batch, $scheduledDate, $director, $refService): bool {
            $locked = BatchRequest::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            // A decided batch cannot be re-decided (FR-DIRA-05).
            if ($locked->status !== 'pending') {
                return false;
            }

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

            return true;
        });

        if (! $wasApproved) {
            return redirect()->route('director.batches.index')
                ->with('error', "{$batch->reference_no} has already been decided — nothing was changed.");
        }

        $studentCount = $batch->batchRequestStudents()->count();

        return redirect()->route('director.batches.index')
            ->with('status', "{$batch->reference_no} approved — {$studentCount} appointment(s) created.");
    }

    /** STUB — FR-DIRA-04 (reject + reviewer stamps) is next. */
    public function reject(BatchRequest $batch): RedirectResponse
    {
        return redirect()->route('director.batches.index')
            ->with('status', "Reject for {$batch->reference_no} isn't wired up yet — no changes were made.");
    }
}
