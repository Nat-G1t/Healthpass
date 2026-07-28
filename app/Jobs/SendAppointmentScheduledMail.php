<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\AppointmentScheduledMail;
use Illuminate\Mail\Mailable;

/**
 * FR-STU-12 (D-39) — deliver ONE student's "your appointment is scheduled"
 * email. Dispatched after the creating transaction commits, from both the
 * student's own booking and the Director's batch approval fan-out.
 *
 * Everything about retries, recipient resolution and failure logging lives in
 * [[AppointmentMailJob]]; only the three hooks below are specific to this
 * message.
 */
class SendAppointmentScheduledMail extends AppointmentMailJob
{
    protected function mailable(): Mailable
    {
        return new AppointmentScheduledMail($this->appointment);
    }

    /**
     * A student who cancelled — or was withdrawn by their college — while this
     * job sat on the queue must not then be told their appointment is
     * confirmed. They get the withdrawal notice instead (FR-STU-13).
     */
    protected function isStillRelevant(): bool
    {
        return $this->appointment->status === 'scheduled';
    }

    protected function description(): string
    {
        return 'scheduling notice';
    }
}
