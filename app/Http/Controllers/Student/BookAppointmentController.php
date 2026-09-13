<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreAppointmentRequest;
use App\Jobs\SendAppointmentScheduledMail;
use App\Models\Appointment;
use App\Services\ClinicScheduleService;
use App\Services\ReferenceNumberService;
use App\Services\ScheduleClashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookAppointmentController extends Controller
{
    public function __construct(
        private readonly ClinicScheduleService $schedule,
        private readonly ScheduleClashService $clashes,
    ) {}

    public function show(): View
    {
        $year = (int) now()->format('Y');
        $month = (int) now()->format('n');

        return view('student.book', [
            'year' => $year,
            'month' => $month,
            'fullDays' => $this->schedule->fullDaysForMonth($year, $month),
            'cutoffDays' => $this->schedule->cutoffDaysForMonth($year, $month),
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
            'full_days' => $this->schedule->fullDaysForMonth($year, $month),
            'cutoff_days' => $this->schedule->cutoffDaysForMonth($year, $month),
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

            // D-54 / BR-25: re-read, under the same lock, whether a batch holds
            // this student during the hour — their College Admin may have
            // submitted one between the Form Request's read and this insert.
            if (in_array($slot, $this->clashes->blockedSlotsForStudent($userId, $date, lock: true), true)) {
                throw ValidationException::withMessages([
                    'time' => $this->clashes->studentClashMessage($date, $slot),
                ]);
            }

            // D-37: the per-slot cap lives inside the SAME locked block — the
            // 12-seat hour is exactly the resource two students race for.
            if ($this->schedule->bookedInSlot($date, $slot, lock: true) >= $this->schedule->hourlyCapacity()) {
                throw ValidationException::withMessages([
                    'time' => $this->schedule->slotFullMessage($slot),
                ]);
            }

            // BR-04 counts SELF-bookings only since D-54 (see the Form Request).
            $duplicate = Appointment::where('student_id', $userId)
                ->where('source', 'self')
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
}
