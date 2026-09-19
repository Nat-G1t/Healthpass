<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BatchRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * D-57 — the unread badges on the sidebar menu (FR-UI-05).
 *
 * forUser() answers "what number does each menu item show?" for the signed-in
 * user's role. A view composer (AppServiceProvider) hands the result to
 * components/layout/sidebar.blade.php, so no query lives in Blade.
 *
 * Two kinds of badge:
 *  - SINCE LAST SEEN — events after the user last opened that page. Opening it
 *    stamps users.nav_seen_at[<route name>] (App\Http\Middleware\MarkNavSeen);
 *    a page never opened falls back to the account's created_at.
 *  - WORK COUNTS — Live Queue and Batch Approvals show what is waiting right
 *    now. Opening the page does not clear them; doing the work does.
 *
 * Each badge reuses its page's own definition (liveQueue(), flagged(), the
 * Activity Log's event set) so a badge and its page cannot disagree. Every
 * number is built from plain COUNT queries — a badge fed by two tables (Batch
 * Tracking: decisions AND encodes) is two counts added together, because one
 * query across both would need a UNION, which means raw SQL.
 *
 * A Support class rather than a Service: it keeps no state and needs nothing
 * injected, like VisitMonths and TransferNotice. The App\Services classes are
 * the ones built around config and collaborators.
 */
final class NavBadges
{
    /** The nav_seen_at key the Kiosk Tutorial's last step writes (FR-STU-11). */
    public const TUTORIAL_COMPLETED = 'student.tutorial_completed';

    /**
     * The four "since last seen" pages: route name (which is also the
     * nav_seen_at key) => the role whose visit stamps it. (Five until D-61
     * removed the student's My Appointments page.)
     */
    public const SEEN_PAGES = [
        'student.records' => 'student',
        'admin.batches.index' => 'college_admin',
        'admin.activity' => 'college_admin',
        'director.anomalies' => 'director',
    ];

    /** Director decisions — the only statuses that carry a reviewed_at. */
    private const DECIDED = ['approved', 'rejected'];

    /**
     * Badge counts for the user's role, keyed by the menu item's route name.
     * A menu item with no badge is not in the array at all.
     *
     * @return array<string, int>
     */
    public static function forUser(User $user): array
    {
        return match ($user->role) {
            'student' => self::student($user),
            'college_admin' => self::collegeAdmin($user),
            // Work count: the Live Queue's own query. reorder() drops its
            // ORDER BY, which a COUNT has no use for.
            // D-64: the physician shares the nurse's Clinic Dashboard.
            'nurse', 'physician' => ['nurse.queue' => ClinicVisit::liveQueue()->reorder()->count()],
            'director' => self::director($user),
            default => [],
        };
    }

    /** When the user last opened $key's page — or, if never, when the account was created. */
    public static function lastSeen(User $user, string $key): Carbon
    {
        $stamp = $user->nav_seen_at[$key] ?? null;

        return $stamp === null ? $user->created_at : Carbon::parse($stamp);
    }

    public static function hasFinishedTutorial(User $user): bool
    {
        return isset($user->nav_seen_at[self::TUTORIAL_COMPLETED]);
    }

    /**
     * A copy of the user's nav_seen_at with $key stamped now. Nothing is
     * written — pass the result to store().
     *
     * @return array<string, string>
     */
    public static function stamped(User $user, string $key): array
    {
        return [...($user->nav_seen_at ?? []), $key => now()->toDateTimeString()];
    }

    /**
     * Save nav_seen_at with the QUERY BUILDER, not Eloquent (the D-48 lesson):
     * an Eloquent update() would also bump updated_at, and opening a page is
     * not an edit to the account.
     *
     * @param  array<string, string>  $navSeen
     */
    public static function store(User $user, array $navSeen): void
    {
        DB::table($user->getTable())
            ->where($user->getKeyName(), $user->getKey())
            ->update(['nav_seen_at' => json_encode($navSeen, JSON_THROW_ON_ERROR)]);

        // Keep the in-memory model in step with what was stored.
        $user->setAttribute('nav_seen_at', $navSeen)->syncOriginalAttribute('nav_seen_at');
    }

    // ── Per role ─────────────────────────────────────────────────────────────

    /** @return array<string, int> */
    private static function student(User $user): array
    {
        $results = ClearanceRecord::query()
            ->where('encoded_at', '>', self::lastSeen($user, 'student.records'))
            ->whereHas('clinicVisit', fn (Builder $visit) => $visit->where('student_id', $user->id))
            ->count();

        return [
            'student.records' => $results,
            // A dot (a count of 1) until the walkthrough reaches its last step.
            // Once finished it never comes back.
            'student.tutorial' => self::hasFinishedTutorial($user) ? 0 : 1,
        ];
    }

    /** @return array<string, int> */
    private static function collegeAdmin(User $user): array
    {
        // The college comes off the signed-in account, never the request
        // (FR-AUTH-06). An admin without one reaches no /admin page at all, so
        // there is nothing to badge.
        if ($user->managed_college_id === null) {
            return [];
        }

        $collegeId = (int) $user->managed_college_id;

        return [
            'admin.batches.index' => self::batchTrackingCount($user, $collegeId),
            'admin.activity' => self::activityLogCount($user, $collegeId),
        ];
    }

    /** Director decisions on the college's batches, plus results encoded for its batch students. */
    private static function batchTrackingCount(User $user, int $collegeId): int
    {
        $seen = self::lastSeen($user, 'admin.batches.index');

        $decisions = BatchRequest::query()
            ->where('college_id', $collegeId)
            ->whereIn('status', self::DECIDED)
            ->where('reviewed_at', '>', $seen)
            ->count();

        // Scoped through the BATCH's college, not the student's current one:
        // the result belongs to the college that booked the cohort (FR-ADM-12).
        $encodes = ClearanceRecord::query()
            ->where('encoded_at', '>', $seen)
            ->whereHas('clinicVisit.appointment.batchRequest', fn (Builder $batch) => $batch->where('college_id', $collegeId))
            ->count();

        return $decisions + $encodes;
    }

    /**
     * The Activity Log's own event set (Admin\ActivityLogController, D-49):
     * submissions, Director decisions and cancellations — minus the viewer's
     * own submissions and cancellations, which are not news to them.
     */
    private static function activityLogCount(User $user, int $collegeId): int
    {
        $seen = self::lastSeen($user, 'admin.activity');
        $batches = fn (): Builder => BatchRequest::query()->where('college_id', $collegeId);

        $submissions = $batches()
            ->where('created_at', '>', $seen)
            ->where('requested_by', '!=', $user->id)
            ->count();

        $decisions = $batches()
            ->whereIn('status', self::DECIDED)
            ->where('reviewed_at', '>', $seen)
            ->count();

        $cancellations = $batches()
            ->where('status', 'cancelled')
            ->where('cancelled_at', '>', $seen)
            ->where('cancelled_by', '!=', $user->id)
            ->count();

        return $submissions + $decisions + $cancellations;
    }

    /** @return array<string, int> */
    private static function director(User $user): array
    {
        return [
            // Work count: every request still waiting for a decision.
            'director.batches.index' => BatchRequest::query()->where('status', 'pending')->count(),
            // The Flagged Anomalies page's own definition, captured since last seen.
            'director.anomalies' => ClinicVisit::flagged()
                ->where('checked_in_at', '>', self::lastSeen($user, 'director.anomalies'))
                ->count(),
        ];
    }
}
