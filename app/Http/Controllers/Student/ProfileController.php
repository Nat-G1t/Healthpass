<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\UpdateStudentProfileRequest;
use App\Mail\OtpVerificationMail;
use App\Models\College;
use App\Models\User;
use App\Support\Otp;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * FR-STU-09 / FR-STU-10 — My ID & Profile.
 *
 * Shows the student's kiosk QR (generated from qr_token), read-only profile
 * fields, an Edit Profile modal for the self-editable fields, and — if the ID
 * was never linked — the same in-browser capture flow used in registration
 * Step 4 (camera / photo upload decoded by html5-qrcode).
 *
 * Changing the email address is gated behind a one-time-password sent to the
 * NEW address (same mechanism as registration Step 3 / Decision D-8): the email
 * is only replaced once the student proves ownership of it. This mirrors
 * RegistrationWizardController's OTP handling.
 */
class ProfileController extends Controller
{
    private const OTP_TTL_MINUTES = 10;

    private const OTP_MAX_ATTEMPTS = 5;

    /** GET /student/id-profile */
    public function show(Request $request): View
    {
        $profile = $request->user()->studentProfile()->with('college')->first();

        // Digits-only student number for the client-side QR match check
        // (same contract the Step 4 capture flow uses).
        $studentNumberDigits = preg_replace('/\D/', '', (string) $profile->student_number);

        // Only render a QR once a real ID is linked — an unlinked profile shows
        // the capture flow instead, so there is nothing scannable to display.
        $qrSvg = $profile->isQrLinked()
            ? QrCode::format('svg')->size(220)->margin(0)->generate($profile->qr_token)
            : null;

        // Surfaces the "email change awaiting verification" banner if one is pending.
        $pendingEmail = $this->pendingEmailChange($request->user());

        // College dropdown options for the Edit modal (FR-STU-09 — editable on transfer).
        $colleges = College::orderBy('name')->get(['id', 'code', 'name']);

        return view('student.id-profile', compact('profile', 'studentNumberDigits', 'qrSvg', 'pendingEmail', 'colleges'));
    }

    /** PATCH /student/id-profile — update only the self-editable fields. */
    public function update(UpdateStudentProfileRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $profile = $user->studentProfile;

        // The new email is only applied after OTP confirmation, so compare first.
        $emailChanged = mb_strtolower($data['email']) !== mb_strtolower($user->email);

        DB::transaction(function () use ($data, $user, $profile): void {
            $profile->update([
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                // Live college: changing it re-scopes the student to the new
                // College Admin automatically (admin lists filter on this column).
                // Past visits keep their snapshot — see clinic_visits.college_id.
                'college_id' => $data['college_id'],
                'course' => $data['course'],
                'year_level' => $data['year_level'],
                'date_of_birth' => $data['date_of_birth'],
                'place_of_birth' => $data['place_of_birth'],
                'civil_status' => $data['civil_status'],
                'address' => $data['address'],
            ]);

            // Keep users.name (used by the sidebar + initials) in sync with the
            // profile name, matching how registration first builds it. The email
            // is deliberately NOT touched here when it changed — see below.
            $user->update([
                'name' => trim($data['first_name'].' '.$data['last_name']),
            ]);
        });

        if ($emailChanged) {
            // Hold the new email aside and send an OTP to it. Nothing is changed
            // on the account until the student confirms the code.
            $this->issueEmailOtp($user, $profile->first_name, $data['email']);

            return redirect()->route('student.id-profile.verify-email')
                ->with('status', 'We sent a 6-digit code to '.$data['email'].' to confirm the change.');
        }

        return redirect()->route('student.id-profile')
            ->with('status', 'Your profile has been updated.');
    }

    // ── Email change verification (OTP to the new address) ───────────────────────

    /** GET /student/id-profile/verify-email — the OTP entry screen. */
    public function showEmailVerification(Request $request): View|RedirectResponse
    {
        $newEmail = $this->pendingEmailChange($request->user());

        // Nothing pending (already confirmed, expired, or cancelled) — go back.
        if ($newEmail === null) {
            return redirect()->route('student.id-profile');
        }

        return view('student.verify-email', [
            'email' => $newEmail,
            // Server-computed so a page refresh never resets the countdown.
            'resendRemaining' => Otp::resendRemainingSeconds(Cache::get($this->emailOtpKey($request->user()))),
        ]);
    }

