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
 * FR-STU-13 (D-41) — "your college withdrew your appointment" notice.
 *
 * The counterpart of [[AppointmentScheduledMail]], sent when a College Admin
 * withdraws a student's appointment from an approved batch (FR-ADM-07). Since
 * D-61 the student cannot book for themselves, so the mail points them back to
 * their college for a new batch request rather than at a booking page.
 *
 * SCHEDULING ONLY, exactly as the scheduling notice: no clearance outcome, no
 * Fit/Unfit, no vitals, no questionnaire answers (FR-STU-08).
 *
 * The recipient is resolved by the CALLER from `$appointment->student->email`
 * (see SendAppointmentWithdrawnMail) — never from request input.
 */
class AppointmentWithdrawnMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Appointment $appointment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'HealthPass — Medical Clearance appointment on '
                .$this->appointment->scheduled_date->format('M j, Y').' cancelled',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.appointment-withdrawn',
            with: [
                'appointment' => $this->appointment,
                'studentName' => $this->appointment->student->name,
                // NULL-safe on pre-D-37 rows: "—" for an appointment with no slot.
                'timeRange' => $this->appointment->timeRangeLabel(),
                'collegeName' => $this->appointment->batchRequest?->college?->name,
            ],
        );
    }
}
