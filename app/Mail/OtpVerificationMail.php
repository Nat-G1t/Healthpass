<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a 6-digit OTP (Decision D-8 pattern). Used by registration Step 3,
 * the student email-change flow, and both password flows — $purpose tailors
 * the subject + intro line per flow (default keeps the original email-verify
 * wording, so existing callers are unchanged).
 * In dev the log mailer writes the rendered message to storage/logs/laravel.log.
 */
class OtpVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $otp,
        public readonly string $firstName,
        public readonly string $purpose = 'verify your email address',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'HealthPass — '.ucfirst($this->purpose),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.otp-verification',
        );
    }
}
