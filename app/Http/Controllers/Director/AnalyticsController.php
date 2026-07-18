<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Support\CaseMonths;
use App\Support\MedicalCaseSummary;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Director Analytics — two views over ONE grouped college × category query,
 * plus the By-Sex donut:
 *
 *  - Medical Cases by College (FR-ANL-02): a horizontal stacked bar, one row
 *    per college (all 12, zero-case rows included), each bar segmented by
 *    the 8 medical-system categories, sorted by total case volume descending.
 *  - Summary of Medical Cases matrix (FR-ANL-03): 8 medical-system rows ×
 *    12 college columns in the PRD's fixed order, plus a TOTAL column and a
 *    totals row. Rendered as the chart's "View as table" toggle (D-30) —
 *    one dataset, two formats, so the page never shows the same numbers
 *    twice. Encoded records with no category contribute nothing to either
 *    view; their count appears in a card-level footnote instead.
 *  - Cases by Medical System (FR-ANL-08): a horizontal bar per system,
 *    overall total across all units, sorted descending — each bar split
 *    into Male/Female segments (full-strength series color vs a lighter
 *    tint of it; the per-system total is drawn at the bar's end).
 *  - By-Sex donut (FR-ANL-04): encoded visits counted once per visit by the
 *    student's profile sex — see bySexDonut() for why its total is allowed
 *    to differ from the case totals above.
 *
 * Counting rules (the case views):
 *  - encoded records only (FR-ANL-07) — a clearance_records row only exists
 *    once the nurse encodes, and the status guard keeps that explicit;
 *  - one count per record × category (D-23) — a multi-category case counts
 *    once in EACH of its systems;
 *  - grouped by clinic_visits.college_id, the capture-time college snapshot
 *    (FR-STU-09, D-17) — a later transfer never re-attributes a past case.
 *
 * Month scope: the whole page is scoped to ONE month (the ?month=YYYY-MM
 * picker, defaulting to the newest month with data — see CaseMonths). A
 * case belongs to the month of its VISIT (checked_in_at), and every view
 * here — chart, matrix, by-system, donut, footnote — filters to it, so the
 * on-screen numbers and the printed monthly report always match.
 */
class AnalyticsController extends Controller
{
    /**
     * Series color per CASE_CATEGORIES slot (same index = same category,
     * fixed order — never reassigned when counts change). Hues chosen by
     * Nat (2026-07-14); colorblind-safety checked with the dataviz palette
     * validator: adjacent-pair CVD separation passes (worst ΔE 40.8), and
     * the two light slots (orange, cyan) sit under 3:1 contrast — the
     * "View as table" fallback below the chart is their required relief.
     */
    private const SERIES_COLORS = [
        '#B45309', // Alimentary System — warm brown
        '#1E3A8A', // Respiratory System — deep navy
        '#F97316', // Musculo-Skeletal System — brand orange
        '#059669', // Integumentary System — emerald
        '#8B5CF6', // Urinary System — purple
        '#64748B', // Metabolic Endocrine System — slate gray
        '#DC2626', // Cardiovascular System — crimson red
        '#06B6D4', // Eyes, Ears, Nose & Throat Disorders — cyan
    ];

    /** Fixed matrix column order, locked by FR-ANL-03. */
    private const MATRIX_COLLEGE_ORDER = [
        'COE', 'CEA', 'CBS', 'CAS', 'CSSP', 'CCS',
        'CHTM', 'CIT', 'LAW', 'GS', 'SHS', 'LHS',
    ];

    /** Donut slice colors (prototype): Male = brand orange, Female = peach. */
    private const SEX_COLORS = ['#FF8C2A', '#FFCAA0'];

    public function __invoke(Request $request): View
    {
        // The month the whole page is scoped to (picker value, or the newest
        // month with data). Everything below filters to it.
        $month = CaseMonths::resolve($request->query('month'));

        // The college × category counts (FR-ANL-03). Built by the shared
        // MedicalCaseSummary — month-scoped — so the matrix here and the
        // printable monthly report (CaseSummaryPrintController) read from ONE
        // aggregation and can never disagree.
        $summary = MedicalCaseSummary::build($month);
        $counts = $summary->counts;                 // counts[college_id][category]
        $totals = $summary->totals;                 // matrix column totals
        $categoryTotals = $summary->categoryTotals; // matrix TOTAL column
        $totalCases = $summary->grandTotal;

        $allColleges = College::orderBy('code')->get();

        // Chart rows: sorted by total volume descending. The initial
        // code-ascending order is the tie-break: PHP sorts are stable, so
        // equal totals keep alphabetical order — deterministic chart rows.
        $colleges = $allColleges
            ->sortByDesc(fn (College $college) => $totals[$college->id] ?? 0)
            ->values();

        // Matrix columns: the FR-ANL-03 fixed order instead.
        $matrixOrder = array_flip(self::MATRIX_COLLEGE_ORDER);
        $matrixColleges = $allColleges
            ->sortBy(fn (College $college) => $matrixOrder[$college->code] ?? PHP_INT_MAX)
            ->values();

        // Footnote count: encoded records that carry no category at all —
        // excluded from the matrix (FR-ANL-03), but the Director should
        // still see they exist. Month-scoped like everything else on the
        // page. whereDoesntHave() is whereHas()'s inverse: a NOT EXISTS
        // subquery on clearance_case_categories.
        $uncategorizedCount = ClearanceRecord::query()
            ->whereHas('clinicVisit', fn (Builder $visit) => $visit->encoded()
                ->whereBetween('checked_in_at', $this->monthBounds($month)))
            ->whereDoesntHave('caseCategories')
            ->count();

        $datasets = [];
        foreach (ClearanceRecord::CASE_CATEGORIES as $i => $category) {
            $datasets[] = [
                'label' => $category,
                'data' => $colleges
                    ->map(fn (College $college) => $counts[$college->id][$category] ?? 0)
                    ->all(),
                'backgroundColor' => self::SERIES_COLORS[$i],
            ];
        }

        return view('director.analytics', [
            'chart' => [
                'labels' => $colleges->pluck('code')->all(),
                'datasets' => $datasets,
            ],
            'totalCases' => $totalCases,
            // Month picker: the months that have data (newest first) and the
            // one currently shown. Changing it reloads the page for that
            // month; the same value drives the Preview & Print report.
            'availableMonths' => CaseMonths::available(),
            'selectedMonth' => $month->format('Y-m'),
            // For the table view (the chart's accessible/contrast fallback).
            'colleges' => $colleges,
            'categories' => ClearanceRecord::CASE_CATEGORIES,
            'counts' => $counts,
            'totals' => $totals,
            // Summary of Medical Cases matrix (FR-ANL-03). Cells and column
            // totals reuse $counts/$totals above; the grand total is
            // $totalCases — same numbers, fixed column order.
            'matrixColleges' => $matrixColleges,
            'categoryTotals' => $categoryTotals,
            'uncategorizedCount' => $uncategorizedCount,
            ...$this->casesBySystem($month),
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
     * Cases by Medical System (FR-ANL-08): same joins and counting rules
     * as the matrix query (encoded only, one count per record × category),
     * plus the student's profile sex so each system's bar can stack a
     * Male and a Female segment. A student without a profile row can't
     * exist through registration, so the extra join drops nothing real.
     * Month-scoped to $month like the rest of the page.
     *
     * @return array{systemChart: array}
     */
    private function casesBySystem(CarbonInterface $month): array
    {
        $rows = ClinicVisit::encodedCaseRows() // FR-ANL-07
            ->join('student_profiles', 'student_profiles.user_id', '=', 'clinic_visits.student_id')
            ->whereBetween('clinic_visits.checked_in_at', $this->monthBounds($month))
            ->groupBy('clearance_case_categories.case_category', 'student_profiles.sex')
            ->select(
                'clearance_case_categories.case_category',
                'student_profiles.sex',
                DB::raw('count(*) as cases'),
            )
            ->toBase()
            ->get();

        $bySexCounts = [];
        foreach ($rows as $row) {
            $bySexCounts[$row->case_category][$row->sex] = (int) $row->cases;
        }

        // All 8 systems, zero-case rows included, sorted by total volume
        // descending — the stable sort keeps CASE_CATEGORIES order as the
        // tie-break, so equal totals render deterministically.
        $categories = collect(ClearanceRecord::CASE_CATEGORIES)
            ->sortByDesc(fn (string $category) => array_sum($bySexCounts[$category] ?? []))
            ->values();

        // Same color per category as the Cases-by-College chart: index in
        // CASE_CATEGORIES = index in SERIES_COLORS. Male keeps the
        // full-strength color; Female gets a lighter tint of it.
        $colorFor = array_combine(ClearanceRecord::CASE_CATEGORIES, self::SERIES_COLORS);

        return [
            'systemChart' => [
                'labels' => $categories->all(),
                // Per-system totals, drawn at each bar's end by the JS —
                // with two segments neither shows the total on its own.
                'totals' => $categories
                    ->map(fn (string $category) => array_sum($bySexCounts[$category] ?? []))
                    ->all(),
                'datasets' => [
                    [
                        'label' => 'Male',
                        'data' => $categories->map(fn (string $c) => $bySexCounts[$c]['M'] ?? 0)->all(),
                        'backgroundColor' => $categories->map(fn (string $c) => $colorFor[$c])->all(),
                    ],
                    [
                        'label' => 'Female',
                        'data' => $categories->map(fn (string $c) => $bySexCounts[$c]['F'] ?? 0)->all(),
                        'backgroundColor' => $categories->map(fn (string $c) => $this->tint($colorFor[$c]))->all(),
                    ],
                ],
            ],
        ];
    }

    /**
     * 50% white tint of a #RRGGBB color — the Female shade of each series
     * color. Derived, not hand-picked, so the pairing can never drift if
     * SERIES_COLORS changes.
     */
    private function tint(string $hex): string
    {
        [$red, $green, $blue] = sscanf($hex, '#%02x%02x%02x');

        return sprintf(
            '#%02X%02X%02X',
            intdiv($red + 255, 2),
            intdiv($green + 255, 2),
            intdiv($blue + 255, 2),
        );
    }

    /**
     * By-Sex donut (FR-ANL-04): encoded visits grouped by the student's
     * profile sex — the SAME encoded() base scope as the matrix query, but
     * counting once per VISIT (people screened), not per record × category.
     * Per the PRD §4.9 AC the two totals therefore diverge by design as
     * soon as a record carries several categories (counts once per system
     * in the matrix) or none (counts zero there, one person here).
     * Month-scoped to $month like the rest of the page.
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
            // Chart.js payload, shipped on a data attribute like 'chart'.
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
