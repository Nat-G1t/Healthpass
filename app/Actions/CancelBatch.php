<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\BatchNotCancellable;
use App\Jobs\SendAppointmentCancelledMail;
use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a whole batch, with a written reason (FR-ADM-11, D-52, D-92, D-129).
 *
 * ONE place for the cancel, used by the College Admin's Cancel
 * (Admin\BatchRequestController) and, since D-129, the Clinic Director's
 * (Director\BatchTrackingController) — so both follow the same rule, the same
 * locks and the same emails. An "action" is just a class that does one job;
 * the controllers stay in charge of who may press the button and where the
 * page goes next.
 *
 * Guards, all resolved server-side:
 *   - BatchRequest::isCancellable() — the SAME rule the page used to decide
 *     whether to draw the button — is re-read under a ROW LOCK, so a
 *     double-click, or a race with the Director approving it in the next tab,
 *     cannot slip past the unlocked read the page did
 *   - the batch's appointment rows are locked too (D-92): the kiosk locks the
 *     appointment it submits against (SubmitKioskVisit), so a student
 *     finishing the kiosk right now either commits their visit before
 *     hasKioskVisit() reads it, or waits for this and then finds their
 *     appointment cancelled
 *
 * Cancelling an APPROVED batch cancels its still-scheduled appointments —
 * which frees their seats, since every capacity count skips cancelled rows —
 * and emails each student the reason, so nobody turns up to a seat that no
 * longer exists. A pending batch has no appointments and emails nobody.
 */
final class CancelBatch
{
    /**
     * @param  User  $by  who pressed the button — stamped as `cancelled_by`
     * @return int how many appointments were cancelled (0 for a pending batch)
     *
     * @throws BatchNotCancellable when the locked row may no longer be cancelled
     */
    public function handle(BatchRequest $batch, User $by, string $reason): int
    {
        $cancelledIds = DB::transaction(function () use ($batch, $by, $reason): array {
            $locked = BatchRequest::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $locked->appointments()->lockForUpdate()->get(['id']);

            if (! $locked->isCancellable()) {
                throw new BatchNotCancellable(self::refusal($locked));
            }

            $wasApproved = $locked->status === 'approved';

            // cancelled_by is the person who PRESSED THE BUTTON, not the
            // batch's original requester — a college can have more than one
            // admin (D-47), and the Director can cancel too (D-129). The
            // Activity Log and every "Cancelled by …" label read it.
            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancellation_reason' => $reason,
            ]);

            if (! $wasApproved) {
                return [];
            }

            // Only the still-scheduled ones: a seat freed by an earlier
            // withdrawal (D-87) was emailed about back then.
            $ids = $locked->appointments()->where('status', 'scheduled')->pluck('id')->all();
            Appointment::whereKey($ids)->update(['status' => 'cancelled']);

            return $ids;
        });

        // Queued AFTER the commit, one job per student (the approve fan-out's
        // shape): a single bad address loses one email, not the whole batch.
        foreach (Appointment::whereKey($cancelledIds)->get() as $appointment) {
            SendAppointmentCancelledMail::dispatch($appointment);
        }

        return count($cancelledIds);
    }

    /** D-92: why a cancel was refused, worded for the state the locked row is in. */
    private static function refusal(BatchRequest $batch): string
    {
        $ref = $batch->reference_no;

        return match (true) {
            $batch->status === 'approved' && $batch->hasKioskVisit() => "{$ref} can no longer be cancelled — a student from this batch has already used the clinic kiosk.",
            $batch->status === 'approved' => "{$ref} can no longer be cancelled — its first clinic hour has already started.",
            $batch->status === 'cancelled' => "{$ref} has already been cancelled.",
            default => "{$ref} can no longer be cancelled — the Clinic Director has already rejected it.",
        };
    }
}
