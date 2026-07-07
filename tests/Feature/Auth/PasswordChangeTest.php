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
 * Authenticated Change Password (all four roles) — OTP-confirmed.
 *
 * Submitting the form must NOT change the password: the new hash is staged in
 * the cache (keyed by user id, so these tests can round-trip over HTTP on the
 * array session driver) and only a correct emailed code applies it.
 */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Submit the change form (correct current password) for the given user. */
    private function startChange(User $user, string $newPassword = 'NewPassword123!'): void
    {
        $this->actingAs($user)->post(route('password.change.store'), [
            'current_password' => 'password', // factory default
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirect(route('password.change.verify'));
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

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('password.change'))->assertRedirect(route('login'));
    }

    public function test_page_renders_for_every_role(): void
    {
        foreach (['student', 'college_admin', 'nurse', 'director'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get(route('password.change'))
                ->assertOk()
                ->assertSee('Change Password');
        }
    }

    // ── Staging: submit sends an OTP but changes nothing ─────────────────────

    public function test_submit_stages_the_change_and_sends_otp_without_changing_password(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->startChange($user);

        Mail::assertSent(OtpVerificationMail::class, fn (OtpVerificationMail $m) => $m->hasTo($user->email));

        // Password is NOT changed by the submit — only staged.
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_wrong_current_password_is_rejected_and_sends_nothing(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('password.change.store'), [
            'current_password' => 'not-my-password',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertSessionHasErrors('current_password');
        Mail::assertNotSent(OtpVerificationMail::class);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_mismatched_confirmation_is_rejected_server_side(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        // The Alpine-disabled button can't be trusted — a direct POST with a
        // mismatch must fail on the server's `confirmed` rule.
        $response = $this->actingAs($user)->post(route('password.change.store'), [
            'current_password' => 'password',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'SomethingElse123!',
        ]);

        $response->assertSessionHasErrors('password');
        Mail::assertNotSent(OtpVerificationMail::class);
    }

    // ── Verify: correct code applies the staged hash ─────────────────────────

    public function test_correct_otp_applies_the_new_password(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->startChange($user);
        [$otp] = $this->otpsSentTo($user->email);

        // The OTP screen renders while the change is pending.
        $this->actingAs($user)->get(route('password.change.verify'))
            ->assertOk()
            ->assertSee($user->email);

        $response = $this->actingAs($user)->post(route('password.change.verify.submit'), ['otp' => $otp]);

        $response->assertRedirect(route('password.change'));
        $response->assertSessionHas('status');

        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
        $this->assertFalse(Hash::check('password', $user->fresh()->password));

        // The staged entry is spent — the verify screen has nothing pending.
        $this->actingAs($user)->get(route('password.change.verify'))
            ->assertRedirect(route('password.change'));
    }

    public function test_abandoned_change_expires_with_the_otp_ttl(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->startChange($user);
        [$otp] = $this->otpsSentTo($user->email);

        // Abandon past the 10-minute TTL: the staged hash expires with the entry.
        $this->travel(11)->minutes();

        $this->actingAs($user)->get(route('password.change.verify'))
            ->assertRedirect(route('password.change'));

        // Even the correct code can no longer apply it.
        $this->actingAs($user)->post(route('password.change.verify.submit'), ['otp' => $otp])
            ->assertRedirect(route('password.change'))
            ->assertSessionHas('error');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_five_wrong_attempts_discard_the_staged_change(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->startChange($user);
        [$otp] = $this->otpsSentTo($user->email);
        $wrong = $this->wrongOtp($otp);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->actingAs($user)->post(route('password.change.verify.submit'), ['otp' => $wrong]);
        }

        // 5th attempt discards the entry with a visible failure…
        $response->assertRedirect(route('password.change'));
        $response->assertSessionHas('error');

        // …after which even the correct code is dead, and nothing changed.
        $this->actingAs($user)->post(route('password.change.verify.submit'), ['otp' => $otp])
            ->assertSessionHas('error');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_cancel_discards_the_staged_change(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->startChange($user);

        $this->actingAs($user)->post(route('password.change.cancel'))
            ->assertRedirect(route('password.change'));

        $this->actingAs($user)->get(route('password.change.verify'))
            ->assertRedirect(route('password.change'));
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    // ── Resend cooldown (Part C) ─────────────────────────────────────────────

    public function test_early_resend_is_rejected_server_side_and_old_code_stays_valid(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->startChange($user);

        // Within the 60s cooldown — rejected regardless of any client timer.
        $this->actingAs($user)->post(route('password.change.verify.resend'))
            ->assertSessionHasErrors('otp');

        // No second mail went out, and the original code still works.
        $this->assertCount(1, $this->otpsSentTo($user->email));
        [$otp] = $this->otpsSentTo($user->email);

        $this->actingAs($user)->post(route('password.change.verify.submit'), ['otp' => $otp])
            ->assertSessionHas('status');
        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
    }

    public function test_resend_after_cooldown_issues_fresh_code_and_invalidates_old(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->startChange($user);

        $this->travel(61)->seconds();

        $this->actingAs($user)->post(route('password.change.verify.resend'))
            ->assertRedirect(route('password.change.verify'))
            ->assertSessionHas('status');

        [$oldOtp, $newOtp] = $this->otpsSentTo($user->email);

        // Old code invalidated…
        $this->actingAs($user)->post(route('password.change.verify.submit'), ['otp' => $oldOtp])
            ->assertSessionHasErrors('otp');

        // …fresh code works and applies the SAME staged password.
        $this->actingAs($user)->post(route('password.change.verify.submit'), ['otp' => $newOtp])
            ->assertSessionHas('status');
        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
    }

    public function test_resubmitting_the_form_within_cooldown_is_rejected(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->startChange($user);

        // A second submit is also an email send — same cooldown applies.
        $this->actingAs($user)->post(route('password.change.store'), [
            'current_password' => 'password',
            'password' => 'AnotherPass123!',
            'password_confirmation' => 'AnotherPass123!',
        ])->assertSessionHas('error');

        $this->assertCount(1, $this->otpsSentTo($user->email));
    }

    // ── Other sessions are logged out ────────────────────────────────────────

    public function test_successful_change_deletes_other_sessions(): void
    {
        // The helper only acts on the database driver (what production uses).
        config(['session.driver' => 'database']);
        Mail::fake();
        $user = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => 'stolen-session-id',
            'user_id' => $user->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'other-browser',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->startChange($user);
        [$otp] = $this->otpsSentTo($user->email);

        $this->actingAs($user)->post(route('password.change.verify.submit'), ['otp' => $otp])
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('sessions', ['id' => 'stolen-session-id']);
    }
}
