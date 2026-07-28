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
 * The counterpart of [[AppointmentScheduledMail]], and only ever sent for a
 * BATCH appointment withdrawn by a College Admin (FR-ADM-07). A self-booked
 * appointment is cancelled by the student themselves, who was standing at the
 * screen when they did it and needs no email telling them so.
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
        $service = $this->appointment->service_type === 'medical'
            ? 'Medical Clearance'
            : 'Dental Check';

        return new Envelope(
            subject: 'HealthPass — '.$service.' appointment on '
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
                // Whether the student can simply rebook for themselves. False
                // once the clinic day has passed — offering "book again" for a
                // date already gone would just confuse them.
                'canRebook' => ! $this->appointment->scheduled_date->lt(today()),
                'bookingUrl' => route('student.appointments'),
            ],
        );
    }
}
