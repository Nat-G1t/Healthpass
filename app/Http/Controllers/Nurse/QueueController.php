<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Models\ClinicVisit;
use Illuminate\View\View;

/**
 * FR-NRS-01 — Nurse Live Queue.
 *
 * Lists every clinic visit still awaiting encoding (status = 'captured'),
 * oldest first (first come, first served): the top row is the longest-waiting
 * student and is tagged "NEXT". Encoded visits have already left the queue.
 *
 * Polling (FR-NRS-02) lands tomorrow; this first pass is server-rendered on
 * page load. The flag booleans and BMI shown here are the SERVER-computed
 * values frozen at kiosk submit (SubmitKioskVisit) — never recomputed client
 * side — so the queue is trustworthy without trusting the browser.
 */
class QueueController extends Controller
{
    public function __invoke(): View
    {
        $visits = ClinicVisit::query()
            ->where('status', 'captured')
            ->with(['student:id,name', 'college:id,name', 'vitalSigns'])
            // FCFS: the earliest check-in is next in line. `id` breaks ties for
            // two visits captured in the same second (deterministic order).
            ->orderBy('checked_in_at')
            ->orderBy('id')
            ->get();

        return view('nurse.queue', compact('visits'));
    }
}
