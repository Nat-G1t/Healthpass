<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchSeen;
use App\Models\DocumentReleaseRequest;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * D-120 — the notification DOTS on Batch Tracking rows, and the number on the
 * Batch Tracking sidebar badge, for the College Admin and the Director.
 *
 * A dot is a small circle at the end of a row whose batch has news the viewer
 * has not opened yet. It goes away when they click that row's View button,
 * which calls markSeen() with the status the page showed them (table #13,
 * `batch_seen`). What counts as news:
 *
 *   College Admin
 *     - Batch Results row: the batch's status changed (Scheduled → In
 *       progress → …), or the Director approved or rejected its document
 *       release request (D-113)
 *     - Batch requests row: the Director REJECTED it (its View opens the
 *       reason). An approval has no View button, so it is no dot — it raises
 *       the badge until the admin next opens Batch Tracking.
 *   Director
 *     - Batch Results row: the status changed, or the college asked for the
 *       batch's documents
 *     - Rejected and Cancelled row: a college cancelled the batch
 *
 * The badge = the viewer's dots (+ the admin's approvals since they last
 * opened the page — NavBadges' "since last seen" stamp). An approved batch
 * starts out "seen" as Scheduled: its approval is the Director's own act, and
 * the college hears of it through the badge, so only what happens AFTER is a
 * dot.
 *
 * Every dot reads the same rules, so a row and the badge cannot disagree.
 * resultsStatus() needs every student's appointment, so the badge works it out
 * only for batches whose status COULD have moved since they were seen — see
 * approvedDotCount().
 */
final class BatchNotifications
{
    /** An approved batch's status at approval — its "seen" status until first opened. */
    private const APPROVED_STATUS = 'scheduled';

    /**
     * Statuses that, once seen after the batch's last clinic day ended, cannot
     * change again. Completed never can. The other two can only while a 2nd
     * chance (D-110) may still be booked, which moves the last clinic day.
     */
    private const SETTLED = ['completed', 'finished_incomplete', 'no_one_attended'];

    /** Remember what $user was just shown of $batch — the View button's click. */
    public static function markSeen(User $user, BatchRequest $batch, string $status): void
    {
        BatchSeen::updateOrCreate(
            ['user_id' => $user->id, 'batch_request_id' => $batch->id],
            ['seen_status' => $status, 'seen_at' => now()],
        );
    }

    /**
     * Which of the rows on a page carry a dot: batch id => bool. An approved
     * batch must have BatchResults::statusRelations() loaded.
     *
     * @param  iterable<BatchRequest>  $batches
     * @return array<int, bool>
     */
    public static function dots(User $user, iterable $batches): array
    {
        $batches = collect($batches);

        if ($batches->isEmpty()) {
            return [];
        }

        $ids = $batches->pluck('id')->all();
        $seen = self::seenRows($user, $ids);
        $release = self::releaseTimes($ids);

        return $batches->mapWithKeys(fn (BatchRequest $batch): array => [
            $batch->id => self::hasDot($user, $batch, $seen->get($batch->id), $release->get($batch->id), fn (): string => $batch->resultsStatus()),
        ])->all();
    }

    /** The Batch Tracking badge: the admin's dots + approvals since last opened, or the Director's dots. */
    public static function count(User $user): int
    {
        return match ($user->role) {
            'college_admin' => self::collegeAdminCount($user),
            'director' => self::directorCount($user),
            default => 0,
        };
    }

    // ── Per role ─────────────────────────────────────────────────────────────

    private static function collegeAdminCount(User $user): int
    {
        $college = fn (): Builder => BatchRequest::query()->where('college_id', $user->managed_college_id);

        // No View button, so no dot: an approval counts until the page is opened.
        $approvals = $college()
            ->where('status', 'approved')
            ->where('reviewed_at', '>', NavBadges::lastSeen($user, 'admin.batches.index'))
            ->count();

        $rejections = $college()
            ->where('status', 'rejected')
            ->whereNotIn('id', BatchSeen::query()->where('user_id', $user->id)->select('batch_request_id'))
            ->count();

        return $approvals + $rejections + self::approvedDotCount($user, $college()->where('status', 'approved'));
    }

    private static function directorCount(User $user): int
    {
        $cancellations = BatchRequest::query()
            ->where('status', 'cancelled')
            ->whereNotIn('id', BatchSeen::query()
                ->where('user_id', $user->id)
                ->where('seen_status', 'cancelled')
                ->select('batch_request_id'))
            ->count();

        return $cancellations + self::approvedDotCount($user, BatchRequest::query()->where('status', 'approved'));
    }

    // ── The rules ────────────────────────────────────────────────────────────

