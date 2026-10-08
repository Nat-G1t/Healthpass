<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * D-119 — the read-only Clinic Calendar card and its two feeds
 * (ClinicCalendarController), on the Director's Batch Tracking page and the
 * clinic's Upcoming Batches page.
 *
 * The month feed gives each day's booked count (the ring) and whether it can
 * be opened — today onward, on a clinic weekday. The day feed lists the eight
 * clinic hours with their booked count and the batches holding them. Both are
 * read-only and answer the Director and clinic staff only.
 */
class ClinicCalendarTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private User $director;

    private User $nurse;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
        $this->director = User::factory()->create(['role' => 'director']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
        $this->admin = User::factory()->create(['role' => 'college_admin', 'managed_college_id' => $this->ccs->id]);
    }

    /** An approved batch with $count seats in $slot on $date. */
    private function batchWithSeats(string $ref, string $date, string $slot, int $count, string $status = 'scheduled'): BatchRequest
    {
        $batch = BatchRequest::create([
            'reference_no' => $ref,
            'college_id' => $this->ccs->id,
            'requested_by' => $this->admin->id,
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'service_type' => 'medical',
            'scheduled_date' => $date,
            'scheduled_time' => $slot,
            'scheduled_blocks' => 1,
            'status' => 'approved',
            'reviewed_by' => $this->director->id,
            'reviewed_at' => now(),
        ]);

        foreach (range(1, $count) as $i) {
            Appointment::factory()->create([
                'student_id' => User::factory()->create(['role' => 'student'])->id,
                'service_type' => 'medical',
                'scheduled_date' => $date,
                'scheduled_time' => $slot,
                'status' => $status,
                'source' => 'batch',
                'batch_request_id' => $batch->id,
                'created_by' => $this->director->id,
            ]);
        }

        return $batch;
    }

    public function test_the_month_feed_rings_each_day_and_shuts_past_days(): void
    {
        $this->batchWithSeats('BR-2026-101', '2026-10-20', '09:00:00', 3);
        $this->batchWithSeats('BR-2026-102', '2026-10-20', '13:00:00', 2);
        // A cancelled seat holds nothing.
        $this->batchWithSeats('BR-2026-103', '2026-10-20', '10:00:00', 4, status: 'cancelled');

        $days = collect($this->actingAs($this->director)
            ->getJson(route('director.calendar.month', ['year' => 2026, 'month' => 10]))
            ->assertOk()
            ->json('days'))->keyBy('date');

        $this->assertCount(31, $days);
        $this->assertSame(5, $days['2026-10-20']['booked']);
        $this->assertSame((int) config('healthpass.daily_capacity'), $days['2026-10-20']['capacity']);
        $this->assertTrue($days['2026-10-20']['open']);
        // Today opens; yesterday does not (read on the server's clock).
        $this->assertTrue($days['2026-10-14']['open']);
        $this->assertFalse($days['2026-10-13']['open']);
        $this->assertSame('Past', $days['2026-10-13']['why']);
    }

    public function test_the_calendar_does_not_go_back_before_this_month(): void
    {
        $this->actingAs($this->director)
            ->getJson(route('director.calendar.month', ['year' => 2026, 'month' => 9]))
            ->assertStatus(422);
    }

    public function test_the_day_feed_lists_each_hour_with_its_batches(): void
    {
        $this->batchWithSeats('BR-2026-101', '2026-10-20', '09:00:00', 3);
        $this->batchWithSeats('BR-2026-102', '2026-10-20', '09:00:00', 2);

        $day = $this->actingAs($this->nurse)
            ->getJson(route('nurse.calendar.day', ['date' => '2026-10-20']))
            ->assertOk()
            ->json();

        $this->assertSame(5, $day['booked']);
        $this->assertCount(8, $day['hours']);

        $nine = collect($day['hours'])->firstWhere('slot', '09:00:00');
        $this->assertSame(5, $nine['booked']);
        $this->assertSame((int) config('healthpass.hourly_capacity'), $nine['capacity']);
        $this->assertSame([
            ['ref' => 'BR-2026-101', 'college' => 'CCS', 'count' => 3],
            ['ref' => 'BR-2026-102', 'college' => 'CCS', 'count' => 2],
        ], $nine['batches']);
        $this->assertSame([], collect($day['hours'])->firstWhere('slot', '10:00:00')['batches']);
    }

    public function test_the_day_feed_refuses_a_past_day(): void
    {
        $this->actingAs($this->director)
            ->getJson(route('director.calendar.day', ['date' => '2026-10-13']))
            ->assertStatus(422);
    }

    public function test_only_the_director_and_clinic_staff_reach_the_feeds(): void
    {
        $physician = User::factory()->create(['role' => 'physician']);
        $month = ['year' => 2026, 'month' => 10];

        $this->actingAs($physician)->getJson(route('nurse.calendar.month', $month))->assertOk();
        $this->actingAs($this->admin)->get(route('director.calendar.month', $month))->assertRedirect();
        $this->actingAs($this->admin)->get(route('nurse.calendar.month', $month))->assertRedirect();
        $this->actingAs($this->nurse)->get(route('director.calendar.month', $month))->assertRedirect();
    }

    public function test_the_card_sits_between_the_two_tables_on_both_pages(): void
    {
        $this->actingAs($this->director)->get(route('director.tracking.index'))
            ->assertOk()
            ->assertSeeInOrder(['Batch Results', 'Clinic Calendar', 'Rejected and Cancelled batches'])
            ->assertSee('data-clinic-calendar', false);

        $this->actingAs($this->nurse)->get(route('nurse.batches.index'))
            ->assertOk()
            ->assertSeeInOrder(['>Upcoming<', 'Clinic Calendar', '>Past<'], false)
            ->assertSee('data-clinic-calendar', false);
    }
}
