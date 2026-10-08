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
 * FR-NRS-01/02 — the clinic's Live Queue.
 *
 * D-126: two queues behind one page — each clinic role sees only its own
 * (ClinicVisit::scopeLiveQueue): the nurse, the captured Medical Assessment
 * visits waiting for the interview; the physician, the captured Medical
 * Clearance visits plus every visit the nurse submitted (`interviewed`).
 * Oldest first (first come, first served into THAT queue): the top row is the
 * longest-waiting student and is tagged "NEXT".
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
        $viewer = $request->user();
        $visits = ClinicVisit::liveQueue($viewer)->get();

        // "Reverse-stack" ghost row (motion pass §6.1b): after Save & Close the
        // visit is already `encoded` (after the nurse's Submit to physician,
        // `interviewed` — D-126), so it is no longer in the queue query by
        // the time the user lands back here — its row would just be silently
        // gone. The controller flashes the id for exactly one request; we
        // re-fetch that visit and hand it to the view separately so it can be
        // rendered once, in its original position, marked `data-leaving`, and
        // the front-end animates it out (fade + rows sliding up).
        //
        // Fail-safe: an unknown id, or one that has not actually left a queue,
        // simply yields no ghost — never an error.
        $ghost = null;
        $ghostIndex = 0;
        $leftId = $request->session()->get('left_queue_visit_id');
        if ($leftId !== null) {
            $ghost = ClinicVisit::query()
                ->whereIn('status', ['interviewed', 'encoded'])
                ->with(['student:id,name', 'college:id,name', 'vitalSigns', 'appointment:id,batch_request_id', 'appointment.batchRequest:id,form_type'])
                ->find($leftId);
        }
        if ($ghost !== null) {
            // Original FCFS slot: how many still-waiting visits entered this
            // queue before the ghost did (same ordering as scopeLiveQueue). In
            // the nurse's queue that is the kiosk check-in; in the physician's,
            // the nurse's submit time when there is one.
            $queuedAt = fn (ClinicVisit $visit) => ($viewer->isPhysician() ? $visit->interviewed_at : null)
                ?? $visit->checked_in_at ?? $visit->created_at;
            $ghostAnchor = $queuedAt($ghost);
            $ghostIndex = $visits
                ->takeWhile(function (ClinicVisit $visit) use ($ghostAnchor, $ghost, $queuedAt): bool {
                    $anchor = $queuedAt($visit);

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
     * at the bottom, visits that left drop out — with no full-page reload.
     */
    public function feed(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $visits = ClinicVisit::liveQueue($viewer)->get();
        $actionLabel = ClinicVisit::queueActionLabel($viewer);

        return response()->json([
            'count' => $visits->count(),
            'visits' => $visits->map(fn (ClinicVisit $visit) => $this->toRow($visit, $actionLabel))->all(),
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
     * Only a visit still WAITING in the remover's OWN queue can be removed
     * (D-126: each role removes only from its own queue). The status is
     * re-read under a row lock, so a colleague who submitted or encoded this
     * visit a moment ago wins, and an encoded record can never be deleted.
     *
     * D-126: an `interviewed` visit also has the nurse's `medical_assessments`
     * row, whose foreign key RESTRICTS the visit's delete — so it is deleted
     * explicitly first, inside the same transaction.
     */
    public function remove(Request $request, ClinicVisit $visit): RedirectResponse
    {
        $removed = DB::transaction(function () use ($request, $visit): bool {
            $locked = ClinicVisit::whereKey($visit->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isInQueueOf($request->user())) {
                return false;
            }

            $locked->medicalAssessment()->delete();
            $locked->delete();

            return true;
        });

        $name = $visit->student->name ?? 'The student';

        if (! $removed) {
            return redirect()->route('nurse.queue')
                ->with('error', "{$name} is no longer waiting in your queue, so nothing was removed.");
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
    private function toRow(ClinicVisit $visit, string $actionLabel): array
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
                // D-66. No is_rr_flagged: the respiratory rate is measured by
                // the clinic (D-65), so the queue never shows one.
                'is_hr_flagged' => (bool) $vs->is_hr_flagged,
            ] : null,
            'time_human' => $capturedAt?->diffForHumans(),
            // Where the row's action link points (FR-NRS-03) — built
            // server-side so the JS poller never guesses the route shape.
            'encode_url' => route('nurse.visits.encode', $visit),
            // D-126: "Interview" in the nurse's queue, "Encode Result" in the
            // physician's — the same page, a different job.
            'action_label' => $actionLabel,
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
