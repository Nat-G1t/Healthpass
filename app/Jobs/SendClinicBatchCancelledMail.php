<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\ClinicBatchCancelledMail;
use App\Models\BatchRequest;
use App\Models\User;
use Illuminate\Mail\Mailable;

/**
 * D-137 — tell ONE nurse or physician that an APPROVED batch was cancelled,
 * by its college (FR-ADM-11) or the Director (D-129). A pending batch never
 * reached the clinic, so cancelling one sends nothing.
 *
 * Dispatched once per active clinic staff account by App\Actions\CancelBatch,
 * after its transaction commits. Retries, the recipient and failure logging
 * live in [[StaffMailJob]].
 *
 * $studentCount is fixed at dispatch: the appointments the cancel just
 * cancelled — the students who will no longer come.
 */
class SendClinicBatchCancelledMail extends StaffMailJob
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
        return new ClinicBatchCancelledMail($this->staff, $this->batch, $this->studentCount);
    }

    protected function description(): string
    {
        return 'batch cancellation notice';
    }

    /** Still clinic staff. A cancellation is final, so it is always still true. */
    protected function isStillRelevant(): bool
    {
        return parent::isStillRelevant() && $this->staff->isClinicStaff();
    }
}
