<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\KioskDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-NRS-06 "Enable Kiosk Mode" (D-27) — nurse-only enrollment, listing, and
 * revocation of trusted kiosk devices. The device auth itself is exercised in
 * Tests\Feature\Kiosk\KioskAccessTest; here we cover the management screen.
 */
class KioskDeviceTest extends TestCase
{
    use RefreshDatabase;

    private function nurse(): User
    {
        return User::factory()->create(['role' => 'nurse', 'status' => 'active']);
    }

    // ── Access control ───────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('nurse.kiosk-devices'))->assertRedirect(route('login'));
    }

    public function test_non_nurse_cannot_view_the_device_page(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->actingAs($student)
            ->get(route('nurse.kiosk-devices'))
            ->assertRedirect(); // bounced by the role middleware
    }

    public function test_nurse_can_view_the_device_page(): void
    {
        $this->actingAs($this->nurse())
            ->get(route('nurse.kiosk-devices'))
            ->assertOk()
            ->assertSee('Kiosk Devices');
    }

    // ── Enroll ───────────────────────────────────────────────────────────────

    public function test_nurse_can_enroll_this_browser_as_a_device(): void
    {
        $nurse = $this->nurse();

        $response = $this->actingAs($nurse)
            ->post(route('nurse.kiosk-devices.store'), ['name' => 'Clinic Pi']);

        $response->assertRedirect(route('nurse.kiosk-devices'));
        // Sets the device cookie on this browser and flashes the one-time token.
        $response->assertCookie(KioskDevice::COOKIE);
        $response->assertSessionHas('new_device_token');

        $this->assertDatabaseHas('kiosk_devices', [
            'name' => 'Clinic Pi',
            'created_by' => $nurse->id,
            'revoked_at' => null,
        ]);
    }

    public function test_enrollment_stores_only_a_hash_never_the_plaintext_token(): void
    {
        $nurse = $this->nurse();

        $this->actingAs($nurse)
            ->post(route('nurse.kiosk-devices.store'), ['name' => 'Clinic Pi']);

        $token = session('new_device_token');
        $device = KioskDevice::firstOrFail();

        $this->assertNotSame($token, $device->token_hash);            // never the raw token
        $this->assertSame(KioskDevice::hashToken($token), $device->token_hash);
    }

    public function test_enrollment_requires_a_name(): void
    {
        $this->actingAs($this->nurse())
            ->post(route('nurse.kiosk-devices.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('kiosk_devices', 0);
    }

    // ── Revoke ───────────────────────────────────────────────────────────────

    public function test_nurse_can_revoke_a_device(): void
    {
        $device = KioskDevice::factory()->create();

        $this->actingAs($this->nurse())
            ->delete(route('nurse.kiosk-devices.destroy', $device))
            ->assertRedirect(route('nurse.kiosk-devices'));

        $this->assertNotNull($device->fresh()->revoked_at);
    }
}
