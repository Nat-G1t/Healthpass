<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Models\ClinicVisit;
use App\Support\CaseMonths;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Director Analytics. The medical-cases statistics (college × category
 * chart, FR-ANL-03 matrix, cases-by-system) were removed by D-32 — the
 * rescoped, captured-data analytics arrive in the rebuild phase. What
 * remains today:
 *
 *  - By-Sex donut (FR-ANL-04): encoded visits counted once per visit by
 *    the student's profile sex.
 *  - Month scope: the whole page is scoped to ONE month (the ?month=YYYY-MM
 *    picker, defaulting to the newest month with data — see CaseMonths).
 *    A visit belongs to the month of its checked_in_at.
 */
class AnalyticsController extends Controller
{
    /** Donut slice colors (prototype): Male = brand orange, Female = peach. */
    private const SEX_COLORS = ['#FF8C2A', '#FFCAA0'];

    public function __invoke(Request $request): View
    {
        // The month the whole page is scoped to (picker value, or the newest
        // month with data). Everything below filters to it.
        $month = CaseMonths::resolve($request->query('month'));

        return view('director.analytics', [
            // Month picker: the months that have data (newest first) and the
            // one currently shown. Changing it reloads the page for that month.
            'availableMonths' => CaseMonths::available(),
            'selectedMonth' => $month->format('Y-m'),
            ...$this->bySexDonut($month),
        ]);
    }

    /**
     * The [start, end] bounds of $month's calendar days — a parameterized
     * BETWEEN on clinic_visits.checked_in_at, portable to the SQLite test DB.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function monthBounds(CarbonInterface $month): array
    {
        return [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];
    }

    /**
     * By-Sex donut (FR-ANL-04): encoded visits grouped by the student's
     * profile sex, counting once per VISIT (people screened). Month-scoped
     * to $month like the rest of the page.
     *
     * @return array{donut: array, bySex: array, totalScreened: int}
     */
    private function bySexDonut(CarbonInterface $month): array
    {
        $bySex = ClinicVisit::encoded() // FR-ANL-07
            ->join('student_profiles', 'student_profiles.user_id', '=', 'clinic_visits.student_id')
            ->whereBetween('clinic_visits.checked_in_at', $this->monthBounds($month))
            ->groupBy('student_profiles.sex')
            ->select('student_profiles.sex', DB::raw('count(*) as visits'))
            ->toBase()
            ->pluck('visits', 'sex');

        $maleCount = (int) ($bySex['M'] ?? 0);
        $femaleCount = (int) ($bySex['F'] ?? 0);
        $totalScreened = $maleCount + $femaleCount;

        // Whole-number legend percentages that always sum to exactly 100:
        // round one slice, the other takes the remainder.
        $malePercent = $totalScreened > 0 ? (int) round($maleCount / $totalScreened * 100) : 0;
        $femalePercent = $totalScreened > 0 ? 100 - $malePercent : 0;

        return [
            // Chart.js payload, shipped on a data attribute.
            'donut' => [
                'labels' => ['Male', 'Female'],
                'datasets' => [[
                    'data' => [$maleCount, $femaleCount],
                    'backgroundColor' => self::SEX_COLORS,
                ]],
            ],
            // Server-rendered legend rows (count + %, FR-ANL-04).
            'bySex' => [
                ['label' => 'Male', 'count' => $maleCount, 'percent' => $malePercent, 'color' => self::SEX_COLORS[0]],
                ['label' => 'Female', 'count' => $femaleCount, 'percent' => $femalePercent, 'color' => self::SEX_COLORS[1]],
            ],
            'totalScreened' => $totalScreened,
        ];
    }
}
