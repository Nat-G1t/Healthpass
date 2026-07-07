<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * After a successful password change, every OTHER session for the user must
 * be logged out (a stolen session should not survive a password reset).
 *
 * A "trait" is PHP's copy-paste-at-compile-time mechanism: the method below is
 * shared by both password controllers without inventing a base class for it.
 */
trait DeletesOtherSessions
{
    /**
     * Delete the user's session rows, optionally keeping the current one.
     * Production uses SESSION_DRIVER=database, so removing a row logs that
     * browser out on its next request. Other drivers (array in tests) have
     * no queryable session store — nothing to delete there.
     */
    private function deleteOtherSessions(User $user, ?string $keepSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
            ->delete();
    }
}
