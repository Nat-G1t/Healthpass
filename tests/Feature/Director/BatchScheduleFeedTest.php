<?php

declare(strict_types=1);

namespace Tests\Feature\Director;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\User;
use App\Services\ClinicScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Review page's two JSON feeds (FR-DIRA-08, D-108):
 *   GET /director/batches/{batch}/calendar?year=&month=  — per day: booked, fits
 *   GET /director/batches/{batch}/hours?date=            — per hour: who, selectable; + clash
 * Every answer is computed on the server, against its own clock.
 */
class BatchScheduleFeedTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $cea;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        // The D-95 figures, pinned: 20 an hour, 200 a day, ten hours 7 AM–5 PM.
        config(['healthpass.hourly_capacity' => 20, 'healthpass.daily_capacity' => 200]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:30', 'Asia/Manila'));   // a Monday

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->cea = College::create(['code' => 'CEA', 'name' => 'College of Engineering and Architecture']);
        $this->director = User::factory()->create(['role' => 'director']);
    }

    private function batch(College $college, int $students, string $status = 'pending', array $overrides = []): BatchRequest
    {
        static $seq = 100;

        $batch = BatchRequest::create(array_merge([
            'reference_no' => 'BR-2026-'.$seq++,
            'college_id' => $college->id,
            'requested_by' => User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $college->id])->id,
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'service_type' => 'medical',
            'status' => $status,
        ], $overrides));

        User::factory()->count($students)->create(['role' => 'student'])->each(
            fn (User $student) => BatchRequestStudent::create(['batch_request_id' => $batch->id, 'student_id' => $student->id]),
        );

        return $batch;
    }

    /** $count appointments of $college's (approved) batch in $slot on $date. */
    private function booked(College $college, string $date, string $slot, int $count, string $status = 'scheduled'): void
    {
        $holder = $this->batch($college, 0, 'approved', ['scheduled_date' => $date, 'scheduled_time' => $slot, 'scheduled_blocks' => 1]);

        Appointment::factory()->count($count)->inSlot($slot)->create([
            'scheduled_date' => $date,
            'status' => $status,
            'batch_request_id' => $holder->id,
        ]);
    }

    private function calendar(BatchRequest $batch, int $year = 2026, int $month = 10): TestResponse
    {
        return $this->actingAs($this->director)->getJson("/director/batches/{$batch->id}/calendar?year={$year}&month={$month}");
    }

    /** @return array{date: string, booked: int, capacity: int, fits: bool, why: ?string} */
    private function day(TestResponse $response, string $date): array
    {
        return collect($response->json('days'))->firstWhere('date', $date);
    }

    private function hours(BatchRequest $batch, string $date): TestResponse
    {
        return $this->actingAs($this->director)->getJson("/director/batches/{$batch->id}/hours?date={$date}");
    }

    /** @return array{slot: string, label: string, booked: int, colleges: list<array{code: string, count: int}>, selectable: bool, why: ?string} */
    private function hour(TestResponse $response, string $slot): array
    {
        return collect($response->json('hours'))->firstWhere('slot', $slot);
    }

    // ── Calendar ────────────────────────────────────────────────────────────

    public function test_the_calendar_returns_every_day_of_the_month(): void
    {
        $days = $this->calendar($this->batch($this->ccs, 5))->assertOk()->json('days');

        $this->assertCount(31, $days);
        $this->assertSame('2026-10-01', $days[0]['date']);
        $this->assertSame('2026-10-31', $days[30]['date']);
        $this->assertSame(200, $days[0]['capacity']);
    }

    public function test_booked_counts_every_non_cancelled_appointment_that_day(): void
    {
        $this->booked($this->ccs, '2026-10-20', '09:00:00', 30);
        $this->booked($this->cea, '2026-10-20', '13:00:00', 15);
        $this->booked($this->cea, '2026-10-20', '14:00:00', 10, status: 'cancelled');
        // A pre-D-37 row with no hour still counts toward the day (BR-02).
        Appointment::factory()->create(['scheduled_date' => '2026-10-20', 'scheduled_time' => null]);

        $response = $this->calendar($this->batch($this->ccs, 5));

        $this->assertSame(46, $this->day($response, '2026-10-20')['booked']);
        $this->assertSame(0, $this->day($response, '2026-10-21')['booked']);
        $this->assertTrue($this->day($response, '2026-10-20')['fits']);
    }

    public function test_past_days_do_not_fit(): void
    {
        $response = $this->calendar($this->batch($this->ccs, 5));

        $this->assertFalse($this->day($response, '2026-10-04')['fits']);
        $this->assertSame('Past', $this->day($response, '2026-10-04')['why']);
        $this->assertTrue($this->day($response, '2026-10-05')['fits']);   // today, hours still ahead
    }

    public function test_a_full_day_does_not_fit(): void
    {
        foreach (app(ClinicScheduleService::class)->slots() as $slot) {
            $this->booked($this->ccs, '2026-10-22', $slot, 20);
        }

        $day = $this->day($this->calendar($this->batch($this->cea, 2)), '2026-10-22');

        $this->assertSame(200, $day['booked']);
        $this->assertFalse($day['fits']);
        $this->assertSame('No hour can fit 2 students', $day['why']);
    }

    public function test_a_day_with_room_but_no_run_of_hours_long_enough_does_not_fit(): void
    {
        // Every other hour full: 25 students need two hours in a row, and no
        // two consecutive hours both have room.
        foreach (['07:00:00', '09:00:00', '11:00:00', '13:00:00', '15:00:00'] as $slot) {
            $this->booked($this->ccs, '2026-10-23', $slot, 20);
        }

        $this->assertFalse($this->day($this->calendar($this->batch($this->cea, 25)), '2026-10-23')['fits']);
        // …while a batch that fits in one hour still can.
        $this->assertTrue($this->day($this->calendar($this->batch($this->cea, 20)), '2026-10-23')['fits']);
    }

    public function test_today_stops_fitting_once_every_possible_start_has_ended(): void
    {
        $batch = $this->batch($this->ccs, 25);   // needs 2 hours: the last start is 3 PM

        $this->travelTo(Carbon::parse('2026-10-05 15:59', 'Asia/Manila'));
        $this->assertTrue($this->day($this->calendar($batch), '2026-10-05')['fits']);

        // 4 PM: the 3–4 PM hour has ended, and a 4 PM start would run past 5.
        $this->travelTo(Carbon::parse('2026-10-05 16:00', 'Asia/Manila'));
        $day = $this->day($this->calendar($batch), '2026-10-05');
        $this->assertFalse($day['fits']);
        $this->assertSame('No hour can fit 25 students', $day['why']);

        // After closing, today reads as closed (BR-20).
        $this->travelTo(Carbon::parse('2026-10-05 17:00', 'Asia/Manila'));
        $this->assertSame('Closed for today', $this->day($this->calendar($batch), '2026-10-05')['why']);
    }

    public function test_the_calendar_validates_its_month(): void
    {
        $batch = $this->batch($this->ccs, 2);

        $this->actingAs($this->director)->getJson("/director/batches/{$batch->id}/calendar")->assertUnprocessable();
        $this->calendar($batch, 2026, 13)->assertUnprocessable();
        $this->calendar($batch, 2025, 12)->assertUnprocessable();
        $this->calendar($batch, 2027, 1)->assertOk();
    }

    // ── Hours ───────────────────────────────────────────────────────────────

    public function test_each_hour_names_the_colleges_in_it(): void
    {
        $this->booked($this->ccs, '2026-10-20', '09:00:00', 8);
        $this->booked($this->cea, '2026-10-20', '09:00:00', 5);

        $response = $this->hours($this->batch($this->ccs, 5), '2026-10-20')->assertOk();

        $this->assertCount(10, $response->json('hours'));
        $nine = $this->hour($response, '09:00:00');
        $this->assertSame('9:00 AM – 10:00 AM', $nine['label']);
        $this->assertSame(13, $nine['booked']);
        $this->assertSame([['code' => 'CCS', 'count' => 8], ['code' => 'CEA', 'count' => 5]], $nine['colleges']);
        $this->assertSame([], $this->hour($response, '10:00:00')['colleges']);
        $this->assertSame(20, $response->json('hourly_capacity'));
    }

    public function test_an_hour_other_colleges_use_is_selectable_while_the_batch_fits(): void
    {
        $this->booked($this->cea, '2026-10-20', '09:00:00', 8);

        $nine = $this->hour($this->hours($this->batch($this->ccs, 12), '2026-10-20'), '09:00:00');

        $this->assertTrue($nine['selectable']);   // 8 + 12 = 20
        $this->assertNull($nine['why']);
    }

    public function test_an_hour_without_room_for_the_batchs_seats_is_not_selectable(): void
    {
        $this->booked($this->cea, '2026-10-20', '09:00:00', 8);
        $this->booked($this->cea, '2026-10-20', '11:00:00', 20);

        $response = $this->hours($this->batch($this->ccs, 25), '2026-10-20');   // 20 + 5

        // From 9: the first hour takes 20 more — no room.
        $this->assertFalse($this->hour($response, '09:00:00')['selectable']);
        $this->assertSame('no room at 9:00 AM', $this->hour($response, '09:00:00')['why']);
        // From 10: its second hour, 11 AM, is full.
        $this->assertSame('no room at 11:00 AM', $this->hour($response, '10:00:00')['why']);
        // From 12: fine.
        $this->assertTrue($this->hour($response, '12:00:00')['selectable']);
    }

    public function test_an_hour_that_has_ended_today_is_not_selectable(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00', 'Asia/Manila'));

        $response = $this->hours($this->batch($this->ccs, 5), '2026-10-05');

        $this->assertFalse($this->hour($response, '11:00:00')['selectable']);
        $this->assertSame('already passed', $this->hour($response, '11:00:00')['why']);
        $this->assertTrue($this->hour($response, '12:00:00')['selectable']);
    }

    public function test_a_start_whose_span_runs_past_the_last_hour_is_not_selectable(): void
    {
        $response = $this->hours($this->batch($this->ccs, 45), '2026-10-20');   // 3 hours

        $this->assertTrue($this->hour($response, '14:00:00')['selectable']);
        $this->assertFalse($this->hour($response, '15:00:00')['selectable']);
        $this->assertSame('runs past 5:00 PM', $this->hour($response, '15:00:00')['why']);
    }

    public function test_clash_lists_the_students_already_scheduled_that_day(): void
    {
        $batch = $this->batch($this->ccs, 3);
        $students = $batch->batchRequestStudents()->orderBy('id')->pluck('student_id');

        $other = $this->batch($this->ccs, 0, 'approved', ['scheduled_date' => '2026-10-20', 'scheduled_time' => '14:00:00', 'scheduled_blocks' => 1]);
        BatchRequestStudent::create(['batch_request_id' => $other->id, 'student_id' => $students[0]]);
        BatchRequestStudent::create(['batch_request_id' => $other->id, 'student_id' => $students[1]]);

        // A PENDING batch holds nothing since D-108.
        $pending = $this->batch($this->ccs, 0);
        BatchRequestStudent::create(['batch_request_id' => $pending->id, 'student_id' => $students[2]]);

        $expected = User::whereIn('id', [$students[0], $students[1]])->orderBy('name')->pluck('name')->all();

        $this->hours($batch, '2026-10-20')->assertJsonPath('clash', ['count' => 2, 'names' => $expected]);
        $this->hours($batch, '2026-10-21')->assertJsonPath('clash', ['count' => 0, 'names' => []]);
    }

    public function test_the_hours_feed_validates_its_date(): void
    {
        $batch = $this->batch($this->ccs, 2);

        $this->hours($batch, 'not-a-date')->assertUnprocessable();
        $this->actingAs($this->director)->getJson("/director/batches/{$batch->id}/hours")->assertUnprocessable();
    }

    public function test_both_feeds_are_refused_for_other_roles(): void
    {
        $batch = $this->batch($this->ccs, 2);

        foreach (['student', 'nurse', 'physician', 'college_admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get("/director/batches/{$batch->id}/calendar?year=2026&month=10")->assertRedirect();
            $this->actingAs($user)->get("/director/batches/{$batch->id}/hours?date=2026-10-20")->assertRedirect();
        }
    }
}
