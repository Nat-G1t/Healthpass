<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Appointment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * D-37 — the clinic day as a list of one-hour booking slots.
 *
 * A *service class* is just a plain PHP class Laravel resolves for you when a
 * controller or Form Request type-hints it (no `new`, no facade). Everything
 * that needs to know "what are the slots?" or "how full is 9 AM on the 14th?"
 * asks this one object, so the slot grid and the counting rule can never drift
 * between the batch form and the Director's approval (the student calendar
 * went with self-booking in D-61).
 *
 * The grid is DERIVED from `healthpass.clinic_hours` (7 AM–5 PM → ten slots,
 * lunch included) and the caps from `healthpass.hourly_capacity` /
 * `daily_capacity`. Nothing here is hardcoded — change the config, the whole
 * app follows.
 *
 * SLOT KEYS ARE ALWAYS 'H:i:s' ("07:00:00"). That single canonical form is what
 * goes in the <select>, into `appointments.scheduled_time`, and into every
 * WHERE clause — MySQL TIME and SQLite TEXT both round-trip it unchanged, so a
 * comparison that passes on the SQLite test suite also passes on MySQL.
 */
class ClinicScheduleService
{
    /** Canonical wire/DB format of a slot key. */
    public const SLOT_FORMAT = 'H:i:s';

    /**
     * Every bookable slot of a clinic day, in order.
     *
     * One slot per open hour: open 07:00, close 17:00 → 07:00:00 … 16:00:00
     * (ten slots; the 4–5 PM slot is the last one that ends by closing time).
     *
     * @return list<string>
     */
    public function slots(): array
    {
        $hours = (array) config('healthpass.clinic_hours');
        $openHour = Carbon::createFromFormat('H:i', $hours['open'])->hour;
        $closeHour = Carbon::createFromFormat('H:i', $hours['close'])->hour;

        if ($closeHour <= $openHour) {
            return [];
        }

        return array_map(
            static fn (int $hour): string => sprintf('%02d:00:00', $hour),
            range($openHour, $closeHour - 1),
        );
    }

    public function isSlot(?string $slot): bool
    {
        return $slot !== null && in_array($slot, $this->slots(), true);
    }

    public function hourlyCapacity(): int
    {
        return (int) config('healthpass.hourly_capacity');
    }

    public function dailyCapacity(): int
    {
        return (int) config('healthpass.daily_capacity');
    }

    // ── Labels ───────────────────────────────────────────────────────────────

    /** "7:00 AM" — the start of the slot, for terse table cells. */
    public function startLabel(string $slot): string
    {
        return Carbon::createFromFormat(self::SLOT_FORMAT, $slot)->format('g:i A');
    }

    /** "7:00 AM – 8:00 AM" — the full hour, for pickers and confirmations. */
    public function label(string $slot): string
    {
        $start = Carbon::createFromFormat(self::SLOT_FORMAT, $slot);

        return $start->format('g:i A').' – '.$start->copy()->addHour()->format('g:i A');
    }

    /**
     * "7:00 AM – 10:00 AM" — the hours a span covers, without the slot count.
     * D-54's clash list names another batch's hours this way.
     *
     * @param  list<string>  $span
     */
    public function rangeLabel(array $span): string
    {
        if ($span === []) {
            return '—';
        }

        $start = Carbon::createFromFormat(self::SLOT_FORMAT, $span[0]);
        $end = Carbon::createFromFormat(self::SLOT_FORMAT, end($span))->addHour();

        return $start->format('g:i A').' – '.$end->format('g:i A');
    }

    /**
     * "7:00 AM – 10:00 AM (3 slots)" — the whole span a batch occupies.
     *
     * @param  list<string>  $span
     */
    public function spanLabel(array $span): string
    {
        if ($span === []) {
            return '—';
        }

        return $this->rangeLabel($span)
            .' ('.count($span).' slot'.(count($span) === 1 ? '' : 's').')';
    }

    // ── Elapsed hours (BR-23) ────────────────────────────────────────────────

