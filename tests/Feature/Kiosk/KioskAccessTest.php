<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use App\Models\College;
use App\Models\KioskDevice;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security upgrade (D-27) — the /kiosk route group is NETWORK-restricted by the
 * `kiosk.access` middleware. The page stays auth-less for the person AT the
 * terminal, but a request may only REACH /kiosk when it is:
 *   (a) an enrolled kiosk DEVICE (valid, un-revoked token via cookie/query),
 *   (b) an authenticated active nurse, or
 *   (c) loopback AND config allows loopback.
 * Everyone else gets a friendly branded 403 (or a JSON 403 for AJAX) — so
 * /kiosk/scan can't be used from the LAN/internet as a PII oracle.
 */
class KioskAccessTest extends TestCase
{
    use RefreshDatabase;

    private const LAN_IP = '192.168.1.50';

    /** A student with a known qr_token, so a scan returns a real 200. */
    private function scannableStudent(string $token): User
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $profile = StudentProfile::factory()->forCollege($college)->create(['qr_token' => $token]);

        return $profile->user;
    }

    /** Enroll a device and return the plaintext token it recognises. */
    private function enrollDevice(string $token, bool $revoked = false): KioskDevice
    {
        return KioskDevice::factory()->withToken($token)
            ->when($revoked, fn ($f) => $f->revoked())
            ->create();
    }

    /** Post to /kiosk/scan from a given source IP (default REMOTE_ADDR is 127.0.0.1). */
    private function scanFrom(string $ip, array $body)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(route('kiosk.scan'), $body);
    }

    /** GET the kiosk page (browser navigation) from a given source IP. */
    private function visitFrom(string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get(route('kiosk.index'));
    }

    // ── Denied: off-terminal, unauthenticated ────────────────────────────────

    /** Anyone on the LAN (or internet) with no session is blocked before the controller. */
    public function test_anonymous_request_from_lan_is_forbidden(): void
    {
        $this->scanFrom(self::LAN_IP, ['token' => 'anything'])
            ->assertStatus(403);
    }

    /** A guest browsing to the page sees the friendly branded 403, not a bare stub. */
    public function test_guest_from_lan_sees_the_restricted_page(): void
    {
        $this->visitFrom(self::LAN_IP)
            ->assertStatus(403)
            ->assertSee('restricted to clinic staff')
            ->assertSee(route('login'));
    }

    /** An authenticated STUDENT is off-terminal staff-wise → sees the restricted page. */
    public function test_student_from_lan_sees_the_restricted_page(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->actingAs($student)
            ->visitFrom(self::LAN_IP)
            ->assertStatus(403)
            ->assertSee('restricted to clinic staff');
    }

    /** A College Admin is not clinic staff for the kiosk → restricted page. */
    public function test_college_admin_from_lan_sees_the_restricted_page(): void
    {
        $admin = User::factory()->create(['role' => 'college_admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->visitFrom(self::LAN_IP)
            ->assertStatus(403);
    }

    /** The Director is not clinic staff for the kiosk → restricted page. */
    public function test_director_from_lan_sees_the_restricted_page(): void
    {
        $director = User::factory()->create(['role' => 'director', 'status' => 'active']);

        $this->actingAs($director)
            ->visitFrom(self::LAN_IP)
            ->assertStatus(403);
    }

    // ── Allowed: enrolled device, active nurse, loopback ─────────────────────

    /** The Pi's own Chromium (loopback) reaches the controller — a valid token → 200. */
    public function test_loopback_request_is_allowed(): void
    {
        $this->scannableStudent('LOOPBACK-OK');

        // Default test REMOTE_ADDR is 127.0.0.1 (loopback).
        $this->postJson(route('kiosk.scan'), ['token' => 'LOOPBACK-OK'])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    /** An active nurse may open the kiosk from any machine (FR-NRS-06). */
    public function test_active_nurse_from_lan_is_allowed(): void
    {
        $this->scannableStudent('NURSE-OK');
        $nurse = User::factory()->create(['role' => 'nurse', 'status' => 'active']);

        $this->actingAs($nurse)
            ->scanFrom(self::LAN_IP, ['token' => 'NURSE-OK'])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    /** An enrolled device's cookie lets a LAN terminal reach even /kiosk/scan. */
    public function test_enrolled_device_cookie_is_allowed_from_lan(): void
    {
        $this->scannableStudent('DEVICE-OK');
        $this->enrollDevice('device-token-abc');

        // withCredentials(): a JSON request only carries cookies when credentials
        // are enabled (the kiosk's own fetch() sends them same-origin).
        $this->withCredentials()
            ->withCookie(KioskDevice::COOKIE, 'device-token-abc')
            ->scanFrom(self::LAN_IP, ['token' => 'DEVICE-OK'])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    /** A REVOKED device's token no longer opens the kiosk — blocked from the LAN. */
    public function test_revoked_device_cookie_is_blocked(): void
    {
        $this->enrollDevice('revoked-token-xyz', revoked: true);

        $this->withCredentials()
            ->withCookie(KioskDevice::COOKIE, 'revoked-token-xyz')
            ->scanFrom(self::LAN_IP, ['token' => 'anything'])
            ->assertStatus(403);
    }

    /** An unknown/garbage device token is treated as no token → restricted page. */
    public function test_unknown_device_cookie_is_blocked(): void
    {
        $this->withCookie(KioskDevice::COOKIE, 'not-a-real-token')
            ->visitFrom(self::LAN_IP)
            ->assertStatus(403);
    }

    // ── Provisioning: ?device_token=… sets the cookie, then redirects clean ───

    /** A valid ?device_token=… sets the cookie and redirects to the clean URL. */
    public function test_valid_query_token_sets_cookie_and_redirects_clean(): void
    {
        $this->enrollDevice('provision-token-123');

        $response = $this->withServerVariables(['REMOTE_ADDR' => self::LAN_IP])
            ->get(route('kiosk.index').'?device_token=provision-token-123');

        $response->assertRedirect(route('kiosk.index'));   // token stripped from the URL
        $response->assertCookie(KioskDevice::COOKIE);       // cookie now provisioned
    }

    /** An invalid ?device_token=… does NOT authorize — still the restricted page. */
    public function test_invalid_query_token_is_not_authorized(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::LAN_IP])
            ->get(route('kiosk.index').'?device_token=wrong')
            ->assertStatus(403);
    }

    // ── Loopback config switch (B) ───────────────────────────────────────────

    /** With allow_loopback=true (the Pi-local shape), loopback reaches the kiosk. */
    public function test_loopback_allowed_when_allow_loopback_true(): void
    {
        config(['healthpass.kiosk.allow_loopback' => true]);

        $this->visitFrom('127.0.0.1')->assertOk();
    }

    /** With allow_loopback=false (hosted shape), even loopback is blocked. */
    public function test_loopback_blocked_when_allow_loopback_false(): void
    {
        config(['healthpass.kiosk.allow_loopback' => false]);

        $this->visitFrom('127.0.0.1')->assertStatus(403);
    }

    // ── Escape hatch: restriction can be turned off entirely for LAN dev ──────

    /** With restrict_access=false, the network gate is skipped entirely. */
    public function test_lan_request_is_allowed_when_restriction_is_off(): void
    {
        config(['healthpass.kiosk.restrict_access' => false]);
        $this->scannableStudent('DEV-OK');

        $this->scanFrom(self::LAN_IP, ['token' => 'DEV-OK'])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }
}
