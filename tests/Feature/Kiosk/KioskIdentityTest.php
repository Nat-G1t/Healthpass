<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * FR-KSK-01/02/03 — kiosk identity endpoints (QR scan + email login).
 *
 * Both endpoints are PUBLIC (no Laravel auth) and must return the same
 * display-safe identity payload so the front-end can route to Identity
 * Confirm the same way. Email login is STUDENTS ONLY — staff are rejected.
 */
class KioskIdentityTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function college(): College
    {
        return College::create(['code' => 'CCS', 'name' => 'College of Computing Studies']);
    }

    /** A student user + profile with a known password and qr_token. */
    private function student(array $overrides = []): StudentProfile
    {
        $user = User::factory()->create(array_merge([
            'role' => 'student',
            'status' => 'active',
            'email' => 'juan.santos@psu.edu.ph',
            'password' => Hash::make('password'),
        ], $overrides['user'] ?? []));

        return StudentProfile::factory()->forCollege($this->college())->create(array_merge([
            'user_id' => $user->id,
            'first_name' => 'Juan',
            'last_name' => 'Santos',
            'student_number' => '2021060001',
            'qr_token' => 'KIOSK-TEST-TOKEN-123',
        ], $overrides['profile'] ?? []));
    }

    // ── QR scan (FR-KSK-01 → FR-KSK-03) ───────────────────────────────────────

    public function test_valid_qr_token_returns_identity(): void
    {
        $this->student();

        $this->postJson(route('kiosk.scan'), ['token' => 'KIOSK-TEST-TOKEN-123'])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'identity' => [
                    'firstName' => 'Juan',
                    'initials' => 'JS',
                    'studentNumber' => '2021060001',
                    'college' => 'College of Computing Studies',
                    'loginMethod' => 'qr',
                ],
            ]);
    }

    public function test_unknown_qr_token_is_rejected(): void
    {
        $this->student();

        $this->postJson(route('kiosk.scan'), ['token' => 'NOPE'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_multiline_id_payload_resolves_via_the_idno_line(): void
    {
        // The physical ID's QR is a multi-line payload; the token lives on the
        // "IDNo:" line. Extraction must pick that line, not the whole blob.
        $this->student();

        $payload = "Name: Juan Santos\r\nIDNo: KIOSK-TEST-TOKEN-123\r\nCourse: BSCS";

        $this->postJson(route('kiosk.scan'), ['token' => $payload])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'identity' => ['firstName' => 'Juan', 'loginMethod' => 'qr'],
            ]);
    }

    public function test_idno_line_is_case_insensitive_and_space_tolerant(): void
    {
        // Scanners vary in spacing/casing around the label; be forgiving.
        $this->student();

        $this->postJson(route('kiosk.scan'), ['token' => "idno :   KIOSK-TEST-TOKEN-123  "])
            ->assertOk()
            ->assertJson(['ok' => true, 'identity' => ['loginMethod' => 'qr']]);
    }

    public function test_single_line_backup_token_still_matches_whole_string(): void
    {
        // The simple backup QR is a bare token with no "IDNo:" line — the whole
        // (trimmed) string is the token.
        $this->student();

        $this->postJson(route('kiosk.scan'), ['token' => '  KIOSK-TEST-TOKEN-123  '])
            ->assertOk()
            ->assertJson(['ok' => true, 'identity' => ['loginMethod' => 'qr']]);
    }

    // ── Email login (FR-KSK-02 → FR-KSK-03) ───────────────────────────────────

    public function test_student_email_login_returns_identity(): void
    {
        $this->student();

        $this->postJson(route('kiosk.login'), [
            'email' => 'juan.santos@psu.edu.ph',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'identity' => ['firstName' => 'Juan', 'loginMethod' => 'email'],
            ]);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->student();

        $this->postJson(route('kiosk.login'), [
            'email' => 'juan.santos@psu.edu.ph',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJson(['ok' => false]);
    }

    public function test_staff_credentials_are_rejected_at_the_kiosk(): void
    {
        // A nurse with valid credentials must NOT be able to log in here.
        User::factory()->create([
            'role' => 'nurse',
            'status' => 'active',
            'email' => 'nurse@psu.edu.ph',
            'password' => Hash::make('password'),
        ]);

        $this->postJson(route('kiosk.login'), [
            'email' => 'nurse@psu.edu.ph',
            'password' => 'password',
        ])->assertStatus(422)->assertJson(['ok' => false]);
    }

    public function test_inactive_student_is_rejected(): void
    {
        $this->student(['user' => ['status' => 'inactive']]);

        $this->postJson(route('kiosk.login'), [
            'email' => 'juan.santos@psu.edu.ph',
            'password' => 'password',
        ])->assertStatus(422)->assertJson(['ok' => false]);
    }

    public function test_unknown_email_is_rejected(): void
    {
        $this->postJson(route('kiosk.login'), [
            'email' => 'ghost@psu.edu.ph',
            'password' => 'password',
        ])->assertStatus(422)->assertJson(['ok' => false]);
    }

    // ── CSRF self-heal token (long-lived kiosk page) ──────────────────────────

    public function test_token_endpoint_returns_a_csrf_token(): void
    {
        $this->getJson(route('kiosk.token'))
            ->assertOk()
            ->assertJsonStructure(['token']);
    }
}
