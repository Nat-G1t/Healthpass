<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopedToManagedCollege;
use App\Http\Controllers\Controller;
use App\Http\Requests\YearlyReportRequest;
use App\Services\YearlyClearanceReport;
use App\Support\ClearanceCounts;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Yearly Clearance Report (FR-ADM-13, D-81, D-94) — every clearance the
 * college's students were issued across a SPAN of calendar years, downloaded
 * as a PDF the college files as a record.
 *
 * D-94: the popup now picks a start year and an end year. The PDF opens with
 * the whole span's totals (and each year's), then gives every year its own
 * section — that year's counts and its table of students — exactly what the
 * one-year report used to print, once per year.
 *
 * A PDF on purpose, unlike the Monthly Report's print view (D-46): D-81 makes
 * this ONE report a deliberate exception, because a yearly record is kept as
 * a file rather than printed once and handed over.
 *
 * SECURITY — the same rule as the monthly report: the college comes from
 * $this->managedCollege() and there is NO ?college= handling, so a
 * hand-edited URL has nothing to override (FR-AUTH-06 / FR-ADM-06). The only
 * input is the year span, and the analytics page's program filter is ignored
 * — the report always covers the whole college.
 */
class YearlyReportController extends Controller
{
    use ScopedToManagedCollege;

    public function __invoke(YearlyReportRequest $request): Response
    {
        $college = $this->managedCollege();

        // One YearlyClearanceReport per calendar year, oldest first — the
        // per-year figures stay exactly what D-81's one-year report computed.
        $sections = array_map(function (int $year) use ($college): array {
            $report = new YearlyClearanceReport($college, $year);

            return ['year' => $year, 'summary' => $report->summary(), 'rows' => $report->rows()];
        }, $request->years());

        // `Pdf::loadView()` renders the Blade view to HTML and lays it out as
        // a PDF (dompdf); `download()` sends it with a Content-Disposition
        // attachment header, so the browser saves the file and the admin
        // stays on the Analytics page.
        return Pdf::loadView('admin.yearly-report', [
            'college' => $college,
            'sections' => $sections,
            // The span's totals are the years' totals added up, so page 1 and
            // the year sections can never disagree.
            'spanSummary' => ClearanceCounts::sum(array_column($sections, 'summary')),
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
        ])
            ->setPaper('letter', 'portrait')
            ->download("HealthPass-Yearly-Report-{$college->code}-{$request->spanLabel()}.pdf");
    }
}
