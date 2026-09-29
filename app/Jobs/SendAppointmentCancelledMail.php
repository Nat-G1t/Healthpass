<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\AppointmentCancelledMail;
use Illuminate\Mail\Mailable;

/**
 * D-92 — tell ONE student their college cancelled the approved batch their
 * appointment belonged to, and why.
 *
 * The closing half of FR-STU-12: the student was emailed when the Director
 * approved the batch, and cannot cancel a batch appointment themselves, so
 * without this they would turn up to a seat that no longer exists.
 *
 * Everything about retries, recipient resolution and failure logging lives in
 * [[AppointmentMailJob]]; only the three hooks below are specific to this
 * message.
 */
class SendAppointmentCancelledMail extends AppointmentMailJob
{
    protected function mailable(): Mailable
    {
        return new AppointmentCancelledMail($this->appointment);
    }

    /**
     * Only ever send about an appointment that really is cancelled. A batch
     * cancel is terminal, so this cannot normally be false — it is here so a
     * stale job on the queue can never tell a student a live appointment was
     * cancelled.
     */
    protected function isStillRelevant(): bool
    {
        return $this->appointment->status === 'cancelled';
    }

    protected function description(): string
    {
        return 'batch cancellation notice';
    }
}
