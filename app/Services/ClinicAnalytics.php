<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BatchRequest;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\VitalSigns;
use App\Support\Programs;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The clinic-analytics card builders, shared by BOTH analytics pages (D-45).
 *
 * A "service" is just a plain PHP class holding logic that would otherwise
 * bloat a controller. This one exists for one reason: the Director page
 * (FR-ANL-09..13) and the College Admin page (FR-ADM-08) draw the SAME six
 * cards from the SAME data, and two copies of these queries would drift apart
 * the first time a rule changed. The controllers now only resolve their
 * filters and hand them here.
 *
 * Every card reads only data HealthPass itself collects — appointments from
 * the web app, vitals from the kiosk:
 *
 *  - Clinic Visits by College (FR-ANL-09) / by Program (FR-ADM-08): kiosk
 *    check-ins per unit, with a Visits-by-Purpose breakdown from the linked
 *    appointments.
 *  - Vital-Sign Flags (FR-ANL-10): count + rate per flag column (five since D-66).
 *  - Visits per Month trend (FR-ANL-11): whole-year, ignores the month.
 *  - BMI Distribution (FR-ANL-12): four rule-based buckets.
 *  - Students Screened by Sex (FR-ANL-04 as amended).
 *
 * All counts compute from CAPTURED data (FR-ANL-07 as rewritten): a visit
 * counts at kiosk check-in, with no encoded-only guard. Since D-60 the clinic
 * runs ONE service — medical clearance — so every card is a single series.
 *
 * THE SCOPE IS FIXED AT CONSTRUCTION and every method reads it — a caller
 * cannot ask one card for a different college than another. That is what
 * makes the College Admin page safe to build on: its controller constructs
 * this with managedCollege() and there is no per-card college argument for a
 * request parameter to reach (FR-AUTH-06 / FR-ADM-06).
 */
final class ClinicAnalytics
{
    /** FR-ANL-09 series color (brand orange). */
    private const VISITS_COLOR = '#FF8C2A';

    /** Donut slice colors (prototype): Male = brand orange, Female = peach. */
    private const SEX_COLORS = ['#FF8C2A', '#FFCAA0'];

    /**
     * Purpose bucket for visits with no recorded purpose — a batch appointment
     * carries none, and a legacy visit may have no appointment at all. (Was
     * "Walk-in / not specified" until D-61 removed walk-ins.)
     */
    private const PURPOSE_NOT_SPECIFIED_LABEL = 'Not specified';

    /**
     * Program bucket for visits that carry no usable program: the D-43 column
     * is nullable and never backfilled, so pre-D-43 visits have none.
     */
    private const NO_PROGRAM_LABEL = 'Not specified';

    /** Wrap width for the program bar's y-axis labels, in characters. */
    private const PROGRAM_LABEL_WRAP = 26;

    /**
     * @param  CarbonImmutable  $month  The month every filtered card scopes to.
     * @param  College|null  $college  null = all colleges (Director only).
     * @param  string|null  $course  A program name, or null for all programs.
     */
    public function __construct(
        private readonly CarbonImmutable $month,
        private readonly ?College $college = null,
        private readonly ?string $course = null,
    ) {}

    // ── Shared scoping ───────────────────────────────────────────────────────

    /**
     * The [start, end] bounds of the month's calendar days — a parameterized
     * BETWEEN, portable to the SQLite test DB.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function monthBounds(): array
    {
        return [$this->month->startOfMonth(), $this->month->endOfMonth()];
    }

    /**
     * The clinic_visits-side scope every card shares: the month, the
     * capture-time college snapshot (FR-STU-09) and the capture-time program
     * snapshot (D-43). Applied to any query that has `clinic_visits` in it,
     * whether that is the base table or a join.
     */
    private function scopeToVisits(Builder $query): Builder
    {
        return $query
            // D-72: a resting visit is a first pass the clinic never saw, so it
            // is in no card, no chart and no report. Applied on the SHARED
            // scope so every count below inherits it. Written as a plain
            // whereIn rather than ClinicVisit's submitted() scope because this
            // method is also handed a VitalSigns query that JOINS
            // clinic_visits, and an Eloquent scope only exists on its own model.
            ->whereIn('clinic_visits.status', ClinicVisit::SUBMITTED_STATUSES)
            ->whereBetween('clinic_visits.checked_in_at', $this->monthBounds())
            ->when($this->college, fn ($q) => $q->where('clinic_visits.college_id', $this->college->id))
            ->when($this->course, fn ($q) => $q->where('clinic_visits.course', $this->course));
    }

