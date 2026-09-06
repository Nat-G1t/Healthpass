<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreAppointmentRequest;
use App\Jobs\SendAppointmentScheduledMail;
use App\Models\Appointment;
use App\Services\ClinicScheduleService;
use App\Services\ReferenceNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookAppointmentController extends Controller
{
    public function __construct(private readonly ClinicScheduleService $schedule) {}

    public function show(): View
    {
        $year = (int) now()->format('Y');
        $month = (int) now()->format('n');

        return view('student.book', [
            'year' => $year,
            'month' => $month,
            'fullDays' => $this->fullDaysForMonth($year, $month),
            'cutoffDays' => $this->cutoffDaysForMonth($year, $month),
            'bookingDays' => config('healthpass.booking_days'),
            // D-37: the slot picker's options come from the config-derived grid,
            // never from a list typed into the Blade file.
            'slots' => array_map(fn (string $slot): array => [
                'value' => $slot,
                'label' => $this->schedule->label($slot),
            ], $this->schedule->slots()),
            'slotCapacity' => $this->schedule->hourlyCapacity(),
        ]);
    }

    /**
     * JSON availability for the booking calendar.
     *
     * Always: the month's full days and the BR-20 cutoff day.
     * Additionally, when a `date` is supplied (the student just picked one),
     * that date's per-slot availability (D-37) — booked, remaining, full — so
     * the slot picker can grey out the hours that are already at 12. One
     * endpoint, as required, rather than a second route for slots.
     */
    public function availability(Request $request): JsonResponse
    {
        $year = max(now()->year, min((int) $request->query('year', now()->year), now()->year + 2));
        $month = max(1, min((int) $request->query('month', now()->month), 12));

        $payload = [
            'full_days' => $this->fullDaysForMonth($year, $month),
            'cutoff_days' => $this->cutoffDaysForMonth($year, $month),
        ];

        $date = (string) $request->query('date', '');

        if ($date !== '' && Carbon::hasFormat($date, 'Y-m-d')) {
            $payload['slots'] = $this->schedule->slotAvailability($date);
            $payload['slot_capacity'] = $this->schedule->hourlyCapacity();
        }

        return response()->json($payload);
    }

    /**
     * FR-STU-04: Create the appointment row inside a transaction, then route to the
     * confirmation screen. When the request expects JSON (fetch from the booking page),
     * return the confirmation URL as JSON so the client can navigate there; otherwise
     * do a normal redirect (progressive-enhancement fallback).
     */
    public function store(StoreAppointmentRequest $request, ReferenceNumberService $refService): RedirectResponse|JsonResponse
    {
        $userId = $request->user()->id;
        $service = $request->validated('service');
        $date = $request->validated('date');
        $slot = $request->validated('time');

        $appointment = DB::transaction(function () use ($request, $refService, $userId, $service, $date, $slot): Appointment {
            // The Form Request already checked capacity (BR-02, D-37) and the
            // one-active-per-student-per-date rule (BR-04), but those reads are
            // unlocked and race with a concurrent booking (two tabs, a double-click,
            // or two students grabbing the last seat in the same hour): both pass
            // the check, both insert. Re-check HERE under a row lock so the read
            // and the insert are one atomic unit. lockForUpdate() takes range/gap
            // locks on MySQL (and SQLite serializes write transactions), so the
            // second caller blocks until the first commits, then sees the
            // up-to-date count/duplicate.
            if ($this->schedule->bookedOnDate($date, lock: true) >= $this->schedule->dailyCapacity()) {
                throw ValidationException::withMessages([
                    'date' => 'This day is fully booked. Please select a different date.',
                ]);
            }

            // D-37: the per-slot cap lives inside the SAME locked block — the
            // 12-seat hour is exactly the resource two students race for.
            if ($this->schedule->bookedInSlot($date, $slot, lock: true) >= $this->schedule->hourlyCapacity()) {
                throw ValidationException::withMessages([
                    'time' => $this->schedule->slotFullMessage($slot),
                ]);
            }

            $duplicate = Appointment::where('student_id', $userId)
                ->where('service_type', $service)
                ->whereDate('scheduled_date', $date)
                ->where('status', '!=', 'cancelled')
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'date' => 'You already have an active '.ucfirst($service).' appointment on this date.',
                ]);
            }

            return Appointment::create([
                'reference_no' => $refService->generateAppointmentRef(),
                'student_id' => $userId,
                'service_type' => $service,
                // D-28: student-chosen purpose of the medical clearance. NULL for
                // dental (normalized away in the Form Request); purpose_other holds
                // the free-text event only when "Others" was picked.
                'purpose' => $request->validated('purpose'),
                'purpose_other' => $request->validated('purpose_other'),
                'scheduled_date' => $date,
                'scheduled_time' => $slot, // D-37
                'status' => 'scheduled',
                'source' => 'self',
            ]);
        });

        // FR-STU-12 (D-39): confirmation email, queued, and only now that the
        // transaction above has COMMITTED. Dispatching from inside it would let
        // a mail failure roll back a perfectly good appointment — and would
        // hand the worker an appointment id that no other connection can see
        // yet. Nothing below can fail the booking: the dispatch is one INSERT
        // into `jobs`, and the send itself happens in another process.
        SendAppointmentScheduledMail::dispatch($appointment);

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

        // FR-STU-11: on the student's first-ever booking (their total appointment
        // count — any status — is exactly this one), the view shows a one-time
        // modal recommending the kiosk tutorial. Computed server-side.
        $isFirstBooking = Appointment::where('student_id', $request->user()->id)->count() === 1;

        return view('student.book-confirmed', [
            'appointment' => $appointment,
            'clinicHours' => config('healthpass.clinic_hours'),
            'isFirstBooking' => $isFirstBooking,
        ]);
    }

    /**
     * FR-STU-06: Cancel a scheduled future appointment owned by the authenticated student.
     *
     * Guards (all → 403 to avoid information leakage):
     *   - appointment must belong to this student
     *   - status must be 'scheduled'
     *   - scheduled date must be strictly after today (cannot cancel on/after the day)
     *   - source must not be 'batch' (D-39)
     *
     * D-39 added that last one. A batch appointment belongs to the cohort the
     * College Admin booked; a student silently dropping out of it left the
     * college's roster wrong with nobody informed, so withdrawal is the admin's
     * call. The last three conditions live together in
     * Appointment::isSelfCancellable(), which the dashboard and confirmation
     * views also call to decide whether to draw the button — one rule, so the
     * button and the server can never disagree.
     */
    public function cancel(Request $request, Appointment $appointment): RedirectResponse
    {
        $user = $request->user();

        abort_if($appointment->student_id !== $user->id, 403);
        abort_unless($appointment->isSelfCancellable(), 403);

        $appointment->update(['status' => 'cancelled']);

        // Send the student back to the page they cancelled from. `from` is
        // matched against a two-value ALLOW-LIST, never used as a URL: a
        // redirect target taken straight from the request body is an open
        // redirect, and this form is reachable by any logged-in student.
        $route = $request->input('from') === 'list'
            ? 'student.my-appointments'
            : 'student.dashboard';

        return redirect()
            ->route($route)
            ->with('status', 'appointment-cancelled');
    }

    /**
     * Day numbers (1–31) the calendar greys out as FULL. FR-STU-03 / BR-02 / D-37.
     *
     * Since D-37 a day is full when EVERY one of its slots is at the hourly cap —
     * a day with one free hour left is still bookable. The outer daily cap is
     * kept as a second condition because it is the only one that sees legacy
     * pre-D-37 rows (scheduled_time NULL), which sit in no slot.
     *
     * Portability (CLAUDE.md): one grouped query over the raw
     * (scheduled_date, scheduled_time) pair — no DAY()/MONTH()/HOUR() in
     * selectRaw or havingRaw — and the per-day roll-up is done in PHP.
     * whereYear/whereMonth are compiled per-driver by Laravel, so they're safe.
     *
     * @return int[]
     */
    private function fullDaysForMonth(int $year, int $month): array
    {
        $hourlyCapacity = $this->schedule->hourlyCapacity();
        $dailyCapacity = $this->schedule->dailyCapacity();
        $slotCount = count($this->schedule->slots());

        $rows = Appointment::query()
            ->whereYear('scheduled_date', $year)
            ->whereMonth('scheduled_date', $month)
            ->where('status', '!=', 'cancelled')
            ->select('scheduled_date', 'scheduled_time', DB::raw('COUNT(*) as cnt'))
            ->groupBy('scheduled_date', 'scheduled_time')
            ->get();

        $fullSlotsPerDay = [];
        $totalPerDay = [];

        foreach ($rows as $row) {
            $day = Carbon::parse($row->scheduled_date)->day;
            $count = (int) $row->cnt;

            $totalPerDay[$day] = ($totalPerDay[$day] ?? 0) + $count;

            if ($row->scheduled_time !== null && $count >= $hourlyCapacity) {
                $fullSlotsPerDay[$day] = ($fullSlotsPerDay[$day] ?? 0) + 1;
            }
        }

        $fullDays = [];

        foreach ($totalPerDay as $day => $total) {
            $everySlotFull = $slotCount > 0 && ($fullSlotsPerDay[$day] ?? 0) >= $slotCount;

            if ($everySlotFull || $total >= $dailyCapacity) {
                $fullDays[] = $day;
            }
        }

        // BR-23: TODAY is also unavailable once no hour is left to book — every
        // slot has either filled up or already ended. Only today can have
        // elapsed hours, so no other day needs this check (and a day with zero
        // appointments never reaches the loop above, which is exactly the
        // late-afternoon case that matters here).
        $today = today();

        if ($year === $today->year && $month === $today->month
            && ! in_array($today->day, $fullDays, true)
            && ! $this->schedule->hasAvailableSlot($today->toDateString())) {
            $fullDays[] = $today->day;
        }

        sort($fullDays);

        return $fullDays;
    }

    /**
     * Day numbers unavailable because of the same-day closing cutoff (BR-20).
     *
     * Returns today's day-of-month only when the given month is the current month AND
     * the local clock has reached closing_hour — i.e. at most one entry, and only for
     * the current month. The cutoff is decided here (server-side), never trusted from
     * the browser clock; the calendar just greys out whatever this returns.
     *
     * @return int[]
     */
    private function cutoffDaysForMonth(int $year, int $month): array
    {
        $today = today();
        $closingHour = (int) config('healthpass.closing_hour');

        $isCurrentMonth = $year === $today->year && $month === $today->month;

        if ($isCurrentMonth && now()->hour >= $closingHour) {
            return [$today->day];
        }

        return [];
    }
}
