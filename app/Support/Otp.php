<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;

/**
 * Shared constants + helpers for the app's OTP flows (Decision D-8 pattern:
 * 6-digit code, SHA-256 hash in Cache, short TTL, attempt cap).
 *
 * Used by: registration Step 3, student email change, Change Password (A),
 * and Forgot Password (B). Each flow keeps its own controller-local logic —
 * this class only centralises the numbers so they can never drift apart.
 */
final class Otp
{
    /** How long a code stays valid after it is issued. */
    public const TTL_MINUTES = 10;

    /** Wrong-guess cap per issued code; the entry is discarded when reached. */
    public const MAX_ATTEMPTS = 5;

    /** Resend button cooldown, enforced server-side on every OTP screen (Part C). */
    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Seconds until the Resend button unlocks for a cached OTP entry.
     * Returns 0 when no entry exists (or a pre-cooldown entry lacks the
     * field) — resending is then allowed immediately.
     *
     * @param  array{resend_available_at?:string}|null  $entry
     */
    public static function resendRemainingSeconds(?array $entry): int
    {
        $availableAt = $entry['resend_available_at'] ?? null;

        if ($availableAt === null) {
            return 0;
        }

        return max(0, Carbon::parse($availableAt)->getTimestamp() - now()->getTimestamp());
    }

    /** Generate a cryptographically random, zero-padded 6-digit code. */
    public static function generate(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }
}
