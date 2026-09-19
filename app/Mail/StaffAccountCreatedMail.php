<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * D-50 — "your HealthPass staff account is ready" (FR-AUTH-10).
 *
 * NO CREDENTIAL TRAVELS IN THIS MESSAGE. The one-time password is displayed to
 * the Director once, on screen, and handed over through official channels
 * (D-35, D-47); it is never emailed and never stored in readable form. The body
 * says that outright, so that a later mail claiming to carry the password is
 * recognisable as a forgery.
 *
 * The sign-in link is built from `route('login')`, so it follows `APP_URL` and
 * needs no edit of its own when the real domain is set at deployment.
 */
class StaffAccountCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly User $staff) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'HealthPass — your staff account is ready');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.staff-account-created',
            with: [
                'staffName' => $this->staff->name,
                'roleLabel' => $this->staff->roleLabel(),
                // Null for clinic staff — they work clinic-wide, not inside a college.
                'collegeName' => $this->staff->managedCollege?->name,
                'loginUrl' => route('login'),
            ],
        );
    }
}
