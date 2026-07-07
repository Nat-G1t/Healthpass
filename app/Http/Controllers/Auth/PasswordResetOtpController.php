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
 * Guest "Forgot password?" — OTP flow replacing Breeze's emailed reset link.
 *
 * Page 1 asks for an email → OTP entry page → new-password page. The password
 * changes ONLY on the final submit; abandoning at any point leaves it intact.
 *
 * Anti-enumeration: every response is identical whether or not the email has
 * an account. An OTP cache entry is written even for unknown emails (a "decoy"
 * whose code is never sent anywhere), so the verify screen, attempt cap, and
 * resend cooldown behave exactly the same — only the actual mail send is
 * skipped. The generic "if an account exists…" message is used throughout.
 */
class PasswordResetOtpController extends Controller
{
    use DeletesOtherSessions;

    /**
     * How long the "OTP verified" pass lasts before the new-password page
     * locks again. Session-bound: the flag lives in this visitor's session.
     */
    private const VERIFIED_TTL_MINUTES = 10;

    private const GENERIC_SENT_MESSAGE = 'If an account exists for that email, we sent a 6-digit code.';

    /** GET /forgot-password (name: password.request) — the email form. */
    public function showEmailForm(): View
    {
        return view('auth.password.forgot');
    }

    /** POST /forgot-password — remember the email, issue + send the OTP. */
    public function sendOtp(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = mb_strtolower($request->input('email'));

        // Bind the flow to this session: later steps only work for this email,
        // and any previous "verified" pass is revoked by starting over.
        $request->session()->put('pwreset.email', $email);
        $request->session()->forget('pwreset.verified_until');

        // Re-submitting within the cooldown silently skips the send (the old
        // code stays valid) — same outward behaviour for every email.
        if (Otp::resendRemainingSeconds(Cache::get($this->otpKey($email))) === 0) {
            $this->issueOtp($email);
        }

        return redirect()->route('password.reset.verify')
            ->with('status', self::GENERIC_SENT_MESSAGE);
    }

    /** GET /forgot-password/verify — the OTP entry screen. */
    public function showVerify(Request $request): View|RedirectResponse
    {
        $email = $request->session()->get('pwreset.email');

        if ($email === null) {
            return redirect()->route('password.request');
        }

        return view('auth.password.forgot-verify', [
            'email' => $email,
            'resendRemaining' => Otp::resendRemainingSeconds(Cache::get($this->otpKey($email))),
        ]);
    }

    /** POST /forgot-password/verify — correct code unlocks the new-password page. */
    public function verifyOtp(Request $request): RedirectResponse
    {
        $email = $request->session()->get('pwreset.email');

        if ($email === null) {
            return redirect()->route('password.request');
        }

        $request->validate([
            'otp' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ], [
            'otp.required' => 'Please enter the 6-digit code.',
            'otp.size' => 'The code must be exactly 6 digits.',
            'otp.regex' => 'The code must contain digits only.',
        ]);

        $cacheKey = $this->otpKey($email);

        /** @var array{hash:string,attempts:int,expires_at:string,resend_available_at:string}|null $entry */
        $entry = Cache::get($cacheKey);

        if ($entry === null) {
            return back()->withErrors(['otp' => 'This code has expired. Use the Resend link to get a new one.']);
        }

        // Guard: should have been deleted at the cap, but be defensive.
        if ($entry['attempts'] >= Otp::MAX_ATTEMPTS) {
            Cache::forget($cacheKey);

            return back()->withErrors(['otp' => 'Too many incorrect attempts. Please request a new code.']);
        }

        if (! hash_equals($entry['hash'], hash('sha256', (string) $request->input('otp')))) {
            $entry['attempts']++;

            if ($entry['attempts'] >= Otp::MAX_ATTEMPTS) {
                Cache::forget($cacheKey);

                return back()->withErrors([
                    'otp' => 'Incorrect code. You have used all 5 attempts — please request a new code.',
                ]);
            }

            $remaining = Otp::MAX_ATTEMPTS - $entry['attempts'];
            Cache::put($cacheKey, $entry, Carbon::parse($entry['expires_at']));

            return back()->withErrors(['otp' => "Incorrect code. {$remaining} attempt(s) remaining."]);
        }

        // ── Correct code: grant a short-lived, session-bound "verified" pass ──
        Cache::forget($cacheKey);

        // New session id at the step-up (anti-fixation); session data is kept.
        $request->session()->regenerate();
        $request->session()->put(
            'pwreset.verified_until',
            now()->addMinutes(self::VERIFIED_TTL_MINUTES)->toIso8601String(),
        );

        return redirect()->route('password.reset.new');
    }