    /**
     * Has this slot on this date already gone by?
     *
     * A slot dies when it ENDS, not when it starts: at 12:00 sharp the
     * 11:00–12:00 hour is over and unbookable, while 12:00–1:00 is still open.
     * That matches BR-20's existing "bookable right up to the last minute"
     * behaviour (today stays bookable at 16:59) instead of contradicting it.
     *
     * Parsing `$date $slot` makes past and future dates fall out for free — a
     * future date's hours can never have ended, a past date's always have.
     * Evaluated against the SERVER clock (Asia/Manila), never the browser's.
     */
    public function isSlotElapsed(string $date, string $slot): bool
    {
        return now()->gte(Carbon::parse($date.' '.$slot)->addHour());
    }

    /**
     * The slots on $date that have already gone by, in order.
     *
     * Only ever non-empty for today (and for past dates, which are blocked
     * long before they reach here).
     *
     * @return list<string>
     */
    public function elapsedSlots(string $date): array
    {
        return $this->elapsedSlotsIn($date, $this->slots());
    }

    /**
     * The slots of one SPAN that have already gone by — the batch-shaped
     * counterpart of fullSlotsIn(), used to refuse approving a cohort into an
     * hour that is already over.
     *
     * @param  list<string>  $span
     * @return list<string>
     */
    public function elapsedSlotsIn(string $date, array $span): array
    {
        return array_values(array_filter(
            $span,
            fn (string $slot): bool => $this->isSlotElapsed($date, $slot),
        ));
    }

    /**
     * The one "this hour is full" wording, used by the Form Request's read and
     * by the locked re-check inside the transaction so the student sees the
     * same sentence whichever gate catches them. It NAMES the hour — "full" on
     * its own doesn't tell anyone which one to move away from.
     */
    public function slotFullMessage(string $slot): string
    {
        return 'The '.$this->label($slot).' slot is fully booked. Please pick another time.';
    }

    // ── Batch span maths (FR-ADM-04) ─────────────────────────────────────────

    /** How many contiguous hour-blocks $studentCount students need. */
    public function blocksFor(int $studentCount): int
    {
        return (int) ceil(max(0, $studentCount) / $this->hourlyCapacity());
    }

    /** The largest batch that can fit in one clinic day. */
    public function maxBatchSize(): int
    {
        return $this->hourlyCapacity() * count($this->slots());
    }

    /**
     * The contiguous slots a batch starting at $startSlot would occupy.
     *
     * Returns [] when the start isn't a slot or the span would run past closing
     * time — the caller turns that into the "won't fit" validation error.
     *
     * @return list<string>
     */
    public function span(string $startSlot, int $blocks): array
    {
        $slots = $this->slots();
        $start = array_search($startSlot, $slots, true);

        if ($start === false || $blocks < 1 || $start + $blocks > count($slots)) {
            return [];
        }

        return array_slice($slots, $start, $blocks);
    }

    /**
     * The span of a batch, straight from its student count.
     *
     * @return list<string>
     */
    public function spanForStudents(string $startSlot, int $studentCount): array
    {
        return $this->span($startSlot, $this->blocksFor($studentCount));
    }

    /**
     * How many of a batch's students land in each hour of its span (D-91):
     * the hourly cap in every hour, the remainder in the last — 45 students
     * from 7 AM are 20 + 20 + 5. The approve fan-out assigns students in
     * exactly this order (`intdiv($index, $perHour)`), so a capacity check
     * built on this map checks the appointments approval will really create.
     *
     * @param  list<string>  $span
     * @return array<string, int> slot => seats the batch takes there
     */
    public function seatsBySlot(array $span, int $studentCount): array
    {
        $perHour = $this->hourlyCapacity();
        $seats = [];

        foreach (array_values($span) as $index => $slot) {
            $seats[$slot] = max(0, min($perHour, $studentCount - $index * $perHour));
        }

        return $seats;
    }

    // ── Counting (BR-02, D-37) ───────────────────────────────────────────────