    /** POST /student/id-profile/verify-email — confirm the code, then apply the email. */
    public function verifyEmail(Request $request): RedirectResponse
    {
        $request->validate([
            'otp' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ], [
            'otp.required' => 'Please enter the 6-digit code.',
            'otp.size' => 'The code must be exactly 6 digits.',
            'otp.regex' => 'The code must contain digits only.',
        ]);

        $user = $request->user();
        $cacheKey = $this->emailOtpKey($user);

        /** @var array{hash:string,attempts:int,expires_at:string,new_email:string}|null $entry */
        $entry = Cache::get($cacheKey);

        // Expired or never issued — the whole request fails; email unchanged.
        if ($entry === null) {
            return $this->emailChangeFailed('This code has expired. Your email was not changed — please try again.');
        }

        // Defensive: should have been cleared at the limit already.
        if ($entry['attempts'] >= self::OTP_MAX_ATTEMPTS) {
            Cache::forget($cacheKey);

            return $this->emailChangeFailed('Too many incorrect attempts. Your email was not changed.');
        }

        if (! hash_equals($entry['hash'], hash('sha256', (string) $request->input('otp')))) {
            $entry['attempts']++;

            // Used up every attempt — discard the pending change entirely.
            if ($entry['attempts'] >= self::OTP_MAX_ATTEMPTS) {
                Cache::forget($cacheKey);

                return $this->emailChangeFailed('Incorrect code — you have used all 5 attempts. Your email was not changed.');
            }

            $remaining = self::OTP_MAX_ATTEMPTS - $entry['attempts'];
            Cache::put($cacheKey, $entry, Carbon::parse($entry['expires_at']));

            return back()->withErrors(['otp' => "Incorrect code. {$remaining} attempt(s) remaining."]);
        }

        // ── Correct code: apply the new email ────────────────────────────────────
        Cache::forget($cacheKey);

        // Re-check uniqueness at apply time in case the address was taken while
        // the code was outstanding (the up-front check can go stale).
        $taken = User::where('email', $entry['new_email'])
            ->whereKeyNot($user->id)
            ->exists();

        if ($taken) {
            return $this->emailChangeFailed('That email address was registered by someone else in the meantime. Your email was not changed.');
        }

        // Ownership of the new address is now proven, so it counts as verified.
        $user->update([
            'email' => $entry['new_email'],
            'email_verified_at' => now(),
        ]);

        return redirect()->route('student.id-profile')
            ->with('status', 'Your email address has been updated to '.$entry['new_email'].'.');
    }

    /** POST /student/id-profile/verify-email/resend — invalidate and re-send the code. */
    public function resendEmailOtp(Request $request): RedirectResponse
    {
        $user = $request->user();
        $newEmail = $this->pendingEmailChange($user);

        if ($newEmail === null) {
            return redirect()->route('student.id-profile');
        }

        // Server-side resend cooldown — the disabled button is UX only.
        $remaining = Otp::resendRemainingSeconds(Cache::get($this->emailOtpKey($user)));

        if ($remaining > 0) {
            return back()->withErrors(['otp' => "Please wait {$remaining} second(s) before requesting a new code."]);
        }

        $this->issueEmailOtp($user, $user->studentProfile->first_name, $newEmail);

        return redirect()->route('student.id-profile.verify-email')
            ->with('status', 'A new code has been sent to '.$newEmail.'.');
    }

    /** POST /student/id-profile/verify-email/cancel — abandon the change. */
    public function cancelEmailChange(Request $request): RedirectResponse
    {
        Cache::forget($this->emailOtpKey($request->user()));

        return redirect()->route('student.id-profile')
            ->with('status', 'Email change cancelled. Your email was not changed.');
    }

    /**
     * POST /student/id-profile/link-id — late ID linking (FR-STU-10).
     *
     * Mirrors the registration link step: the client extracts the IDNo and the
     * digit match is re-checked server-side (never trust the client), then the
     * provisional qr_token is replaced with the scanned IDNo.
     */
    public function linkId(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id_number' => ['required', 'string', 'max:30'],
        ], [
            'id_number.required' => 'No ID number was submitted.',
        ]);

        $profile = $request->user()->studentProfile;
        $submittedDigits = preg_replace('/\D/', '', $validated['id_number']);
        $profileDigits = preg_replace('/\D/', '', (string) $profile->student_number);

        if ($submittedDigits !== $profileDigits) {
            return back()->withErrors([
                'id_number' => 'The ID number does not match your registered student number.',
            ]);
        }

        try {
            $profile->update(['qr_token' => $validated['id_number']]);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors([
                'id_number' => 'This ID is already linked to another account. Contact the clinic if this is an error.',
            ]);
        }

        return redirect()->route('student.id-profile')
            ->with('status', 'Your student ID has been linked successfully.');
    }

    // ── Private helpers ──────────────────────────────────────────────────────────

    /** Cache key holding this user's pending email-change OTP entry. */
    private function emailOtpKey(User $user): string
    {
        return 'email_change_otp_'.$user->id;
    }

    /** The new email awaiting confirmation, or null if no change is pending. */
    private function pendingEmailChange(User $user): ?string
    {
        return Cache::get($this->emailOtpKey($user))['new_email'] ?? null;
    }

    /**
     * Generate a 6-digit OTP, cache its SHA-256 hash alongside the pending new
     * email (TTL = OTP_TTL_MINUTES), and mail the plaintext to the new address.
     * The plaintext never touches the cache — only the hash is stored.
     */
    private function issueEmailOtp(User $user, string $firstName, string $newEmail): void
    {
        $otp = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(self::OTP_TTL_MINUTES);

        Cache::put($this->emailOtpKey($user), [
            'hash' => hash('sha256', $otp),
            'attempts' => 0,
            'expires_at' => $expiresAt->toIso8601String(),
            'resend_available_at' => now()->addSeconds(Otp::RESEND_COOLDOWN_SECONDS)->toIso8601String(),
            'new_email' => $newEmail,
        ], $expiresAt);

        Mail::to($newEmail)->send(new OtpVerificationMail($otp, $firstName));
    }

    /** Redirect back to the profile with a visible "change failed" message. */
    private function emailChangeFailed(string $message): RedirectResponse
    {
        return redirect()->route('student.id-profile')->with('error', $message);
    }
}
