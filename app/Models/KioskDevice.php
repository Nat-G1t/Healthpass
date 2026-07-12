<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser/terminal a nurse has enrolled as a trusted kiosk DEVICE
 * (FR-NRS-06, D-27). See the migration for the flagged-schema rationale.
 *
 * SECURITY: we persist only a SHA-256 hash of the device token, never the
 * plaintext. Lookups hash the presented token and match the stored hash —
 * the same shape Sanctum uses for API tokens. The token is high-entropy
 * random (not a password), so a fast hash for O(1) DB lookup is correct here.
 */
class KioskDevice extends Model
{
    /** @use HasFactory<\Database\Factories\KioskDeviceFactory> */
    use HasFactory;

    /**
     * Cookie the enrolled browser carries. It is HttpOnly + SameSite=Lax, and
     * Laravel's default cookie encryption keeps the client from reading or
     * forging it. Kept long-lived so a kiosk terminal stays enrolled across
     * reboots (the incognito Pi launcher uses the query-param path instead).
     */
    public const COOKIE = 'hp_kiosk_device';

    /** ~5 years, in minutes — effectively permanent until the nurse revokes it. */
    public const COOKIE_LIFETIME_MINUTES = 60 * 24 * 365 * 5;

    protected $fillable = [
        'name',
        'token_hash',
        'created_by',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }

    /** The nurse who enrolled this device (may be NULL if that account was removed). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Scope: only un-revoked devices — the ones that may still reach the kiosk. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** SHA-256 hex of a plaintext token — the value stored in `token_hash`. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Resolve a presented plaintext token to its active device row, or null.
     * Used by KioskAccess to gate the /kiosk route group.
     */
    public static function findActiveByToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        return static::query()->active()
            ->where('token_hash', static::hashToken($token))
            ->first();
    }
}
