<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\College;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * D-50 — the one-time "you have been moved to another college" notice a
 * transferred College Admin sees on their dashboard (FR-AUTH-10).
 *
 * WHY THE CACHE AND NOT A COLUMN. The notice has to outlive the request that
 * created it — the admin may not sign in for days — so it cannot be a session
 * flash. But it is a courtesy nudge, not a record: the email sent at the same
 * moment is the durable notification, and this only exists so the change is not
 * a surprise when the admin's students appear to vanish. Losing one to a
 * `cache:clear` costs nothing, which is exactly why it does not deserve a
 * schema change. The cache is database-backed here, so it survives requests and
 * deploys.
 *
 * Read with pull(), which returns the notice AND deletes it in one step, so it
 * shows exactly once.
 */
final class TransferNotice
{
    /**
     * A notice nobody has seen in a month has been overtaken by events, and the
     * email already told them. Not indefinite, so an unused key cannot sit in
     * the cache table forever.
     */
    private const TTL_DAYS = 30;

    /** Record that this admin was moved, for their next dashboard visit. */
    public static function put(User $staff, College $from, College $to): void
    {
        Cache::put(
            self::key($staff),
            [
                // Names, not ids: the notice is display text, and resolving ids
                // later would mean a second lookup for something already known.
                'from' => $from->name,
                'to' => $to->name,
                'toCode' => $to->code,
            ],
            now()->addDays(self::TTL_DAYS),
        );
    }

    /**
     * Return this admin's pending notice and clear it, or null if there is none.
     *
     * @return array{from: string, to: string, toCode: string}|null
     */
    public static function pull(User $staff): ?array
    {
        return Cache::pull(self::key($staff));
    }

    /** Discard a pending notice without showing it. */
    public static function forget(User $staff): void
    {
        Cache::forget(self::key($staff));
    }

    private static function key(User $staff): string
    {
        return 'staff.transfer-notice.'.$staff->getKey();
    }
}
