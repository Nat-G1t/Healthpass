<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Shared behaviour for every queued email ABOUT one staff account (D-50) — the
 * welcome notice when the Director provisions an account, and the transfer
 * notice when a College Admin is moved (FR-AUTH-10).
 *
 * The staff-side twin of [[AppointmentMailJob]], and deliberately a separate
 * base class rather than a generalisation of it: that one is built around an
 * Appointment and resolves its recipient through `$appointment->student`, which
 * is not a thing a staff notice has. Two small bases beat one that has to ask
 * what kind of thing it is holding.
 *
 * WHY QUEUED: provisioning an account and moving an admin between colleges are
 * both single database writes that have already committed by the time any of
 * this runs. A mail server that is down must not take the Director's action
 * down with it.
 *
 * NOTE: with no queue worker running, these rows sit in `jobs` unsent forever
 * and nobody is told. See docs/deployment-hosted.md §Queue worker.
 */
abstract class StaffMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Three attempts, backing off 1 min then 5 min — same policy as the appointment notices. */
    public int $tries = 3;

    /** @var list<int> seconds to wait before retry 2 and retry 3 */
    public array $backoff = [60, 300];

    /** If the account is gone by the time the worker runs, drop the job quietly. */
    public bool $deleteWhenMissingModels = true;

    /**
     * Deliberately NOT `readonly`. PHP only lets a readonly property be
     * initialized from the class that declares it, and the queue rebuilds a job
     * by hydrating properties on an object of the SUBCLASS — which throws
     * "Cannot initialize readonly property ... from scope" the moment the job
     * round-trips. The same trap D-41 hit on AppointmentMailJob.
     */
    public User $staff;

    public function __construct(User $staff)
    {
        $this->staff = $staff;
    }

    /** The message this job delivers. */
    abstract protected function mailable(): Mailable;

    /** Short label for log lines, e.g. "welcome notice". */
    abstract protected function description(): string;

    /**
     * Re-checked at SEND time, not dispatch time. Both notices are pointless if
     * the account was deactivated in the minutes it spent on the queue —
     * telling someone their account is ready, when it is not, is worse than
     * silence. Subclasses may narrow this further.
     */
    protected function isStillRelevant(): bool
    {
        return $this->staff->status === 'active';
    }

    public function handle(): void
    {
        if (! $this->isStillRelevant()) {
            return;
        }

        // The recipient is ALWAYS the address on the User record — never
        // anything from a request body.
        if ($this->staff->email === null || $this->staff->email === '') {
            Log::warning(
                'Staff '.$this->description().' skipped: account has no email address',
                $this->logContext(),
            );

            return;
        }

        Mail::to($this->staff->email)->send($this->mailable());
    }

    /** Last attempt failed — log it, because nothing else will say so. */
    public function failed(Throwable $exception): void
    {
        Log::error(
            'Staff '.$this->description().' failed after all retries: '.$exception->getMessage(),
            $this->logContext(),
        );
    }

    /**
     * The id and role are opaque enough to trace the row; the person's name and
     * email address deliberately stay out of the log file.
     *
     * @return array<string, int|string|null>
     */
    protected function logContext(): array
    {
        return [
            'user_id' => $this->staff->id,
            'role' => $this->staff->role,
        ];
    }
}
