<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Models\ClinicVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    public function index(Request $request): View
    {
        $visits = ClinicVisit::liveQueue()->get();

        // "Reverse-stack" ghost row (motion pass §6.1b): after Save & Close the
        // visit is already `encoded`, so it is no longer in the queue query by
        // the time the nurse lands back here — its row would just be silently
        // gone. EncodeController flashes the id for exactly one request; we
        // re-fetch that visit and hand it to the view separately so it can be
        // rendered once, in its original position, marked `data-leaving`, and
        // the front-end animates it out (fade + rows sliding up).
        //
        // Fail-safe: an unknown id, or one that is not actually encoded, simply
        // yields no ghost — never an error.
        $ghost = null;
        $ghostIndex = 0;
        $encodedId = $request->session()->get('encoded_visit_id');
        if ($encodedId !== null) {
            $ghost = ClinicVisit::query()
                ->where('status', 'encoded')
                ->with(['student:id,name', 'college:id,name', 'vitalSigns', 'appointment:id,batch_request_id', 'appointment.batchRequest:id,form_type'])
                ->find($encodedId);
        }
        if ($ghost !== null) {
            // Original FCFS slot: how many still-waiting visits checked in
            // before the ghost did (same ordering as scopeLiveQueue).
            $ghostAnchor = $ghost->checked_in_at ?? $ghost->created_at;
            $ghostIndex = $visits
                ->takeWhile(function (ClinicVisit $visit) use ($ghostAnchor, $ghost): bool {
                    $anchor = $visit->checked_in_at ?? $visit->created_at;

                    return $anchor < $ghostAnchor
                        || ($anchor == $ghostAnchor && $visit->id < $ghost->id);
                })
                ->count();
        }

        return view('nurse.queue', compact('visits', 'ghost', 'ghostIndex'));
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
     * Remove a student from the Live Queue (D-93).
     *
     * A student finishes the kiosk, lands in the queue, and then leaves the
     * clinic without being seen. Before D-93 their row sat at the top of the
     * queue for the rest of the day with no way to clear it.
     *
     * The visit is DELETED, not flagged: its vital signs and screening answers
     * go with it (both tables cascade on delete). Nothing was ever encoded, so
     * no clearance record or printed document refers to it. The student's
     * appointment is untouched and has no submitted visit any more, so
     * Appointment::todayFor() offers it again — if they come back, they simply
     * go through the kiosk again.
     *
     * Only a `captured` visit can be removed — one still WAITING in the queue.
     * The status is re-read under a row lock, so a colleague who encoded this
     * visit a moment ago wins, and an encoded record can never be deleted.
     */
    public function remove(ClinicVisit $visit): RedirectResponse
    {
        $removed = DB::transaction(function () use ($visit): bool {
            $locked = ClinicVisit::whereKey($visit->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'captured') {
                return false;
            }

            $locked->delete();

            return true;
        });

        $name = $visit->student->name ?? 'The student';

        if (! $removed) {
            return redirect()->route('nurse.queue')
                ->with('error', "{$name} is no longer waiting in the queue, so nothing was removed.");
        }

        return redirect()->route('nurse.queue')
            ->with('status', "{$name} was removed from the queue. If they come back, they can go through the kiosk again.");
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
            // D-62: which official form this student is on ('clearance' |
            // 'assessment'); the row shows it as a small badge.
            'form_type' => $visit->formType(),
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
                // D-66. No is_rr_flagged: the respiratory rate is measured at
                // encode (D-65), so the queue never has one to show.
                'is_hr_flagged' => (bool) $vs->is_hr_flagged,
            ] : null,
            'time_human' => $capturedAt?->diffForHumans(),
            // Where the row's "Encode Result" link points (FR-NRS-03) — built
            // server-side so the JS poller never guesses the route shape.
            'encode_url' => route('nurse.visits.encode', $visit),
            // D-93: where the row's Remove confirmation posts, for the same reason.
            'remove_url' => route('nurse.visits.remove', $visit),
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
