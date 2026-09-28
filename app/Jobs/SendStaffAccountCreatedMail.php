<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\StaffAccountCreatedMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\Mailable;

/**
 * D-50 — tell a newly provisioned staff member their HealthPass account exists
 * and where to sign in (FR-AUTH-10).
 *
 * Without it, an account created by the Director is invisible until somebody
 * remembers to tell the person by hand — which on a hosted deployment means the
 * account may sit unused for weeks.
 *
 * D-85: IT CARRIES THE ONE-TIME PASSWORD. The password now goes to the new
 * staff member's own mailbox and the Director never sees it (superseding the
 * show-once screen of D-35/D-47). RequirePasswordChange still forces it to be
 * replaced at first sign-in, so it stays a one-use credential.
 *
 * ShouldBeEncrypted: a queued job is written to the `jobs` table (and, if every
 * retry fails, to `failed_jobs`) until the worker sends it. This interface
 * makes Laravel encrypt that stored payload with APP_KEY, so the password is
 * never sitting in the database as readable text while the mail waits.
 */
class SendStaffAccountCreatedMail extends StaffMailJob implements ShouldBeEncrypted
{
    /** Not readonly, for the same queue-hydration reason as StaffMailJob::$staff. */
    public string $oneTimePassword;

    public function __construct(User $staff, string $oneTimePassword)
    {
        parent::__construct($staff);

        $this->oneTimePassword = $oneTimePassword;
    }

    protected function mailable(): Mailable
    {
        return new StaffAccountCreatedMail($this->staff, $this->oneTimePassword);
    }

    protected function description(): string
    {
        return 'welcome notice';
    }
}
