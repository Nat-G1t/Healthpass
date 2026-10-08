<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\College;
use App\Models\User;
use App\Services\ClinicScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * D-122 — the clinic screens 8 AM–4 PM: eight one-hour slots, 20 an hour,
 * 160 a day. Runs on the real config defaults (nothing pinned), so it fails
 * if the config drifts from the decision.
 *
 * Bookings made before D-122 at 7 AM or 4 PM, and days already above 160,
 * are left alone (Q14) — these tests also prove every page that shows them
 * still works.
 */
class ClinicHoursEightToFourTest extends TestCase
{
    use RefreshDatabase;

    /** A Monday, mid-morning. */
    private const TODAY = '2026-10-12 09:30:00';

    private const CLINIC_DAY = '2026-10-20';

    private College $ccs;

    private User $director;

    private User $admin;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TODAY));

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->director = User::factory()->create(['role' => 'director']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
        $this->admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
    }

    /** A pending batch of $count students — no date, no hour (D-108). */
    private function pendingBatch(int $count): BatchRequest
    {
        static $seq = 300;

        $batch = BatchRequest::create([
            'reference_no' => 'BR-2026-'.$seq++,
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'service_type' => 'medical',
        ]);

        foreach (User::factory()->count($count)->create(['role' => 'student']) as $student) {
            BatchRequestStudent::create(['batch_request_id' => $batch->id, 'student_id' => $student->id]);
        }

        return $batch;
    }

    /**
     * A batch approved BEFORE D-122 at $start for $blocks hours, $perHour
     * students seated in each — what the old 7 AM–5 PM day could produce.
     */
    private function legacyBatch(string $date, string $start, int $blocks, int $perHour): BatchRequest
    {
        static $seq = 800;

        $batch = BatchRequest::create([
            'reference_no' => 'BR-2026-'.$seq++,
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'service_type' => 'medical',
            'scheduled_date' => $date,
            'scheduled_time' => $start,
            'scheduled_blocks' => $blocks,
            'status' => 'approved',
            'reviewed_by' => $this->director->id,
            'reviewed_at' => now()->subDays(10),
        ]);

        foreach (app(ClinicScheduleService::class)->spanFrom($start, $blocks) as $slot) {
            foreach (range(1, $perHour) as $i) {
                $student = User::factory()->create(['role' => 'student']);
                $appointment = Appointment::factory()->create([
                    'student_id' => $student->id,
                    'service_type' => 'medical',
                    'scheduled_date' => $date,
                    'scheduled_time' => $slot,
                    'status' => 'scheduled',
                    'source' => 'batch',
                    'batch_request_id' => $batch->id,
                    'created_by' => $this->director->id,
                ]);
                BatchRequestStudent::create([
                    'batch_request_id' => $batch->id,
                    'student_id' => $student->id,
                    'appointment_id' => $appointment->id,
                ]);
            }
        }

        return $batch;
    }

    private function approve(BatchRequest $batch, string $start, string $date = self::CLINIC_DAY)
    {
        return $this->actingAs($this->director)
            ->post("/director/batches/{$batch->id}/approve", ['scheduled_date' => $date, 'start_time' => $start]);
    }

    // ── The new day ─────────────────────────────────────────────────────────

    public function test_the_hour_picker_lists_exactly_the_eight_new_hours(): void
    {
        // A 7 AM booking from before D-122 on the same day is not offered back.
        $this->legacyBatch(self::CLINIC_DAY, '07:00:00', 1, 3);

        $hours = $this->actingAs($this->director)
            ->getJson(route('director.batches.hours', ['batch' => $this->pendingBatch(5), 'date' => self::CLINIC_DAY]))
            ->assertOk()
            ->json('hours');

        $this->assertSame(
            ['08:00:00', '09:00:00', '10:00:00', '11:00:00', '12:00:00', '13:00:00', '14:00:00', '15:00:00'],
            array_column($hours, 'slot'),
        );
        $this->assertSame('8:00 AM – 9:00 AM', $hours[0]['label']);
        $this->assertSame('3:00 PM – 4:00 PM', $hours[7]['label']);
    }

    public function test_approval_refuses_a_span_that_runs_past_4_pm(): void
    {
        // 21 students need two hours: from 3 PM that is 3–5 PM.
        $batch = $this->pendingBatch(21);

        $this->approve($batch, '15:00:00')
            ->assertRedirect("/director/batches/{$batch->id}/review")
            ->assertSessionHas('error', fn (string $error): bool => str_contains($error, 'starting at 3:00 PM it would run past 4:00 PM'));

        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_approval_seats_160_students_from_8_am_across_all_eight_hours(): void
    {
        Queue::fake();
        $batch = $this->pendingBatch(160);

        $this->approve($batch, '08:00:00')->assertRedirect('/director/batches')->assertSessionMissing('error');

        $this->assertSame(8, (int) $batch->fresh()->scheduled_blocks);
        $perHour = Appointment::where('batch_request_id', $batch->id)->get()->countBy('scheduled_time')->sortKeys()->all();
        $this->assertSame(array_fill_keys(app(ClinicScheduleService::class)->slots(), 20), $perHour);
    }

    public function test_the_161st_seat_is_refused_on_an_empty_day(): void
    {
        $schedule = app(ClinicScheduleService::class);
        $this->assertTrue($schedule->dayHasRoomFor(0, 160));
        $this->assertFalse($schedule->dayHasRoomFor(0, 161));

        $batch = $this->pendingBatch(161);

        $this->approve($batch, '08:00:00')->assertRedirect("/director/batches/{$batch->id}/review")->assertSessionHas('error');

        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
    }

    // ── Bookings made before D-122 ──────────────────────────────────────────

    public function test_a_legacy_7_am_batch_still_renders_its_hours_everywhere(): void
    {
        $batch = $this->legacyBatch(self::CLINIC_DAY, '07:00:00', 2, 3);
        $span = '7:00 AM – 9:00 AM (2 slots)';

        $this->assertSame(['07:00:00', '08:00:00'], $batch->scheduledSpan());

        // The batch results header (College Admin and Director).
        $this->actingAs($this->admin)->get(route('admin.batches.results', $batch))->assertOk()->assertSee($span);
        $this->actingAs($this->director)->get(route('director.tracking.results', $batch))->assertOk()->assertSee($span);

        // The clinic's Upcoming card.
        $this->actingAs($this->nurse)->get(route('nurse.batches.index'))->assertOk()
            ->assertSee($batch->reference_no)->assertSee($span);

        // The calendar day view keeps the 7 AM row, with its batch.
        $day = $this->actingAs($this->nurse)
            ->getJson(route('nurse.calendar.day', ['date' => self::CLINIC_DAY]))
            ->assertOk()
            ->json();
        $seven = collect($day['hours'])->firstWhere('slot', '07:00:00');
        $this->assertSame('7:00 AM – 8:00 AM', $seven['label']);
        $this->assertSame(3, $seven['booked']);
        $this->assertSame($batch->reference_no, $seven['batches'][0]['ref']);
        $this->assertSame(6, $day['booked']);

        // The student's appointment card.
        $student = $batch->appointments()->where('scheduled_time', '07:00:00')->firstOrFail()->student;
        $this->actingAs($student)->get('/student/dashboard')->assertOk()->assertSee('7:00 AM');
    }

    public function test_a_legacy_4_pm_hour_keeps_its_label_and_its_calendar_row(): void
    {
        $batch = $this->legacyBatch(self::CLINIC_DAY, '15:00:00', 2, 2);

        $this->assertSame('3:00 PM – 5:00 PM (2 slots)', $batch->scheduledSpanLabel());

        $hours = collect($this->actingAs($this->director)
            ->getJson(route('director.calendar.day', ['date' => self::CLINIC_DAY]))
            ->assertOk()
            ->json('hours'));

        $this->assertSame('4:00 PM – 5:00 PM', $hours->firstWhere('slot', '16:00:00')['label']);
        $this->assertSame('16:00:00', $hours->last()['slot']);   // in time order
    }

    public function test_a_legacy_7_am_batch_keeps_its_first_hour_for_the_college_cancel(): void
    {
        // D-92 measures the first hour from the stored span — 7 AM, not "no hour".
        $batch = $this->legacyBatch('2026-10-13', '07:00:00', 1, 2);

        $this->assertFalse($batch->hasFirstHourStarted());

        $this->travelTo(Carbon::parse('2026-10-13 07:00:00'));
        $this->assertTrue($batch->fresh()->hasFirstHourStarted());
    }

    public function test_a_day_booked_past_the_new_cap_renders_full_and_takes_no_more(): void
    {
        // 200 seats over the old ten hours, 7 AM–5 PM.
        $this->legacyBatch(self::CLINIC_DAY, '07:00:00', 10, 20);
        $pending = $this->pendingBatch(1);

        $day = collect($this->actingAs($this->director)
            ->getJson(route('director.batches.calendar', ['batch' => $pending, 'year' => 2026, 'month' => 10]))
            ->assertOk()
            ->json('days'))->firstWhere('date', self::CLINIC_DAY);

        $this->assertSame(200, $day['booked']);
        $this->assertSame(160, $day['capacity']);
        $this->assertFalse($day['fits']);

        // The clinic's read-only calendar and the Review page still render.
        $this->actingAs($this->director)
            ->getJson(route('director.calendar.day', ['date' => self::CLINIC_DAY]))
            ->assertOk()
            ->assertJsonPath('booked', 200)
            ->assertJsonCount(10, 'hours');
        $this->actingAs($this->director)->get("/director/batches/{$pending->id}/review")->assertOk();

        // …and it simply refuses new seats.
        $this->approve($pending, '08:00:00')->assertSessionHas('error');
        $this->assertSame('pending', $pending->fresh()->status);
    }
}
