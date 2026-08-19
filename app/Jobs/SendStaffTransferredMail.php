<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\StaffTransferredMail;
use App\Models\College;
use App\Models\User;
use Illuminate\Mail\Mailable;

/**
 * D-50 — tell a College Admin that the Director moved them to another college
 * (FR-AUTH-10).
 *
 * Being moved silently is disorienting in a way the other staff changes are
 * not: everything the admin looks at is suddenly a different college's data,
 * with no explanation on screen for why their students vanished.
 *
 * Both colleges are passed in and FIXED at dispatch, rather than re-read from
 * the account when the worker runs. If the Director moves the same admin again
 * while this job is still queued, this message must still describe the move it
 * was dispatched for — re-reading would make it describe the newer one twice.
 */
class SendStaffTransferredMail extends StaffMailJob
{
    public College $from;

    public College $to;

    public function __construct(User $staff, College $from, College $to)
    {
        parent::__construct($staff);

        $this->from = $from;
        $this->to = $to;
    }

    protected function mailable(): Mailable
    {
        return new StaffTransferredMail($this->staff, $this->from, $this->to);
    }

    protected function description(): string
    {
        return 'transfer notice';
    }

    /**
     * Narrower than the base check: a transfer notice only makes sense for an
     * account that is still an active College Admin. If the role changed, the
     * message would describe a scope the account no longer has.
     */
    protected function isStillRelevant(): bool
    {
        return parent::isStillRelevant() && $this->staff->role === 'college_admin';
    }
}
