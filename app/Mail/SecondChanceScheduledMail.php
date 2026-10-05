<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Appointment;
use App\Models\BatchRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * D-110 — "you missed your clinic day; here is your 2nd (and last) chance".
 *
 * Sent once per 2nd-chance appointment the healthpass:second-chances command
 * books. It names the batch's real form (Medical Clearance or Medical
 * Assessment Form), the day the student missed, and the new date, hour and
 * arrival time — and the Batch ID, never the APT reference (D-101).
 *
 * SCHEDULING ONLY, like the FR-STU-12 notice: no clearance outcome, vitals or
 * questionnaire answers. The recipient comes from the student's User record
 * (SendSecondChanceMail), never from request input.
 */
class SecondChanceScheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Appointment $appointment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'HealthPass — your 2nd chance for your '.$this->formName()
                .' on '.$this->appointment->scheduled_date->format('D, M j, Y'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.second-chance-scheduled',
            with: [
                'appointment' => $this->appointment,
                'studentName' => $this->appointment->student->name,
                'formName' => $this->formName(),
                'batchRef' => $this->appointment->batchRequest?->reference_no,
                'missedDate' => $this->appointment->rescheduledFrom?->scheduled_date,
                'timeRange' => $this->appointment->timeRangeLabel(),
                'arrivalTime' => $this->appointment->arrivalTimeLabel(),
                'clinicLocation' => (string) config('healthpass.clinic_location'),
            ],
        );
    }

    /** "Medical Clearance" / "Medical Assessment Form" — the batch's own form (D-62). */
    private function formName(): string
    {
        return $this->appointment->batchRequest?->formTypeLabel() ?? BatchRequest::FORM_TYPES['clearance'];
    }
}