    /**
     * One row's dot. $status works the approved batch's status out only when
     * it is needed — it is the expensive part.
     *
     * @param  array{requested: ?Carbon, decided: ?Carbon}|null  $release
     * @param  Closure(): string  $status
     */
    private static function hasDot(User $user, BatchRequest $batch, ?BatchSeen $seen, ?array $release, Closure $status): bool
    {
        return match ($batch->status) {
            'approved' => self::hasReleaseNews($user, $batch, $seen, $release)
                || $status() !== ($seen->seen_status ?? self::APPROVED_STATUS),
            'rejected' => $user->role === 'college_admin' && $seen === null,
            'cancelled' => $user->role === 'director' && $seen?->seen_status !== 'cancelled',
            default => false,
        };
    }

    /**
     * Release-request news since the viewer last opened the batch (or, if
     * never, since it was approved): for the Director a new request, for the
     * college the Director's answer to one.
     *
     * @param  array{requested: ?Carbon, decided: ?Carbon}|null  $release
     */
    private static function hasReleaseNews(User $user, BatchRequest $batch, ?BatchSeen $seen, ?array $release): bool
    {
        $news = $user->role === 'director' ? $release['requested'] ?? null : $release['decided'] ?? null;
        $since = $seen?->seen_at ?? $batch->reviewed_at;

        return $news !== null && ($since === null || $news->gt($since));
    }

    /**
     * How many of $approved's batches carry a dot, working resultsStatus() out
     * only where the answer is not already known:
     *
     *   - release news → a dot
     *   - never opened, first hour already started → a dot (it can no longer
     *     read Scheduled, the status it started out "seen" as)
     *   - opened while Completed, or while Finished incomplete / No one
     *     attended AFTER its last clinic day ended → no dot (settled)
     *   - anything else → load its students and compare
     */
    private static function approvedDotCount(User $user, Builder $approved): int
    {
        $batches = $approved
            ->select(['id', 'status', 'scheduled_date', 'scheduled_time', 'reviewed_at'])
            ->withMax(['appointments as last_clinic_date' => fn ($query) => $query->where('status', '!=', 'cancelled')], 'scheduled_date')
            ->get();

        if ($batches->isEmpty()) {
            return 0;
        }

        $ids = $batches->pluck('id')->all();
        $seen = self::seenRows($user, $ids);
        $release = self::releaseTimes($ids);
        $count = 0;
        $unsure = [];

        foreach ($batches as $batch) {
            $row = $seen->get($batch->id);
            $firstHour = $batch->firstHourStart();

            if (self::hasReleaseNews($user, $batch, $row, $release->get($batch->id))) {
                $count++;
            } elseif ($row === null && $firstHour !== null && now()->gte($firstHour)) {
                $count++;
            } elseif ($row === null || ! self::isSettled($row, $batch->last_clinic_date)) {
                $unsure[] = $batch->id;
            }
        }

        if ($unsure !== []) {
            $count += BatchRequest::query()
                ->whereIn('id', $unsure)
                ->with(BatchResults::statusRelations())
                ->get()
                ->filter(fn (BatchRequest $batch): bool => $batch->resultsStatus()
                    !== ($seen->get($batch->id)->seen_status ?? self::APPROVED_STATUS))
                ->count();
        }

        return $count;
    }

    /** Was $row taken at a status the batch can no longer leave? */
    private static function isSettled(BatchSeen $row, ?string $lastClinicDate): bool
    {
        if ($row->seen_status === 'completed') {
            return true;
        }

        return in_array($row->seen_status, self::SETTLED, true)
            && $lastClinicDate !== null
            && $row->seen_at->gte(Appointment::absentCutoffOn(Carbon::parse($lastClinicDate)));
    }

    // ── Loading ──────────────────────────────────────────────────────────────

    /**
     * @param  list<int>  $batchIds
     * @return Collection<int, BatchSeen>  keyed by batch id
     */
    private static function seenRows(User $user, array $batchIds): Collection
    {
        return BatchSeen::query()
            ->where('user_id', $user->id)
            ->whereIn('batch_request_id', $batchIds)
            ->get()
            ->keyBy('batch_request_id');
    }

    /**
     * Each batch's latest release request and latest decision on one, from ONE
     * grouped query (MAX() is the same in MySQL and SQLite).
     *
     * @param  list<int>  $batchIds
     * @return Collection<int, array{requested: ?Carbon, decided: ?Carbon}>  keyed by batch id
     */
    private static function releaseTimes(array $batchIds): Collection
    {
        return DocumentReleaseRequest::query()
            ->whereIn('batch_request_id', $batchIds)
            ->groupBy('batch_request_id')
            ->select('batch_request_id', DB::raw('MAX(created_at) as requested'), DB::raw('MAX(reviewed_at) as decided'))
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->batch_request_id => [
                'requested' => $row->requested !== null ? Carbon::parse($row->requested) : null,
                'decided' => $row->decided !== null ? Carbon::parse($row->decided) : null,
            ]]);
    }
}
