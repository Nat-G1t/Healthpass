<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use Illuminate\Database\Eloquent\Builder;
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

    public function __invoke(): View
    {
        // One row per (college, category): plain portable JOIN + GROUP BY —
        // exactly what the D-23 child table was designed for. Starting from
        // the shared encoded() scope keeps this query and the by-sex donut
        // (FR-ANL-04) on the same "encoded only" base; toBase() drops the
        // Eloquent model layer so we get plain rows, not ClinicVisit objects.
        $rows = ClinicVisit::encoded() // FR-ANL-07
            ->join('clearance_records', 'clearance_records.clinic_visit_id', '=', 'clinic_visits.id')
            ->join('clearance_case_categories', 'clearance_case_categories.clearance_record_id', '=', 'clearance_records.id')
            ->groupBy('clinic_visits.college_id', 'clearance_case_categories.case_category')
            ->select(
                'clinic_visits.college_id',
                'clearance_case_categories.case_category',
                DB::raw('count(*) as cases'),
            )
            ->toBase()
            ->get();

        // counts[college_id][category], totals[college_id] (matrix columns),
        // and categoryTotals[category] (matrix TOTAL column) — all three
        // shapes fall out of the same result set in one pass.
        $counts = [];
        $totals = [];
        $categoryTotals = [];
        foreach ($rows as $row) {
            $counts[$row->college_id][$row->case_category] = (int) $row->cases;
            $totals[$row->college_id] = ($totals[$row->college_id] ?? 0) + (int) $row->cases;
            $categoryTotals[$row->case_category] = ($categoryTotals[$row->case_category] ?? 0) + (int) $row->cases;
        }

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
        // still see they exist. whereDoesntHave() is whereHas()'s inverse:
        // a NOT EXISTS subquery on clearance_case_categories.
        $uncategorizedCount = ClearanceRecord::query()
            ->whereHas('clinicVisit', fn (Builder $visit) => $visit->encoded())
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
            'totalCases' => (int) $rows->sum('cases'),
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
            ...$this->casesBySystem(),
            ...$this->bySexDonut(),
        ]);
    }

    /**
     * Cases by Medical System (FR-ANL-08): same joins and counting rules
     * as the matrix query (encoded only, one count per record × category),
     * plus the student's profile sex so each system's bar can stack a
     * Male and a Female segment. A student without a profile row can't
     * exist through registration, so the extra join drops nothing real.
     *
     * @return array{systemChart: array}
     */
    private function casesBySystem(): array
    {
        $rows = ClinicVisit::encoded() // FR-ANL-07
            ->join('clearance_records', 'clearance_records.clinic_visit_id', '=', 'clinic_visits.id')
            ->join('clearance_case_categories', 'clearance_case_categories.clearance_record_id', '=', 'clearance_records.id')
            ->join('student_profiles', 'student_profiles.user_id', '=', 'clinic_visits.student_id')
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
     *
     * @return array{donut: array, bySex: array, totalScreened: int}
     */
    private function bySexDonut(): array
    {
        $bySex = ClinicVisit::encoded() // FR-ANL-07
            ->join('student_profiles', 'student_profiles.user_id', '=', 'clinic_visits.student_id')
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
