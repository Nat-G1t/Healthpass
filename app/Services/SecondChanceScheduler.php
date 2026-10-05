<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * D-110 / BR-27 — one automatic 2nd-chance day for students who miss theirs.
 *
 * At the absent cutoff (`healthpass.absent_cutoff`, 8:00 PM) on a clinic day,
 * the students of each batch who did not make it are booked TOGETHER on the
 * nearest day that fits all of them, at that day's earliest free hours, on the
 * same batch. The healthpass:second-chances command runs this class and emails
 * the students; the rules all live here, so the tests can call them directly.
 *
 * "Missed" (Q3): an appointment of an APPROVED batch whose cutoff has passed
 * with no clinic visit at all, or with only a `resting` one (sent to rest,
 * never came back — D-72). A `captured` visit the clinic hasn't encoded is the
 * clinic's delay, not the student's, so it is never rescheduled.
 *
 * Like the Director's approval (BatchApprovalController::approve()), the day
 * is chosen and booked inside ONE transaction with the capacity reads locked,
 * so an approval running at the same moment cannot fill the same seats.
 */
class SecondChanceScheduler
{
    public function __construct(
        private readonly ClinicScheduleService $schedule,
        private readonly ReferenceNumberService $references,
    ) {}

    /**
     * Every appointment that is missed as of $now and still waiting for its
     * 2nd chance, oldest first:
     *
     *   - its cutoff passed within the last `second_chance.catch_up_hours`
     *     hours (24) — older absences, including every one from before D-110,
     *     are never rescheduled;
     *   - still `scheduled`, with no SUBMITTED visit (none, or only resting);
     *   - not itself a 2nd chance, and no 2nd chance booked for it yet;
     *   - on an approved batch, for a student whose account is still active
     *     (a graduated student is left Unattended).
     *
     * Portable SQL (CLAUDE.md): the query only narrows to a range of dates
     * computed here in PHP; the exact "cutoff inside the window" test then runs
     * in PHP with Appointment::absentCutoffOn(), the same cutoff every status
     * reads.
     *
     * @return Collection<int, Appointment>
     */
    public function missedAppointments(Carbon $now): Collection
    {
        $windowStart = $now->copy()->subHours((int) config('healthpass.second_chance.catch_up_hours'));

        return Appointment::query()
            ->where('status', 'scheduled')
            ->whereNull('rescheduled_from_id')
            ->whereDoesntHave('secondChance')
            ->whereDoesntHave('clinicVisit', fn (Builder $visit) => $visit->submitted())
            ->whereHas('batchRequest', fn (Builder $batch) => $batch->where('status', 'approved'))
            ->whereHas('student', fn (Builder $student) => $student->where('status', 'active'))
            ->whereDate('scheduled_date', '>=', $windowStart->toDateString())
            ->whereDate('scheduled_date', '<=', $now->toDateString())
            ->with('batchRequest')
            ->orderBy('id')
            ->get()
            ->filter(function (Appointment $appointment) use ($now, $windowStart): bool {
                $cutoff = Appointment::absentCutoffOn($appointment->scheduled_date);

                return $cutoff->lte($now) && $cutoff->gt($windowStart);
            })
            ->values();
    }

    /**
     * Book one batch's missed students on their 2nd-chance day, and return
     * that day — or null when no day within `second_chance.search_days` fits
     * them all, in which case nobody is booked (they stay Unattended).
     *
     * One DB::transaction: find the day, create one appointment per student,
     * and re-point each student's roster row at their new appointment, so
     * every batch page reads the student's CURRENT appointment. The missed one
     * stays reachable through `rescheduled_from_id`.
     *
     * A second run racing this one fails on the unique `rescheduled_from_id`
     * index and rolls back whole; the first run's booking stands.
     *
     * @param  Collection<int, Appointment>  $missed  one batch's missed appointments
     */
    public function scheduleFor(BatchRequest $batch, Collection $missed): ?Carbon
    {
        $missed = $missed->sortBy('id')->values();
        $studentIds = $missed->pluck('student_id')->all();

        return DB::transaction(function () use ($batch, $missed, $studentIds): ?Carbon {
            // Q5: the nearest day FROM TOMORROW that fits the whole group.
            foreach (range(1, (int) config('healthpass.second_chance.search_days')) as $daysAhead) {
                $date = today()->addDays($daysAhead);
                $seats = $this->seatsOn($date->toDateString(), $studentIds);

                if ($seats !== null) {
                    $this->book($batch, $missed, $date->toDateString(), $seats);

                    return $date;
                }
            }

            return null;
        });
    }

