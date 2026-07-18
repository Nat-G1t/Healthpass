<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ClinicVisit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The month dimension for Director analytics — the list of months that
 * have cases (for the picker) and the parse/fallback rule (for the request
 * value). Shared by AnalyticsController (the on-screen dashboard) and
 * CaseSummaryPrintController (the printed report) so both agree on which
 * month is "current" and both fall back the same way.
 *
 * A "month with data" is one that has at least one categorized encoded
 * case — the same rows the matrix counts (FR-ANL-03/07).
 */
final class CaseMonths
{
    /**
     * Distinct months as ['value' => 'YYYY-MM', 'label' => 'Month YYYY'],
     * newest first, for the month <select>. Months are derived in PHP from
     * Carbon-cast checked_in_at values (not a raw DATE_FORMAT), so the query
     * stays portable to the SQLite test database.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function available(): array
    {
        return self::categorizedEncoded()
            ->orderByDesc('checked_in_at')
            ->pluck('checked_in_at')
            ->map(fn (CarbonInterface $date) => $date->format('Y-m'))
            ->unique()
            ->values()
            ->map(fn (string $yearMonth) => [
                'value' => $yearMonth,
                'label' => CarbonImmutable::createFromFormat('Y-m-d', $yearMonth.'-01')->format('F Y'),
            ])
            ->all();
    }

    /**
     * Parse a YYYY-MM request value to the first day of that month. Anything
     * missing or malformed falls back to the newest month that has cases (or
     * the current month when there is no data at all) — every screen still
     * renders. The regex pins a valid 01–12 month so createFromFormat can't
     * roll over into the next year.
     */
    public static function resolve(mixed $month): CarbonImmutable
    {
        if (is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1) {
            return CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        }

        $latest = self::categorizedEncoded()->max('checked_in_at');

        return $latest
            ? CarbonImmutable::parse($latest)->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();
    }

    /** Encoded visits carrying at least one case category (FR-ANL-03/07). */
    private static function categorizedEncoded(): Builder
    {
        return ClinicVisit::encoded()->whereHas('clearanceRecord.caseCategories');
    }
}
