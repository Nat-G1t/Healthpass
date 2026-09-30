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
 * Director Analytics (FR-ANL-09..13 + the amended FR-ANL-04), scoped by the
 * ?year= + ?month= pickers (D-99) and the ?college=<id> dropdown (FR-ANL-13).
 *
 * Since D-45 every card is built by App\Services\ClinicAnalytics, which the
 * College Admin page (FR-ADM-08) also calls — this controller's only job is
 * to resolve the two filters and hand them over. The behaviour of each card
 * is documented on the service.
 */
class AnalyticsController extends Controller
{
    public function __invoke(Request $request): View
    {
        // Every filter validates server-side: an unknown college id falls
        // back to "All colleges", and an unusable year/month to the newest
        // month with data. D-99: the college is resolved FIRST because the
        // month picker greys out months by it — a month the picked college
        // has no visits in moves to that year's latest one that it does.
        $college = $this->resolveCollege($request->query('college'));
        $month = VisitMonths::resolvePicked($request->query('year'), $request->query('month'), $college);
        $years = VisitMonths::years();

        $analytics = new ClinicAnalytics($month, $college);

        // D-46: when the filter narrows to ONE college, the by-College card
        // is a single bar with nothing to compare itself against, so it
        // becomes Clinic Visits by Program for that college. Both builders
        // run under the SAME scope and describe the same visits one level
        // down, so their totals agree by construction; the view picks which
        // pair of rows it draws. With "All colleges" the program builder is
        // never called and the card is exactly what it was before.
        $visitCards = [
            ...$analytics->visitsByCollege(),
            ...($college === null ? [] : $analytics->visitsByProgram()),
        ];

        return view('director.analytics', [
            // D-99: a year picker, then January–December of that year.
            'years' => $years,
            'selectedYear' => $month->year,
            'monthOptions' => VisitMonths::monthsOf($month->year, $college),
            'selectedMonthNumber' => $month->format('m'),
            // Y-m, still what the Print Monthly Report link carries.
            'selectedMonth' => $month->format('Y-m'),
            'selectedMonthLabel' => $month->format('F Y'),
            'colleges' => College::orderBy('code')->get(['id', 'code']),
            'selectedCollegeId' => $college?->id,
            // D-94: the Yearly Report popup's years for its start and end
            // pickers, newest first — from the SERVER clock (BR-20). The same
            // list as the year picker above.
            'reportYears' => $years,
            ...$visitCards,
            ...$analytics->visitsByPurpose(),
            ...$analytics->vitalSignFlags(),
            // D-99: the selected year, month by month, for the selected
            // college (or all of them).
            ...$analytics->visitsTrend(),
            ...$analytics->bmiDistribution(),
            ...$analytics->bySexDonut(),
            ...$analytics->flagsBySex(),
        ]);
    }

    /**
     * The ?college=<id> filter value as a College, or null for "All
     * colleges". Anything non-numeric or unknown degrades to null so a
     * hand-edited URL can never error the page (FR-ANL-13).
     */
    private function resolveCollege(mixed $collegeId): ?College
    {
        if (! is_string($collegeId) || ! ctype_digit($collegeId)) {
            return null;
        }

        return College::find((int) $collegeId);
    }
}