    /**
     * Non-cancelled appointments in one slot on one date.
     *
     * $lock takes a row lock so the read and a following insert are one atomic
     * unit — pass true ONLY from inside a transaction (see the booking store()).
     */
    public function bookedInSlot(string $date, string $slot, bool $lock = false): int
    {
        return Appointment::query()
            ->whereDate('scheduled_date', $date)
            ->where('scheduled_time', $slot)
            ->where('status', '!=', 'cancelled')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->count();
    }

    /** Non-cancelled appointments on one date, legacy NULL-time rows included. */
    public function bookedOnDate(string $date, bool $lock = false): int
    {
        return Appointment::query()
            ->whereDate('scheduled_date', $date)
            ->where('status', '!=', 'cancelled')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->count();
    }

    /**
     * slot key => booked count for one date. Every slot is present (0 when free).
     *
     * Grouped on the raw scheduled_time value, no date/time SQL functions, so
     * the query is identical on MySQL and the SQLite test suite (CLAUDE.md).
     *
     * @return array<string, int>
     */
    public function bookedBySlot(string $date): array
    {
        $counts = Appointment::query()
            ->whereDate('scheduled_date', $date)
            ->whereNotNull('scheduled_time')
            ->where('status', '!=', 'cancelled')
            ->select('scheduled_time', DB::raw('COUNT(*) as cnt'))
            ->groupBy('scheduled_time')
            ->pluck('cnt', 'scheduled_time')
            ->all();

        $bySlot = [];
        foreach ($this->slots() as $slot) {
            $bySlot[$slot] = (int) ($counts[$slot] ?? 0);
        }

        return $bySlot;
    }

    // ── Arrival times (D-96) ─────────────────────────────────────────────────

    /**
     * Every arrival time inside one slot, in order: the slot's start, then one
     * kiosk budget apart — 3600 s ÷ hourly_capacity, so 3 minutes at 20 an
     * hour. 8 AM → 08:00:00, 08:03:00 … 08:57:00. Same 'H:i:s' form as a slot.
     *
     * @return list<string>
     */
    public function arrivalTimes(string $slot): array
    {
        $capacity = $this->hourlyCapacity();

        if ($capacity < 1) {
            return [];
        }

        $start = Carbon::createFromFormat(self::SLOT_FORMAT, $slot);
        $budgetSeconds = intdiv(3600, $capacity);

        return array_map(
            fn (int $seat): string => $start->copy()->addSeconds($seat * $budgetSeconds)->format(self::SLOT_FORMAT),
            range(0, $capacity - 1),
        );
    }

    /**
     * The arrival times in one slot that no appointment holds yet, earliest
     * first — shared by every batch in that hour, so two batches can never
     * hand out the same time.
     *
     * "Free", not "after the last one": a cancelled batch (D-92) gives its
     * times back, and the next batch fills that gap instead of doubling up on
     * a time someone already has in their inbox. Called inside the approval
     * transaction, after its locked capacity check.
     *
     * @return list<string>
     */
    public function freeArrivalTimes(string $date, string $slot): array
    {
        $taken = Appointment::query()
            ->whereDate('scheduled_date', $date)
            ->where('scheduled_time', $slot)
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('arrival_time')
            ->pluck('arrival_time')
            ->all();

        return array_values(array_diff($this->arrivalTimes($slot), $taken));
    }

    /**
     * The slot picker payload for one date.
     *
     * `full` (at the hourly cap) and `elapsed` (BR-23, the hour has ended) are
     * reported separately so the UI can say *why* an hour is greyed out —
     * "Full" and "Past" are different problems for the student. `available` is
     * the one the picker actually disables on.
     *
     * @return list<array{value: string, label: string, booked: int, remaining: int, full: bool, elapsed: bool, available: bool}>
     */
    public function slotAvailability(string $date): array
    {
        $capacity = $this->hourlyCapacity();

        $rows = [];
        foreach ($this->bookedBySlot($date) as $slot => $booked) {
            $full = $booked >= $capacity;
            $elapsed = $this->isSlotElapsed($date, $slot);

            $rows[] = [
                'value' => $slot,
                'label' => $this->label($slot),
                'booked' => $booked,
                'remaining' => max(0, $capacity - $booked),
                'full' => $full,
                'elapsed' => $elapsed,
                'available' => ! $full && ! $elapsed,
            ];
        }

        return $rows;
    }

