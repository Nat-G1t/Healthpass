<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendSecondChanceMail;
use App\Models\Appointment;
use App\Services\SecondChanceScheduler;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * D-110 — give the students who missed their clinic day one automatic 2nd
 * chance, and email them.
 *
 *   php artisan healthpass:second-chances
 *
 * An *Artisan command* is a PHP class you run from the terminal with
 * `php artisan <name>`, like `migrate` or `serve`. This one is also started by
 * Laravel's scheduler every five minutes (routes/console.php), so nobody has
 * to remember to run it at 8 PM.
 *
 * It is IDEMPOTENT — running it twice does no harm: an appointment that
 * already has a 2nd chance is never picked again, a 2nd chance never gets one
 * of its own, and the unique `appointments.rescheduled_from_id` column refuses
 * a duplicate even if two runs overlap. A second run the same evening finds
 * nothing to do.
 *
 * The rules live in SecondChanceScheduler; this class only goes through the
 * batches one by one, queues each batch's emails after its booking has
 * committed, and prints a summary.
 */
class ScheduleSecondChances extends Command
{
    protected $signature = 'healthpass:second-chances';

    protected $description = 'D-110: book one 2nd-chance day for students who missed their clinic day, and email them';

    public function handle(SecondChanceScheduler $scheduler): int
    {
        // One group per batch: a batch's missed students are booked together (Q5).
        $groups = $scheduler->missedAppointments(now())->groupBy('batch_request_id');

        if ($groups->isEmpty()) {
            $this->info('No missed students to reschedule.');

            return self::SUCCESS;
        }

        $rescheduled = 0;
        $leftUnattended = 0;

        foreach ($groups as $missed) {
            $batch = $missed->first()->batchRequest;
            $students = $missed->count().' '.Str::plural('student', $missed->count());

            try {
                $date = $scheduler->scheduleFor($batch, $missed);
            } catch (UniqueConstraintViolationException) {
                // Another run booked this group first; its booking stands.
                $this->line("{$batch->reference_no}: already rescheduled by another run — skipped.");

                continue;
            }

            if ($date === null) {
                $searchDays = (int) config('healthpass.second_chance.search_days');

                // Reference and count only — no student names in the log.
                Log::warning("No 2nd-chance day fits batch {$batch->reference_no} within {$searchDays} days — its missed students stay Unattended.", [
                    'batch_reference' => $batch->reference_no,
                    'students' => $missed->count(),
                ]);
                $this->warn("{$batch->reference_no}: no day within {$searchDays} days fits {$students} — left Unattended.");
                $leftUnattended += $missed->count();

                continue;
            }

            // FR-STU-12's rule: queued only AFTER the booking committed, so a
            // mail failure can never undo it, one job per student.
            $secondChances = Appointment::whereIn('rescheduled_from_id', $missed->modelKeys())->get();

            foreach ($secondChances as $appointment) {
                SendSecondChanceMail::dispatch($appointment);
            }

            $rescheduled += $secondChances->count();
            $this->info("{$batch->reference_no}: {$students} rescheduled to {$date->format('D, M j, Y')}.");
        }

        $this->info("Done — {$rescheduled} rescheduled, {$leftUnattended} left Unattended.");

        return self::SUCCESS;
    }
}
