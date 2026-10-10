<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\BatchSeen;
use App\Models\DocumentReleaseRequest;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * D-120 — the notification DOTS on Batch Tracking rows, and the number on the
 * Batch Tracking sidebar badge, for the College Admin and the Director. D-125
 * adds the clinic's dots (nurse and physician) on its Completed batches card.
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
 *     - Batch requests row: the Director REJECTED it, or (D-129) CANCELLED
 *       it (its View opens the reason). An approval has no View button, so
 *       it is no dot — it raises the badge until the admin next opens Batch
 *       Tracking.
 *   Director
 *     - Batch Results row: the status changed, or the college asked for the
 *       batch's documents
 *     - Rejected and Cancelled row: a college cancelled the batch (the
 *       Director's own cancellations, D-129, are no news — like rejections)
 *   Clinic staff (D-125) — per user, so the nurse and the physician each
 *   have their own
 *     - Completed batches row: the Director approved a release request that
 *       asks for PRINTED COPIES since this user last opened the batch (or
 *       they never have). Nothing else makes a clinic dot, and the clinic
 *       has no badge (count() is 0 — O4).
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
     * batch must have BatchResults::statusRelations() loaded, a cancelled one
     * its `canceller` (with `role`) — who cancelled decides whose news it is.
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

    /**
     * D-131 — does Previous / Next of a card's pager carry a dot? Previous
     * when a row on ANY earlier page has one, Next when a row on any later
     * page does.
     *
     * $card is the card's whole list under its current filters, in its own
     * order: its query (read here as ids only — one query), or, when the card
     * was filtered in PHP (the Director's status filter, D-130), the batches
     * themselves. The rows on $page are skipped — their own dots show.
     * $statuses limits which batches can carry a dot on this card, as the
     * controller limits its row dots (the College Admin's Batch requests card
     * dots only rejected and cancelled rows); empty means any.
     *
     * Queries: one for the ids, then dotIds()'s (none when nothing is off the
     * page).
     *
     * @param  Builder<BatchRequest>|Collection<int, BatchRequest>  $card
     * @param  list<string>  $statuses
     * @return array{previous: bool, next: bool}
     */
    public static function pagerDots(User $user, Builder|Collection $card, LengthAwarePaginator $page, array $statuses = []): array
    {
        $ids = $card instanceof Builder
            ? $card->clone()->select($card->qualifyColumn('id'))->pluck('id')
            : $card->pluck('id');

        $start = ($page->currentPage() - 1) * $page->perPage();
        $before = $ids->slice(0, $start);
        $after = $ids->slice($start + $page->perPage());
        $dotted = self::dotIds($user, $before->merge($after)->all(), $statuses);

        return [
            'previous' => $before->intersect($dotted)->isNotEmpty(),
            'next' => $after->intersect($dotted)->isNotEmpty(),
        ];
    }

    /**
     * D-131 — which of $ids carry a dot, from the ids alone: dots() with the
     * loading done here, and an approved batch's status worked out only where
     * the badge would work it out (approvedDotIds()). The same rules, so a
     * pager dot cannot disagree with the rows it points at.
     *
     * Queries: the batches (with their last clinic day), their cancellers,
     * the seen rows, the release times — and, only if some approved batch's
     * status is still unsure, one batch query plus statusRelations()' four.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $statuses  only these statuses can carry a dot; empty means any
     * @return list<int>
     */
    private static function dotIds(User $user, array $ids, array $statuses = []): array
    {
        if ($ids === []) {
            return [];
        }

        $batches = self::withDotColumns(BatchRequest::query()->whereIn('id', $ids))
            ->when($statuses !== [], fn (Builder $query) => $query->whereIn('status', $statuses))
            ->with('canceller:id,role')
            ->get();

        if ($batches->isEmpty()) {
            return [];
        }

        $batchIds = $batches->pluck('id')->all();
        $seen = self::seenRows($user, $batchIds);
        $release = self::releaseTimes($batchIds);

        // The clinic's dot never reads the status, so only the College Admin's
        // and the Director's approved batches take the status path.
        [$approved, $others] = $batches->partition(
            fn (BatchRequest $batch): bool => $batch->status === 'approved' && ! $user->isClinicStaff(),
        );

        $dotted = $others->filter(fn (BatchRequest $batch): bool => self::hasDot(
            $user, $batch, $seen->get($batch->id), $release->get($batch->id), fn (): string => $batch->resultsStatus(),
        ))->pluck('id')->all();

        return [...$dotted, ...self::approvedDotIds($user, $approved, $seen, $release)];
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

        // D-129: a cancellation is news to the college only when the Director made it.
        $directorCancels = $college()
            ->where('status', 'cancelled')
            ->whereHas('canceller', fn (Builder $query) => $query->where('role', 'director'))
            ->whereNotIn('id', self::seenAs($user, 'cancelled'))
            ->count();

        return $approvals + $rejections + $directorCancels + self::approvedDotCount($user, $college()->where('status', 'approved'));
    }

    private static function directorCount(User $user): int
    {
        // D-129: only a COLLEGE's cancellation — the Director's own is no news.
        // whereDoesntHave also keeps a cancellation with no recorded canceller.
        $cancellations = BatchRequest::query()
            ->where('status', 'cancelled')
            ->whereDoesntHave('canceller', fn (Builder $query) => $query->where('role', 'director'))
            ->whereNotIn('id', self::seenAs($user, 'cancelled'))
            ->count();

        return $cancellations + self::approvedDotCount($user, BatchRequest::query()->where('status', 'approved'));
    }

    // ── The rules ────────────────────────────────────────────────────────────

    /**
     * One row's dot. $status works the approved batch's status out only when
     * it is needed — it is the expensive part.
     *
     * @param  array{requested: ?Carbon, decided: ?Carbon, copies_approved: ?Carbon}|null  $release
     * @param  Closure(): string  $status
     */
    private static function hasDot(User $user, BatchRequest $batch, ?BatchSeen $seen, ?array $release, Closure $status): bool
    {
        if ($user->isClinicStaff()) {
            return self::hasPrintRequestNews($batch, $seen, $release);
        }

        return match ($batch->status) {
            'approved' => self::hasReleaseNews($user, $batch, $seen, $release)
                || $status() !== ($seen->seen_status ?? self::APPROVED_STATUS),
            'rejected' => $user->role === 'college_admin' && $seen === null,
            // D-129: whoever did NOT cancel it gets the news.
            'cancelled' => $seen?->seen_status !== 'cancelled' && match ($user->role) {
                'director' => ! $batch->wasCancelledByDirector(),
                'college_admin' => $batch->wasCancelledByDirector(),
                default => false,
            },
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
     * D-125 — the clinic's only news: a printed-copies release approved after
     * this user last opened the batch, or one they have never opened.
     *
     * @param  array{requested: ?Carbon, decided: ?Carbon, copies_approved: ?Carbon}|null  $release
     */
    private static function hasPrintRequestNews(BatchRequest $batch, ?BatchSeen $seen, ?array $release): bool
    {
        $approvedAt = $release['copies_approved'] ?? null;

        return $batch->status === 'approved'
            && $approvedAt !== null
            && ($seen === null || $approvedAt->gt($seen->seen_at));
    }

    /** How many of $approved's batches carry a dot — approvedDotIds() over the whole query. */
    private static function approvedDotCount(User $user, Builder $approved): int
    {
        $batches = self::withDotColumns($approved)->get();

        if ($batches->isEmpty()) {
            return 0;
        }

        $ids = $batches->pluck('id')->all();

        return count(self::approvedDotIds($user, $batches, self::seenRows($user, $ids), self::releaseTimes($ids)));
    }

    /**
     * Which of the approved $batches carry a dot (loaded through
     * withDotColumns()), working resultsStatus() out only where the answer
     * is not already known:
     *
     *   - release news → a dot
     *   - never opened, first hour already started → a dot (it can no longer
     *     read Scheduled, the status it started out "seen" as)
     *   - opened while Completed, or while Finished incomplete / No one
     *     attended AFTER its last clinic day ended → no dot (settled)
     *   - anything else → load its students and compare
     *
     * @param  Collection<int, BatchRequest>  $batches
     * @param  Collection<int, BatchSeen>  $seen  keyed by batch id
     * @param  Collection<int, array{requested: ?Carbon, decided: ?Carbon, copies_approved: ?Carbon}>  $release  keyed by batch id
     * @return list<int>
     */
    private static function approvedDotIds(User $user, Collection $batches, Collection $seen, Collection $release): array
    {
        $dotted = [];
        $unsure = [];

        foreach ($batches as $batch) {
            $row = $seen->get($batch->id);
            $firstHour = $batch->firstHourStart();

            if (self::hasReleaseNews($user, $batch, $row, $release->get($batch->id))) {
                $dotted[] = $batch->id;
            } elseif ($row === null && $firstHour !== null && now()->gte($firstHour)) {
                $dotted[] = $batch->id;
            } elseif ($row === null || ! self::isSettled($row, $batch->last_clinic_date)) {
                $unsure[] = $batch->id;
            }
        }

        if ($unsure === []) {
            return $dotted;
        }

        $moved = BatchRequest::query()
            ->whereIn('id', $unsure)
            ->with(BatchResults::statusRelations())
            ->get()
            ->filter(fn (BatchRequest $batch): bool => $batch->resultsStatus()
                !== ($seen->get($batch->id)->seen_status ?? self::APPROVED_STATUS))
            ->pluck('id')
            ->all();

        return [...$dotted, ...$moved];
    }

    /**
     * The columns the dot rules read, plus each batch's last clinic day (a
     * 2nd chance, D-110, can move it past scheduled_date) for isSettled().
     */
    private static function withDotColumns(Builder $query): Builder
    {
        return $query
            ->select(['id', 'status', 'scheduled_date', 'scheduled_time', 'reviewed_at', 'cancelled_by'])
            ->withMax(['appointments as last_clinic_date' => fn ($query) => $query->where('status', '!=', 'cancelled')], 'scheduled_date');
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

    /** The ids of the batches $user last opened while they were at $status. */
    private static function seenAs(User $user, string $status): Builder
    {
        return BatchSeen::query()
            ->where('user_id', $user->id)
            ->where('seen_status', $status)
            ->select('batch_request_id');
    }

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
     * Each batch's latest release request, latest decision on one, and (D-125)
     * when a printed-copies request was approved — from ONE grouped query.
     * MAX() and CASE WHEN are the same in MySQL and SQLite; the values are
     * bound, and a boolean is stored as 1 in both.
     *
     * @param  list<int>  $batchIds
     * @return Collection<int, array{requested: ?Carbon, decided: ?Carbon, copies_approved: ?Carbon}>  keyed by batch id
     */
    private static function releaseTimes(array $batchIds): Collection
    {
        $time = fn (?string $value): ?Carbon => $value !== null ? Carbon::parse($value) : null;

        return DocumentReleaseRequest::query()
            ->whereIn('batch_request_id', $batchIds)
            ->groupBy('batch_request_id')
            ->select('batch_request_id', DB::raw('MAX(created_at) as requested'), DB::raw('MAX(reviewed_at) as decided'))
            ->selectRaw('MAX(CASE WHEN status = ? AND wants_printed_copies = ? THEN reviewed_at END) as copies_approved', ['approved', 1])
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->batch_request_id => [
                'requested' => $time($row->requested),
                'decided' => $time($row->decided),
                'copies_approved' => $time($row->copies_approved),
            ]]);
    }
}