    /**
     * Slots in $span with no room for their share of a batch of $studentCount
     * students, as slot keys.
     *
     * D-91: a slot is full FOR THIS BATCH when what is already booked there
     * plus the seats the batch would take there (seatsBySlot()) goes over the
     * hourly cap. Before D-91 only an hour already AT the cap was refused, so
     * an hour holding 8 still took another batch's 12 and ended with 20.
     *
     * The caller names the offending hour in its error message rather than a
     * bare "full" — the College Admin has to know WHICH hour to move away from.
     *
     * @param  list<string>  $span
     * @return list<string>
     */
    public function fullSlotsIn(string $date, array $span, int $studentCount, bool $lock = false): array
    {
        $booked = [];

        foreach ($span as $slot) {
            $booked[$slot] = $this->bookedInSlot($date, $slot, $lock);
        }

        return $this->slotsWithoutRoom($span, $studentCount, $booked);
    }

    /**
     * The D-91 room rule on counts the caller already has: the slots of $span
     * where booked + this batch's seats there goes over the hourly cap. Shared
     * by fullSlotsIn() (fresh, optionally locked counts) and startBlocker()
     * (the month calendar's one-query counts), so both apply the same rule.
     *
     * @param  list<string>  $span
     * @param  array<string, int>  $bookedBySlot
     * @return list<string>
     */
    public function slotsWithoutRoom(array $span, int $studentCount, array $bookedBySlot): array
    {
        $capacity = $this->hourlyCapacity();
        $seats = $this->seatsBySlot($span, $studentCount);

        return array_values(array_filter(
            $span,
            fn (string $slot): bool => $seats[$slot] > 0
                && ($bookedBySlot[$slot] ?? 0) + $seats[$slot] > $capacity,
        ));
    }

    /**
     * Why a slot has no room for a batch (D-91): "is already fully booked"
     * when no seat is left, otherwise "has only 4 seats left". One wording
     * for the batch form, its locked re-check and the Director's refusal.
     */
    public function noRoomReason(string $date, string $slot): string
    {
        $left = max(0, $this->hourlyCapacity() - $this->bookedInSlot($date, $slot));

        return $left === 0
            ? 'is already fully booked'
            : 'has only '.$left.' '.Str::plural('seat', $left).' left';
    }

    // ── Director scheduling (D-108) ──────────────────────────────────────────
    // Since D-108 the Director picks each batch's date and start hour. These
    // feed the Review page's calendar and hour picker; approve() re-checks the
    // same rules under a lock, so the page only ever helps.

    /** BR-02: would $studentCount more students keep the day within daily_capacity? */
    public function dayHasRoomFor(int $bookedOnDay, int $studentCount): bool
    {
        return $bookedOnDay + $studentCount <= $this->dailyCapacity();
    }

    /**
     * Why a batch of $studentCount students cannot START at $startSlot on
     * $date, or null when it can (D-108, Q17). In approve()'s order:
     *
     *   'overrun'  the span would run past the last clinic hour
     *   'elapsed'  one of its hours has already ended today (BR-23)
     *   'full'     one of its hours lacks room for this batch's seats (D-91)
     *
     * Counts come in as $bookedBySlot (bookedBySlot()'s shape), so the month
     * calendar can try every start hour of every day from one query.
     *
     * @param  array<string, int>  $bookedBySlot
     */
    public function startBlocker(string $date, string $startSlot, int $studentCount, array $bookedBySlot): ?string
    {
        $span = $this->spanForStudents($startSlot, $studentCount);

        return match (true) {
            $span === [] => 'overrun',
            $this->elapsedSlotsIn($date, $span) !== [] => 'elapsed',
            $this->slotsWithoutRoom($span, $studentCount, $bookedBySlot) !== [] => 'full',
            default => null,
        };
    }

