<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopedToManagedCollege;
use App\Http\Controllers\Controller;
use App\Services\ClinicAnalytics;
use App\Support\Programs;
use App\Support\VisitMonths;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Printable Monthly Clinic Report (FR-ADM-09, D-46) — the artifact a college
 * hands over, as opposed to the screen it looks at (FR-ADM-08).
 *
 * It is the SAME six card builders, rendered as tables instead of charts:
 * charts do not print reliably and a report has to be readable on paper. The
 * deliverable is a Blade page plus window.print(), exactly the pattern the
 * clearance form already uses (Nurse\PrintClearanceController) — not a PDF
 * package, not a CSV (FR-ANL-06 export stays struck by D-32).
 *
 * SECURITY — identical to the analytics page it prints: the college comes
 * from $this->managedCollege() and is fixed on the service at construction.
 * There is deliberately NO ?college= handling, so a hand-edited print URL has
 * nothing to override (FR-AUTH-06 / FR-ADM-06). Only the month and program
 * filters carry over from the analytics page's query string, and the program
 * is checked against THIS college's catalog before it reaches a query (D-42).
 */
class MonthlyReportController extends Controller
{
    use ScopedToManagedCollege;

    public function __invoke(Request $request): View
    {
        $college = $this->managedCollege();

        // Both filters degrade rather than error, exactly as they do on the
        // analytics page — the printout must render for any URL the admin
        // can reach, and Programs::isValid() is the same catalog check the
        // page's own filter makes.
        $month = VisitMonths::resolve($request->query('month'), $college);
        $program = $request->query('program');
        $program = is_string($program) && Programs::isValid($college->id, $program) ? $program : null;

        $analytics = new ClinicAnalytics($month, $college, $program);

        return view('admin.monthly-report', [
            'college' => $college,
            'monthLabel' => $month->format('F Y'),
            'selectedProgram' => $program,
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
            ...$analytics->visitsByProgram(),
            ...$analytics->visitsByPurpose(),
            ...$analytics->vitalSignFlags(),
            ...$analytics->bmiDistribution(),
            ...$analytics->bySexDonut(),
        ]);
    }
}
