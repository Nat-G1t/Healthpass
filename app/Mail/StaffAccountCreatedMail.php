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
 * D-85: THIS MESSAGE CARRIES THE ONE-TIME PASSWORD. It is the only place the
 * password ever appears in readable form — the Director does not see it, and
 * the database holds only its bcrypt hash. The body tells the reader to change
 * it immediately; RequirePasswordChange forces that at first sign-in anyway.
 *
 * The sign-in link is built from `route('login')`, so it follows `APP_URL` and
 * needs no edit of its own when the real domain is set at deployment.
 */
class StaffAccountCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $staff,
        public readonly string $oneTimePassword,
    ) {}

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
                'oneTimePassword' => $this->oneTimePassword,
            ],
        );
    }
}
