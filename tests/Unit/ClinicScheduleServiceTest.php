<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ClinicScheduleService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * D-37 — the one-hour slot grid and the batch-span maths.
 *
 * These are the pure (no-database) rules: what the slots are, how many hours a
 * batch of N students occupies, and when a span runs past closing time.
 */
class ClinicScheduleServiceTest extends TestCase
{
    private function service(): ClinicScheduleService
    {
        return app(ClinicScheduleService::class);
    }

    // ── Slot grid derives from config, not from a hardcoded list ─────────────

    public function test_the_clinic_day_is_ten_one_hour_slots(): void
    {
        $slots = $this->service()->slots();

        $this->assertCount(10, $slots);
        $this->assertSame('07:00:00', $slots[0]);
        $this->assertSame('16:00:00', $slots[9]);
    }

    public function test_the_lunch_hour_is_a_bookable_slot(): void
    {
        $this->assertContains('12:00:00', $this->service()->slots());
    }

    public function test_slot_list_follows_the_clinic_hours_config(): void
    {
        // Proves the grid is DERIVED, not hardcoded: shrink the clinic day and
        // the slot list must shrink with it.
        config(['healthpass.clinic_hours.open' => '09:00']);
        config(['healthpass.clinic_hours.close' => '12:00']);

        $this->assertSame(
            ['09:00:00', '10:00:00', '11:00:00'],
            $this->service()->slots(),
        );
    }

    public function test_capacities_come_from_config(): void
    {
        // D-95: 20 an hour (a 3-minute kiosk budget), 20 × ten slots a day.
        $this->assertSame(20, $this->service()->hourlyCapacity());
        $this->assertSame(200, $this->service()->dailyCapacity());
        $this->assertSame(200, $this->service()->maxBatchSize());
    }

    // ── Labels ───────────────────────────────────────────────────────────────

    public function test_slot_label_shows_the_whole_hour(): void
    {
        $this->assertSame('7:00 AM – 8:00 AM', $this->service()->label('07:00:00'));
        $this->assertSame('4:00 PM', $this->service()->startLabel('16:00:00'));
    }

    public function test_span_label_reads_as_a_range_with_a_slot_count(): void
    {
        $span = $this->service()->spanForStudents('07:00:00', 45);

        $this->assertSame('7:00 AM – 10:00 AM (3 slots)', $this->service()->spanLabel($span));
    }

    // ── Span maths (FR-ADM-04) ───────────────────────────────────────────────

    public function test_batch_of_forty_five_spans_three_hours(): void
    {
        $this->assertSame(3, $this->service()->blocksFor(45));
    }

    public function test_batch_of_forty_spans_two_hours(): void
    {
        $this->assertSame(2, $this->service()->blocksFor(40));
    }

    public function test_a_single_student_still_takes_one_whole_hour(): void
    {
        $this->assertSame(1, $this->service()->blocksFor(1));
    }

    public function test_span_returns_the_contiguous_slots(): void
    {
        $this->assertSame(
            ['07:00:00', '08:00:00', '09:00:00'],
            $this->service()->spanForStudents('07:00:00', 45),
        );
    }

    public function test_span_that_would_cross_closing_time_is_empty(): void
    {
        // 45 students need 3 hours; starting at 3 PM would end at 6 PM.
        $this->assertSame([], $this->service()->spanForStudents('15:00:00', 45));
    }

    public function test_span_ending_exactly_at_closing_time_fits(): void
    {
        $this->assertSame(
            ['14:00:00', '15:00:00', '16:00:00'],
            $this->service()->spanForStudents('14:00:00', 45),
        );
    }

    public function test_seats_by_slot_fills_each_hour_to_the_cap_and_leaves_the_remainder_last(): void
    {
        // D-91: the same split the approve fan-out makes — 20 + 20 + 5.
        $span = $this->service()->span('07:00:00', 3);

        $this->assertSame(
            ['07:00:00' => 20, '08:00:00' => 20, '09:00:00' => 5],
            $this->service()->seatsBySlot($span, 45),
        );
    }

    // ── Arrival times (D-96) ─────────────────────────────────────────────────

    public function test_arrival_times_are_one_kiosk_budget_apart_from_the_hourly_capacity(): void
    {
        // 20 an hour → 3 minutes apart; the grid follows the config, so 24 an
        // hour would be 2½ minutes apart.
        $this->assertSame(['07:00:00', '07:03:00'], array_slice($this->service()->arrivalTimes('07:00:00'), 0, 2));

        config(['healthpass.hourly_capacity' => 24]);

        $times = $this->service()->arrivalTimes('07:00:00');
        $this->assertCount(24, $times);
        $this->assertSame(['07:00:00', '07:02:30', '07:05:00'], array_slice($times, 0, 3));
    }

    // ── Elapsed hours (BR-23) ────────────────────────────────────────────────

    /**
     * The exact boundary Nat asked about: at 12:00 sharp the 11 AM–12 PM hour
     * is over, and 12–1 PM is the earliest thing still bookable.
     */
    public function test_at_noon_the_eleven_oclock_slot_has_passed_and_noon_has_not(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));
        $today = today()->toDateString();

        $this->assertTrue($this->service()->isSlotElapsed($today, '11:00:00'));
        $this->assertFalse($this->service()->isSlotElapsed($today, '12:00:00'));
    }

    public function test_a_slot_survives_until_it_ends(): void
    {
        // 11:59 — the 11 AM hour is nearly over but still bookable, matching
        // BR-20's "today stays bookable at 16:59" behaviour.
        Carbon::setTestNow(Carbon::parse('2026-07-27 11:59', 'Asia/Manila'));

        $this->assertFalse($this->service()->isSlotElapsed(today()->toDateString(), '11:00:00'));
    }

    public function test_elapsed_slots_lists_every_hour_already_gone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00', 'Asia/Manila'));

        $this->assertSame(
            ['07:00:00', '08:00:00', '09:00:00', '10:00:00', '11:00:00'],
            $this->service()->elapsedSlots(today()->toDateString()),
        );
    }

    public function test_a_future_date_has_no_elapsed_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 16:30', 'Asia/Manila'));

        $this->assertSame([], $this->service()->elapsedSlots(today()->addDay()->toDateString()));
    }

    public function test_after_closing_no_hour_of_today_is_left(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 17:00', 'Asia/Manila'));

        $this->assertCount(10, $this->service()->elapsedSlots(today()->toDateString()));
    }

    public function test_a_time_outside_the_grid_is_not_a_slot(): void
    {
        $this->assertFalse($this->service()->isSlot('06:00:00'));
        $this->assertFalse($this->service()->isSlot('17:00:00'));
        $this->assertFalse($this->service()->isSlot('07:30:00'));
        $this->assertTrue($this->service()->isSlot('07:00:00'));
    }
}