    /**
     * Visits in scope: ALL kiosk check-ins of the month — captured and
     * encoded alike (FR-ANL-07).
     */
    private function visitsInScope(): Builder
    {
        return $this->scopeToVisits(ClinicVisit::query());
    }

    /** The month's captured screenings (vital_signs joined to their visit). */
    private function screeningsInScope(): Builder
    {
        return $this->scopeToVisits(
            VitalSigns::query()->join('clinic_visits', 'clinic_visits.id', '=', 'vital_signs.clinic_visit_id')
        );
    }

    // ── Cards ────────────────────────────────────────────────────────────────

    /**
     * Clinic Visits by College (FR-ANL-09): one row per college — all 11 with
     * zero rows included, or just the filtered one — sorted by visits
     * descending (stable tie-break by code).
     *
     * @return array{collegeRows: list<array{code: string, visits: int}>, totalVisits: int, collegeBar: array}
     */
    public function visitsByCollege(): array
    {
        $visits = $this->countBy($this->visitsInScope(), 'college_id', 'clinic_visits.college_id');

        $rows = College::orderBy('code')
            ->when($this->college, fn ($query) => $query->whereKey($this->college->id))
            ->get(['id', 'code'])
            ->map(fn (College $unit) => $this->row('code', $unit->code, (int) ($visits[$unit->id] ?? 0)))
            // sortBy is stable, and the rows arrive code-ascending — so
            // equal counts keep their alphabetical order (the tie-break).
            ->sortByDesc('visits')
            ->values();

        return [
            'collegeRows' => $rows->all(),
            ...$this->totals($rows),
            'collegeBar' => $this->visitsBar($rows->pluck('code')->all(), $rows),
        ];
    }

    /**
     * Clinic Visits by Program (FR-ADM-08, D-45): the same card one level
     * down — one row per program the college offers, zero-visit programs
     * included, sorted by visits descending with an alphabetical tie-break.
     *
     * It reads the capture-time `clinic_visits.course` snapshot (D-43), so a
     * student who shifts program does not silently restate last month's
     * report.
     *
     * A trailing "Not specified" row appears only when visits in scope carry
     * no program (the D-43 column is nullable and never backfilled) or one the
     * catalog no longer lists. Without it the card's headline total would
     * silently disagree with every other card on the page.
     *
     * @return array{programRows: list<array{program: string, visits: int}>, totalVisits: int, programBar: array}
     */
    public function visitsByProgram(): array
    {
        $catalog = $this->college === null ? [] : Programs::forCollege($this->college->id);

        $visits = $this->countBy($this->visitsInScope(), 'course', 'clinic_visits.course');

        // Alphabetical, not catalog order, so the stable sort below leaves
        // equal counts in alphabetical order — the same tie-break as colleges.
        $rows = collect($catalog)
            ->when(
                $this->course !== null,
                fn (Collection $programs) => $programs->filter(fn (string $program) => $program === $this->course),
            )
            ->sort()
            ->values()
            ->map(fn (string $program) => $this->row('program', $program, (int) ($visits[$program] ?? 0)));

        // A program filter names one catalog program, so it can never select
        // the unlisted bucket — only the unfiltered view can show it.
        if ($this->course === null) {
            $unlisted = $this->unlisted($visits, $catalog);

            if ($unlisted > 0) {
                $rows = $rows->concat([$this->row('program', self::NO_PROGRAM_LABEL, $unlisted)]);
            }
        }

        $rows = $rows->sortByDesc('visits')->values();

        return [
            'programRows' => $rows->all(),
            ...$this->totals($rows),
            'programBar' => $this->visitsBar(
                $rows->map(fn (array $row) => $this->wrapLabel($row['program']))->all(),
                $rows,
            ),
        ];
    }

