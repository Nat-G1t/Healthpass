<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BatchRequest;
use Illuminate\Support\Collection;

/**
 * D-90 — pending batch requests that cannot both fit in the clinic.
 *
 * A pending batch holds no clinic seats: only the Director's approval creates
 * appointments. So two colleges can ask for the same hours on the same day and
 * both wait on the Approvals page. Whichever is approved first takes the seats,
 * and the other is then refused by the hourly cap (D-37, D-91) and can only be
 * rejected and resubmitted.
 *
 * Two pending batches CONFLICT when each fits on its own but together they
 * would put more than `hourly_capacity` students into some hour. Two small
 * batches sharing an hour (5 + 5 students) do not conflict — the clinic can
 * take both. First come wins, as D-54 settled for students: the batch
 * submitted EARLIER is the one the Director is advised to approve.
 *
 * Advice only. Nothing here blocks an approval; approve() re-checks the seats
 * under a lock and is the only gate.
 */
class BatchConflictService
{
    public function __construct(private readonly ClinicScheduleService $schedule) {}

    /**
     * The other pending batches that conflict with $batch, earliest submitted
     * first. Empty when $batch cannot be approved at all, or does not fit on
     * its own — the capacity block already speaks for it then.
     *
     * @return Collection<int, BatchRequest>
     */
    public function conflictsWith(BatchRequest $batch): Collection
    {
        if (! $batch->isApprovable()) {
            return collect();
        }

        $date = $batch->requested_date->toDateString();
        $booked = $this->schedule->bookedBySlot($date);
        $mine = $this->seats($batch);

        if (! $this->fits($booked, $mine)) {
            return collect();
        }

        return BatchRequest::query()
            ->with('college:id,code')
            ->withCount('batchRequestStudents')
            ->where('status', 'pending')
            ->whereDate('requested_date', $date)
            ->whereKeyNot($batch->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(function (BatchRequest $other) use ($booked, $mine): bool {
                if (! $other->isApprovable()) {
                    return false;
                }

                $theirs = $this->seats($other);

                return $this->fits($booked, $theirs) && ! $this->fits($booked, $mine, $theirs);
            })
            ->values();
    }

    /**
     * The start hours on $batch's date where it would still fit once every
     * batch in $ahead has been approved — the times the college can resubmit
     * for. Hours that have ended (BR-23) and spans that would run past closing
     * are left out.
     *
     * @param  Collection<int, BatchRequest>  $ahead
     * @return list<string> slot keys
     */
    public function freeStartsAfter(BatchRequest $batch, Collection $ahead): array
    {
        $date = $batch->requested_date->toDateString();
        $booked = $this->schedule->bookedBySlot($date);
        $students = $this->studentCount($batch);
        $taken = $ahead->map(fn (BatchRequest $other): array => $this->seats($other))->all();

        return array_values(array_filter(
            $this->schedule->slots(),
            function (string $start) use ($date, $booked, $students, $taken): bool {
                $span = $this->schedule->spanForStudents($start, $students);

                return $span !== []
                    && $this->schedule->elapsedSlotsIn($date, $span) === []
                    && $this->fits($booked, $this->schedule->seatsBySlot($span, $students), ...$taken);
            },
        ));
    }

    /** @return array<string, int> slot => seats the batch takes there */
    private function seats(BatchRequest $batch): array
    {
        return $this->schedule->seatsBySlot($batch->requestedSpan(), $this->studentCount($batch));
    }

    /**
     * withCount() when the caller loaded it, a query otherwise. Cast because
     * MySQL's PDO driver returns the count as a string.
     */
    private function studentCount(BatchRequest $batch): int
    {
        return (int) ($batch->batch_request_students_count ?? $batch->batchRequestStudents()->count());
    }

    /**
     * Would these batches, added to what is already booked, stay within the
     * hourly cap in every hour they use? Only the hours they use are checked —
     * the same rule as ClinicScheduleService::fullSlotsIn().
     *
     * @param  array<string, int>  $booked
     * @param  array<string, int>  ...$seatMaps
     */
    private function fits(array $booked, array ...$seatMaps): bool
    {
        $adding = [];

        foreach ($seatMaps as $seats) {
            foreach ($seats as $slot => $count) {
                $adding[$slot] = ($adding[$slot] ?? 0) + $count;
            }
        }

        $capacity = $this->schedule->hourlyCapacity();

        foreach ($adding as $slot => $count) {
            if ($count > 0 && ($booked[$slot] ?? 0) + $count > $capacity) {
                return false;
            }
        }

        return true;
    }
}
