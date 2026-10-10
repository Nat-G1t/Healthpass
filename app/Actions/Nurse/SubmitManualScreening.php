<?php

declare(strict_types=1);

namespace App\Actions\Nurse;

use App\Actions\Kiosk\SubmitKioskVisit;
use App\Models\Appointment;
use App\Models\ClinicVisit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * D-133 — Manual Screening: the nurse does the kiosk's job by hand when the
 * kiosk is down, then hands the student to the physician.
 *
 * The visit is written by the kiosk's own persistence
 * (SubmitKioskVisit::recordVisit()), so it is an ordinary submitted visit —
 * same snapshot, reference number, server-computed BMI and flags — marked
 * `login_method = manual` and `entry_method = manual`. There is no rest &
 * re-check (README default): a flagged reading goes straight through,
 * flagged as usual.
 *
 *  - Medical Clearance → `captured`, which is the PHYSICIAN's queue (D-126).
 *  - Medical Assessment → `interviewed` with the nurse's interview written in
 *    the same transaction (the medical_assessments row + the respiratory
 *    rate), exactly what InterviewController leaves behind, so it too lands
 *    in the physician's queue. The queues are never bypassed.
 */
final class SubmitManualScreening
{
    public function __construct(private SubmitKioskVisit $kiosk) {}

    /**
     * @param  array  $screening  The validated vitals / screening / socialHistory.
     * @param  ?array  $interview  Medical Assessment only: ['sections' => the
     *                             medical_assessments attributes, 'vitals' =>
     *                             the interview_vitals, respiratory rate included].
     * @return ?ClinicVisit The new visit, or null when the appointment stopped
     *                      being screenable meanwhile (the kiosk got there
     *                      first, or the batch was cancelled) — nothing written.
     */
    public function handle(Appointment $appointment, User $nurse, array $screening, ?array $interview): ?ClinicVisit
    {
        return DB::transaction(function () use ($appointment, $nurse, $screening, $interview): ?ClinicVisit {
            // The kiosk's submit and the Director / college cancel hold this
            // same row lock, so once we hold it, the re-check below is final:
            // a kiosk visit written a moment ago is seen, and no new one can
            // start until this transaction ends.
            $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isManuallyScreenable()) {
                return null;
            }

            // D-62: the form decides the queue — re-read on the server from
            // the locked appointment's batch, never from the request.
            $isAssessment = $locked->load('batchRequest')->formType() === 'assessment';

            $visit = $this->kiosk->recordVisit($locked, [
                ...$screening,
                'vitalMethods' => ['manual'],
                'bpReading' => null,
            ], [
                'login_method' => 'manual',
                'status' => $isAssessment ? 'interviewed' : 'captured',
                'interviewed_by' => $isAssessment ? $nurse->id : null,
                'interviewed_at' => $isAssessment ? now() : null,
            ]);

            if ($isAssessment) {
                $visit->medicalAssessment()->create([
                    ...$interview['sections'],
                    'interview_vitals' => $interview['vitals'],
                ]);

                $visit->vitalSigns->recordRespiratoryRate($interview['vitals']['respiratory_rate']);
            }

            return $visit;
        });
    }

    /**
     * Why $appointment can't be screened by hand — the sentence the nurse
     * reads back on the search page. A visit typed in here a moment ago (a
     * double click, or a colleague) is told apart from a kiosk visit.
     */
    public static function refusalFor(Appointment $appointment): string
    {
        $name = $appointment->student?->name ?? 'That student';

        $visitToday = ClinicVisit::where('student_id', $appointment->student_id)
            ->whereDate('checked_in_at', today())
            ->latest('id')
            ->first();

        return match (true) {
            $visitToday === null => "{$name} is no longer on today's clinic schedule.",
            $visitToday->login_method === 'manual' => "{$name} was already screened by hand — {$visitToday->reference_no}.",
            default => "{$name} has just been screened at the kiosk.",
        };
    }
}
