<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Appointment;
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
 * Shared behaviour for every queued email ABOUT one appointment
 * (FR-STU-12 scheduling notice, FR-STU-13 withdrawal notice).
 *
 * An *abstract class* is one that cannot be created on its own — it exists only
 * to be extended. Everything the two notices do identically lives here (retry
 * policy, recipient resolution, failure logging); each subclass supplies just
 * the two things that genuinely differ: which Mailable to send, and when
 * sending would no longer be appropriate.
 *
 * WHY QUEUED: the web request that creates or withdraws an appointment returns
 * immediately, and a mail server that is down or slow cannot take the booking,
 * the approval or the withdrawal down with it — those transactions have already
 * committed by the time any of this runs.
 *
 * ONE JOB PER STUDENT, always — never one job looping a roster — so a single
 * bad address fails only its own message.
 *
 * NOTE: with no queue worker running, these rows sit in `jobs` unsent forever
 * and nobody is told. See docs/deployment-hosted.md §Queue worker.
 */
abstract class AppointmentMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Three attempts, backing off 1 min then 5 min — enough to ride out a brief
     * SMTP blip or a rate-limit rejection without hammering the provider. After
     * the third, failed() below records it and the row lands in `failed_jobs`.
     */
    public int $tries = 3;

    /** @var list<int> seconds to wait before retry 2 and retry 3 */
    public array $backoff = [60, 300];

    /**
     * SerializesModels stores only the appointment's id and re-reads the row
     * when the worker picks the job up. If the row is gone by then, drop the
     * job quietly instead of failing it — there is no one left to email.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Deliberately NOT `readonly`. PHP only lets a readonly property be
     * initialized from the class that declares it, and the queue rebuilds a job
     * by hydrating its properties on an object of the SUBCLASS — which throws
     * "Cannot initialize readonly property ... from scope" the moment the job
     * round-trips through the queue. Plain public is the idiomatic Laravel
     * shape for job payloads anyway.
     */
    public Appointment $appointment;

    public function __construct(Appointment $appointment)
    {
        $this->appointment = $appointment;
    }

    /** The message this job delivers. */
    abstract protected function mailable(): Mailable;

    /**
     * Re-checked at SEND time, not at dispatch time: minutes may have passed on
     * the queue and the appointment may have moved on since. Each subclass
     * decides what still makes its message truthful.
     */
    abstract protected function isStillRelevant(): bool;

    /** Short label for log lines, e.g. "scheduling notice". */
    abstract protected function description(): string;

    public function handle(): void
    {
        if (! $this->isStillRelevant()) {
            return;
        }

        $student = $this->appointment->student;

        // The recipient is ALWAYS the address on the User record — never
        // anything from a request body. A student with no address on file is a
        // data problem to log, not an error to retry three times.
        if ($student === null || $student->email === null || $student->email === '') {
            Log::warning(
                'Appointment '.$this->description().' skipped: student has no email address',
                $this->logContext(),
            );

            return;
        }

        Mail::to($student->email)->send($this->mailable());
    }

    /**
     * Last attempt failed. Log it loudly — a silently dropped appointment email
     * means a student who shows up to nothing, or does not show up at all.
     */
    public function failed(Throwable $exception): void
    {
        Log::error(
            'Appointment '.$this->description().' failed after all retries: '.$exception->getMessage(),
            $this->logContext(),
        );
    }

    /**
     * Enough to trace the row, and nothing more. The reference number and the
     * user id are both opaque identifiers; the student's name, email address
     * and any clinical detail deliberately stay out of the log file.
     *
     * @return array<string, int|string|null>
     */
    protected function logContext(): array
    {
        return [
            'appointment_reference' => $this->appointment->reference_no,
            'student_id' => $this->appointment->student_id,
            'source' => $this->appointment->source,
        ];
    }
}
