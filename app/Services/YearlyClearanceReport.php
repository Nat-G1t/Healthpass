<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ClinicVisit;
use App\Models\College;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The Yearly Clearance Report's figures (FR-ADM-13, D-81): every clearance
 * ONE college issued in ONE calendar year, as table rows plus five counts.
 *
 * It is a class of its own, not code in the controller, so the tests can
 * check the numbers directly instead of reading them back out of a PDF.
 *
 * What counts is an ENCODED visit — one with a Fit/Unfit — of either form
 * (Medical Clearance and Medical Assessment). `captured` visits are still
 * waiting for the clinic and `resting` ones (D-72) never reached it, so both
 * are left out and Fit + Unfit always equals the total. A student encoded
 * twice in the year is two clearances: two rows, counted twice.
 *
 * A visit counts only when it came from a batch THIS college submitted and
 * was captured under this college (see the privacy note on the query).
 *
 * Like ClinicAnalytics, THE SCOPE IS FIXED AT CONSTRUCTION: the caller passes
 * managedCollege(), and there is no other way to name a college.
 */
final class YearlyClearanceReport
{
    /** Program cell for a visit captured before D-43 recorded one. */
    public const NO_PROGRAM = 'Not specified';

    /**
     * The table rows, oldest visit first.
     *
     * @var list<array{name: string, result: string, sex: string, checkedInAt: CarbonInterface, program: string}>
     */
    private readonly array $rows;

    public function __construct(College $college, int $year)
    {
        // A calendar year in the app timezone (Asia/Manila). A range rather
        // than whereYear(): YEAR() is MySQL-only SQL the SQLite test suite
        // would not run the same way, and a range can use the index on
        // checked_in_at. `<` next Jan 1 (not `<=` Dec 31 23:59:59) leaves no
        // gap for fractional seconds.
        $start = CarbonImmutable::create($year)->startOfYear();

        $this->rows = ClinicVisit::query()
            // Inner join: only visits that HAVE a Fit/Unfit row. `encoded` is
            // one of ClinicVisit::SUBMITTED_STATUSES, so resting visits are
            // excluded exactly as scopeSubmitted() would.
            ->join('clearance_records', 'clearance_records.clinic_visit_id', '=', 'clinic_visits.id')
            // Every student account gets its profile at registration, so an
            // inner join drops no visit.
            ->join('student_profiles', 'student_profiles.user_id', '=', 'clinic_visits.student_id')
            // PRIVACY (§6.6, D-81): a College Admin may see a student's
            // Fit/Unfit only for a visit that one of THEIR OWN college's
            // batches generated — the same outcome Batch Results (FR-ADM-12)
            // already shows them. So the visit must come from a batch of this
            // college, which leaves out a pre-D-61 self-booked visit (no
            // batch) and a student who changed college between their batch
            // and their visit (another college's batch).
            ->join('appointments', 'appointments.id', '=', 'clinic_visits.appointment_id')
            ->join('batch_requests', 'batch_requests.id', '=', 'appointments.batch_request_id')
            ->where('batch_requests.college_id', $college->id)
            // ...AND the capture-time college snapshot, as every analytics
            // count uses.
            ->where('clinic_visits.college_id', $college->id)
            ->where('clinic_visits.status', 'encoded')
            ->where('clinic_visits.checked_in_at', '>=', $start)
            ->where('clinic_visits.checked_in_at', '<', $start->addYear())
            ->orderBy('clinic_visits.checked_in_at')
            ->orderBy('student_profiles.last_name')
            ->orderBy('student_profiles.first_name')
            ->orderBy('clinic_visits.id')
            ->get([
                'clinic_visits.checked_in_at',
                'clinic_visits.course',
                'clearance_records.result',
                'student_profiles.first_name',
                'student_profiles.middle_name',
                'student_profiles.last_name',
                'student_profiles.sex',
            ])
            ->map(fn (ClinicVisit $visit): array => [
                // "Last, First M." — the same shape as the batch roster picker.
                'name' => $visit->last_name.', '.$visit->first_name
                    .($visit->middle_name ? ' '.mb_substr($visit->middle_name, 0, 1).'.' : ''),
                'result' => $visit->result,
                'sex' => $visit->sex,
                'checkedInAt' => $visit->checked_in_at,
                // The program recorded AT the visit (D-43), not the student's
                // current one — the same value Visits by Program counts.
                'program' => $visit->course ?? self::NO_PROGRAM,
            ])
            ->all();
    }

    /**
     * @return list<array{name: string, result: string, sex: string, checkedInAt: CarbonInterface, program: string}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * The five headline counts, taken from the SAME rows the table prints, so
     * the numbers and the table can never disagree.
     *
     * @return array{total: int, fit: int, unfit: int, male: int, female: int}
     */
    public function summary(): array
    {
        $rows = collect($this->rows);

        return [
            'total' => $rows->count(),
            'fit' => $rows->where('result', 'Fit')->count(),
            'unfit' => $rows->where('result', 'Unfit')->count(),
            'male' => $rows->where('sex', 'M')->count(),
            'female' => $rows->where('sex', 'F')->count(),
        ];
    }
}
