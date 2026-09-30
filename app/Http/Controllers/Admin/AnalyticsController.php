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
 * College Admin Analytics (FR-ADM-08, D-45): the Director's six analytics
 * cards scoped to ONE college, with Clinic Visits by College replaced by
 * Clinic Visits by Program. Every card is built by the shared
 * App\Services\ClinicAnalytics, so the two pages cannot drift apart.
 *
 * SECURITY — the whole point of this controller:
 * the college comes from $this->managedCollege() (the ScopedToManagedCollege
 * trait, FR-AUTH-06 / FR-ADM-06) and is handed to the service at construction.
 * There is deliberately NO ?college= handling here — not a validated one, not
 * a rejected one. A ?college= in the query string is simply never read, so
 * there is nothing for it to override, and no future edit to a validation rule
 * can accidentally open one.
 *
 * The only filters are the year + month (D-99) and the program, and the
 * program is checked against THIS college's catalog before it reaches a query.
 */
class AnalyticsController extends Controller
{
    use ScopedToManagedCollege;

    public function __invoke(Request $request): View
    {
        $college = $this->managedCollege();

        // Every filter degrades rather than errors, matching how the Director
        // page resolves its own (FR-ANL-13): an unusable year/month falls back
        // to the newest month this college has data in, and an unknown
        // program falls back to "All programs". D-99: the month picker greys
        // out months by this college, and a month it has no visits in moves
        // to that year's latest one that it does.
        $month = VisitMonths::resolvePicked($request->query('year'), $request->query('month'), $college);
        $years = VisitMonths::years();
        $programs = Programs::forCollege($college->id);
        $program = $this->resolveProgram($request->query('program'), $programs);

        $analytics = new ClinicAnalytics($month, $college, $program);

        return view('admin.analytics', [
            'college' => $college,
            // D-99: a year picker, then January–December of that year.
            'years' => $years,
            'selectedYear' => $month->year,
            'monthOptions' => VisitMonths::monthsOf($month->year, $college),
            'selectedMonthNumber' => $month->format('m'),
            // Y-m, still what the Print Monthly Report link carries.
            'selectedMonth' => $month->format('Y-m'),
            'selectedMonthLabel' => $month->format('F Y'),
            'programs' => $programs,
            'selectedProgram' => $program,
            // FR-ADM-13 (D-81, D-94): the Yearly Report popup's years for both
            // its start and end pickers, newest first.
            // Built from the SERVER clock (BR-20) — never in the browser. The
            // same list as the year picker above.
            'reportYears' => $years,
            ...$analytics->visitsByProgram(),
            ...$analytics->visitsByPurpose(),
            ...$analytics->vitalSignFlags(),
            // D-99: the selected year, month by month — inside this college
            // (an admin is never shown another college's numbers, aggregated
            // or not) and the selected program.
            ...$analytics->visitsTrend(),
            ...$analytics->bmiDistribution(),
            ...$analytics->bySexDonut(),
            ...$analytics->flagsBySex(),
        ]);
    }

    /**
     * The ?program= filter value, or null for "All programs".
     *
     * Validated against the MANAGED college's catalog (D-42), so a program
     * belonging to another college is rejected exactly like a made-up one —
     * a hand-edited URL cannot reach a query with a foreign value.
     *
     * @param  list<string>  $programs  This college's catalog.
     */
    private function resolveProgram(mixed $program, array $programs): ?string
    {
        return is_string($program) && in_array($program, $programs, strict: true)
            ? $program
            : null;
    }
}
