<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Models\ClearanceRecord;
use App\Models\College;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Director Analytics — Medical Cases by College (FR-ANL-02): a horizontal
 * stacked bar, one row per college (all 12, zero-case rows included), each
 * bar segmented by the 8 medical-system categories, sorted by total case
 * volume descending.
 *
 * Counting rules:
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

    public function __invoke(): View
    {
        // One row per (college, category): plain portable JOIN + GROUP BY —
        // exactly what the D-23 child table was designed for.
        $rows = DB::table('clearance_case_categories')
            ->join('clearance_records', 'clearance_records.id', '=', 'clearance_case_categories.clearance_record_id')
            ->join('clinic_visits', 'clinic_visits.id', '=', 'clearance_records.clinic_visit_id')
            ->where('clinic_visits.status', 'encoded') // FR-ANL-07
            ->groupBy('clinic_visits.college_id', 'clearance_case_categories.case_category')
            ->select(
                'clinic_visits.college_id',
                'clearance_case_categories.case_category',
                DB::raw('count(*) as cases'),
            )
            ->get();

        // counts[college_id][category] and totals[college_id].
        $counts = [];
        $totals = [];
        foreach ($rows as $row) {
            $counts[$row->college_id][$row->case_category] = (int) $row->cases;
            $totals[$row->college_id] = ($totals[$row->college_id] ?? 0) + (int) $row->cases;
        }

        // All colleges, sorted by total volume descending. The initial
        // code-ascending order is the tie-break: PHP sorts are stable, so
        // equal totals keep alphabetical order — deterministic chart rows.
        $colleges = College::orderBy('code')
            ->get()
            ->sortByDesc(fn (College $college) => $totals[$college->id] ?? 0)
            ->values();

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
        ]);
    }
}
