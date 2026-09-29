<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Http\Requests\YearlyReportRequest;
use App\Services\DirectorYearlyReport;
use App\Support\ClearanceCounts;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * The Director's Yearly Clearance Report (D-94) — a PDF of clearance COUNTS
 * for every college and each of its programs, across a span of calendar years.
 *
 * The College Admin's report (FR-ADM-13) lists their students by name; this
 * one lists none. For each year it gives every college's line — took
 * clearance, Fit, Unfit, Male, Female — with its programs' lines underneath,
 * and it opens with the span's totals by college.
 *
 * D-94 widens D-81's scoped exception to D-46 / FR-ANL-06 (no analytics
 * export) by exactly this one download. The Director Analytics page itself is
 * still not printed or exported.
 *
 * Same year-span popup and validation as the admin report
 * (YearlyReportRequest); the analytics page's month and college filters are
 * ignored — the report always covers every college.
 */
class YearlyReportController extends Controller
{
    public function __invoke(YearlyReportRequest $request): Response
    {
        $years = array_map(function (int $year): array {
            $report = new DirectorYearlyReport($year);

            return ['year' => $year, 'summary' => $report->summary(), 'colleges' => $report->colleges()];
        }, $request->years());

        return Pdf::loadView('director.yearly-report', [
            'years' => $years,
            'spanColleges' => $this->spanColleges($years),
            'spanSummary' => ClearanceCounts::sum(array_column($years, 'summary')),
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
        ])
            ->setPaper('letter', 'portrait')
            ->download("HealthPass-Yearly-Report-All-Colleges-{$request->spanLabel()}.pdf");
    }

    /**
     * Each college's counts added up across the span, for the opening page.
     * Every year lists the same colleges in the same order, so the first
     * year's list is the template.
     *
     * @param  list<array{year: int, summary: array<string, int>, colleges: list<array<string, mixed>>}>  $years
     * @return list<array{code: string, name: string, counts: array<string, int>}>
     */
    private function spanColleges(array $years): array
    {
        return array_map(fn (array $college, int $index): array => [
            'code' => $college['code'],
            'name' => $college['name'],
            'counts' => ClearanceCounts::sum(array_map(
                fn (array $year): array => $year['colleges'][$index]['counts'],
                $years,
            )),
        ], $years[0]['colleges'], array_keys($years[0]['colleges']));
    }
}
