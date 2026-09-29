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
 * D-92 — "your college cancelled your approved schedule" notice.
 *
 * The counterpart of [[AppointmentScheduledMail]], sent to every student whose
 * appointment a College Admin's batch cancel (FR-ADM-11) just cancelled. It
 * names the Batch ID and carries the college's written reason. Since D-61 the
 * student cannot book for themselves, so it points them back to their college.
 *
 * SCHEDULING ONLY, exactly as the scheduling notice: no clearance outcome, no
 * Fit/Unfit, no vitals, no questionnaire answers (FR-STU-08).
 *
 * The recipient is resolved by the CALLER from `$appointment->student->email`
 * (see SendAppointmentCancelledMail) — never from request input.
 */
class AppointmentCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Appointment $appointment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'HealthPass — your clinic appointment on '
                .$this->appointment->scheduled_date->format('M j, Y').' was cancelled',
        );
    }

    public function content(): Content
    {
        $batch = $this->appointment->batchRequest;

        return new Content(
            view: 'mail.appointment-cancelled',
            with: [
                'appointment' => $this->appointment,
                'studentName' => $this->appointment->student->name,
                // NULL-safe on pre-D-37 rows: "—" for an appointment with no slot.
                'timeRange' => $this->appointment->timeRangeLabel(),
                'batchRef' => $batch?->reference_no,
                'formLabel' => $batch?->formTypeLabel() ?? 'Medical Clearance',
                'reason' => $batch?->cancellation_reason,
                'collegeName' => $batch?->college?->name,
            ],
        );
    }
}
