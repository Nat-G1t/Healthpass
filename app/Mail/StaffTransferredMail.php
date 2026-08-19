<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\College;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * D-50 — "you now manage a different college" (FR-AUTH-10).
 *
 * Both colleges are handed in by the caller and fixed at dispatch, so the
 * message always describes the move it was sent for even if the account is
 * moved again while this one is still queued.
 *
 * Scheduling and account scope only: no student data, no clinical detail.
 */
class StaffTransferredMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * NOT named $from / $to: Illuminate\Mail\Mailable already declares both
     * (they hold the sender and recipient address lists), and redeclaring them
     * is a fatal error. Do not "tidy" these names back.
     */
    public function __construct(
        public readonly User $staff,
        public readonly College $fromCollege,
        public readonly College $toCollege,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'HealthPass — you now manage '.$this->toCollege->code);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.staff-transferred',
            with: [
                'staffName' => $this->staff->name,
                'fromCollege' => $this->fromCollege->name,
                'fromCode' => $this->fromCollege->code,
                'toCollege' => $this->toCollege->name,
                'toCode' => $this->toCollege->code,
                'loginUrl' => route('login'),
            ],
        );
    }
}
