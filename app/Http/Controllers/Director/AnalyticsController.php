<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\VitalSigns;
use App\Support\VisitMonths;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Director Analytics, rebuilt after the D-32 rescope (FR-ANL-09..13).
 * Every card reads only data HealthPass itself collects — appointments
 * from the web app, vitals from the kiosk:
 *
 *  - Clinic Visits by College (FR-ANL-09): kiosk check-ins + completed
 *    dental appointments, stacked per college, with a Visits-by-Purpose
 *    breakdown from the linked appointments.
 *  - Vital-Sign Flags (FR-ANL-10): count + rate per flag column.
 *  - Visits per Month trend (FR-ANL-11): whole-year, ignores the filters.
 *  - BMI Distribution (FR-ANL-12): four rule-based buckets.
 *  - Students Screened by Sex (FR-ANL-04 as amended).
 *
 * All counts compute from CAPTURED data (FR-ANL-07 as rewritten): medical
 * visits count at kiosk check-in — no encoded-only guard — and dental
 * counts from completed appointments (D-33). The page is scoped by the
 * ?month=YYYY-MM picker and the ?college=<id> dropdown (FR-ANL-13);
 * only the trend ignores them, by design.
 */
class AnalyticsController extends Controller
{
    /** FR-ANL-09 series colors (pair CVD-validated 2026-07-18). */
    private const MEDICAL_COLOR = '#FF8C2A';

    private const DENTAL_COLOR = '#2563EB';

    /** Donut slice colors (prototype): Male = brand orange, Female = peach. */
    private const SEX_COLORS = ['#FF8C2A', '#FFCAA0'];

    /** Purpose bucket for visits with no linked appointment or no purpose. */
    private const WALK_IN_LABEL = 'Walk-in / not specified';