    /**
     * The start hours on $date from which the whole batch fits — none means
     * the day is greyed out on the Director's calendar.
     *
     * @param  array<string, int>  $bookedBySlot
     * @return list<string>
     */
    public function fittingStarts(string $date, int $studentCount, array $bookedBySlot): array
    {
        return array_values(array_filter(
            $this->slots(),
            fn (string $slot): bool => $this->startBlocker($date, $slot, $studentCount, $bookedBySlot) === null,
        ));
    }

    /**
     * One month of bookings for the Director's calendar: per day ('Y-m-d'),
     * the non-cancelled appointments in total — legacy NULL-time rows included,
     * the same count as bookedOnDate() (BR-02) — and per slot. Days with no
     * booking are left out.
     *
     * One grouped query over a DATE RANGE (whereDate is compiled per driver;
     * no DAY()/MONTH() in raw SQL), rolled up per day in PHP — portable to the
     * SQLite test suite (CLAUDE.md).
     *
     * @return array<string, array{total: int, bySlot: array<string, int>}>
     */
    public function bookingsForMonth(int $year, int $month): array
    {
        $first = Carbon::create($year, $month, 1);

        $rows = Appointment::query()
            ->whereDate('scheduled_date', '>=', $first->toDateString())
            ->whereDate('scheduled_date', '<=', $first->copy()->endOfMonth()->toDateString())
            ->where('status', '!=', 'cancelled')
            ->select('scheduled_date', 'scheduled_time', DB::raw('COUNT(*) as cnt'))
            ->groupBy('scheduled_date', 'scheduled_time')
            ->get();

        $days = [];

        foreach ($rows as $row) {
            $date = Carbon::parse($row->scheduled_date)->toDateString();
            $count = (int) $row->cnt;

            $days[$date] ??= ['total' => 0, 'bySlot' => []];
            $days[$date]['total'] += $count;

            if ($row->scheduled_time !== null) {
                $days[$date]['bySlot'][$row->scheduled_time] = ($days[$date]['bySlot'][$row->scheduled_time] ?? 0) + $count;
            }
        }

        return $days;
    }

    /**
     * Who already holds seats in each hour on $date, for the hour picker's
     * "~ scheduled for CCS (8/20)": slot => [{code, count}], colleges by code.
     * Read through each appointment's batch, so it names the college that
     * booked it. Every column is table-qualified: batch_requests has its own
     * scheduled_date, scheduled_time and status.
     *
     * @return array<string, list<array{code: string, count: int}>>
     */
    public function collegesBySlot(string $date): array
    {
        $rows = Appointment::query()
            ->join('batch_requests', 'batch_requests.id', '=', 'appointments.batch_request_id')
            ->join('colleges', 'colleges.id', '=', 'batch_requests.college_id')
            ->whereDate('appointments.scheduled_date', $date)
            ->whereNotNull('appointments.scheduled_time')
            ->where('appointments.status', '!=', 'cancelled')
            ->select('appointments.scheduled_time', 'colleges.code', DB::raw('COUNT(*) as cnt'))
            ->groupBy('appointments.scheduled_time', 'colleges.code')
            ->orderBy('colleges.code')
            ->get();

        $bySlot = [];

        foreach ($rows as $row) {
            $bySlot[$row->scheduled_time][] = ['code' => $row->code, 'count' => (int) $row->cnt];
        }

        return $bySlot;
    }

    // ── Month calendar (BR-20) ───────────────────────────────────────────────
    // Moved here from the student booking controller by D-54. The College
    // Admin's mini calendar went with D-108; the Director's Review-page
    // calendar (BatchApprovalController::calendarDays) reads this one.

    /**
     * Day numbers unavailable because of the same-day closing cutoff (BR-20).
     *
     * Returns today's day-of-month only when the given month is the current month AND
     * the local clock has reached closing_hour — i.e. at most one entry, and only for
     * the current month. The cutoff is decided here (server-side), never trusted from
     * the browser clock; the calendar just greys out whatever this returns.
     *
     * D-108: the Director's calendar shows such a day as "Closed for today".
     *
     * @return int[]
     */
    public function cutoffDaysForMonth(int $year, int $month): array
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
