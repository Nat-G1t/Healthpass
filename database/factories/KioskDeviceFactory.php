<?php

namespace Database\Factories;

use App\Models\KioskDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KioskDevice>
 *
 * Enrolls a kiosk device (D-27). The plaintext token is not persisted, so tests
 * that need it call ->withToken('…') to pin a known value and set its hash.
 */
class KioskDeviceFactory extends Factory
{
    protected $model = KioskDevice::class;

    public function definition(): array
    {
        return [
            'name' => 'Clinic Terminal '.fake()->numberBetween(1, 99),
            'token_hash' => KioskDevice::hashToken(Str::random(64)),
            'created_by' => null,
            'revoked_at' => null,
        ];
    }

    /** Pin a known plaintext token (stored as its hash) so a test can present it. */
    public function withToken(string $token): static
    {
        return $this->state(fn () => ['token_hash' => KioskDevice::hashToken($token)]);
    }

    /** An already-revoked device — its token must be rejected. */
    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
