<?php

declare(strict_types=1);

namespace Tests\Feature\SecondChance;

use App\Jobs\SendSecondChanceMail;
use App\Mail\SecondChanceScheduledMail;
use App\Models\Appointment;
use App\Services\ScheduleClashService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SecondChanceFixture;
use Tests\TestCase;

/**
 * D-110 / BR-27 — the healthpass:second-chances job.
 *
 * At the 8 PM cutoff of a clinic day, the students of each batch who missed it
 * (no visit, or sent to rest and never back) are booked TOGETHER on the
 * nearest day from tomorrow that fits all of them, at its earliest free hours,
 * on the same batch — once. Each is emailed after the booking commits.
 */
class SecondChanceSchedulerTest extends TestCase
{
    use RefreshDatabase;
    use SecondChanceFixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSecondChance();
    }

    // ── Who gets a 2nd chance ───────────────────────────────────────────────

    public function test_a_no_show_gets_one_second_chance_the_next_day_on_the_same_batch(): void
    {
        $batch = $this->makeBatch(form: 'assessment');
        $original = $this->addStudent($batch);

        // A minute before the cutoff they may still turn up: nothing happens.
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 19:59:00'));
        $this->runJob();
        $this->assertNull($this->secondChanceOf($original));

        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();

        $secondChance = $this->secondChanceOf($original);
        $this->assertNotNull($secondChance);
        $this->assertSame($this->day(1), $secondChance->scheduled_date->toDateString());
        $this->assertSame($batch->id, $secondChance->batch_request_id);
        $this->assertSame('scheduled', $secondChance->status);
        $this->assertSame('batch', $secondChance->source);
        $this->assertNull($secondChance->created_by);
        $this->assertNotSame($original->reference_no, $secondChance->reference_no);
        $this->assertStringStartsWith('APT-', $secondChance->reference_no);
        // Q4: the earliest free hour, and its first arrival time (D-96).
        $this->assertSame('07:00:00', $secondChance->scheduled_time);
        $this->assertSame('07:00:00', $secondChance->arrival_time);

        // The roster row now points at the 2nd chance; the missed one is untouched.
        $this->assertTrue($this->rosterAppointment($batch, $original->student_id)->is($secondChance));
        $this->assertSame('scheduled', $original->fresh()->status);

        Queue::assertPushed(SendSecondChanceMail::class, 1);
        Queue::assertPushed(SendSecondChanceMail::class, fn (SendSecondChanceMail $job): bool => $job->appointment->is($secondChance));
    }

    public function test_the_email_names_the_form_and_the_batch_id_not_the_apt_reference(): void
    {
        $batch = $this->makeBatch(form: 'assessment');
        $original = $this->addStudent($batch);

        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();

        $secondChance = $this->secondChanceOf($original);
        $mail = new SecondChanceScheduledMail($secondChance);

        $mail->assertHasSubject('HealthPass — your 2nd chance for your Medical Assessment Form on Tue, Oct 6, 2026');
        $mail->assertSeeInHtml($batch->reference_no);
        $mail->assertDontSeeInHtml($secondChance->reference_no);
        $mail->assertSeeInHtml('Monday, October 5, 2026');   // the day they missed
        $mail->assertSeeInHtml('This is your last chance.');
        $mail->assertSeeInHtml('7:00 AM – 8:00 AM');
        $mail->assertSeeInHtml('Arrive at');
    }

    public function test_a_student_sent_to_rest_who_never_came_back_is_rescheduled(): void
    {
        $batch = $this->makeBatch();
        $original = $this->addStudent($batch);
        $this->visit($original, 'resting');

        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();

        $this->assertNotNull($this->secondChanceOf($original));
        $this->assertSame('rescheduled', $this->rosterAppointment($batch, $original->student_id)->clearanceProgress());
    }

    public function test_a_captured_visit_the_clinic_has_not_encoded_is_not_rescheduled(): void
    {
        $batch = $this->makeBatch();
        $original = $this->addStudent($batch);
        $this->visit($original, 'captured');

        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();

        // The clinic's delay, not the student's: still "At the clinic".
        $this->assertNull($this->secondChanceOf($original));
        $this->assertSame('in_clinic', $this->rosterAppointment($batch, $original->student_id)->clearanceProgress());
        Queue::assertNothingPushed();
    }

    public function test_an_encoded_student_is_not_rescheduled(): void
    {
        $batch = $this->makeBatch();
        $original = $this->addStudent($batch);
        $this->visit($original, 'encoded', 'Fit');

        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();

        $this->assertNull($this->secondChanceOf($original));
    }

    public function test_running_the_job_twice_books_one_second_chance_per_student(): void
    {
        $batch = $this->makeBatch(2);

        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();
        $this->travelTo(now()->addMinutes(5));
        $this->runJob();

        $this->assertSame(2, Appointment::whereNotNull('rescheduled_from_id')->count());
        $this->assertSame(2, $batch->batchRequestStudents()->count());
        Queue::assertPushed(SendSecondChanceMail::class, 2);
    }

    // ── Which day, which hours ──────────────────────────────────────────────

    public function test_the_batchs_missed_students_move_together_to_the_first_day_that_fits_all_of_them(): void
    {
        // Tomorrow has room for two more students only.
        config(['healthpass.daily_capacity' => 10]);
        $this->fill($this->day(1), '10:00:00', 8);

        $batch = $this->makeBatch(3);

        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();

        $days = Appointment::whereNotNull('rescheduled_from_id')->get()
            ->map(fn (Appointment $appointment): string => $appointment->scheduled_date->toDateString())
            ->unique()->values()->all();

        $this->assertSame([$this->day(2)], $days);
        $this->assertSame(3, $batch->appointments()->whereNotNull('rescheduled_from_id')->count());
    }

    public function test_one_student_already_scheduled_tomorrow_moves_the_whole_group(): void
    {
        $batch = $this->makeBatch();
        $busy = $this->addStudent($batch);
        $this->addStudent($batch);

        // BR-25: one clinic schedule per student per day.
        Appointment::factory()->create([
            'student_id' => $busy->student_id,
            'scheduled_date' => $this->day(1),
            'scheduled_time' => '13:00:00',
            'status' => 'scheduled',
        ]);

        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();

        $days = Appointment::whereNotNull('rescheduled_from_id')->pluck('scheduled_date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())->unique()->values()->all();

        $this->assertSame([$this->day(2)], $days);
    }

    public function test_the_group_takes_the_earliest_free_hours_with_free_arrival_times(): void
    {
        // Tomorrow 7–8 AM already holds 18 of 20, on its first 18 arrival times.
        $this->fill($this->day(1), '07:00:00', 18);

        $batch = $this->makeBatch(3);

        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();

        $booked = $batch->appointments()->whereNotNull('rescheduled_from_id')->orderBy('rescheduled_from_id')->get()
            ->map(fn (Appointment $appointment): array => [$appointment->scheduled_time, $appointment->arrival_time])
            ->all();

        $this->assertSame([
            ['07:00:00', '07:54:00'],
            ['07:00:00', '07:57:00'],
            ['08:00:00', '08:00:00'],
        ], $booked);
    }

    public function test_when_no_day_fits_nobody_is_rescheduled_and_a_warning_is_logged(): void
    {
        config([
            'healthpass.second_chance.search_days' => 2,
            'healthpass.daily_capacity' => 5,
        ]);
        $this->fill($this->day(1), '07:00:00', 5);
        $this->fill($this->day(2), '07:00:00', 4);   // room for one, the group is two

        $batch = $this->makeBatch(2);
        Log::spy();

        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();

        $this->assertSame(0, Appointment::whereNotNull('rescheduled_from_id')->count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, $batch->reference_no))
            ->once();
        Queue::assertNothingPushed();

        foreach ($batch->batchRequestStudents as $row) {
            $this->assertSame('unattended', $row->appointment->clearanceProgress());
        }
    }

    // ── One chance only, and only recent absences ──────────────────────────

    public function test_a_missed_second_chance_gets_no_third_chance(): void
    {
        $batch = $this->makeBatch();
        $original = $this->addStudent($batch);

        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();

        // The 2nd chance is missed too.
        $this->atCutoffOf($this->day(1));
        $this->runJob();

        $this->assertSame(1, Appointment::whereNotNull('rescheduled_from_id')->count());
        $this->assertSame('unattended', $this->rosterAppointment($batch, $original->student_id)->clearanceProgress());
        Queue::assertPushed(SendSecondChanceMail::class, 1);
    }

    public function test_a_cutoff_more_than_the_catch_up_window_ago_is_ignored(): void
    {
        $original = $this->addStudent($this->makeBatch());

        // 25 hours after the cutoff — the server was off all that time.
        $this->travelTo(Carbon::parse($this->day(1).' 21:00:00'));
        $this->runJob();

        $this->assertNull($this->secondChanceOf($original));
    }

    public function test_a_cutoff_inside_the_catch_up_window_is_still_processed(): void
    {
        $original = $this->addStudent($this->makeBatch());

        // 23 hours after the cutoff: caught up, booked from tomorrow as seen NOW.
        $this->travelTo(Carbon::parse($this->day(1).' 19:00:00'));
        $this->runJob();

        $this->assertSame($this->day(2), $this->secondChanceOf($original)?->scheduled_date->toDateString());
    }

    public function test_a_cancelled_batch_and_an_inactive_student_get_no_second_chance(): void
    {
        $cancelled = $this->addStudent($this->makeBatch(status: 'cancelled'));

        $batch = $this->makeBatch();
        $graduated = $this->addStudent($batch, userStatus: 'inactive');
        $active = $this->addStudent($batch);

        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();

        $this->assertNull($this->secondChanceOf($cancelled));
        $this->assertNull($this->secondChanceOf($graduated));
        $this->assertNotNull($this->secondChanceOf($active));
        $this->assertSame('unattended', $this->rosterAppointment($batch, $graduated->student_id)->clearanceProgress());
    }

    // ── BR-25 after a 2nd chance ────────────────────────────────────────────

    public function test_the_directors_clash_check_sees_the_second_chance_day(): void
    {
        $original = $this->addStudent($this->makeBatch());

        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();

        $clashes = app(ScheduleClashService::class);

        // Another batch cannot put the student on their 2nd-chance day…
        $this->assertArrayHasKey($original->student_id, $clashes->clashesForBatch([$original->student_id], $this->day(1)));
        // …while any other free day is still fine.
        $this->assertSame([], $clashes->clashesForBatch([$original->student_id], $this->day(3)));
    }

    // ── The scheduler ───────────────────────────────────────────────────────

    public function test_the_job_is_scheduled_every_five_minutes_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'healthpass:second-chances'));

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}
