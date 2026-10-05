<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\SecondChanceScheduledMail;
use Illuminate\Mail\Mailable;

/**
 * D-110 — deliver ONE student's "your 2nd chance" email. Dispatched by the
 * healthpass:second-chances command after each batch's booking has committed.
 *
 * Retries, recipient resolution and failure logging live in
 * [[AppointmentMailJob]]; only the three hooks below belong to this message.
 */
class SendSecondChanceMail extends AppointmentMailJob
{
    protected function mailable(): Mailable
    {
        return new SecondChanceScheduledMail($this->appointment);
    }

    /** A 2nd chance that stopped being `scheduled` on the queue is not announced. */
    protected function isStillRelevant(): bool
    {
        return $this->appointment->status === 'scheduled';
    }

    protected function description(): string
    {
        return 'second-chance notice';
    }
}
