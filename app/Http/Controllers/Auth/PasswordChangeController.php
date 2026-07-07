<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\DeletesOtherSessions;
use App\Http\Controllers\Controller;
use App\Mail\OtpVerificationMail;
use App\Models\User;
use App\Support\Otp;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Authenticated "Change Password" for all four roles — OTP-confirmed.
 *
 * Submitting the form does NOT change the password. The new password's bcrypt
 * hash is staged in the cache next to the OTP entry (Decision D-8 pattern:
 * SHA-256 code hash, 10-min TTL, 5-attempt cap) and a code is mailed to the
 * user's own address. Only a correct code applies the staged hash; abandoning
 * the flow lets the whole entry — staged hash included — expire with the TTL.
 */
class PasswordChangeController extends Controller
{
    use DeletesOtherSessions;

    /** GET /password/change — the 3-field form. */
    public function show(): View
    {
        return view('auth.password.change');
    }

    /** POST /password/change — validate, stage the new hash, send the OTP. */
    public function store(Request $request): RedirectResponse
    {
        // current_password rule re-checks the user's real password server-side —
        // the Alpine button gating on the form is UX only, never trusted.
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();

        // Submitting again while a code is still fresh would re-send mail, so
        // the resend cooldown applies here too (Part C — server-side, always).
        $remaining = Otp::resendRemainingSeconds(Cache::get($this->otpKey($user)));

        if ($remaining > 0) {
            return back()->with('error', "A code was already sent to your email. Please wait {$remaining} second(s) before requesting another.");
        }

        $this->issueOtp($user, Hash::make($request->input('password')));

        return redirect()->route('password.change.verify');
    }

    /** GET /password/change/verify — the OTP entry screen. */
    public function showVerify(Request $request): View|RedirectResponse
    {
        $entry = Cache::get($this->otpKey($request->user()));

        // Nothing staged (expired, cancelled, or never submitted) — start over.
        if ($entry === null) {
            return redirect()->route('password.change');
        }

        return view('auth.password.change-verify', [
            'email' => $request->user()->email,
            'resendRemaining' => Otp::resendRemainingSeconds($entry),
        ]);
    }

    /** POST /password/change/verify — correct code applies the staged hash. */
    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'otp' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ], [
            'otp.required' => 'Please enter the 6-digit code.',
            'otp.size' => 'The code must be exactly 6 digits.',
            'otp.regex' => 'The code must contain digits only.',
        ]);

        $user = $request->user();
        $cacheKey = $this->otpKey($user);

        /** @var array{hash:string,attempts:int,expires_at:string,resend_available_at:string,new_password_hash:string}|null $entry */
        $entry = Cache::get($cacheKey);

        // Expired or never issued — the staged hash is gone; password unchanged.
        if ($entry === null) {
            return redirect()->route('password.change')
                ->with('error', 'That code has expired. Your password was not changed — please start again.');
        }

        // Guard: should have been deleted at the cap, but be defensive.
        if ($entry['attempts'] >= Otp::MAX_ATTEMPTS) {
            Cache::forget($cacheKey);

            return redirect()->route('password.change')
                ->with('error', 'Too many incorrect attempts. Your password was not changed — please start again.');
        }

        if (! hash_equals($entry['hash'], hash('sha256', (string) $request->input('otp')))) {
            $entry['attempts']++;

            if ($entry['attempts'] >= Otp::MAX_ATTEMPTS) {
                Cache::forget($cacheKey);

                return redirect()->route('password.change')
                    ->with('error', 'Incorrect code — you have used all 5 attempts. Your password was not changed.');
            }

            $remaining = Otp::MAX_ATTEMPTS - $entry['attempts'];
            Cache::put($cacheKey, $entry, Carbon::parse($entry['expires_at']));

            return back()->withErrors(['otp' => "Incorrect code. {$remaining} attempt(s) remaining."]);
        }

        // ── Correct code: apply the staged hash ──────────────────────────────
        Cache::forget($cacheKey);

        // Already bcrypt from staging; the User model's 'hashed' cast detects
        // that and does not double-hash.
        $user->update(['password' => $entry['new_password_hash']]);

        // New session id for this browser; every other session is logged out.
        $request->session()->regenerate();
        $this->deleteOtherSessions($user, $request->session()->getId());

        return redirect()->route('password.change')
            ->with('status', 'Your password has been updated.');
    }

    /** POST /password/change/verify/resend — fresh code, same staged password. */
    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();
        $entry = Cache::get($this->otpKey($user));

        // Entry expired ⇒ the staged hash expired with it; the user must
        // re-enter the new password (we never keep it anywhere else).
        if ($entry === null) {
            return redirect()->route('password.change')
                ->with('error', 'That code has expired. Please enter your new password again.');
        }

        $remaining = Otp::resendRemainingSeconds($entry);

        if ($remaining > 0) {
            return back()->withErrors(['otp' => "Please wait {$remaining} second(s) before requesting a new code."]);
        }

        // Re-issuing overwrites the cache entry: fresh code, fresh attempt
        // budget, same staged hash — the old code is invalidated.
        $this->issueOtp($user, $entry['new_password_hash']);

        return redirect()->route('password.change.verify')
            ->with('status', 'A new code has been sent to your email.');
    }

    /** POST /password/change/cancel — abandon; password stays unchanged. */
    public function cancel(Request $request): RedirectResponse
    {
        Cache::forget($this->otpKey($request->user()));

        return redirect()->route('password.change')
            ->with('status', 'Password change cancelled. Your password was not changed.');
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Cache key for this user's pending password change. Keyed by user id
     * (like the student email-change flow) rather than session id, so it
     * survives session regeneration and can never bleed across accounts.
     */
    private function otpKey(User $user): string
    {
        return 'pwd_change_otp_'.$user->id;
    }

    /**
     * Generate a 6-digit OTP, cache its SHA-256 hash alongside the staged
     * bcrypt hash of the new password (TTL = Otp::TTL_MINUTES), and mail the
     * plaintext code to the user. Neither the code nor the plaintext password
     * ever touches the cache.
     */
    private function issueOtp(User $user, string $stagedPasswordHash): void
    {
        $otp = Otp::generate();
        $expiresAt = now()->addMinutes(Otp::TTL_MINUTES);

        Cache::put($this->otpKey($user), [
            'hash' => hash('sha256', $otp),
            'attempts' => 0,
            'expires_at' => $expiresAt->toIso8601String(),
            'resend_available_at' => now()->addSeconds(Otp::RESEND_COOLDOWN_SECONDS)->toIso8601String(),
            'new_password_hash' => $stagedPasswordHash,
        ], $expiresAt);

        Mail::to($user->email)->send(new OtpVerificationMail(
            $otp,
            Str::before($user->name, ' '),
            'confirm your password change',
        ));
    }
}
