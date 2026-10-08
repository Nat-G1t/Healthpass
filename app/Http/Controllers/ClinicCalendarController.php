<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ClinicScheduleService;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * D-119 — the read-only Clinic Calendar card's two JSON feeds.
 *
 * The card shows the same month calendar and fill rings the Director
 * schedules with (Review page, D-108), but only to LOOK: pick a day and see
 * how full each clinic hour is and which batches hold it. It sits on the
 * Director's Batch Tracking page and on the clinic's Upcoming Batches page,
 * so this one controller is routed twice — once inside each role's group,
 * which is where the access check lives (`role:director` / `role:nurse,physician`).
 *
 * Today onward only (Nat, 2026-10-05): a past day is greyed in the month feed
 * and refused by the day feed. "Today" is the SERVER's date, never the
 * browser's (BR-20's rule).
 */
class ClinicCalendarController extends Controller
{
    public function __construct(private readonly ClinicScheduleService $schedule) {}

    /**
     * One month's days: how many students are booked on each (the ring) and
     * whether the day can be opened.
     *
     * @return JsonResponse {days: list<{date, booked, capacity, open, why}>}
     */
    public function month(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:'.today()->year, 'max:'.(today()->year + 2)],
            'month' => ['required', 'integer', 'between:1,12'],
        ]);

        $first = Carbon::create((int) $validated['year'], (int) $validated['month'], 1);
        abort_if($first->lt(today()->startOfMonth()), 422, 'The calendar starts at this month.');

        $bookings = $this->schedule->bookingsForMonth($first->year, $first->month);
        $bookingDays = (array) config('healthpass.booking_days');
        $today = today()->toDateString();
        $days = [];

        foreach (CarbonPeriod::create($first, $first->copy()->endOfMonth()) as $day) {
            $date = $day->toDateString();

            // 'Y-m-d' strings sort as dates, so < means "before today".
            $why = match (true) {
                $date < $today => 'Past',
                ! in_array($day->dayOfWeek, $bookingDays, true) => 'No clinic on this day',
                default => null,
            };

            $days[] = [
                'date' => $date,
                'booked' => $bookings[$date]['total'] ?? 0,
                'capacity' => $this->schedule->dailyCapacity(),
                'open' => $why === null,
                'why' => $why,
            ];
        }

        return response()->json(['days' => $days]);
    }

    /**
     * One day's clinic hours: how many are booked in each, out of the hourly
     * capacity, and the batches holding those seats.
     *
     * @return JsonResponse {booked, capacity, hours: list<{slot, label, booked, capacity, batches}>}
     */
    public function day(Request $request): JsonResponse
    {
        $date = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before:'.today()->addYears(3)->startOfYear()->toDateString()],
        ])['date'];

        $batches = $this->schedule->batchesBySlot($date);
        $hours = [];

        // D-122: a 7 AM / 4 PM booking from before the 8–4 day keeps its row.
        foreach ($this->schedule->bookedBySlotWithLegacy($date) as $slot => $booked) {
            $hours[] = [
                'slot' => $slot,
                'label' => $this->schedule->label($slot),
                'booked' => $booked,
                'capacity' => $this->schedule->hourlyCapacity(),
                'batches' => $batches[$slot] ?? [],
            ];
        }

        return response()->json([
            // The whole day, so a pre-D-37 row with no hour still counts here.
            'booked' => $this->schedule->bookedOnDate($date),
            'capacity' => $this->schedule->dailyCapacity(),
            'hours' => $hours,
        ]);
    }
}