    /**
     * The hour each student would take on $date — one slot key per student,
     * in order — or null when the group does not fit there together.
     *
     * "Fit" (Q5), with the counts read under lock like approve():
     *   - the clinic takes bookings that weekday (`healthpass.booking_days`);
     *   - the day stays within daily_capacity (BR-02);
     *   - nobody in the group already has a clinic schedule that day (BR-25);
     *   - the hours have enough free seats between them.
     *
     * Hours (Q4): the EARLIEST free ones — from the first clinic hour, each
     * hour's free seats in order. 7–8 AM holding 18 of 20 takes two of the
     * group; the rest start at 8 AM.
     *
     * @param  list<int>  $studentIds
     * @return list<string>|null
     */
    private function seatsOn(string $date, array $studentIds): ?array
    {
        $groupSize = count($studentIds);

        if (! in_array(Carbon::parse($date)->dayOfWeek, (array) config('healthpass.booking_days'), true)) {
            return null;
        }

        if (! $this->schedule->dayHasRoomFor($this->schedule->bookedOnDate($date, lock: true), $groupSize)) {
            return null;
        }

        $alreadyScheduled = Appointment::query()
            ->whereIn('student_id', $studentIds)
            ->whereDate('scheduled_date', $date)
            ->where('status', '!=', 'cancelled')
            ->lockForUpdate()
            ->count();

        if ($alreadyScheduled > 0) {
            return null;
        }

        $seats = [];

        foreach ($this->schedule->slots() as $slot) {
            $free = $this->schedule->hourlyCapacity() - $this->schedule->bookedInSlot($date, $slot, lock: true);
            $take = max(0, min($free, $groupSize - count($seats)));
            $seats = [...$seats, ...array_fill(0, $take, $slot)];

            if (count($seats) === $groupSize) {
                return $seats;
            }
        }

        return null;
    }

    /**
     * Create the 2nd-chance appointments and re-point the roster rows.
     *
     * Each student takes the next FREE arrival time in their hour (D-96),
     * read once per hour like approve() does and handed out in order;
     * array_shift() gives null only if the cap was lowered after earlier
     * times were handed out. `created_by` is NULL: the system booked it, not
     * a person.
     *
     * @param  Collection<int, Appointment>  $missed
     * @param  list<string>  $seats
     */
    private function book(BatchRequest $batch, Collection $missed, string $date, array $seats): void
    {
        $freeTimesBySlot = [];

        foreach ($missed as $index => $original) {
            $slot = $seats[$index];
            $freeTimesBySlot[$slot] ??= $this->schedule->freeArrivalTimes($date, $slot);

            $secondChance = Appointment::create([
                'reference_no' => $this->references->generateAppointmentRef(),
                'student_id' => $original->student_id,
                'service_type' => $original->service_type,
                'scheduled_date' => $date,
                'scheduled_time' => $slot,
                'arrival_time' => array_shift($freeTimesBySlot[$slot]),
                'status' => 'scheduled',
                'source' => 'batch',
                'batch_request_id' => $batch->id,
                'rescheduled_from_id' => $original->id,
                'created_by' => null,
            ]);

            BatchRequestStudent::where('batch_request_id', $batch->id)
                ->where('appointment_id', $original->id)
                ->update(['appointment_id' => $secondChance->id]);
        }
    }
}
