<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreAppointmentRequest;
use App\Models\Appointment;
use App\Services\ReferenceNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    /**
     * FR-STU-04: Create the appointment row inside a transaction, then route to the
     * confirmation screen. When the request expects JSON (fetch from the booking page),
     * return the confirmation URL as JSON so the client can navigate there; otherwise
     * do a normal redirect (progressive-enhancement fallback).
     */
    public function store(StoreAppointmentRequest $request, ReferenceNumberService $refService): RedirectResponse|JsonResponse
    {
        $appointment = DB::transaction(function () use ($request, $refService): Appointment {
            return Appointment::create([
                'reference_no' => $refService->generateAppointmentRef(),
                'student_id' => $request->user()->id,
                'service_type' => $request->validated('service'),
                'scheduled_date' => $request->validated('date'),
                'status' => 'scheduled',
                'source' => 'self',
            ]);
        });

        $confirmUrl = route('student.appointments.confirmed', $appointment);

        if ($request->expectsJson()) {
            return response()->json(['redirect' => $confirmUrl]);
        }

        return redirect()->to($confirmUrl);
    }

    /**
     * Show the post-booking confirmation screen (FR-STU-04).
     * Only the student who owns the appointment may view it.
     */
    public function confirmed(Request $request, Appointment $appointment): View
    {
        abort_if($appointment->student_id !== $request->user()->id, 403);

        return view('student.book-confirmed', [
            'appointment' => $appointment,
            'clinicHours' => config('healthpass.clinic_hours'),
        ]);
    }

    /**
     * FR-STU-06: Cancel a scheduled future appointment owned by the authenticated student.
     *
     * Guards (all → 403 to avoid information leakage):
     *   - appointment must belong to this student
     *   - status must be 'scheduled'
     *   - scheduled date must be strictly after today (cannot cancel on/after the day)
     */
    public function cancel(Request $request, Appointment $appointment): RedirectResponse
    {
        $user = $request->user();

        abort_if($appointment->student_id !== $user->id, 403);
        abort_if($appointment->status !== 'scheduled', 403);
        abort_if(! $appointment->scheduled_date->gt(today()), 403);

        $appointment->update(['status' => 'cancelled']);

        return redirect()
            ->route('student.dashboard')
            ->with('status', 'appointment-cancelled');
    }

    /**
     * Day numbers (1–31) where non-cancelled appointment count >= daily_capacity.
     * Implements FR-STU-03 / BR-02.
     *
     * Groups on the full scheduled_date (not DAY(...)) so the query stays portable —
     * MySQL in dev, SQLite in the test suite. The day-of-month is derived in PHP.
     * whereYear/whereMonth are compiled per-driver by Laravel, so they're safe here.
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
            ->select('scheduled_date', DB::raw('COUNT(*) as cnt'))
            ->groupBy('scheduled_date')
            ->having('cnt', '>=', $capacity)
            ->pluck('scheduled_date')
            ->map(fn ($date) => Carbon::parse($date)->day)
            ->values()
            ->all();
    }
}
