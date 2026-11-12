<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\BatchRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * D-137 — "an approved batch was cancelled", to a nurse or physician (see
 * SendClinicBatchCancelledMail): the schedule that is no longer happening,
 * how many students will not come, who cancelled it and their reason.
 *
 * No link: a cancelled batch has no page on the Clinic Dashboard. No
 * student names, as the approval notice.
 */
class ClinicBatchCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $staff,
        public readonly BatchRequest $batch,
        public readonly int $studentCount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "HealthPass — batch {$this->batch->reference_no} on "
                .$this->batch->scheduled_date->format('M j, Y').' was cancelled',
        );
    }

    public function content(): Content
    {
        $canceller = $this->batch->canceller;

        return new Content(
            view: 'mail.clinic-batch-cancelled',
            with: [
                'staffName' => $this->staff->name,
                'batchRef' => $this->batch->reference_no,
                'collegeName' => $this->batch->college->name,
                'collegeCode' => $this->batch->college->code,
                'formLabel' => $this->batch->formTypeLabel(),
                'purpose' => $this->batch->reasonText(),
                'dateLabel' => $this->batch->scheduled_date->format('l, F j, Y'),
                'hoursLabel' => $this->batch->scheduledSpanLabel(),
                'studentsLabel' => $this->studentCount.' '.Str::plural('student', $this->studentCount),
                // D-129: the Director can cancel too — say who it was.
                'cancelledByDirector' => $this->batch->wasCancelledByDirector(),
                'cancellerLabel' => match (true) {
                    $canceller === null => '—',
                    // An account named after its role ("Clinic Director") says it once.
                    $canceller->name === $canceller->roleLabel() => $canceller->name,
                    default => $canceller->name.' ('.$canceller->roleLabel().')',
                },
                'reason' => $this->batch->cancellation_reason,
            ],
        );
    }
}
