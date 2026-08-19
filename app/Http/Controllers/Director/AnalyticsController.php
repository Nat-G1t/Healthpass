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
 * ?month=YYYY-MM picker and the ?college=<id> dropdown (FR-ANL-13).
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
        // Both filters validate server-side: an unknown month format falls
        // back to the newest month with data (VisitMonths::resolve), an
        // unknown college id falls back to "All colleges".
        $month = VisitMonths::resolve($request->query('month'));
        $college = $this->resolveCollege($request->query('college'));

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
            'availableMonths' => VisitMonths::available(),
            'selectedMonth' => $month->format('Y-m'),
            'selectedMonthLabel' => $month->format('F Y'),
            'colleges' => College::orderBy('code')->get(['id', 'code']),
            'selectedCollegeId' => $college?->id,
            ...$visitCards,
            ...$analytics->visitsByPurpose(),
            ...$analytics->vitalSignFlags(),
            // No argument = the clinic-wide series: the trend ignores both
            // page filters by design (FR-ANL-11).
            ...$analytics->visitsTrend(),
            ...$analytics->bmiDistribution(),
            ...$analytics->bySexDonut(),
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