    /**
     * Visits by Purpose (inside the FR-ANL-09 card): the month's visits
     * bucketed by their batch's reason — since D-62 the batch reason IS the
     * printed purpose. Joined clinic_visits → appointments → batch_requests
     * with LEFT JOINs, so a legacy visit with no appointment or no batch
     * yields NULL and lands in "Not specified", as does a retired pre-D-62
     * reason the form lists don't know.
     *
     * Grouped by (form_type, reason) because a key's label belongs to its
     * form; rows that share a label ("Others, Specify" is on both forms) are
     * then merged into one bar.
     *
     * @return array{purposeRows: list<array{label: string, count: int}>, purposeMax: int}
     */
    public function visitsByPurpose(): array
    {
        $counts = $this->visitsInScope()
            ->leftJoin('appointments', 'appointments.id', '=', 'clinic_visits.appointment_id')
            ->leftJoin('batch_requests', 'batch_requests.id', '=', 'appointments.batch_request_id')
            ->groupBy('batch_requests.form_type', 'batch_requests.reason')
            ->select('batch_requests.form_type', 'batch_requests.reason', DB::raw('count(*) as visits'))
            ->toBase()
            ->get();

        $rows = $counts
            ->groupBy(fn (object $row): string => BatchRequest::REASONS_BY_FORM[$row->form_type][$row->reason]
                ?? self::PURPOSE_NOT_SPECIFIED_LABEL)
            ->map(fn (Collection $group, string $label): array => [
                'label' => $label,
                'count' => (int) $group->sum('visits'),
            ])
            ->sortBy('label')          // stable tie-break…
            ->sortByDesc('count')      // …under the count ordering
            ->values();

        return [
            'purposeRows' => $rows->all(),
            'purposeMax' => (int) $rows->max('count'),
        ];
    }

    /**
     * Vital-Sign Flags (FR-ANL-10): count + rate per flag column, over ALL
     * captured screenings of the month (no encoded-only guard, FR-ANL-07).
     * The flags were computed server-side at capture (BR-13/14); here they
     * are only counted. Rate = % of the month's screenings, one decimal.
     *
     * @return array{screenings: int, flagTiles: list<array{label: string, count: int, rate: float, sub: string}>}
     */
    public function vitalSignFlags(): array
    {
        $screenings = $this->screeningsInScope()->count();
        $thresholds = config('healthpass.thresholds');

        $rate = fn (int $count): float => $screenings > 0
            ? round($count / $screenings * 100, 1)
            : 0.0;

        $tile = function (string $label, string $column, string $sub) use ($rate): array {
            $count = $this->screeningsInScope()->where($column, true)->count();

            return ['label' => $label, 'count' => $count, 'rate' => $rate($count), 'sub' => $sub];
        };

        return [
            'screenings' => $screenings,
            'flagTiles' => [
                $tile('High Blood Pressure', 'is_bp_flagged', "≥ {$thresholds['bp_systolic']}/{$thresholds['bp_diastolic']} · locked threshold"),
                $tile('Fever', 'is_temp_flagged', "> {$thresholds['temperature_max']} °C · per PRD business rule"),
                $tile('Abnormal BMI', 'is_bmi_flagged', "BMI ≥ {$thresholds['bmi_obese']} · flagged at capture"),
                // D-66. The heart-rate tile counts captures like the three
                // above; the respiratory-rate one can only count visits the
                // clinic has encoded, because that is where the rate is
                // measured (D-65) — its rate therefore reads low against a
                // month still being encoded, which the caption says outright.
                $tile('High Heart Rate', 'is_hr_flagged', "> {$thresholds['heart_rate_max']} bpm · flagged at capture"),
                $tile('Abnormal Respiratory Rate', 'is_rr_flagged',
                    "outside {$thresholds['respiratory_rate_min']}–{$thresholds['respiratory_rate_max']}/min · measured by the clinic at encode"),
            ],
        ];
    }

