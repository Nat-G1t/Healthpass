<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ClinicVisit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The Summary of Medical Cases aggregation (FR-ANL-03) in one place —
 * the college × medical-system counts that BOTH the on-screen matrix
 * (AnalyticsController, cumulative) and the printable monthly report
 * (CaseSummaryPrintController, month-scoped) read from, so the screen and
 * the printout can never disagree on a number.
 *
 * Counting rules, identical to the chart/matrix (FR-ANL-02/03, D-23):
 *  - encoded records only (FR-ANL-07), via ClinicVisit::encodedCaseRows();
 *  - one count per record × category — a multi-category case counts once
 *    in each of its systems;
 *  - grouped by clinic_visits.college_id, the capture-time college snapshot
 *    (FR-STU-09, D-17), so a later transfer never re-attributes a case.
 */
final class MedicalCaseSummary
{
    /**
     * @param  array<int, array<string, int>>  $counts  counts[collegeId][category]
     * @param  array<int, int>  $totals  totals[collegeId] — matrix column totals
     * @param  array<string, int>  $categoryTotals  categoryTotals[category] — TOTAL column
     * @param  int  $grandTotal  matrix grand total
     */
    private function __construct(
        public readonly array $counts,
        public readonly array $totals,
        public readonly array $categoryTotals,
        public readonly int $grandTotal,
    ) {}

    /**
     * Build the summary. Pass a month to scope it to visits checked in
     * during that calendar month (the printed report); omit it for the
     * cumulative all-time figures the dashboard matrix shows.
     */
    public static function build(?CarbonInterface $month = null): self
    {
        $query = ClinicVisit::encodedCaseRows() // FR-ANL-07, shared base
            ->groupBy('clinic_visits.college_id', 'clearance_case_categories.case_category')
            ->select(
                'clinic_visits.college_id',
                'clearance_case_categories.case_category',
                DB::raw('count(*) as cases'),
            );

        // Month scope keys on checked_in_at (when the student was seen), not
        // encoded_at — a case belongs to the month of the visit. Carbon bounds
        // keep this a parameterized BETWEEN, portable to the SQLite test DB.
        if ($month !== null) {
            $query->whereBetween('clinic_visits.checked_in_at', [
                $month->copy()->startOfMonth(),
                $month->copy()->endOfMonth(),
            ]);
        }

        $counts = [];
        $totals = [];
        $categoryTotals = [];
        $grandTotal = 0;

        foreach ($query->toBase()->get() as $row) {
            $cases = (int) $row->cases;
            $counts[$row->college_id][$row->case_category] = $cases;
            $totals[$row->college_id] = ($totals[$row->college_id] ?? 0) + $cases;
            $categoryTotals[$row->case_category] = ($categoryTotals[$row->case_category] ?? 0) + $cases;
            $grandTotal += $cases;
        }

        return new self($counts, $totals, $categoryTotals, $grandTotal);
    }
}
