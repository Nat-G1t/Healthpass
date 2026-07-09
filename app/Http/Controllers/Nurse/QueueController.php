<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Models\ClinicVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * FR-NRS-01/02 — Nurse Live Queue.
 *
 * Lists every clinic visit still awaiting encoding (status = 'captured'),
 * oldest first (first come, first served): the top row is the longest-waiting
 * student and is tagged "NEXT". Encoded visits have already left the queue.
 *
 * `index()` server-renders the queue on page load; `feed()` returns the same
 * rows as lean JSON for the front-end poll (every 4 s, FR-NRS-02) that updates
 * the table in place. Both read `ClinicVisit::liveQueue()` so they can never
 * disagree on which rows are shown or in what order.
 *
 * The flag booleans and BMI shown here are the SERVER-computed values frozen at
 * kiosk submit (SubmitKioskVisit) — never recomputed client side — so the queue
 * is trustworthy without trusting the browser.
 */
class QueueController extends Controller
{
    /** Server-rendered queue (initial paint). */
    public function index(): View
    {
        $visits = ClinicVisit::liveQueue()->get();

        return view('nurse.queue', compact('visits'));
    }

    /**
     * JSON feed for the 4 s poll (FR-NRS-02, SM-2).
     *
     * Lean by design: only the fields a row needs to render, oldest first.
     * The front-end keys rows by `id` to update in place — new arrivals append
     * at the bottom, encoded visits drop out — with no full-page reload.
     */
    public function feed(): JsonResponse
    {
        $visits = ClinicVisit::liveQueue()->get();

        return response()->json([
            'count' => $visits->count(),
            'visits' => $visits->map(fn (ClinicVisit $visit) => $this->toRow($visit))->all(),
        ]);
    }

    /**
     * Shape one visit into the lean row payload the queue table renders.
     * Mirrors the columns of the server-rendered table in nurse/queue.blade.php.
     *
     * @return array<string, mixed>
     */
    private function toRow(ClinicVisit $visit): array
    {
        $vs = $visit->vitalSigns;
        $capturedAt = $visit->checked_in_at ?? $visit->created_at;

        return [
            'id' => $visit->id,
            'reference_no' => $visit->reference_no,
            'name' => $visit->student->name ?? '—',
            'initials' => $this->initials($visit->student->name ?? ''),
            'college' => $visit->college->name ?? '—',
            // Vitals summary + flags are the server-frozen values (never client-recomputed).
            'vitals' => $vs ? [
                'temperature_c' => $vs->temperature_c,
                'bp_systolic' => $vs->bp_systolic,
                'bp_diastolic' => $vs->bp_diastolic,
                'bmi' => $vs->bmi,
                'heart_rate_bpm' => $vs->heart_rate_bpm,
                'is_temp_flagged' => (bool) $vs->is_temp_flagged,
                'is_bp_flagged' => (bool) $vs->is_bp_flagged,
                'is_bmi_flagged' => (bool) $vs->is_bmi_flagged,
            ] : null,
            'time_human' => $capturedAt?->diffForHumans(),
        ];
    }

    /** First letters of up to the first two words, upper-cased (avatar chip). */
    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))
            ->take(2)
            ->implode('');
    }
}
