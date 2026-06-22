<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreRegistrationInfoRequest;
use App\Mail\OtpVerificationMail;
use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Drives the 4-step student self-registration wizard.
 *
 * Step 1 — Consent      GET  /register            (name: register)
 * Step 2 — Info         GET  /register/info        (name: register.info)
 * Step 3 — Verify       GET  /register/verify      (name: register.verify)
 * Step 4 — Link ID      GET  /register/link-id     (name: register.link-id)  [placeholder]
 *
 * FR-REG-08: no users row is created until email OTP is verified in Step 3.
 * Steps 1–2 write only to the session.
 */
class RegistrationWizardController extends Controller
{
    private const OTP_TTL_MINUTES = 10;

    private const OTP_MAX_ATTEMPTS = 5;

    // ── Step 1: Consent ─────────────────────────────────────────────────────

    public function step1(): View
    {
        return view('auth.register.step1');
    }

    public function storeConsent(Request $request): RedirectResponse
    {
        $request->validate(['consent' => ['accepted']], [
            'consent.accepted' => 'You must accept the data privacy notice to continue.',
        ]);

        // Consent timestamp held in session — written to student_profiles.privacy_consent_at
        // only after the account is created following successful OTP in Step 3.
        $request->session()->put('reg.consent_at', now()->toIso8601String());

        return redirect()->route('register.info');
    }

    // ── Step 2: Personal Information ────────────────────────────────────────

    public function step2(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('reg.consent_at')) {
            return redirect()->route('register');
        }

        $colleges = College::orderBy('name')->get(['id', 'code', 'name']);

