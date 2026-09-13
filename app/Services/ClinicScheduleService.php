<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Appointment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * D-37 — the clinic day as a list of one-hour booking slots.
 *
 * A *service class* is just a plain PHP class Laravel resolves for you when a
 * controller or Form Request type-hints it (no `new`, no facade). Everything
 * that needs to know "what are the slots?" or "how full is 9 AM on the 14th?"
 * asks this one object, so the slot grid and the counting rule can never drift
 * between the student calendar, the batch form and the Director's approval.
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

    /** Is there any hour left on $date that is neither elapsed nor full? */
    public function hasAvailableSlot(string $date): bool
    {
        $capacity = $this->hourlyCapacity();

        foreach ($this->bookedBySlot($date) as $slot => $booked) {
            if ($booked < $capacity && ! $this->isSlotElapsed($date, $slot)) {
                return true;
            }
        }

        return false;
    }

    /** The one "that hour has gone by" wording, shared by both Form Requests. */
    public function slotElapsedMessage(string $slot): string
    {
        return 'The '.$this->label($slot).' slot has already passed. Please pick a later time today.';
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
     * Slots in $span that are already at the hourly cap, as slot keys.
     *
     * The caller names the offending hour in its error message rather than a
     * bare "full" — the College Admin has to know WHICH hour to move away from.
     *
     * @param  list<string>  $span
     * @return list<string>
     */
    public function fullSlotsIn(string $date, array $span, bool $lock = false): array
    {
        $capacity = $this->hourlyCapacity();

        return array_values(array_filter(
            $span,
            fn (string $slot): bool => $this->bookedInSlot($date, $slot, $lock) >= $capacity,
        ));
    }

    // ── Month calendar (FR-STU-03, FR-ADM-04) ────────────────────────────────
    // Moved here from BookAppointmentController by D-54, so the student booking
    // calendar and the College Admin's New Batch mini calendar grey out exactly
    // the same days.

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
    public function fullDaysForMonth(int $year, int $month): array
    {
        $hourlyCapacity = $this->hourlyCapacity();
        $dailyCapacity = $this->dailyCapacity();
        $slotCount = count($this->slots());

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
            && ! $this->hasAvailableSlot($today->toDateString())) {
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
