<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopedToManagedCollege;
use App\Http\Controllers\Controller;
use App\Models\BatchRequest;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * College Activity Log (FR-ADM-10, D-49) — who did what for this college.
 *
 * A college may now have MORE THAN ONE College Admin (nothing has ever
 * constrained `users.managed_college_id` to one account, and D-47 gave the
 * Director a screen that makes a second one easy). Two admins working the same
 * college could each submit batches without the other knowing, and the Director's
 * decisions arrived with no trace anywhere on the admin side. This page is that
 * shared record. It is equally useful with one admin, which is why it is not
 * conditional on there being two.
 *
 * DERIVED, NOT LOGGED — the important design decision here. There is no
 * `activity_logs` table and nothing writes an audit row anywhere. Every entry is
 * read back out of `batch_requests`, which already stores `requested_by` +
 * `created_at` for the submission and `reviewed_by` + `reviewed_at` +
 * `rejection_reason` for the decision. Two consequences, both good:
 *
 *  - The log CANNOT DRIFT from the data. It is not a parallel record that a
 *    missed write could leave incomplete — it is the same rows Batch Tracking
 *    renders, read a different way, so the two can never disagree.
 *  - No 11th table (the canon is 10 — D-32 having just brought it back), no
 *    migration, no write path on any existing action.
 *
 * The flattening happens in PHP rather than as a SQL UNION on purpose: a UNION
 * of "submitted" and "decided" rows needs raw SQL, and the suite runs on SQLite
 * while dev and prod are MySQL (CLAUDE.md). A college's batch history is tens of
 * rows, not millions, so there is nothing to gain by risking that drift.
 *
 * SCOPE is the standard /admin rule (FR-AUTH-06 / FR-ADM-06): the college comes
 * from managedCollege() and the request is never consulted for it, so one
 * college can never read another's activity.
 */
class ActivityLogController extends Controller
{
    use ScopedToManagedCollege;

    /** Entries per page — matches the Nurse Dashboard's history. */
    private const PER_PAGE = 15;

    public function __invoke(Request $request): View
    {
        $college = $this->managedCollege();

        // Start from the college's own relationship, never a request value.
        $batches = $college->batchRequests()
            ->with(['requester:id,name', 'reviewer:id,name'])
            ->withCount('batchRequestStudents')
            ->latest('created_at')
            ->get();

        $entries = $this->toEntries($batches)
            // Newest first, tie-broken so a decision still outranks the
            // submission it decided. Two entries can share a timestamp when
            // seeded data is generated in one pass, and without the tie-break
            // the timeline would show the approval above or below its own
            // submission depending on nothing at all.
            ->sortByDesc(fn (array $entry): array => [
                $entry['at']?->getTimestamp() ?? 0,
                $entry['type'] === 'submitted' ? 0 : 1,
            ])
            ->values();

        return view('admin.activity', [
            'college' => $college,
            'entries' => $this->paginate($entries, $request),
        ]);
    }

    /**
     * Flatten each batch row into the events it records: always a submission,
     * plus a decision once the Director has made one.
     *
     * @param  Collection<int, BatchRequest>  $batches
     * @return Collection<int, array<string, mixed>>
     */
    private function toEntries(Collection $batches): Collection
    {
        return $batches->flatMap(function (BatchRequest $batch): array {
            // Cast: withCount() comes back an int on SQLite but a STRING from
            // MySQL's PDO driver, and Str::plural() types its second argument.
            $students = (int) $batch->batch_request_students_count;

            $entries = [[
                'type' => 'submitted',
                'at' => $batch->created_at,
                'actor' => $batch->requester?->name,
                'actorRole' => 'College Admin',
                'batch' => $batch,
                'detail' => $students.' '.Str::plural('student', $students).' · '.$batch->reasonText(),
            ]];

            // A pending batch has no decision yet. reviewed_at is the guard
            // rather than status, because it is the field that carries WHEN —
            // an entry with no timestamp could not be placed on a timeline.
            if ($batch->reviewed_at !== null && in_array($batch->status, ['approved', 'rejected'], true)) {
                $entries[] = [
                    'type' => $batch->status,
                    'at' => $batch->reviewed_at,
                    'actor' => $batch->reviewer?->name,
                    'actorRole' => 'Clinic Director',
                    'batch' => $batch,
                    'detail' => $batch->status === 'rejected'
                        ? $batch->rejection_reason
                        : 'Appointments generated for '.$batch->scheduled_date?->format('M j, Y').'.',
                ];
            }

            return $entries;
        });
    }

    /**
     * Paginate the flattened collection.
     *
     * The entries are built in PHP, so there is no query to paginate — this
     * wraps the finished collection in the same paginator the Blade views
     * already know how to render, and keeps the page in the URL.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginate(Collection $entries, Request $request): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $entries->forPage($page, self::PER_PAGE)->values(),
            $entries->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }
}
