<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * FR-STU-12 (D-39) — "your appointment is scheduled" notice.
 *
 * Sent for BOTH sources: the student's own booking (FR-STU-04) and each
 * appointment a Director's batch approval fans out (BR-08). Same Mailable, same
 * template; the template branches on `$appointment->source` for the couple of
 * lines that genuinely differ (who booked it, who can cancel it).
 *
 * SCHEDULING ONLY. This mail must never carry a clearance outcome, Fit/Unfit,
 * vitals or questionnaire answers — those reach the student through My Records
 * after the nurse encodes (FR-STU-08), never through an inbox. Anything added
 * to the template later has to respect that.
 *
 * The recipient is resolved by the CALLER from `$appointment->student->email`
 * (see SendAppointmentScheduledMail) — never from request input.
 *
 * In dev the log mailer writes the rendered message to storage/logs/laravel.log.
 */
class AppointmentScheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Appointment $appointment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'HealthPass — Medical Clearance appointment on '
                .$this->appointment->scheduled_date->format('M j, Y'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.appointment-scheduled',
            with: [
                'appointment' => $this->appointment,
                'studentName' => $this->appointment->student->name,
                // Both of these are NULL-safe on pre-D-37 / pre-D-28 rows:
                // timeRangeLabel() returns "—" for a NULL slot, purposeText()
                // returns null and the template drops the row.
                'timeRange' => $this->appointment->timeRangeLabel(),
                'purposeText' => $this->appointment->purposeText(),
                'isBatch' => $this->appointment->source === 'batch',
                'collegeName' => $this->appointment->batchRequest?->college?->name,
                'clinicLocation' => (string) config('healthpass.clinic_location'),
                'tutorialUrl' => route('student.tutorial'),
            ],
        );
    }
}