        return view('auth.register.step2', compact('colleges'));
    }

    public function storeInfo(StoreRegistrationInfoRequest $request): RedirectResponse
    {
        if (! $request->session()->has('reg.consent_at')) {
            return redirect()->route('register');
        }

        $data = $request->validated();
        unset($data['password_confirmation']); // don't stage the confirmation copy

        // Stage validated data server-side (FR-REG-08 — no user row yet).
        // The User model's 'hashed' cast will bcrypt password on creation.
        $request->session()->put('reg.info', $data);

        return redirect()->route('register.verify');
    }

    // ── Step 3: Email Verify (FR-REG-04 / FR-REG-05 / Decision D-8) ─────────

    public function step3(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('reg.info')) {
            return redirect()->route('register.info');
        }

        /** @var array<string,mixed> $info */
        $info = $request->session()->get('reg.info');

        // Only generate + send OTP on first visit; reload reuses the cached code.
        if (! Cache::has($this->otpCacheKey($request))) {
            $this->issueOtp($request, $info['email'], $info['first_name']);
        }

        return view('auth.register.step3', ['email' => $info['email']]);
    }

    /** POST /register/verify — validate the submitted 6-digit code. */
    public function verifyOtp(Request $request): RedirectResponse
    {
        if (! $request->session()->has('reg.info')) {
            return redirect()->route('register.info');
        }

        $request->validate([
            'otp' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ], [
            'otp.required' => 'Please enter the 6-digit code.',
            'otp.size' => 'The code must be exactly 6 digits.',
            'otp.regex' => 'The code must contain digits only.',
        ]);

        $cacheKey = $this->otpCacheKey($request);

        /** @var array{hash:string,attempts:int,expires_at:string}|null $entry */
        $entry = Cache::get($cacheKey);

        if ($entry === null) {
            return back()->withErrors(['otp' => 'This code has expired. Use the Resend link to get a new one.']);
        }

        // Guard: should have been deleted at 5 attempts, but be defensive.
        if ($entry['attempts'] >= self::OTP_MAX_ATTEMPTS) {
            Cache::forget($cacheKey);

            return back()->withErrors(['otp' => 'Too many incorrect attempts. Please request a new code.']);
        }

        if (! hash_equals($entry['hash'], hash('sha256', (string) $request->input('otp')))) {
            $entry['attempts']++;

            if ($entry['attempts'] >= self::OTP_MAX_ATTEMPTS) {
                Cache::forget($cacheKey);

                return back()->withErrors([
                    'otp' => 'Incorrect code. You have used all 5 attempts — please request a new code.',
                ]);
            }

            $remaining = self::OTP_MAX_ATTEMPTS - $entry['attempts'];
            Cache::put($cacheKey, $entry, Carbon::parse($entry['expires_at']));

            return back()->withErrors([
                'otp' => "Incorrect code. {$remaining} attempt(s) remaining.",
            ]);
        }

        // ── Correct OTP: create account in one transaction ───────────────────
        Cache::forget($cacheKey);

        /** @var array<string,mixed> $info */
        $info = $request->session()->get('reg.info');
        $consent = $request->session()->get('reg.consent_at');

        $user = DB::transaction(function () use ($info, $consent) {
            $user = User::create([
                'role' => 'student',
                'name' => trim($info['first_name'].' '.$info['last_name']),
                'email' => $info['email'],
                'password' => $info['password'], // 'hashed' cast bcrypts on assignment
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            // Generate a unique QR token (64-char random string).
            // Collision is astronomically unlikely; loop guards the UNIQUE constraint.
            do {
                $token = Str::random(64);
            } while (StudentProfile::where('qr_token', $token)->exists());

            StudentProfile::create([
                'user_id' => $user->id,
                'college_id' => (int) $info['college_id'],
                'student_number' => $info['student_number'],
                'first_name' => $info['first_name'],
                'middle_name' => $info['middle_name'] ?? null,
                'last_name' => $info['last_name'],
                'sex' => $info['sex'],
                'course' => $info['course'],
                'year_level' => $info['year_level'],
                'date_of_birth' => $info['date_of_birth'],
                'place_of_birth' => $info['place_of_birth'],
                'civil_status' => $info['civil_status'],
                'address' => $info['address'],
                'qr_token' => $token,
                'privacy_consent_at' => $consent,
            ]);

            return $user;
        });

        $request->session()->forget(['reg.info', 'reg.consent_at']);

        Auth::login($user);
        $request->session()->regenerate(); // prevent session fixation

        return redirect()->route('register.link-id');
    }

    /** POST /register/verify/resend — invalidate current code and issue a fresh one. */
    public function resendOtp(Request $request): RedirectResponse
    {
        if (! $request->session()->has('reg.info')) {
            return redirect()->route('register.info');
        }

        /** @var array<string,mixed> $info */
        $info = $request->session()->get('reg.info');

        Cache::forget($this->otpCacheKey($request));
        $this->issueOtp($request, $info['email'], $info['first_name']);

        return redirect()->route('register.verify')
            ->with('status', 'A new code has been sent to your email.');
    }

    // ── Step 4: Link ID (FR-REG-06) ─────────────────────────────────────────

    /** GET /register/link-id — shown after OTP creates and logs in the student. */
    public function step4(Request $request): View|RedirectResponse
    {
        $profile = $request->user()?->studentProfile;

        if (! $profile) {
            return redirect()->route('student.dashboard');
        }

        // Digits-only version is passed to the client so the JS can compare
        // against the QR payload without exposing the full student_number format.
        $studentNumberDigits = preg_replace('/\D/', '', $profile->student_number);

        return view('auth.register.step4', compact('studentNumberDigits'));
    }

    /** POST /register/link-id — store extracted IDNo, replacing the provisional qr_token. */
    public function linkId(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id_number' => ['required', 'string', 'max:30'],
        ], [
            'id_number.required' => 'No ID number was submitted.',
        ]);

        $profile = $request->user()->studentProfile;
        $submittedDigits = preg_replace('/\D/', '', $validated['id_number']);
        $profileDigits = preg_replace('/\D/', '', $profile->student_number);

        // Server-side digit comparison is the authoritative check (the client
        // comparison in JS is UX feedback only — never trust client-only validation).
        if ($submittedDigits !== $profileDigits) {
            return back()->withErrors([
                'id_number' => 'The ID number does not match your registered student number.',
            ]);
        }

        try {
            $profile->update(['qr_token' => $validated['id_number']]);
        } catch (UniqueConstraintViolationException) {
            // Another student already has this IDNo linked — race condition or
            // duplicate registration attempt.
            return back()->withErrors([
                'id_number' => 'This ID is already linked to another account. Contact the clinic if this is an error.',
            ]);
        }

        return redirect()->route('student.dashboard')
            ->with('status', 'Your student ID has been linked successfully.');
    }

    /** POST /register/link-id/skip — keep provisional token, go to dashboard (FR-REG-07). */
    public function skipLinkId(): RedirectResponse
    {
        return redirect()->route('student.dashboard');
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /** Cache key for this session's pending OTP entry. */
    private function otpCacheKey(Request $request): string
    {
        return 'reg_otp_'.$request->session()->getId();
    }

    /**
     * Generate a cryptographically random 6-digit OTP, store its SHA-256 hash
     * in the cache (TTL = OTP_TTL_MINUTES), and send the plaintext via mail.
     * The plaintext never touches the cache — only the hash is persisted.
     */
    private function issueOtp(Request $request, string $email, string $firstName): void
    {
        $otp = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(self::OTP_TTL_MINUTES);

        Cache::put($this->otpCacheKey($request), [
            'hash' => hash('sha256', $otp),
            'attempts' => 0,
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        Mail::to($email)->send(new OtpVerificationMail($otp, $firstName));
    }
}
