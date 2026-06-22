<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookAppointmentController extends Controller
{
    public function show(): View
    {
        $year = (int) now()->format('Y');
        $month = (int) now()->format('n');

        return view('student.book', [
            'year' => $year,
            'month' => $month,
            'fullDays' => $this->fullDaysForMonth($year, $month),
            'bookingDays' => config('healthpass.booking_days'),
        ]);
    }

    /**
     * JSON: day numbers in the given month where non-cancelled count >= daily_capacity.
     * Consumed by the Alpine calendar on prev/next navigation.
     */
    public function availability(Request $request): JsonResponse
    {
        $year = max(now()->year, min((int) $request->query('year', now()->year), now()->year + 2));
        $month = max(1, min((int) $request->query('month', now()->month), 12));

        return response()->json(['full_days' => $this->fullDaysForMonth($year, $month)]);
    }

    /** Stub — FR-STU-04 (create appointment row + reference no.) wired up next. */
    public function store(): RedirectResponse
    {
        return back()->with('status', 'booking-submitted');
    }

    /**
     * Day numbers (1–31) where non-cancelled appointment count >= daily_capacity.
     * Implements FR-STU-03 / BR-02.
     *
     * @return int[]
     */
    private function fullDaysForMonth(int $year, int $month): array
    {
        $capacity = (int) config('healthpass.daily_capacity');

        return Appointment::query()
            ->whereYear('scheduled_date', $year)
            ->whereMonth('scheduled_date', $month)
            ->where('status', '!=', 'cancelled')
            ->selectRaw('DAY(scheduled_date) as day, COUNT(*) as cnt')
            ->groupByRaw('DAY(scheduled_date)')
            ->havingRaw('cnt >= ?', [$capacity])
            ->pluck('day')
            ->map(fn ($d) => (int) $d)
            ->values()
            ->all();
    }
}
