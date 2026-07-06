<?php

namespace Tests\Feature\Auth;

use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function college(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    /** Valid Step 2 payload; password is the plaintext we later log in with. */
    private function infoPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'student_number' => '2024-00001',
            'college_id' => $this->college()->id,
            'sex' => 'M',
            'course' => 'BS Computer Science',
            'year_level' => '1',
            'date_of_birth' => '2003-05-15',
            'place_of_birth' => 'Angeles City, Pampanga',
            'civil_status' => 'Single',
            'address' => '123 Rizal St., Angeles City',
            'email' => 'juan@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ], $overrides);
    }

    /** Runs Step 1 (consent) + Step 2 (info) so the session holds reg.info. */
    private function stageConsentAndInfo(): void
    {
        $this->post(route('register.consent'), ['consent' => '1'])
            ->assertRedirect(route('register.info'));

        $this->post(route('register.info.store'), $this->infoPayload())
            ->assertRedirect(route('register.verify'));
    }

    // ── Password is staged hashed, and the plaintext still authenticates ──────

    /**
     * The security fix stages a bcrypt hash (not plaintext) in the session, and the
     * User model's 'hashed' cast must NOT re-hash that already-bcrypt value — otherwise
     * the student could never log in with the password they typed.
     *
     * Note on scope: the OTP round-trip (Step 3) can't be driven over HTTP in the test
     * suite because SESSION_DRIVER=array regenerates the session id on every request,
     * and the OTP cache is keyed by that id. So we prove the two things the fix is
     * actually about — (1) staging hashes the password, and (2) the staged hash logs in
     * unchanged through the exact User::create() the verify step performs.
     */
    public function test_password_is_staged_hashed_and_original_plaintext_still_logs_in(): void
    {
        $this->stageConsentAndInfo();

        // (1) The session stages a bcrypt hash of the ORIGINAL plaintext, never the
        //     plaintext itself — so it is not readable at rest in the sessions table.
        $staged = session('reg.info');
        $this->assertNotSame('Password123!', $staged['password']);
        $this->assertStringStartsWith('$2y$', $staged['password']);
        $this->assertTrue(Hash::check('Password123!', $staged['password']));

        // (2) Create the account exactly as RegistrationWizardController@verifyOtp does:
        //     the staged (already-hashed) value flows straight into User::create(). If
        //     the 'hashed' cast double-hashed it, Hash::check below would fail.
        $user = User::create([
            'role' => 'student',
            'name' => trim($staged['first_name'].' '.$staged['last_name']),
            'email' => $staged['email'],
            'password' => $staged['password'],
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->assertTrue(Hash::check('Password123!', $user->fresh()->password));

        // …and the original plaintext authenticates end-to-end through the login form.
        $this->post('/login', ['email' => 'juan@example.com', 'password' => 'Password123!']);
        $this->assertAuthenticatedAs($user);
    }

    // ── Resend OTP is rate-limited (mail-bomb chokepoint, throttle:3,5) ───────

    public function test_resend_otp_is_throttled_on_fourth_hit_within_window(): void
    {
        Mail::fake();

        $this->stageConsentAndInfo();

        // 3 resends allowed within the 5-minute window…
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('register.verify.resend'))
                ->assertRedirect(route('register.verify'));
        }

        // …the 4th is rate-limited. (reg-resend has its own bucket, so the earlier
        // consent/info hits don't count against this cap.)
        $this->post(route('register.verify.resend'))->assertStatus(429);
    }
}
