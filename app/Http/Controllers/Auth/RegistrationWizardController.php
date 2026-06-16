<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreRegistrationInfoRequest;
use App\Models\College;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Drives the 4-step student self-registration wizard.
 *
 * Step 1 — Consent      GET  /register           (name: register)
 * Step 2 — Info         GET  /register/info       (name: register.info)
 * Step 3 — Verify       GET  /register/verify     (name: register.verify)  [placeholder today]
 * Step 4 — Link ID      (FR-REG-06, future sprint)
 *
 * FR-REG-08: no users row is created until email OTP is verified in Step 3.
 * Steps 1–2 only write to the session.
 */
class RegistrationWizardController extends Controller
{
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

        // Hold consent timestamp in session — written to student_profiles.privacy_consent_at
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
        // Belt-and-suspenders: also checked in FormRequest but consent could have expired.
        if (! $request->session()->has('reg.consent_at')) {
            return redirect()->route('register');
        }

        $data = $request->validated();
        unset($data['password_confirmation']); // don't stage the confirmation copy

        // Stage validated data server-side (FR-REG-08 — no user row yet).
        // Password is stored raw; the User model's 'hashed' cast will bcrypt it on creation.
        $request->session()->put('reg.info', $data);

        return redirect()->route('register.verify');
    }

    // ── Step 3: Email Verify (placeholder — OTP flow in next sprint) ────────

    public function step3(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('reg.info')) {
            return redirect()->route('register.info');
        }

        /** @var array<string,mixed> $info */
        $info = $request->session()->get('reg.info');

        return view('auth.register.step3', ['email' => $info['email']]);
    }
}
