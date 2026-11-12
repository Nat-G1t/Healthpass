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
 * D-137 — "the Director approved a batch for the clinic", to a nurse or
 * physician (see SendClinicBatchApprovedMail).
 *
 * The batch's schedule and a COUNT of its students, with a link to the
 * batch's page on the Clinic Dashboard — never the students' names: the
 * roster is on that page, behind the clinic's sign-in.
 */
class ClinicBatchApprovedMail extends Mailable
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
            subject: "HealthPass — new batch {$this->batch->reference_no} on ".$this->batch->scheduled_date->format('M j, Y'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.clinic-batch-approved',
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
                // D-136: the Director approved printed copies with the batch.
                'printedCopies' => (bool) $this->batch->printed_copies_approved,
                // Follows APP_URL, so it is the live domain on the server.
                'batchUrl' => route('nurse.batches.results', $this->batch),
            ],
        );
    }
}
