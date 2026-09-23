<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpVerificationMail;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
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
            // Must be a real CCS program from config/programs.php (D-42).
            'course' => 'Bachelor of Science in Computer Science',
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

    // ── Resend cooldown (Part C — 60s, server-enforced) ────────────────────────

    /**
     * The registration OTP entry is keyed by SESSION ID, which the array driver
     * regenerates on every request (see the note on the test above). To exercise
     * the cooldown over HTTP we switch this test to the database session driver
     * and carry the session cookie manually so the id — and therefore the OTP
     * cache key — stays stable across requests.
     */
    public function test_registration_resend_respects_the_60s_cooldown(): void
    {
        config(['session.driver' => 'database']);
        // Bypass cookie encryption both ways so the raw session id read from
        // the first response can be replayed on later requests.
        $this->withoutMiddleware(EncryptCookies::class);
        $this->disableCookieEncryption();
        Mail::fake();

        // First request mints the session; every later request re-sends its cookie.
        $response = $this->post(route('register.consent'), ['consent' => '1']);
        $sessionId = $response->getCookie(config('session.cookie'), decrypt: false)->getValue();
        $this->withCookie(config('session.cookie'), $sessionId);

        $this->post(route('register.info.store'), $this->infoPayload())
            ->assertRedirect(route('register.verify'));

        // Step 3 GET issues the first OTP (and starts the cooldown).
        $this->get(route('register.verify'))->assertOk();
        $this->assertCount(1, Mail::sent(OtpVerificationMail::class));

        // Early resend → rejected server-side, nothing re-sent.
        $this->post(route('register.verify.resend'))->assertSessionHasErrors('otp');
        $this->assertCount(1, Mail::sent(OtpVerificationMail::class));

        // After the cooldown elapses the resend goes through.
        $this->travel(61)->seconds();

        $this->post(route('register.verify.resend'))
            ->assertRedirect(route('register.verify'))
            ->assertSessionHas('status');
        $this->assertCount(2, Mail::sent(OtpVerificationMail::class));
    }

    // ── A code with a letter is refused before any attempt is counted ─────────

    /** Same database-session trick as the cooldown test: the OTP key is the session id. */
    public function test_a_code_with_a_letter_is_refused_without_using_an_attempt(): void
    {
        config(['session.driver' => 'database']);
        $this->withoutMiddleware(EncryptCookies::class);
        $this->disableCookieEncryption();
        Mail::fake();

        $response = $this->post(route('register.consent'), ['consent' => '1']);
        $sessionId = $response->getCookie(config('session.cookie'), decrypt: false)->getValue();
        $this->withCookie(config('session.cookie'), $sessionId);

        $this->post(route('register.info.store'), $this->infoPayload())
            ->assertRedirect(route('register.verify'));
        $this->get(route('register.verify'))->assertOk();
        $otp = Mail::sent(OtpVerificationMail::class)->first()->otp;

        // More letter-codes than the 5-attempt budget…
        for ($i = 0; $i < 6; $i++) {
            $this->post(route('register.verify.submit'), ['otp' => '12a456'])
                ->assertSessionHasErrors(['otp' => "Letters aren't allowed — the code is 6 numbers."]);
        }

        // …and the real code still creates the account.
        $this->post(route('register.verify.submit'), ['otp' => $otp])
            ->assertRedirect(route('register.link-id'));
        $this->assertDatabaseHas('users', ['email' => 'juan@example.com']);
    }

    // ── Step 3 revisited after verifying (FR-REG-04, 2026-09-23) ──────────────

    public function test_a_logged_in_student_sees_step_three_as_already_verified(): void
    {
        $student = StudentProfile::factory()->forCollege($this->college())->create()->user;

        $this->actingAs($student)
            ->get(route('register.verify'))
            ->assertOk()
            ->assertSee('Your email is already verified.')
            ->assertSee(route('register.link-id'), false)
            ->assertDontSee(route('register.verify.submit'), false)
            ->assertDontSee('Start over');
    }

    public function test_logged_in_staff_on_step_three_go_to_their_dashboard(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);

        $this->actingAs($nurse)
            ->get(route('register.verify'))
            ->assertRedirect('/nurse/dashboard');
    }

    public function test_a_guest_without_staged_info_is_sent_back_to_step_two(): void
    {
        $this->get(route('register.verify'))
            ->assertRedirect(route('register.info'));
    }

    public function test_a_guest_with_staged_info_gets_the_otp_form(): void
    {
        Mail::fake();

        $this->withSession(['reg.info' => $this->infoPayload()])
            ->get(route('register.verify'))
            ->assertOk()
            ->assertSee(route('register.verify.submit'), false)
            ->assertDontSee('Your email is already verified.');
    }

    /** no-store keeps the pages out of the back/forward cache, so Back asks the server. */
    public function test_every_wizard_page_is_sent_no_store(): void
    {
        Mail::fake();

        $this->get(route('register'))->assertHeader('Cache-Control', 'no-store, private');

        $this->withSession(['reg.consent_at' => now()->toIso8601String()])
            ->get(route('register.info'))
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->withSession(['reg.info' => $this->infoPayload()])
            ->get(route('register.verify'))
            ->assertHeader('Cache-Control', 'no-store, private');

        $student = StudentProfile::factory()->forCollege($this->college())->create()->user;
        $this->actingAs($student)
            ->get(route('register.link-id'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
