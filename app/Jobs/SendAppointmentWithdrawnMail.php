<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\AppointmentWithdrawnMail;
use Illuminate\Mail\Mailable;

/**
 * FR-STU-13 (D-41) — tell ONE student their college withdrew the appointment
 * it had booked for them.
 *
 * The closing half of the D-39 / D-40 pair: the student was emailed when the
 * college booked them (FR-STU-12) and cannot cancel a batch appointment
 * themselves (FR-STU-06), so when the College Admin withdraws it they would
 * otherwise turn up to a seat that no longer exists.
 *
 * Everything about retries, recipient resolution and failure logging lives in
 * [[AppointmentMailJob]]; only the three hooks below are specific to this
 * message.
 */
class SendAppointmentWithdrawnMail extends AppointmentMailJob
{
    protected function mailable(): Mailable
    {
        return new AppointmentWithdrawnMail($this->appointment);
    }

    /**
     * Only ever send about an appointment that really is cancelled. Withdrawal
     * is terminal today, so this cannot normally be false — it is here so that
     * if a row is ever revived, a stale job on the queue does not tell the
     * student their live appointment was withdrawn.
     */
    protected function isStillRelevant(): bool
    {
        return $this->appointment->status === 'cancelled';
    }

    protected function description(): string
    {
        return 'withdrawal notice';
    }
}
