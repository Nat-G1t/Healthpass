<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Support\VisitMonths;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FR-NRS-09 (D-44) — Nurse Dashboard, the nurse's landing page.
 *
 * Four stat tiles plus the ENCODE HISTORY. Once a visit is encoded it leaves
 * the Live Queue (ClinicVisit::scopeLiveQueue only sees `captured`), so before
 * this page the nurse had no way to look back at a day's work. The history is
 * CLINIC-WIDE, not per-nurse: two nurses on shift must still read one
 * continuous log, which is why every row names who encoded it.
 *
 * `clearance_records` IS the encode log — one row per encoded visit — so the
 * history reads from there rather than re-deriving it from clinic_visits.
 *
 * An "__invoke" controller is a single-action controller: Laravel calls the
 * class itself, so the route needs no method name. Used here because this
 * page has exactly one action.
 */
class DashboardController extends Controller
{
    /**
     * History rows per page (kept in step with DashboardPageTest). Deliberately
     * NOT healthpass.ui.rows_per_page (FR-UI-06): the live queue shows more on purpose.
     */
    private const PER_PAGE = 15;

    /** The GET keys that describe the history table's state (filters + page). */
    public const TABLE_STATE_KEYS = ['month', 'result', 'q', 'page'];

    /**
     * The table's current filters and page, for links that leave the dashboard
     * and must bring the nurse back to the same view (FR-NRS-09: View → the
     * visit page's back link). Only the four whitelisted keys, and only
     * non-empty strings — so the URL stays clean and nothing else rides along.
     */
    public static function tableState(Request $request): array
    {
        return array_filter(
            $request->only(self::TABLE_STATE_KEYS),
            fn ($value) => is_string($value) && $value !== '',
        );
    }

    public function __invoke(Request $request): View
    {
        $availableMonths = VisitMonths::available();
        $selectedMonth = $this->selectedMonth($request, $availableMonths);
        $selectedResult = $this->selectedResult($request);
        $search = trim((string) $request->query('q', ''));

        return view('nurse.dashboard', [
            'stats' => $this->stats(),
            'records' => $this->history($selectedMonth, $selectedResult, $search),
            'availableMonths' => $availableMonths,
            'selectedMonth' => $selectedMonth,
            'selectedResult' => $selectedResult,
            'search' => $search,
            'tableState' => self::tableState($request),
        ]);
    }

    // ── Stat tiles ───────────────────────────────────────────────────────────

    /**
     * The four tiles. These describe the clinic RIGHT NOW — they deliberately
     * ignore the table's filters, so changing the month picker never rewrites
     * "encoded today" underneath the nurse.
     *
     * @return array{encodedToday: int, encodedMonth: int, awaitingEncode: int, flaggedMonth: int}
     */
    private function stats(): array
    {
        $now = CarbonImmutable::now();
        $today = [$now->startOfDay(), $now->endOfDay()];
        $month = [$now->startOfMonth(), $now->endOfMonth()];

        return [
            'encodedToday' => ClearanceRecord::whereBetween('encoded_at', $today)->count(),
            'encodedMonth' => ClearanceRecord::whereBetween('encoded_at', $month)->count(),
            // The Live Queue's own count (BR-11): visits captured at the kiosk
            // and still waiting for an assessment.
            // D-72: 'captured' already excludes a resting visit by itself -
            // it is neither captured nor encoded until the re-check releases it.
            'awaitingEncode' => ClinicVisit::where('status', 'captured')->count(),
            // Flags surface from CAPTURE (FR-ANL-07), so an un-encoded visit
            // counts here too — that is the point of the tile.
            'flaggedMonth' => ClinicVisit::flagged()->whereBetween('checked_in_at', $month)->count(),
        ];
    }

    // ── History ──────────────────────────────────────────────────────────────

    /**
     * The encode log, newest first. `id` breaks ties for two results saved in
     * the same second so the order is total (and page 2 can never repeat a
     * row from page 1).
     *
     * withQueryString() re-attaches the current filters to the pager links,
     * so paging does not silently reset the month/result/search.
     */
    private function history(?string $month, ?string $result, string $search): LengthAwarePaginator
    {
        return ClearanceRecord::query()
            // Eager-load everything the table prints, so 15 rows cost a fixed
            // handful of queries instead of one per row (the "N+1" problem).
            // The column lists must keep the foreign keys the nested relations
            // are matched on (student_id, college_id) or the nesting breaks.
            ->with([
                'clinicVisit:id,reference_no,student_id,college_id,course',
                'clinicVisit.student:id,name',
                'clinicVisit.college:id,code,name',
                'encoder:id,name,role', // role → the Nurse / Physician badge (D-64)
            ])
            ->when($month !== null, fn (Builder $query) => $query->whereBetween('encoded_at', $this->monthBounds($month)))
            ->when($result !== null, fn (Builder $query) => $query->where('result', $result))
            ->when($search !== '', fn (Builder $query) => $this->applySearch($query, $search))
            ->orderByDesc('encoded_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Search one box against two places: the visit's reference number and the
     * student's name. whereHas() filters by a related table — it compiles to
     * an EXISTS subquery, so the orWhere pair stays grouped inside it and
     * cannot leak out and widen the month/result filters.
     *
     * `%` and `_` typed by the nurse are left as LIKE wildcards rather than
     * escaped: the ESCAPE clause differs between MySQL and SQLite (the test
     * database), and the worst case is an over-broad match on a log this
     * nurse may already read in full. The term itself is a bound parameter.
     */
    private function applySearch(Builder $query, string $search): Builder
    {
        $term = '%'.$search.'%';

        return $query->whereHas('clinicVisit', fn (Builder $visit) => $visit
            ->where('reference_no', 'like', $term)
            ->orWhereHas('student', fn (Builder $student) => $student->where('name', 'like', $term)));
    }

    // ── Filter resolution ────────────────────────────────────────────────────

    /**
     * The month filter, or null for "All months".
     *
     * Only a month the picker actually offers is accepted. Anything else —
     * missing, malformed, hand-typed — degrades to "all", so the <select> and
     * the table can never disagree about what is on screen.
     *
     * @param  list<array{value: string, label: string}>  $availableMonths
     */
    private function selectedMonth(Request $request, array $availableMonths): ?string
    {
        $month = $request->query('month');

        return is_string($month) && in_array($month, array_column($availableMonths, 'value'), true)
            ? $month
            : null;
    }

    /** The result filter, or null for "All results". */
    private function selectedResult(Request $request): ?string
    {
        $result = $request->query('result');

        return is_string($result) && in_array($result, ClearanceRecord::RESULTS, true)
            ? $result
            : null;
    }

    /**
     * Month bounds as Carbon instances — NOT a raw MONTH()/DATE_FORMAT, which
     * is MySQL-only and would fail on the SQLite test database (same rule the
     * Director's AnalyticsController::monthBounds() follows).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function monthBounds(string $month): array
    {
        $start = VisitMonths::resolve($month);

        return [$start, $start->endOfMonth()];
    }
}
