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
 * Director Analytics — two views over ONE grouped college × category query:
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
 *
 * Counting rules (both views):
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
        ]);
    }
}
