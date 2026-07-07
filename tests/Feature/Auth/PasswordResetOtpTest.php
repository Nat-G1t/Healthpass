<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Mail\OtpVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Guest "Forgot password?" — OTP flow (replaces Breeze's emailed reset link).
 *
 * The OTP cache entry is keyed by a hash of the EMAIL (not the session id),
 * so these tests can round-trip over HTTP on the array session driver; the
 * flow's session-bound state (pwreset.email / verified pass) persists across
 * in-test requests because the session store is shared within a test.
 */
class PasswordResetOtpTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_MESSAGE = 'If an account exists for that email, we sent a 6-digit code.';

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function user(): User
    {
        return User::factory()->create(['email' => 'juan@example.com']);
    }

    /** Every OTP mailed to $email, oldest first (requires Mail::fake()). */
    private function otpsSentTo(string $email): array
    {
        return Mail::sent(OtpVerificationMail::class)
            ->filter(fn (OtpVerificationMail $mail) => $mail->hasTo($email))
            ->map(fn (OtpVerificationMail $mail) => $mail->otp)
            ->values()
            ->all();
    }

    /** A 6-digit code guaranteed not to equal $otp. */
    private function wrongOtp(string $otp): string
    {
        return $otp === '111111' ? '222222' : '111111';
    }

    /** Request a code and pass OTP verification, landing on the new-password page. */
    private function verifyOtpFor(User $user): void
    {
        $this->post(route('password.email'), ['email' => $user->email]);
        [$otp] = $this->otpsSentTo($user->email);

        $this->post(route('password.reset.verify.submit'), ['otp' => $otp])
            ->assertRedirect(route('password.reset.new'));
    }

    // ── Pages ────────────────────────────────────────────────────────────────

    public function test_forgot_password_page_renders(): void
    {
        // Route name password.request must survive — the login page links it.
        $this->get(route('password.request'))->assertOk()->assertSee('Forgot Password');
    }

    public function test_verify_page_without_a_pending_request_redirects_to_start(): void
    {
        $this->get(route('password.reset.verify'))->assertRedirect(route('password.request'));
    }

    public function test_new_password_page_requires_a_verified_session(): void
    {
        $this->get(route('password.reset.new'))->assertRedirect(route('password.request'));

        // A direct POST is refused the same way — no state, no change.
        $this->post(route('password.reset.update'), [
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertRedirect(route('password.request'));
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function test_full_reset_happy_path_changes_password_only_on_final_submit(): void
    {
        Mail::fake();
        $user = $this->user();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.reset.verify'))
            ->assertSessionHas('status', self::GENERIC_MESSAGE);

        $this->get(route('password.reset.verify'))->assertOk();

        [$otp] = $this->otpsSentTo($user->email);
        $this->post(route('password.reset.verify.submit'), ['otp' => $otp])
            ->assertRedirect(route('password.reset.new'));

        // OTP verified but NOT submitted yet — password still the old one.
        $this->assertTrue(Hash::check('password', $user->fresh()->password));

        $this->get(route('password.reset.new'))->assertOk();

        $this->post(route('password.reset.update'), [
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
        $this->assertFalse(Hash::check('password', $user->fresh()->password));

        // The reset state is spent — the new-password page locks again.
        $this->get(route('password.reset.new'))->assertRedirect(route('password.request'));
    }

    // ── Anti-enumeration ─────────────────────────────────────────────────────

    public function test_unknown_email_gets_the_identical_response_and_no_mail(): void
    {
        Mail::fake();
        $this->user(); // an account exists, but we ask about a different email

        $response = $this->post(route('password.email'), ['email' => 'nobody@example.com']);

        // Byte-identical outward behaviour to the known-email case…
        $response->assertRedirect(route('password.reset.verify'));
        $response->assertSessionHas('status', self::GENERIC_MESSAGE);

        // …the verify screen renders normally (decoy entry drives the cooldown)…
        $this->get(route('password.reset.verify'))->assertOk();

        // …but nothing was actually sent.
        Mail::assertNotSent(OtpVerificationMail::class);
    }

    // ── OTP verification ─────────────────────────────────────────────────────

    public function test_five_wrong_attempts_discard_the_code(): void
    {
        Mail::fake();
        $user = $this->user();

        $this->post(route('password.email'), ['email' => $user->email]);
        [$otp] = $this->otpsSentTo($user->email);
        $wrong = $this->wrongOtp($otp);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->post(route('password.reset.verify.submit'), ['otp' => $wrong]);
        }

        $response->assertSessionHasErrors('otp');

        // The correct code is dead too, and the password never changed.
        $this->post(route('password.reset.verify.submit'), ['otp' => $otp])
            ->assertSessionHasErrors('otp');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_abandoning_after_otp_verification_leaves_password_unchanged(): void
    {
        Mail::fake();
        $user = $this->user();

        $this->verifyOtpFor($user);

        // Walk away: the verified pass expires with its short TTL.
        $this->travel(11)->minutes();

        $this->get(route('password.reset.new'))->assertRedirect(route('password.request'));
        $this->post(route('password.reset.update'), [
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertRedirect(route('password.request'));

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_final_mismatch_is_rejected_server_side_and_can_be_retried(): void
    {
        Mail::fake();
        $user = $this->user();

        $this->verifyOtpFor($user);

        // Alpine gating bypassed — the `confirmed` rule must catch it.
        $this->post(route('password.reset.update'), [
            'password' => 'NewPassword123!',
            'password_confirmation' => 'Different123!',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));

        // Validation failure does not burn the verified pass — retry works.
        $this->post(route('password.reset.update'), [
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
    }

    // ── Resend cooldown (Part C) ─────────────────────────────────────────────

    public function test_early_resend_is_rejected_server_side(): void
    {
        Mail::fake();
        $user = $this->user();

        $this->post(route('password.email'), ['email' => $user->email]);

        $this->post(route('password.reset.verify.resend'))
            ->assertSessionHasErrors('otp');

        $this->assertCount(1, $this->otpsSentTo($user->email));
    }

    public function test_resend_after_cooldown_issues_fresh_code_and_invalidates_old(): void
    {
        Mail::fake();
        $user = $this->user();

        $this->post(route('password.email'), ['email' => $user->email]);

        $this->travel(61)->seconds();

        $this->post(route('password.reset.verify.resend'))
            ->assertRedirect(route('password.reset.verify'))
            ->assertSessionHas('status', self::GENERIC_MESSAGE);

        [$oldOtp, $newOtp] = $this->otpsSentTo($user->email);

        $this->post(route('password.reset.verify.submit'), ['otp' => $oldOtp])
            ->assertSessionHasErrors('otp');

        $this->post(route('password.reset.verify.submit'), ['otp' => $newOtp])
            ->assertRedirect(route('password.reset.new'));
    }

    public function test_reposting_the_email_form_within_cooldown_sends_nothing_new(): void
    {
        Mail::fake();
        $user = $this->user();

        $this->post(route('password.email'), ['email' => $user->email]);

        // Same generic outcome, but the send is silently skipped.
        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.reset.verify'))
            ->assertSessionHas('status', self::GENERIC_MESSAGE);

        $this->assertCount(1, $this->otpsSentTo($user->email));
    }

    // ── Session hygiene ──────────────────────────────────────────────────────

    public function test_successful_reset_deletes_all_of_the_users_sessions(): void
    {
        // The helper only acts on the database driver (what production uses).
        config(['session.driver' => 'database']);
        Mail::fake();
        $user = $this->user();

        DB::table('sessions')->insert([
            'id' => 'stolen-session-id',
            'user_id' => $user->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'other-browser',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->verifyOtpFor($user);

        $this->post(route('password.reset.update'), [
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('sessions', ['id' => 'stolen-session-id']);
    }
}
