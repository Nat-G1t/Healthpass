<?php

declare(strict_types=1);

namespace Tests\Feature\SecondChance;

use App\Jobs\SendSecondChanceMail;
use App\Models\Appointment;
use App\Models\ClinicVisit;
use App\Models\MedicalAssessment;
use App\Models\ScreeningResponse;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SecondChanceFixture;
use Tests\TestCase;

/**
 * D-132 (amends D-110 Q3) — at the 8 PM cutoff, a student still waiting in the
 * nurse's or the physician's queue is removed (as the queue's Remove does),
 * reads Unattended, and gets the 2nd chance with the batch's other missed
 * students. Same 24-hour catch-up window; older visits are never touched.
 */
class QueueCutoffRemovalTest extends TestCase
{
    use RefreshDatabase;
    use SecondChanceFixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSecondChance();
    }

    // ── What is removed ─────────────────────────────────────────────────────

    public function test_a_captured_visit_is_removed_at_the_cutoff_and_rescheduled_with_the_absent_students(): void
    {
        $batch = $this->makeBatch();
        $absent = $this->addStudent($batch);
        $waiting = $this->addStudent($batch);
        $visit = $this->waitingVisit($waiting, 'captured');

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 20:05:00'));
        $this->runJob();

        // The visit and everything captured with it are gone.
        $this->assertModelMissing($visit);
        $this->assertSame(0, VitalSigns::where('clinic_visit_id', $visit->id)->count());
        $this->assertSame(0, ScreeningResponse::where('clinic_visit_id', $visit->id)->count());

        // The day they missed reads Unattended…
        $this->assertSame('unattended', $waiting->fresh()->clearanceProgress());

        // …and they were booked together with the batch's absent student.
        $secondChance = $this->secondChanceOf($waiting);
        $this->assertNotNull($secondChance);
        $this->assertSame($this->day(1), $secondChance->scheduled_date->toDateString());
        $this->assertSame($this->secondChanceOf($absent)?->scheduled_date->toDateString(), $secondChance->scheduled_date->toDateString());
        $this->assertSame('rescheduled', $this->rosterAppointment($batch, $waiting->student_id)->clearanceProgress());

        Queue::assertPushed(SendSecondChanceMail::class, 2);
        Queue::assertPushed(SendSecondChanceMail::class, fn (SendSecondChanceMail $job): bool => $job->appointment->is($secondChance));
    }

    public function test_an_interviewed_visit_is_removed_with_its_assessment_row(): void
    {
        $batch = $this->makeBatch(form: 'assessment');
        $waiting = $this->addStudent($batch);
        $visit = $this->waitingVisit($waiting, 'interviewed');
        $this->assertSame(1, MedicalAssessment::where('clinic_visit_id', $visit->id)->count());

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 20:05:00'));
        $this->runJob();

        $this->assertModelMissing($visit);
        $this->assertSame(0, MedicalAssessment::where('clinic_visit_id', $visit->id)->count());
        $this->assertSame(0, VitalSigns::where('clinic_visit_id', $visit->id)->count());
        $this->assertNotNull($this->secondChanceOf($waiting));
    }

    public function test_encoded_and_resting_visits_are_not_removed(): void
    {
        $batch = $this->makeBatch();
        $encoded = $this->visit($this->addStudent($batch), 'encoded', 'Fit');
        $resting = $this->visit($this->addStudent($batch), 'resting');

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 20:05:00'));
        $this->runJob();

        $this->assertSame('encoded', $encoded->fresh()?->status);
        // D-110 still reschedules the resting student — without deleting the row.
        $this->assertSame('resting', $resting->fresh()?->status);
        $this->assertNotNull($this->secondChanceOf($resting->appointment));
    }

    public function test_a_visit_with_no_appointment_is_never_removed(): void
    {
        $legacy = ClinicVisit::create([
            'reference_no' => 'HP-2026-7900',
            'student_id' => $this->addStudent($this->makeBatch())->student_id,
            'college_id' => $this->college->id,
            'appointment_id' => null,
            'login_method' => 'qr',
            'status' => 'captured',
            'checked_in_at' => now(),
        ]);

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 20:05:00'));
        $this->runJob();

        $this->assertSame('captured', $legacy->fresh()?->status);
    }

    public function test_a_visit_encoded_after_it_was_read_is_skipped(): void
    {
        $visit = $this->waitingVisit($this->addStudent($this->makeBatch()), 'captured');
        $staleCopy = ClinicVisit::find($visit->id);

        // The physician saves between the job's read and its delete.
        $visit->update(['status' => 'encoded']);

        $this->assertFalse($staleCopy->removeFromQueue());
        $this->assertSame('encoded', $visit->fresh()?->status);
    }

    // ── When ────────────────────────────────────────────────────────────────

    public function test_nothing_is_removed_before_the_cutoff(): void
    {
        $waiting = $this->addStudent($this->makeBatch());
        $visit = $this->waitingVisit($waiting, 'captured');

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 19:55:00'));
        $this->runJob();

        $this->assertSame('captured', $visit->fresh()?->status);
        $this->assertSame('in_clinic', $waiting->fresh()->clearanceProgress());
        $this->assertNull($this->secondChanceOf($waiting));
    }

    public function test_a_cutoff_outside_the_catch_up_window_is_never_cleared_automatically(): void
    {
        $waiting = $this->addStudent($this->makeBatch());
        $visit = $this->waitingVisit($waiting, 'captured');

        // 30 hours after the cutoff — the server was off all that time.
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 20:00:00')->addHours(30));
        $this->runJob();

        $this->assertSame('captured', $visit->fresh()?->status);
        $this->assertNull($this->secondChanceOf($waiting));
    }

    public function test_a_leftover_on_the_second_chance_day_is_removed_with_no_third_chance(): void
    {
        $batch = $this->makeBatch();
        $original = $this->addStudent($batch);

        // Day 1: a no-show — booked on day 2.
        $this->atCutoffOf(self::CLINIC_DAY);
        $this->runJob();
        $secondChance = $this->secondChanceOf($original);

        // Day 2: they come, but the clinic never gets to them.
        $this->travelTo(Carbon::parse($this->day(1).' 10:00:00'));
        $visit = $this->waitingVisit($secondChance, 'captured');

        $this->atCutoffOf($this->day(1));
        $this->runJob();

        $this->assertModelMissing($visit);
        $this->assertSame(1, Appointment::whereNotNull('rescheduled_from_id')->count());
        $this->assertSame('unattended', $this->rosterAppointment($batch, $original->student_id)->clearanceProgress());
        Queue::assertPushed(SendSecondChanceMail::class, 1);
    }

    // ── Batch status and logging ───────────────────────────────────────────

    public function test_a_batch_held_open_only_by_a_waiting_visit_moves_on(): void
    {
        $batch = $this->makeBatch();
        $this->visit($this->addStudent($batch), 'encoded', 'Fit');
        $waiting = $this->addStudent($batch);
        $this->waitingVisit($waiting, 'captured');

        // After the cutoff, before the job: held open by the waiting visit.
        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 20:01:00'));
        $this->assertSame('still_in_progress', $batch->fresh()->resultsStatus());

        $this->runJob();

        // The 2nd-chance day is under way.
        $this->travelTo(Carbon::parse($this->day(1).' 10:00:00'));
        $this->assertSame('in_progress', $batch->fresh()->resultsStatus());

        // Left waiting again on that day: removed at its cutoff, and the batch is done.
        $this->waitingVisit($this->rosterAppointment($batch, $waiting->student_id), 'captured');
        $this->atCutoffOf($this->day(1));
        $this->runJob();

        $this->assertSame('finished_incomplete', $batch->fresh()->resultsStatus());
    }

    public function test_the_run_logs_the_count_and_references_once_without_names(): void
    {
        Log::spy();

        $batch = $this->makeBatch();
        $first = $this->waitingVisit($this->addStudent($batch), 'captured');
        $second = $this->waitingVisit($this->addStudent($batch), 'captured');
        $names = [$first->student->name, $second->student->name];

        $this->travelTo(Carbon::parse(self::CLINIC_DAY.' 20:05:00'));
        $this->runJob();

        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context) use ($first, $second, $names): bool {
            $logged = $message.json_encode($context);

            return $context['count'] === 2
                && $context['visit_ids'] === [$first->id, $second->id]
                && $context['references'] === [$first->reference_no, $second->reference_no]
                && ! str_contains($logged, $names[0])
                && ! str_contains($logged, $names[1]);
        });
    }

    /**
     * A visit waiting in a queue, as the kiosk leaves it: vitals and screening
     * answers, plus — once the nurse submitted it (`interviewed`) — the
     * assessment row whose foreign key restricts the visit's delete.
     */
    private function waitingVisit(Appointment $appointment, string $status): ClinicVisit
    {
        $visit = $this->visit($appointment, $status);

        VitalSigns::create([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 165.0,
            'weight_kg' => 60.0,
            'bmi' => 22.0,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 115,
            'bp_diastolic' => 75,
            'entry_method' => 'manual',
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            'is_bmi_flagged' => false,
        ]);

        ScreeningResponse::create([
            'clinic_visit_id' => $visit->id,
            ...array_fill_keys(array_keys(ScreeningResponse::QUESTIONS), false),
            'is_pregnant' => false,
        ]);

        if ($status === 'interviewed') {
            $visit->medicalAssessment()->create(['medical_history' => ['patient' => [], 'family' => [], 'specify' => []]]);
            $visit->update(['interviewed_by' => $this->nurse->id, 'interviewed_at' => now()]);
        }

        return $visit;
    }
}
