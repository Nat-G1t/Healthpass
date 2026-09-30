<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Models\College;
use App\Services\ClinicAnalytics;
use App\Support\VisitMonths;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Director's printable Monthly Clinic Report (D-97) — the College Admin's
 * FR-ADM-09 report one level up: colleges where the admin's has programs,
 * plus a College Summary table (visits, flagged, abnormal BMI, male/female
 * per college).
 *
 * It prints what the Director's analytics page is showing: the same
 * ?month= and ?college= filters, resolved the same way, and the same
 * ClinicAnalytics builders — so the paper cannot disagree with the screen.
 * With one college picked it is that college's report: its programs and its
 * one summary row (D-46's page rule, carried onto paper).
 *
 * Same template and print-in-place frame as the admin's report
 * (reports/monthly-report.blade.php, partials/print-frame).
 */
class MonthlyReportController extends Controller
{
    public function __invoke(Request $request): View
    {
        $month = VisitMonths::resolve($request->query('month'));
        $college = $this->resolveCollege($request->query('college'));

        $analytics = new ClinicAnalytics($month, $college);

        return view('reports.monthly-report', [
            'scopeName' => $college === null ? 'All colleges' : "{$college->name} ({$college->code})",
            'scopeCode' => $college?->code ?? 'All colleges',
            'monthLabel' => $month->format('F Y'),
            'selectedProgram' => null,
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
            ...($college === null ? $analytics->visitsByCollege() : $analytics->visitsByProgram()),
            ...$analytics->collegeSummary(),
            ...$analytics->visitsByPurpose(),
            ...$analytics->vitalSignFlags(),
            ...$analytics->bmiDistribution(),
            ...$analytics->bySexDonut(),
            ...$analytics->flagsBySex(),
        ]);
    }

    /**
     * The ?college=<id> filter as a College, or null for "All colleges" — the
     * same rule as the analytics page (AnalyticsController), so a hand-edited
     * URL degrades instead of erroring.
     */
    private function resolveCollege(mixed $collegeId): ?College
    {
        if (! is_string($collegeId) || ! ctype_digit($collegeId)) {
            return null;
        }

        return College::find((int) $collegeId);
    }
}
