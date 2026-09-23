<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use Illuminate\Support\Collection;

/**
 * D-54 / D-77 / BR-25 — does a student already have a clinic schedule that day?
 *
 * D-54 began with a double-booking Nat found — a self-booking and a batch in
 * the same hour for one student. Self-booking is gone (D-61); what remains is
 * one student on two batches on the same day. This class is the ONE
 * definition of a clash, read by the batch submission and the Director's
 * approval, so the two can never disagree. (Like ClinicScheduleService, it is
 * a plain *service class*: Laravel builds it and hands it to any controller or
 * Form Request that type-hints it.)
 *
 * The rule:
 *   1. A clash is the same DATE (D-77): a student may hold only one clinic
 *      schedule per day, so a 7–8 AM batch clashes with a 2–3 PM batch the
 *      same student is on that day, whatever the form (Medical Clearance or
 *      Medical Assessment Form). D-54 made it an hour overlap; D-77 widened it
 *      to the whole day.
 *   2. A batch holds its date, for every student on it, from the moment it is
 *      submitted: status `pending` or `approved`. Rejected and cancelled
 *      batches hold nothing, and neither does a batch a student was WITHDRAWN
 *      from (FR-ADM-07 — their appointment on it is cancelled, the pivot row
 *      is kept).
 *   3. One kind of clash: a batch vs another pending/approved batch the student
 *      is on, same date — hours don't matter, so a pre-D-37 batch with no hour
 *      span clashes too. (D-54 had a second kind, a batch vs the student's own
 *      self-booking; D-61 removed self-booking, so it went.)
 *
 * The query is plain Eloquent — no raw SQL, so the SQLite test suite runs the
 * same queries MySQL does.
 *
 * $lock: pass true ONLY from inside a DB::transaction(). The rows are then
 * re-read with lockForUpdate(), so two batch writes racing for the same
 * student cannot both get through — the same reasoning as the capacity
 * re-checks in ClinicScheduleService.
 */
class ScheduleClashService
{
    /** A batch in one of these states holds its date (decision 2). */
    private const HOLDING_STATUSES = ['pending', 'approved'];

    public function __construct(private readonly ClinicScheduleService $schedule) {}

    /**
     * Which of these students already have a clinic schedule on $date, and
     * with what — one entry per clashing student.
     *
     * $exceptBatchId leaves one batch out: a Director approving a pending batch
     * must not count that batch against its own students.
     *
     * @param  list<int>  $studentUserIds  USER ids (what batch_request_students stores)
     * @return array<int, list<string>> user id => e.g.
     *                                  ['Already scheduled for a Medical Clearance that day, on BR-2026-004 (9:00 AM – 11:00 AM)']
     */
    public function clashesForBatch(
        array $studentUserIds,
        string $date,
        ?int $exceptBatchId = null,
        bool $lock = false,
    ): array {
        if ($studentUserIds === []) {
            return [];
        }

        $clashes = [];

        foreach ($this->batchPlaces($studentUserIds, $date, $exceptBatchId, $lock) as $place) {
            $clashes[$place->student_id][] = $this->describe($place->batchRequest);
        }

        return $clashes;
    }

    /**
     * "Already scheduled for a Medical Clearance that day, on BR-2026-004
     * (9:00 AM – 11:00 AM)" — the hours are left off for a pre-D-37 batch,
     * which has none.
     */
    private function describe(BatchRequest $batch): string
    {
        $form = BatchRequest::FORM_TYPES[$batch->form_type];
        $span = $batch->requestedSpan();
        $hours = $span === [] ? '' : ' ('.$this->schedule->rangeLabel($span).')';

        return "Already scheduled for a {$form} that day, on {$batch->reference_no}{$hours}";
    }

    /**
     * These students' places on the batches that currently hold them on $date:
     * one batch_request_students row per student per batch, batch loaded.
     *
     * whereHas() keeps only the rows whose RELATED batch matches the condition
     * (Laravel writes it as an EXISTS sub-query, which runs the same on SQLite
     * and MySQL); with() then loads every row's batch in one extra query rather
     * than one query per row.
     *
     * @param  list<int>  $studentUserIds
     * @return Collection<int, BatchRequestStudent>
     */
    private function batchPlaces(array $studentUserIds, string $date, ?int $exceptBatchId, bool $lock): Collection
    {
        return BatchRequestStudent::query()
            ->whereIn('student_id', $studentUserIds)
            ->whereHas('batchRequest', fn ($batch) => $batch
                ->whereIn('status', self::HOLDING_STATUSES)
                // Approval copies requested_date into scheduled_date unchanged
                // (D-36), so the two agree for every batch that can be approved.
                ->whereDate('requested_date', $date)
                ->when($exceptBatchId !== null, fn ($batch) => $batch->whereKeyNot($exceptBatchId)))
            ->with(['batchRequest', 'appointment:id,status'])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('id')
            ->get()
            // Withdrawn from an approved batch (FR-ADM-07): the row is kept for
            // the batch's history, but the appointment it points at is cancelled.
            ->reject(fn (BatchRequestStudent $place): bool => $place->appointment?->status === 'cancelled')
            ->values();
    }
}
