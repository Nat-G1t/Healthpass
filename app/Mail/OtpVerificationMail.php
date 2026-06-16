<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers the 6-digit OTP for wizard Step 3 (FR-REG-04, Decision D-8).
 * In dev the log mailer writes the rendered message to storage/logs/laravel.log.
 */
class OtpVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $otp,
        public readonly string $firstName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'HealthPass — Verify your email address',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.otp-verification',
        );
    }
}
