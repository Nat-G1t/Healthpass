<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Support\VisitMonths;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
    public const TABLE_STATE_KEYS = ['year', 'month', 'result', 'q', 'page'];

    /**
     * The table's current filters and page, for links that leave the dashboard
     * and must bring the nurse back to the same view (FR-NRS-09: View → the
     * visit page's back link). Only the whitelisted keys, and only
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
        // D-100: a Year picker, then January–December of that year — the
        // Analytics filter's shape, but each keeps an "All" option.
        $encodedMonths = $this->encodedMonthsByYear();
        $selectedYear = $this->selectedYear($request);
        $selectedMonth = $this->selectedMonth($request, $selectedYear, $encodedMonths);
        $selectedResult = $this->selectedResult($request);
        $search = trim((string) $request->query('q', ''));

        return view('nurse.dashboard', [
            'stats' => $this->stats(),
            'records' => $this->history($this->dateBounds($selectedYear, $selectedMonth), $selectedResult, $search),
            // 2021 to this year, the same list the Analytics year picker offers.
            'years' => VisitMonths::years(),
            'monthOptions' => $this->monthOptions(),
            'encodedMonths' => $encodedMonths,
            'selectedYear' => $selectedYear,
            'selectedMonth' => $selectedMonth === null ? null : sprintf('%02d', $selectedMonth),
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
     * so paging does not silently reset the year/month/result/search.
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $bounds  null = all years
     */
    private function history(?array $bounds, ?string $result, string $search): LengthAwarePaginator
    {
        return ClearanceRecord::query()
            // Eager-load everything the table prints, so 15 rows cost a fixed
            // handful of queries instead of one per row (the "N+1" problem).
            // The column lists must keep the foreign keys the nested relations
            // are matched on (student_id, college_id, user_id) or the nesting breaks.
            ->with([
                'clinicVisit:id,reference_no,student_id,college_id,course',
                'clinicVisit.student:id,name',
                'clinicVisit.student.studentProfile:id,user_id,student_number', // the Student ID column (D-100)
                'clinicVisit.college:id,code,name',
                'encoder:id,name,role', // role → the Nurse / Physician badge (D-64)
            ])
            ->when($bounds !== null, fn (Builder $query) => $query->whereBetween('encoded_at', $bounds))
            ->when($result !== null, fn (Builder $query) => $query->where('result', $result))
            ->when($search !== '', fn (Builder $query) => $this->applySearch($query, $search))
            ->orderByDesc('encoded_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Search one box against two places: the visit's reference number and the
     * student's ID number (D-100 — the student's name is no longer searched).
     * whereHas() filters by a related table — it compiles to
     * an EXISTS subquery, so the orWhere pair stays grouped inside it and
     * cannot leak out and widen the year/month/result filters.
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
            ->orWhereHas('student.studentProfile', fn (Builder $profile) => $profile->where('student_number', 'like', $term)));
    }

    // ── Filter resolution ────────────────────────────────────────────────────

    /**
     * The year filter, or null for "All years". Only a year the picker offers
     * is accepted; anything else — missing, malformed, hand-typed — degrades
     * to "all", so the <select> and the table never disagree.
     */
    private function selectedYear(Request $request): ?int
    {
        $year = $request->query('year');

        return is_string($year) && ctype_digit($year) && in_array((int) $year, VisitMonths::years(), true)
            ? (int) $year
            : null;
    }

    /**
     * The month filter (1–12), or null for "All months".
     *
     * A month only means something inside a picked year, and only a month
     * that year has encodes in is accepted — exactly the months the picker
     * leaves enabled. Anything else degrades to "All months" of that year.
     *
     * @param  array<int, list<int>>  $encodedMonths
     */
    private function selectedMonth(Request $request, ?int $year, array $encodedMonths): ?int
    {
        $month = $request->query('month');

        if ($year === null || ! is_string($month) || preg_match('/^(0[1-9]|1[0-2])$/', $month) !== 1) {
            return null;
        }

        return in_array((int) $month, $encodedMonths[$year] ?? [], true) ? (int) $month : null;
    }

    /**
     * The months that have at least one encoded result, per year — e.g.
     * [2026 => [9, 10]]. The page greys out every other month of the picked
     * year. Built in PHP from the Carbon-cast encoded_at (not YEAR()/MONTH(),
     * which are MySQL-only), so it stays portable to the SQLite test database.
     *
     * @return array<int, list<int>>
     */
    private function encodedMonthsByYear(): array
    {
        return ClearanceRecord::query()
            ->whereNotNull('encoded_at')
            ->pluck('encoded_at')
            ->groupBy(fn (CarbonInterface $encodedAt) => $encodedAt->year)
            ->map(fn ($dates) => $dates->map(fn (CarbonInterface $encodedAt) => $encodedAt->month)->unique()->sort()->values()->all())
            ->all();
    }

    /**
     * January–December for the Month picker, as ['value' => '09', 'number' => 9, 'label' => 'September'].
     *
     * @return list<array{value: string, number: int, label: string}>
     */
    private function monthOptions(): array
    {
        return array_map(fn (int $month): array => [
            'value' => sprintf('%02d', $month),
            'number' => $month,
            'label' => CarbonImmutable::create(2000, $month, 1)->format('F'),
        ], range(1, 12));
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
     * The picked year (or one month of it) as Carbon bounds, or null for "All
     * years" — NOT a raw YEAR()/MONTH()/DATE_FORMAT, which is MySQL-only and
     * would fail on the SQLite test database.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function dateBounds(?int $year, ?int $month): ?array
    {
        if ($year === null) {
            return null;
        }

        $start = CarbonImmutable::create($year, $month ?? 1, 1)->startOfMonth();

        return [$start, $month === null ? $start->endOfYear() : $start->endOfMonth()];
    }
}