    /**
     * Visits per Month trend (FR-ANL-11): clinic visits per month, across ALL
     * months with data. The MONTH filter is always ignored — that is the whole
     * point of the card.
     *
     * Months are derived in PHP from Carbon-cast dates, so no MySQL-only date
     * functions reach the SQLite test DB.
     *
     * @param  bool  $withinScope  false (Director): the clinic-wide, all-college
     *                             series — FR-ANL-11 ignores the page filters by
     *                             design. true (College Admin): narrowed to this
     *                             service's college + program, because an admin
     *                             must never be shown another college's numbers,
     *                             aggregated or not (FR-ADM-06).
     * @return array{trend: array, trendMonthCount: int}
     */
    public function visitsTrend(bool $withinScope = false): array
    {
        $visitsByMonth = ClinicVisit::query()
            // D-72. The trend bypasses scopeToVisits() (it spans every month),
            // so it applies the same exclusion itself.
            ->submitted()
            ->whereNotNull('checked_in_at')
            ->when($withinScope && $this->college, fn ($q) => $q->where('clinic_visits.college_id', $this->college->id))
            ->when($withinScope && $this->course, fn ($q) => $q->where('clinic_visits.course', $this->course))
            ->pluck('checked_in_at')
            ->countBy(fn ($date) => $date->format('Y-m'));

        $months = $visitsByMonth->keys()->sort()->values();

        // Short month names; the year is added only when the data spans
        // more than one calendar year, to keep the axis readable.
        $spansYears = $months->map(fn (string $m) => substr($m, 0, 4))->unique()->count() > 1;
        $label = fn (string $yearMonth) => CarbonImmutable::createFromFormat('Y-m-d', $yearMonth.'-01')
            ->format($spansYears ? 'M Y' : 'M');

        return [
            'trend' => [
                'labels' => $months->map($label)->all(),
                'datasets' => [
                    [
                        'label' => 'Clinic visits',
                        'data' => $months->map(fn (string $m) => $visitsByMonth[$m] ?? 0)->all(),
                        'borderColor' => self::VISITS_COLOR,
                    ],
                ],
            ],
            'trendMonthCount' => $months->count(),
        ];
    }

    /**
     * BMI Distribution (FR-ANL-12): the month's captured screenings across
     * four rule-based buckets of their STORED BMI (computed server-side at
     * submit). Bucketed in PHP — portable, and the row volumes are small.
     * Descriptive only, no profiling (D-1 stands).
     *
     * @return array{bmiRows: list<array{label: string, count: int, opacity: float}>, bmiMax: int, bmiTotal: int}
     */
    public function bmiDistribution(): array
    {
        $bmis = $this->screeningsInScope()
            ->pluck('vital_signs.bmi')
            ->map(fn ($bmi) => (float) $bmi);

        $buckets = [
            'Underweight (< 18.5)' => fn (float $bmi) => $bmi < 18.5,
            'Normal (18.5–24.9)' => fn (float $bmi) => $bmi >= 18.5 && $bmi < 25,
            'Overweight (25–29.9)' => fn (float $bmi) => $bmi >= 25 && $bmi < 30,
            'Obese (≥ 30)' => fn (float $bmi) => $bmi >= 30,
        ];

        // Single hue, stepping opacity with the ordinal buckets (mockup) —
        // an ordinal ramp, not four categorical colors.
        $opacities = [0.45, 0.65, 0.85, 1.0];

        $rows = [];
        foreach ($buckets as $label => $matches) {
            $rows[] = [
                'label' => $label,
                'count' => $bmis->filter($matches)->count(),
                'opacity' => $opacities[count($rows)],
            ];
        }

        return [
            'bmiRows' => $rows,
            'bmiMax' => (int) collect($rows)->max('count'),
            'bmiTotal' => $bmis->count(),
        ];
    }

