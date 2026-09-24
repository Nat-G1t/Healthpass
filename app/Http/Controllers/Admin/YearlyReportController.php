<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopedToManagedCollege;
use App\Http\Controllers\Controller;
use App\Services\YearlyClearanceReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Yearly Clearance Report (FR-ADM-13, D-81) — every clearance the college's
 * students were issued in one calendar year, downloaded as a PDF the college
 * files as a record.
 *
 * A PDF on purpose, unlike the Monthly Report's print view (D-46): D-81 makes
 * this ONE report a deliberate exception, because a yearly record is kept as
 * a file rather than printed once and handed over.
 *
 * SECURITY — the same rule as the monthly report: the college comes from
 * $this->managedCollege() and there is NO ?college= handling, so a
 * hand-edited URL has nothing to override (FR-AUTH-06 / FR-ADM-06). The only
 * input is the year, and the analytics page's program filter is ignored — the
 * report always covers the whole college.
 */
class YearlyReportController extends Controller
{
    use ScopedToManagedCollege;

    public function __invoke(Request $request): Response
    {
        // The popup only offers these years, so a bad one means a hand-edited
        // URL; the normal redirect-back-with-errors is enough. "Now" is the
        // server clock (BR-20), never the browser's.
        $validated = $request->validate([
            'year' => [
                'required',
                'integer',
                'min:'.config('healthpass.reports.yearly_first_year'),
                'max:'.now()->year,
            ],
        ]);

        $college = $this->managedCollege();
        $year = (int) $validated['year'];
        $report = new YearlyClearanceReport($college, $year);

        // `Pdf::loadView()` renders the Blade view to HTML and lays it out as
        // a PDF (dompdf); `download()` sends it with a Content-Disposition
        // attachment header, so the browser saves the file and the admin
        // stays on the Analytics page.
        return Pdf::loadView('admin.yearly-report', [
            'college' => $college,
            'year' => $year,
            'summary' => $report->summary(),
            'rows' => $report->rows(),
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
        ])
            ->setPaper('letter', 'portrait')
            ->download("HealthPass-Yearly-Report-{$college->code}-{$year}.pdf");
    }
}