    public function __invoke(Request $request): View
    {
        // Both filters validate server-side: an unknown month format falls
        // back to the newest month with data (VisitMonths::resolve), an
        // unknown college id falls back to "All colleges".
        $month = VisitMonths::resolve($request->query('month'));
        $college = $this->resolveCollege($request->query('college'));

        return view('director.analytics', [
            'availableMonths' => VisitMonths::available(),
            'selectedMonth' => $month->format('Y-m'),
            'selectedMonthLabel' => $month->format('F Y'),
            'colleges' => College::orderBy('code')->get(['id', 'code']),
            'selectedCollegeId' => $college?->id,
            ...$this->visitsByCollege($month, $college),
            ...$this->visitsByPurpose($month, $college),
            ...$this->vitalSignFlags($month, $college),
            ...$this->visitsTrend(),
            ...$this->bmiDistribution($month, $college),
            ...$this->bySexDonut($month, $college),
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

    /**
     * The [start, end] bounds of $month's calendar days — a parameterized
     * BETWEEN, portable to the SQLite test DB.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function monthBounds(CarbonImmutable $month): array
    {
        return [$month->startOfMonth(), $month->endOfMonth()];
    }

    /**
     * Medical visits in scope: ALL kiosk check-ins of the month — captured
     * and encoded alike (FR-ANL-07) — optionally narrowed to one college
     * via the capture-time snapshot (FR-STU-09).
     */
    private function medicalVisitsInScope(CarbonImmutable $month, ?College $college): Builder
    {
        return ClinicVisit::query()
            ->whereBetween('clinic_visits.checked_in_at', $this->monthBounds($month))
            ->when($college, fn ($query) => $query->where('clinic_visits.college_id', $college->id));
    }

    /**
     * Dental visits in scope: COMPLETED dental appointments of the month
     * (D-33), attributed to the student's CURRENT college — dental has no
     * capture-time snapshot (stated limitation, FR-ANL-09).
     */
    private function dentalVisitsInScope(CarbonImmutable $month, ?College $college): Builder
    {
        [$start, $end] = $this->monthBounds($month);

        return Appointment::query()
            ->where('service_type', 'dental')
            ->where('status', 'completed')
            ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
            ->join('student_profiles', 'student_profiles.user_id', '=', 'appointments.student_id')
            ->when($college, fn ($query) => $query->where('student_profiles.college_id', $college->id));
    }

    /**
     * Clinic Visits by College (FR-ANL-09): one row per college — all 12
     * with zero rows included, or just the filtered one — sorted by total
     * descending (stable tie-break by code), split Medical / Dental.
     *
     * @return array{collegeRows: list<array{code: string, medical: int, dental: int, total: int}>, totalVisits: int, totalMedical: int, totalDental: int, collegeBar: array}
     */
    private function visitsByCollege(CarbonImmutable $month, ?College $college): array
    {
        $medical = $this->medicalVisitsInScope($month, $college)
            ->groupBy('college_id')
            ->select('college_id', DB::raw('count(*) as visits'))
            ->toBase()
            ->pluck('visits', 'college_id');

        $dental = $this->dentalVisitsInScope($month, $college)
            ->groupBy('student_profiles.college_id')
            ->select('student_profiles.college_id', DB::raw('count(*) as visits'))
            ->toBase()
            ->pluck('visits', 'college_id');

        $rows = College::orderBy('code')
            ->when($college, fn ($query) => $query->whereKey($college->id))
            ->get(['id', 'code'])
            ->map(fn (College $unit) => [
                'code' => $unit->code,
                'medical' => (int) ($medical[$unit->id] ?? 0),
                'dental' => (int) ($dental[$unit->id] ?? 0),
                'total' => (int) ($medical[$unit->id] ?? 0) + (int) ($dental[$unit->id] ?? 0),
            ])
            // sortBy is stable, and the rows arrive code-ascending — so
            // equal totals keep their alphabetical order (the tie-break).
            ->sortByDesc('total')
            ->values();

        return [
            'collegeRows' => $rows->all(),
            'totalVisits' => $rows->sum('total'),
            'totalMedical' => $rows->sum('medical'),
            'totalDental' => $rows->sum('dental'),
            // Chart.js payload for the stacked horizontal bar.
            'collegeBar' => [
                'labels' => $rows->pluck('code')->all(),
                'datasets' => [
                    ['label' => 'Medical', 'data' => $rows->pluck('medical')->all(), 'backgroundColor' => self::MEDICAL_COLOR],
                    ['label' => 'Dental', 'data' => $rows->pluck('dental')->all(), 'backgroundColor' => self::DENTAL_COLOR],
                ],
            ],
        ];
    }

    /**
     * Visits by Purpose (inside the FR-ANL-09 card): the month's medical
     * visits bucketed by their linked appointment's purpose. A visit with
     * no linked appointment (walk-in, BR-10) or an appointment without a
     * purpose falls into the "Walk-in / not specified" bucket — the LEFT
     * JOIN yields NULL for both cases.
     *
     * @return array{purposeRows: list<array{label: string, count: int}>, purposeMax: int}
     */
    private function visitsByPurpose(CarbonImmutable $month, ?College $college): array
    {
        $counts = $this->medicalVisitsInScope($month, $college)
            ->leftJoin('appointments', 'appointments.id', '=', 'clinic_visits.appointment_id')
            ->groupBy('appointments.purpose')
            ->select('appointments.purpose', DB::raw('count(*) as visits'))
            ->toBase()
            ->get();

        $rows = $counts
            ->map(fn (object $row) => [
                'label' => $row->purpose ?? self::WALK_IN_LABEL,
                'count' => (int) $row->visits,
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
    private function vitalSignFlags(CarbonImmutable $month, ?College $college): array
    {
        $screeningsInScope = fn () => VitalSigns::query()
            ->join('clinic_visits', 'clinic_visits.id', '=', 'vital_signs.clinic_visit_id')
            ->whereBetween('clinic_visits.checked_in_at', $this->monthBounds($month))
            ->when($college, fn ($query) => $query->where('clinic_visits.college_id', $college->id));

        $screenings = $screeningsInScope()->count();
        $thresholds = config('healthpass.thresholds');

        $rate = fn (int $count): float => $screenings > 0
            ? round($count / $screenings * 100, 1)
            : 0.0;

        $tile = function (string $label, string $column, string $sub) use ($screeningsInScope, $rate): array {
            $count = $screeningsInScope()->where($column, true)->count();

            return ['label' => $label, 'count' => $count, 'rate' => $rate($count), 'sub' => $sub];
        };

        return [
            'screenings' => $screenings,
            'flagTiles' => [
                $tile('High Blood Pressure', 'is_bp_flagged', "≥ {$thresholds['bp_systolic']}/{$thresholds['bp_diastolic']} · locked threshold"),
                $tile('Fever', 'is_temp_flagged', "> {$thresholds['temperature_max']} °C · per PRD business rule"),
                $tile('Abnormal BMI', 'is_bmi_flagged', "BMI ≥ {$thresholds['bmi_obese']} · flagged at capture"),
            ],
        ];
    }

    /**
     * Visits per Month trend (FR-ANL-11): medical screenings and completed
     * dental appointments per month, across ALL months with data. Ignores
     * both page filters by design — the whole-year, all-college view.
     * Months are derived in PHP from Carbon-cast dates, so no MySQL-only
     * date functions reach the SQLite test DB.
     *
     * @return array{trend: array, trendMonthCount: int}
     */
    private function visitsTrend(): array
    {
        $medicalByMonth = ClinicVisit::query()
            ->whereNotNull('checked_in_at')
            ->pluck('checked_in_at')
            ->countBy(fn ($date) => $date->format('Y-m'));

        $dentalByMonth = Appointment::query()
            ->where('service_type', 'dental')
            ->where('status', 'completed')
            ->pluck('scheduled_date')
            ->countBy(fn ($date) => $date->format('Y-m'));

        $months = $medicalByMonth->keys()
            ->merge($dentalByMonth->keys())
            ->unique()
            ->sort()
            ->values();

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
                        'label' => 'Medical screenings',
                        'data' => $months->map(fn (string $m) => $medicalByMonth[$m] ?? 0)->all(),
                        'borderColor' => self::MEDICAL_COLOR,
                    ],
                    [
                        'label' => 'Completed dental',
                        'data' => $months->map(fn (string $m) => $dentalByMonth[$m] ?? 0)->all(),
                        'borderColor' => self::DENTAL_COLOR,
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
    private function bmiDistribution(CarbonImmutable $month, ?College $college): array
    {
        $bmis = VitalSigns::query()
            ->join('clinic_visits', 'clinic_visits.id', '=', 'vital_signs.clinic_visit_id')
            ->whereBetween('clinic_visits.checked_in_at', $this->monthBounds($month))
            ->when($college, fn ($query) => $query->where('clinic_visits.college_id', $college->id))
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
     * Obeys both filters (FR-ANL-13).
     *
     * @return array{donut: array, bySex: array, totalScreened: int}
     */
    private function bySexDonut(CarbonImmutable $month, ?College $college): array
    {
        $bySex = $this->medicalVisitsInScope($month, $college)
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
}
