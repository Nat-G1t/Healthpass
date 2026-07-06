<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security audit fix — the /kiosk route group is NETWORK-restricted by the
 * `kiosk.access` middleware. The page stays auth-less for the person AT the
 * terminal, but a request may only REACH /kiosk when it comes from loopback
 * (the Pi's own Chromium) or from an authenticated active nurse. Everyone else
 * gets 403 — so /kiosk/scan can't be used from the LAN/internet as a PII oracle
 * against guessable QR tokens.
 */
class KioskAccessTest extends TestCase
{
    use RefreshDatabase;

    private const LAN_IP = '192.168.1.50';

    /** A student with a known qr_token, so a loopback scan returns a real 200. */
    private function scannableStudent(string $token): User
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $profile = StudentProfile::factory()->forCollege($college)->create(['qr_token' => $token]);

        return $profile->user;
    }

    /** Post to /kiosk/scan from a given source IP (default REMOTE_ADDR is 127.0.0.1). */
    private function scanFrom(string $ip, array $body)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(route('kiosk.scan'), $body);
    }

    // ── Denied: off-terminal, unauthenticated ────────────────────────────────

    /** Anyone on the LAN (or internet) with no session is blocked before the controller. */
    public function test_anonymous_request_from_lan_is_forbidden(): void
    {
        $this->scanFrom(self::LAN_IP, ['token' => 'anything'])
            ->assertStatus(403);
    }

    /** An authenticated STUDENT is still off-terminal staff-wise → blocked from the LAN. */
    public function test_student_from_lan_is_forbidden(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->actingAs($student)
            ->scanFrom(self::LAN_IP, ['token' => 'anything'])
            ->assertStatus(403);
    }

    // ── Allowed: the Pi's loopback, and active nurses ────────────────────────

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

    // ── Escape hatch: restriction can be turned off for LAN dev/testing ──────

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
