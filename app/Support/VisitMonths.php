<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ClinicVisit;
use App\Models\College;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The month dimension for Director analytics — the list of months that
 * have data (for the picker) and the parse/fallback rule (for the request
 * value). Generalized from the old CaseMonths (D-32 rescope, FR-ANL-13).
 *
 * A "month with data" is one with at least one clinic-visit check-in
 * (captured OR encoded — FR-ANL-07 counts from capture), so the picker never
 * offers a month that would render every card empty.
 *
 * Since D-99 the two analytics pages pick a YEAR and then a month of it
 * (years(), monthsOf(), resolvePicked()); the Nurse Dashboard and the
 * printed monthly reports still use the single-list available() / resolve().
 */
final class VisitMonths
{
    /**
     * Distinct months as ['value' => 'YYYY-MM', 'label' => 'Month YYYY'],
     * newest first, for the month <select>. Months are derived in PHP from
     * Carbon-cast dates (not a raw DATE_FORMAT), so the queries stay
     * portable to the SQLite test database.
     *
     * @param  College|null  $college  Narrow the list to one college's data.
     *                                 The Director and the Nurse pass nothing
     *                                 — they read the whole clinic. The College
     *                                 Admin page (D-45) passes its managed
     *                                 college so its picker never offers a
     *                                 month in which only OTHER colleges had
     *                                 visits (FR-ADM-06).
     * @return list<array{value: string, label: string}>
     */
    public static function available(?College $college = null): array
    {
        $visitMonths = self::checkIns($college)
            ->pluck('checked_in_at')
            ->map(fn (CarbonInterface $date) => $date->format('Y-m'));

        return $visitMonths
            ->unique()
            ->sortDesc()
            ->values()
            ->map(fn (string $yearMonth) => [
                'value' => $yearMonth,
                'label' => CarbonImmutable::createFromFormat('Y-m-d', $yearMonth.'-01')->format('F Y'),
            ])
            ->all();
    }

    /**
     * Parse a YYYY-MM request value to the first day of that month. Anything
     * missing or malformed falls back to the newest month that has data (or
     * the current month when there is no data at all) — every screen still
     * renders. The regex pins a valid 01–12 month so createFromFormat can't
     * roll over into the next year.
     *
     * $college narrows that fallback the same way it narrows available(), so a
     * college-scoped page defaults to the newest month ITS college has data
     * in, not one where only other colleges were active.
     */
    public static function resolve(mixed $month, ?College $college = null): CarbonImmutable
    {
        if (is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1) {
            return CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        }

        $newest = self::available($college)[0]['value'] ?? null;

        return $newest
            ? CarbonImmutable::createFromFormat('Y-m-d', $newest.'-01')->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();
    }

    // ── The analytics pages' year + month pickers (D-99) ─────────────────────

    /**
     * The years the analytics year picker offers, newest first: 2021 (the
     * Yearly Report's first year) to the current year on the SERVER clock
     * (BR-20) — every year, whether or not it has visits.
     *
     * @return list<int>
     */
    public static function years(): array
    {
        return range(now()->year, (int) config('healthpass.reports.yearly_first_year'));
    }

    /**
     * The month picker for one year: always January to December, each
     * flagged with whether it has visits in scope. The page greys out
     * (disables) the months without, so the picker still never offers an
     * empty month.
     *
     * @return list<array{value: string, label: string, hasVisits: bool}>
     */
    public static function monthsOf(int $year, ?College $college = null): array
    {
        $withVisits = self::monthsWithVisits($year, $college);

        return array_map(fn (int $month): array => [
            'value' => sprintf('%02d', $month),
            'label' => CarbonImmutable::create($year, $month, 1)->format('F'),
            'hasVisits' => in_array($month, $withVisits, true),
        ], range(1, 12));
    }

    /**
     * The ?year= + ?month= pair as the first day of a month. The older
     * single ?month=YYYY-MM form (bookmarks, the Print Monthly Report link)
     * is read the same way.
     *
     *  - A month with visits in scope is used as is.
     *  - A month without any — typically just after switching year — moves
     *    to that year's latest month with visits, so the page never sits on
     *    a greyed-out month. A year with no visits at all keeps the month,
     *    and every card shows its empty state.
     *  - Anything missing, malformed or outside years() falls back to the
     *    newest month with data, exactly as resolve() does.
     *
     * $college is the same scope monthsOf() greys months out by.
     */
    public static function resolvePicked(mixed $year, mixed $month, ?College $college = null): CarbonImmutable
    {
        $picked = self::parsePicked($year, $month);

        if ($picked === null) {
            return self::resolve(null, $college);
        }

        [$year, $month] = $picked;
        $withVisits = self::monthsWithVisits($year, $college);

        if ($withVisits !== [] && ! in_array($month, $withVisits, true)) {
            $month = max($withVisits);
        }

        return CarbonImmutable::create($year, $month, 1)->startOfMonth();
    }

    /**
     * [year, month] from either request form, or null when it is not a real
     * month inside one of years().
     *
     * @return array{0: int, 1: int}|null
     */
    private static function parsePicked(mixed $year, mixed $month): ?array
    {
        if (is_string($month) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $parts) === 1) {
            [, $year, $month] = $parts;
        }

        if (! is_string($year) || ! ctype_digit($year)
            || ! is_string($month) || preg_match('/^(0[1-9]|1[0-2])$/', $month) !== 1) {
            return null;
        }

        return in_array((int) $year, self::years(), true) ? [(int) $year, (int) $month] : null;
    }

    /**
     * The months (1–12) of $year with at least one visit in scope. A date
     * range, not YEAR()/MONTH(), so the query stays portable to SQLite.
     *
     * @return list<int>
     */
    private static function monthsWithVisits(int $year, ?College $college): array
    {
        $start = CarbonImmutable::create($year, 1, 1)->startOfYear();

        return self::checkIns($college)
            ->whereBetween('checked_in_at', [$start, $start->endOfYear()])
            ->pluck('checked_in_at')
            ->map(fn (CarbonInterface $date) => (int) $date->format('n'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The check-ins every picker list is built from.
     */
    private static function checkIns(?College $college): Builder
    {
        return ClinicVisit::query()
            // D-72: a month in which only resting visits happened had no
            // clinic activity, so it must not be offered.
            ->submitted()
            ->whereNotNull('checked_in_at')
            // The capture-time college snapshot (FR-STU-09), same as every
            // analytics count.
            ->when($college, fn ($query) => $query->where('college_id', $college->id));
    }
}
