<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BatchRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 * approve()/reject() are STUBS for now: the real decision flow
 * (FR-DIRA-02/04 — one transaction, appointment fan-out, reviewer stamps)
 * lands next. Today they only round-trip a flash so the UI is testable.
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

    /** STUB — FR-DIRA-02 (transaction + appointment fan-out) is next. */
    public function approve(BatchRequest $batch): RedirectResponse
    {
        return redirect()->route('director.batches.index')
            ->with('status', "Approve for {$batch->reference_no} isn't wired up yet — no changes were made.");
    }

    /** STUB — FR-DIRA-04 (reject + reviewer stamps) is next. */
    public function reject(BatchRequest $batch): RedirectResponse
    {
        return redirect()->route('director.batches.index')
            ->with('status', "Reject for {$batch->reference_no} isn't wired up yet — no changes were made.");
    }
}
