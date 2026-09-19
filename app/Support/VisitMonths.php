<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ClinicVisit;
use App\Models\College;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The month dimension for Director analytics — the list of months that
 * have data (for the picker) and the parse/fallback rule (for the request
 * value). Generalized from the old CaseMonths (D-32 rescope, FR-ANL-13).
 *
 * A "month with data" is one with at least one clinic-visit check-in
 * (captured OR encoded — FR-ANL-07 counts from capture), so the picker never
 * offers a month that would render every card empty.
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
        $visitMonths = ClinicVisit::query()
            ->whereNotNull('checked_in_at')
            // The capture-time college snapshot (FR-STU-09), same as every
            // analytics count.
            ->when($college, fn ($query) => $query->where('college_id', $college->id))
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
}
