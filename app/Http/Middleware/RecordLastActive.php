<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-48: stamps `users.last_active_at` so the Director's Staff Accounts screen
 * can show when an account was last actually used, not just whether it is
 * allowed to log in (FR-AUTH-10).
 *
 * Middleware, for anyone new to Laravel, is a filter every request passes
 * through. This one is appended to the web group, after the two gates that can
 * turn a request away — a request that ends in "you are deactivated" or "change
 * your password first" is not the account being used, so it must not count as
 * activity.
 *
 * WRITE THROTTLING is the whole design problem here. Stamping on every request
 * would add a write to every page load, and the Live Queue alone polls every
 * four seconds. So we only write when the stored value is missing or older than
 * THRESHOLD_SECONDS — at most one write per account per minute, which is far
 * finer than the "has this account been used this week" question the column
 * exists to answer.
 *
 * The write goes through the query builder rather than $user->save() on purpose:
 * it must not bump `updated_at` or fire model events, because merely loading a
 * page is not a change to the user record.
 */
class RecordLastActive
{
    /** Don't write again until the stored stamp is at least this old. */
    private const THRESHOLD_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $this->isStale($user)) {
            $now = now();

            // DB::table, not User::whereKey(...)->update(): an ELOQUENT update
            // silently adds `updated_at` to the SET clause, which would turn the
            // user record's "last edited" stamp into a second "last seen" stamp.
            // The query builder writes exactly the one column named here.
            DB::table($user->getTable())
                ->where($user->getKeyName(), $user->getKey())
                ->update(['last_active_at' => $now]);

            // Keep the in-memory model in step, so anything later in THIS
            // request reads the value we just stored rather than the old one.
            $user->setAttribute('last_active_at', $now)->syncOriginalAttribute('last_active_at');
        }

        return $next($request);
    }

    private function isStale(User $user): bool
    {
        $lastActive = $user->last_active_at;

        // A comparison, not diffInSeconds(): Carbon 3 returns those signed and
        // as floats, so the sign depends on argument order. `lte` cannot be
        // read the wrong way round.
        return $lastActive === null
            || $lastActive->lte(now()->subSeconds(self::THRESHOLD_SECONDS));
    }
}
