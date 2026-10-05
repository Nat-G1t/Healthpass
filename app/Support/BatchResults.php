<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;

/**
 * D-111 — one approved batch's results page, as the plain data its partials
 * (resources/views/batches/results/) render: the batch status, the three
 * summary-card numbers and one row per student.
 *
 * The College Admin's page builds it today; the Director's (D-112) and the
 * clinic's (D-114) pages show the same body, so they call this too and every
 * role sees the same numbers.
 *
 * OUTCOME ONLY (PRD §6.6): a row is the student's name, student number,
 * status key and Fit / Unfit — nothing else. No vital sign, screening answer,
 * clinic note, physician detail or clinic-visit reference goes into it, and
 * the eager-loads below never even select them.
 *
 * D-113: an ENCODED row also carries its visit's database id (`visit_id`,
 * null on every other row) — the handle the document download's checkboxes
 * post. It is an id, not clinical data: the server still decides, by
 * BatchDocumentZip's rule, whether that visit may be downloaded.
 */
final class BatchResults
{
    /**
     * Everything BatchRequest::resultsStatus() and resultsSummary() read —
     * for a list that shows only each batch's status (the Batch Results card).
     * Eager-loaded, so the page costs the same handful of queries whether a
     * batch has 4 students or 200.
     *
     *   - D-87: students withdrawn under the removed per-student withdrawal
     *     are left out (scopeNotWithdrawn()).
     *   - The appointment's own columns: `status` / `scheduled_date` for the
     *     cutoff, `scheduled_time` for the clinic day's first hour, and
     *     `rescheduled_from_id` to tell a 2nd chance (D-110).
     *   - The visit's `status` tells a resting student (Rest / Retake) from a
     *     captured one (At the clinic); the clearance record gives only
     *     `result`.
     *
     * @return array<int|string, mixed>
     */
    public static function statusRelations(): array
    {
        return [
            'batchRequestStudents' => fn ($query) => $query->notWithdrawn(),
            'batchRequestStudents.appointment:id,status,scheduled_date,scheduled_time,rescheduled_from_id',
            'batchRequestStudents.appointment.clinicVisit:id,appointment_id,status',
            'batchRequestStudents.appointment.clinicVisit.clearanceRecord:id,clinic_visit_id,result',
        ];
    }

    /**
     * statusRelations() plus what each row shows: the student's name and
     * student number, and the profile's last and first name to sort by.
     *
     * @return array<int|string, mixed>
     */
    public static function pageRelations(): array
    {
        return [
            ...self::statusRelations(),
            'batchRequestStudents.student:id,name',
            'batchRequestStudents.student.studentProfile:id,user_id,student_number,first_name,last_name',
        ];
    }

    /**
     * The page payload for a batch loaded with pageRelations(). Rows are in a
     * stable order — last name, first name, then the roster row's id — and
     * hold the same students resultsSummary() counts (one with no appointment
     * is held by neither). The views own every word: they turn `status` into
     * a label and a badge.
     *
     * @return array{
     *     status: string,
     *     summary: array{total: int, completed: int, fit: int, rescheduled: int},
     *     rows: list<array{name: string, number: string, status: string, result: ?string, visit_id: ?int}>,
     * }
     */
    public static function page(BatchRequest $batch): array
    {
        $rows = $batch->batchRequestStudents
            ->filter(fn (BatchRequestStudent $row): bool => $row->appointment !== null)
            ->sort(fn (BatchRequestStudent $a, BatchRequestStudent $b): int => self::sortKey($a) <=> self::sortKey($b))
            ->map(fn (BatchRequestStudent $row): array => [
                'name' => $row->student?->name ?? 'Unknown student',
                'number' => $row->student?->studentProfile?->student_number ?? '—',
                'status' => $row->appointment->clearanceProgress(),
                'result' => $row->appointment->clearanceResult(),
                'visit_id' => self::encodedVisitId($row),
            ])
            ->values()
            ->all();

        return [
            'status' => $batch->resultsStatus(),
            'summary' => $batch->resultsSummary(),
            'rows' => $rows,
        ];
    }

    /**
     * D-113: the visit's id when the clinic has encoded it (the same two facts
     * BatchDocumentZip::downloadableVisits() checks), otherwise null.
     */
    private static function encodedVisitId(BatchRequestStudent $row): ?int
    {
        $visit = $row->appointment->clinicVisit;

        return $visit?->status === 'encoded' && $visit->clearanceRecord !== null ? $visit->id : null;
    }

    /**
     * [last name, first name, roster id], lower-cased so "de la Cruz" sorts
     * with "De Leon".
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private static function sortKey(BatchRequestStudent $row): array
    {
        $profile = $row->student?->studentProfile;

        return [
            mb_strtolower((string) $profile?->last_name),
            mb_strtolower((string) $profile?->first_name),
            $row->id,
        ];
    }
}
