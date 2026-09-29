<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ClinicVisit;
use App\Models\College;
use App\Support\ClearanceCounts;
use Carbon\CarbonImmutable;

/**
 * The Director's Yearly Clearance Report figures for ONE calendar year (D-94):
 * every college ("department") with its five counts — took clearance, Fit,
 * Unfit, Male, Female — and, under it, the same five counts for each of its
 * programs.
 *
 * Unlike the College Admin's report (YearlyClearanceReport) it lists NO
 * students: only counts. And it is clinic-wide — the Director already sees
 * every college (FR-ANL-13), so there is no batch-ownership filter: every
 * ENCODED visit of the year counts, under the college and program recorded AT
 * the visit (the D-17 / D-43 snapshots every analytics count uses).
 *
 * What counts is exactly what the admin report counts: an encoded visit (one
 * with a Fit/Unfit), either form. `captured` and `resting` visits are left
 * out, so Fit + Unfit always equals the total; a student cleared twice counts
 * twice.
 *
 * Every college and every catalog program appears, zeros included (chosen by
 * Nat, 2026-09-29). A visit whose program is not in its college's catalog — a
 * pre-D-43 visit with none recorded, or a program since renamed — still
 * counts, on its own row after the catalog, so a college's program rows always
 * add up to its line.
 */
final class DirectorYearlyReport
{
    /** Program row for a visit captured before D-43 recorded one. */
    public const NO_PROGRAM = 'Not specified';

    /**
     * @var list<array{code: string, name: string, counts: array<string, int>, programs: list<array{program: string, counts: array<string, int>}>}>
     */
    private readonly array $colleges;

    public function __construct(int $year)
    {
        // A calendar year in the app timezone, as a range — YEAR() is MySQL-only
        // SQL the SQLite test suite would not run the same way (see
        // YearlyClearanceReport for the same choice).
        $start = CarbonImmutable::create($year)->startOfYear();

        // One grouped query: how many encoded visits per college, program,
        // result and sex. COUNT(*) and GROUP BY are portable SQL, so MySQL and
        // the SQLite suite agree.
        $groups = ClinicVisit::query()
            ->join('clearance_records', 'clearance_records.clinic_visit_id', '=', 'clinic_visits.id')
            ->join('student_profiles', 'student_profiles.user_id', '=', 'clinic_visits.student_id')
            ->where('clinic_visits.status', 'encoded')
            ->where('clinic_visits.checked_in_at', '>=', $start)
            ->where('clinic_visits.checked_in_at', '<', $start->addYear())
            ->groupBy('clinic_visits.college_id', 'clinic_visits.course', 'clearance_records.result', 'student_profiles.sex')
            ->selectRaw('clinic_visits.college_id, clinic_visits.course, clearance_records.result, student_profiles.sex, COUNT(*) as visits')
            ->toBase()
            ->get();

        $this->colleges = College::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (College $college): array => $this->collegeLine($college, $groups->where('college_id', $college->id)))
            ->all();
    }

    /**
     * Every college, by code, each with its program rows.
     *
     * @return list<array{code: string, name: string, counts: array<string, int>, programs: list<array{program: string, counts: array<string, int>}>}>
     */
    public function colleges(): array
    {
        return $this->colleges;
    }

    /**
     * The whole clinic's five counts for the year — the colleges added up.
     *
     * @return array{total: int, fit: int, unfit: int, male: int, female: int}
     */
    public function summary(): array
    {
        return ClearanceCounts::sum(array_column($this->colleges, 'counts'));
    }

    /**
     * One college's line and its program rows: the catalog in catalog order,
     * then any program found in the visits that the catalog does not list.
     *
     * @param  iterable<object{course: ?string, result: string, sex: ?string, visits: int|string}>  $groups
     * @return array{code: string, name: string, counts: array<string, int>, programs: list<array{program: string, counts: array<string, int>}>}
     */
    private function collegeLine(College $college, iterable $groups): array
    {
        $byProgram = array_fill_keys(config("programs.{$college->code}.programs", []), ClearanceCounts::EMPTY);

        foreach ($groups as $group) {
            $program = $group->course ?? self::NO_PROGRAM;
            $visits = (int) $group->visits;   // COUNT(*) is a string on MySQL's PDO driver

            $byProgram[$program] = ClearanceCounts::add($byProgram[$program] ?? ClearanceCounts::EMPTY, [
                'total' => $visits,
                'fit' => $group->result === 'Fit' ? $visits : 0,
                'unfit' => $group->result === 'Unfit' ? $visits : 0,
                'male' => $group->sex === 'M' ? $visits : 0,
                'female' => $group->sex === 'F' ? $visits : 0,
            ]);
        }

        $programs = [];

        foreach ($byProgram as $program => $counts) {
            $programs[] = ['program' => (string) $program, 'counts' => $counts];
        }

        return [
            'code' => $college->code,
            'name' => $college->name,
            'counts' => ClearanceCounts::sum(array_column($programs, 'counts')),
            'programs' => $programs,
        ];
    }
}