    /** POST /forgot-password/verify/resend — fresh code, old one invalidated. */
    public function resendOtp(Request $request): RedirectResponse
    {
        $email = $request->session()->get('pwreset.email');

        if ($email === null) {
            return redirect()->route('password.request');
        }

        $remaining = Otp::resendRemainingSeconds(Cache::get($this->otpKey($email)));

        if ($remaining > 0) {
            return back()->withErrors(['otp' => "Please wait {$remaining} second(s) before requesting a new code."]);
        }

        $this->issueOtp($email);

        return redirect()->route('password.reset.verify')
            ->with('status', self::GENERIC_SENT_MESSAGE);
    }

    /** GET /forgot-password/new — new password + confirm, nothing else. */
    public function showNewPassword(Request $request): View|RedirectResponse
    {
        if (! $this->isVerified($request)) {
            return $this->verificationExpired($request);
        }

        return view('auth.password.forgot-new');
    }

    /** POST /forgot-password/new — the ONLY place the password actually changes. */
    public function updatePassword(Request $request): RedirectResponse
    {
        if (! $this->isVerified($request)) {
            return $this->verificationExpired($request);
        }

        // The Alpine button gating is UX only — matching is re-checked here.
        $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $email = $request->session()->get('pwreset.email');
        $user = User::where('email', $email)->first();

        // Whatever happens next, the reset state is spent: one pass, one change.
        $request->session()->forget(['pwreset.email', 'pwreset.verified_until']);

        // Only reachable for a non-existent account by guessing a decoy code
        // (~5-in-a-million). Fail generically; nothing to change.
        if ($user === null) {
            $request->session()->regenerate();

            return redirect()->route('password.request')
                ->with('error', 'This password reset could not be completed. Please start again.');
        }

        $user->update(['password' => Hash::make($request->input('password'))]);

        // Log out every session the account had (incl. whoever changed it —
        // this visitor is a guest), and re-key this session.
        $this->deleteOtherSessions($user);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('status', 'Your password has been changed. Please sign in with your new password.');
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Cache key for the pending reset OTP. Keyed by a hash of the email (not
     * the session id) so two browsers resetting the same account share one
     * code + one attempt budget.
     */
    private function otpKey(string $email): string
    {
        return 'pw_reset_otp_'.hash('sha256', mb_strtolower($email));
    }

    /**
     * Issue an OTP entry for this email (Decision D-8 pattern). The entry is
     * written whether or not an account exists — the code is only MAILED when
     * one does, so unknown emails are indistinguishable from real ones.
     */
    private function issueOtp(string $email): void
    {
        $otp = Otp::generate();
        $expiresAt = now()->addMinutes(Otp::TTL_MINUTES);

        Cache::put($this->otpKey($email), [
            'hash' => hash('sha256', $otp),
            'attempts' => 0,
            'expires_at' => $expiresAt->toIso8601String(),
            'resend_available_at' => now()->addSeconds(Otp::RESEND_COOLDOWN_SECONDS)->toIso8601String(),
        ], $expiresAt);

        $user = User::where('email', $email)->first();

        if ($user !== null) {
            Mail::to($email)->send(new OtpVerificationMail(
                $otp,
                Str::before($user->name, ' '),
                'reset your password',
            ));
        }
    }

    /** Does this session hold an unexpired "OTP verified" pass? */
    private function isVerified(Request $request): bool
    {
        $verifiedUntil = $request->session()->get('pwreset.verified_until');

        return $verifiedUntil !== null && now()->lt(Carbon::parse($verifiedUntil));
    }

    /** Clear stale reset state and send the visitor back to the start. */
    private function verificationExpired(Request $request): RedirectResponse
    {
        $request->session()->forget(['pwreset.email', 'pwreset.verified_until']);

        return redirect()->route('password.request')
            ->with('error', 'Your reset session has expired. Please start again.');
    }
}
