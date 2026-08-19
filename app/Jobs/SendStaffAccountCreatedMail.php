<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\StaffAccountCreatedMail;
use Illuminate\Mail\Mailable;

/**
 * D-50 — tell a newly provisioned staff member their HealthPass account exists
 * and where to sign in (FR-AUTH-10).
 *
 * Without it, an account created by the Director is invisible until somebody
 * remembers to tell the person by hand — which on a hosted deployment means the
 * account may sit unused for weeks.
 *
 * IT CARRIES NO PASSWORD. The one-time password is shown on the Director's
 * screen exactly once and handed over through official channels (D-35, D-47).
 * Emailing it would put a live credential in a mailbox and in every relay
 * between here and there, which is the whole thing that rule exists to prevent.
 * The message says so explicitly, so a future mail asking for the password
 * reads as the phishing attempt it would be.
 */
class SendStaffAccountCreatedMail extends StaffMailJob
{
    protected function mailable(): Mailable
    {
        return new StaffAccountCreatedMail($this->staff);
    }

    protected function description(): string
    {
        return 'welcome notice';
    }
}
