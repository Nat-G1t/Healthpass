<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\ClinicBatchApprovedMail;
use App\Models\BatchRequest;
use App\Models\User;
use Illuminate\Mail\Mailable;

/**
 * D-137 — tell ONE nurse or physician that the Director approved a batch
 * (the panel's "the clinic should know when a schedule is approved").
 *
 * Dispatched once per active clinic staff account (User::activeClinicStaff())
 * by the Director's approval, after its transaction commits — the same shape
 * as the students' scheduling emails, so one bad address loses one email.
 * Retries, the recipient and failure logging live in [[StaffMailJob]].
 *
 * $studentCount is fixed at dispatch: the seats the approval just created.
 */
class SendClinicBatchApprovedMail extends StaffMailJob
{
    /** Not readonly, for the same queue-hydration reason as StaffMailJob::$staff. */
    public BatchRequest $batch;

    public int $studentCount;

    public function __construct(User $staff, BatchRequest $batch, int $studentCount)
    {
        parent::__construct($staff);

        $this->batch = $batch;
        $this->studentCount = $studentCount;
    }

    protected function mailable(): Mailable
    {
        return new ClinicBatchApprovedMail($this->staff, $this->batch, $this->studentCount);
    }

    protected function description(): string
    {
        return 'batch approval notice';
    }

    /**
     * Still clinic staff, and the batch still approved. A batch cancelled
     * while this sat on the queue is not announced as coming — its
     * cancellation notice says what happened instead.
     */
    protected function isStillRelevant(): bool
    {
        return parent::isStillRelevant()
            && $this->staff->isClinicStaff()
            && $this->batch->status === 'approved';
    }
}
