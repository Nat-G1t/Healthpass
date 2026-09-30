<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Mail\AppointmentScheduledMail;
use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\User;
use App\Services\ClinicScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * D-96 — every batch student gets their own arrival time inside their hour,
 * one 3-minute kiosk budget apart (3600 s ÷ hourly_capacity), assigned at
 * approval and stored so the emailed time never moves.
 */
class ArrivalTimeTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private User $director;

    private User $admin;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned, so these tests describe the 3-minute budget (D-95) and not
        // whatever the config default happens to be.
        config(['healthpass.hourly_capacity' => 20, 'healthpass.daily_capacity' => 200]);

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->director = User::factory()->create(['role' => 'director']);
        $this->admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
        $this->date = now()->addDays(7)->toDateString();
    }

    private function makeBatch(int $studentCount, string $startSlot = '07:00:00'): BatchRequest
    {
        static $seq = 500;

        $batch = BatchRequest::create([
            'reference_no' => 'BR-'.now()->year.'-'.$seq++,
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'reason' => 'ojt',
            'service_type' => 'medical',
            'requested_date' => $this->date,
            'requested_time' => $startSlot,
            'requested_blocks' => app(ClinicScheduleService::class)->blocksFor($studentCount),
        ]);

        User::factory()->count($studentCount)->create(['role' => 'student'])->each(
            fn (User $student) => BatchRequestStudent::create([
                'batch_request_id' => $batch->id,
                'student_id' => $student->id,
            ])
        );

        return $batch;
    }

    private function approve(BatchRequest $batch): void
    {
        $this->actingAs($this->director)
            ->post("/director/batches/{$batch->id}/approve")
            ->assertSessionHas('status');
    }

    /** @return list<string|null> the batch's arrival times, in pivot (roster) order */
    private function arrivalTimesOf(BatchRequest $batch): array
    {
        return $batch->batchRequestStudents()->orderBy('id')->with('appointment')->get()
            ->map(fn (BatchRequestStudent $row) => $row->appointment->arrival_time)
            ->all();
    }

    public function test_the_hour_is_cut_into_twenty_three_minute_arrival_times(): void
    {
        $times = app(ClinicScheduleService::class)->arrivalTimes('08:00:00');

        $this->assertCount(20, $times);
        $this->assertSame(['08:00:00', '08:03:00', '08:06:00'], array_slice($times, 0, 3));
        $this->assertSame('08:57:00', end($times));
    }

    public function test_a_batch_gets_times_in_roster_order_and_each_hour_starts_again(): void
    {
        Mail::fake();
        $batch = $this->makeBatch(23);   // 20 at 7 AM, 3 at 8 AM

        $this->approve($batch);

        $times = $this->arrivalTimesOf($batch);
        $this->assertSame(['07:00:00', '07:03:00', '07:06:00'], array_slice($times, 0, 3));
        $this->assertSame('07:57:00', $times[19]);
        $this->assertSame(['08:00:00', '08:03:00', '08:06:00'], array_slice($times, 20));
        $this->assertCount(23, array_unique($times));
    }

    public function test_a_second_batch_in_the_same_hour_continues_after_the_first(): void
    {
        Mail::fake();
        $first = $this->makeBatch(5);
        $second = $this->makeBatch(3);

        $this->approve($first);
        $this->approve($second);

        $this->assertSame(['07:15:00', '07:18:00', '07:21:00'], $this->arrivalTimesOf($second));
    }

    public function test_a_cancelled_appointment_gives_its_time_back_and_nobody_is_doubled(): void
    {
        Mail::fake();
        $first = $this->makeBatch(3);
        $this->approve($first);

        // The 7:03 student's appointment is cancelled (e.g. a D-92 cancel).
        Appointment::where('batch_request_id', $first->id)->where('arrival_time', '07:03:00')
            ->update(['status' => 'cancelled']);

        $second = $this->makeBatch(2);
        $this->approve($second);

        // The gap is filled first, then the first unused time after 7:06.
        $this->assertSame(['07:03:00', '07:09:00'], $this->arrivalTimesOf($second));
    }

    public function test_the_email_and_the_student_dashboard_show_the_arrival_time(): void
    {
        Mail::fake();
        $batch = $this->makeBatch(2);
        $this->approve($batch);

        $appointment = Appointment::where('batch_request_id', $batch->id)
            ->where('arrival_time', '07:03:00')->firstOrFail();

        Mail::assertSent(
            AppointmentScheduledMail::class,
            fn (AppointmentScheduledMail $mail): bool => $mail->appointment->is($appointment)
                && str_contains($mail->render(), '7:03 AM')
                && str_contains($mail->render(), 'Arrive at'),
        );

        $this->actingAs($appointment->student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Arrive at', '7:03 AM']);
    }

    public function test_an_appointment_from_before_d96_shows_only_its_hour(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $appointment = Appointment::factory()->inSlot('09:00:00')->create([
            'student_id' => $student->id,
            'scheduled_date' => $this->date,
        ]);

        $this->assertNull($appointment->arrival_time);
        $this->assertNull($appointment->arrivalTimeLabel());
        $this->assertStringNotContainsString('Arrive at', (new AppointmentScheduledMail($appointment))->render());

        $this->actingAs($student)->get(route('student.dashboard'))->assertOk()->assertDontSee('Arrive at');
    }
}