    /**
     * Students Screened by Sex (FR-ANL-04 as amended): the month's captured
     * visits — no encoded-only guard any more (FR-ANL-07) — grouped by the
     * student's profile sex, counting once per VISIT (people screened).
     * Obeys every filter (FR-ANL-13).
     *
     * @return array{donut: array, bySex: array, totalScreened: int}
     */
    public function bySexDonut(): array
    {
        $bySex = $this->visitsInScope()
            ->join('student_profiles', 'student_profiles.user_id', '=', 'clinic_visits.student_id')
            ->groupBy('student_profiles.sex')
            ->select('student_profiles.sex', DB::raw('count(*) as visits'))
            ->toBase()
            ->pluck('visits', 'sex');

        $maleCount = (int) ($bySex['M'] ?? 0);
        $femaleCount = (int) ($bySex['F'] ?? 0);
        $totalScreened = $maleCount + $femaleCount;

        // Whole-number legend percentages that always sum to exactly 100:
        // round one slice, the other takes the remainder.
        $malePercent = $totalScreened > 0 ? (int) round($maleCount / $totalScreened * 100) : 0;
        $femalePercent = $totalScreened > 0 ? 100 - $malePercent : 0;

        return [
            // Chart.js payload, shipped on a data attribute.
            'donut' => [
                'labels' => ['Male', 'Female'],
                'datasets' => [[
                    'data' => [$maleCount, $femaleCount],
                    'backgroundColor' => self::SEX_COLORS,
                ]],
            ],
            // Server-rendered legend rows (count + %, FR-ANL-04).
            'bySex' => [
                ['label' => 'Male', 'count' => $maleCount, 'percent' => $malePercent, 'color' => self::SEX_COLORS[0]],
                ['label' => 'Female', 'count' => $femaleCount, 'percent' => $femalePercent, 'color' => self::SEX_COLORS[1]],
            ],
            'totalScreened' => $totalScreened,
        ];
    }

    // ── Row helpers, shared by the two "visits by …" cards ───────────────────

    /**
     * `GROUP BY $column` counts as [value => visits]. $alias is the key the
     * result is plucked under — a qualified column arrives back unqualified.
     *
     * @return Collection<array-key, int>
     */
    private function countBy(Builder $query, string $alias, string $column): Collection
    {
        return $query
            ->groupBy($column)
            ->select($column, DB::raw('count(*) as visits'))
            ->toBase()
            ->pluck('visits', $alias);
    }

    /** One bar row, keyed by whatever names the unit ('code' or 'program'). */
    private function row(string $key, string $label, int $visits): array
    {
        return [$key => $label, 'visits' => $visits];
    }

    /**
     * Visits counted under a value the catalog does not list — a NULL program
     * snapshot, or one retired since. Summed, not listed: the card shows them
     * as a single bucket.
     *
     * @param  Collection<array-key, int>  $counts
     * @param  list<string>  $catalog
     */
    private function unlisted(Collection $counts, array $catalog): int
    {
        // $visits is untyped on purpose: MySQL's PDO driver hands COUNT(*)
        // back as a string where SQLite hands back an int, and strict_types
        // would reject the string.
        return (int) $counts
            ->reject(fn ($visits, $value) => in_array((string) $value, $catalog, strict: true))
            ->sum();
    }

    /**
     * The headline number under a set of bar rows.
     *
     * @param  Collection<int, array>  $rows
     * @return array{totalVisits: int}
     */
    private function totals(Collection $rows): array
    {
        return ['totalVisits' => (int) $rows->sum('visits')];
    }

    /**
     * The Chart.js payload for the horizontal visits bar. Labels are passed in
     * because programs need wrapping and college codes do not.
     *
     * @param  Collection<int, array>  $rows
     */
    private function visitsBar(array $labels, Collection $rows): array
    {
        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Visits', 'data' => $rows->pluck('visits')->all(), 'backgroundColor' => self::VISITS_COLOR],
            ],
        ];
    }

    /**
     * A program name split into short lines. Chart.js renders an ARRAY of
     * strings as a multi-line axis tick, which is the only way a label like
     * "Bachelor of Science in Information Technology" fits beside a bar
     * without swallowing the plot area.
     *
     * @return list<string>
     */
    private function wrapLabel(string $label): array
    {
        return explode("\n", wordwrap($label, self::PROGRAM_LABEL_WRAP, "\n", cut_long_words: true));
    }
}
