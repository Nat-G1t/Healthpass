<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\PasswordResetOtpController;
use App\Http\Controllers\Auth\RegistrationWizardController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    // NOTE on throttle prefixes: for a guest the rate-limit key is sha1(domain|ip)
    // with NO path component, so several `throttle:x,y` routes on one IP would share
    // ONE counter — the tightest cap (resend=3) would then trip on traffic from the
    // other steps. The 3rd throttle arg is a bucket prefix; a distinct prefix per
    // endpoint gives each its own independent counter (the audit's intent).

    // ── Registration wizard (FR-REG-01..08) ─────────────────────────────────
    // Step 1: Consent
    Route::get('register', [RegistrationWizardController::class, 'step1'])
        ->name('register');
    Route::post('register/consent', [RegistrationWizardController::class, 'storeConsent'])
        ->middleware('throttle:15,1,reg-consent')
        ->name('register.consent');

    // Step 2: Personal Information
    Route::get('register/info', [RegistrationWizardController::class, 'step2'])
        ->name('register.info');
    Route::post('register/info', [RegistrationWizardController::class, 'storeInfo'])
        ->middleware('throttle:15,1,reg-info')
        ->name('register.info.store');

    // Step 3: Email Verify (FR-REG-04 / FR-REG-05 / Decision D-8)
    Route::get('register/verify', [RegistrationWizardController::class, 'step3'])
        ->name('register.verify');
    // throttle:10,1 caps guesses across codes (the per-code 5-attempt cap still
    // applies inside verifyOtp); resend is throttle:3,5 — it sends real email, so
    // this is the mail-bomb chokepoint (3 per 5 minutes).
    Route::post('register/verify', [RegistrationWizardController::class, 'verifyOtp'])
        ->middleware('throttle:10,1,reg-verify')
        ->name('register.verify.submit');
    Route::post('register/verify/resend', [RegistrationWizardController::class, 'resendOtp'])
        ->middleware('throttle:3,5,reg-resend')
        ->name('register.verify.resend');

    // Legacy Breeze route — removed; RegistrationWizardController handles registration.
    // Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // ── Forgot password — OTP flow (replaces Breeze's emailed reset link) ───
    // Route name password.request is kept so the login page link keeps working.
    Route::get('forgot-password', [PasswordResetOtpController::class, 'showEmailForm'])
        ->name('password.request');
    // Sends real email — mail-bomb chokepoint, plus the 60s resend cooldown
    // enforced inside the controller.
    Route::post('forgot-password', [PasswordResetOtpController::class, 'sendOtp'])
        ->middleware('throttle:5,1,forgot-pw')
        ->name('password.email');
    Route::get('forgot-password/verify', [PasswordResetOtpController::class, 'showVerify'])
        ->name('password.reset.verify');
    // throttle:10,1 caps guesses across codes (the per-code 5-attempt cap still
    // applies inside verifyOtp).
    Route::post('forgot-password/verify', [PasswordResetOtpController::class, 'verifyOtp'])
        ->middleware('throttle:10,1,fp-verify')
        ->name('password.reset.verify.submit');
    Route::post('forgot-password/verify/resend', [PasswordResetOtpController::class, 'resendOtp'])
        ->middleware('throttle:3,5,fp-resend')
        ->name('password.reset.verify.resend');
    Route::get('forgot-password/new', [PasswordResetOtpController::class, 'showNewPassword'])
        ->name('password.reset.new');
    Route::post('forgot-password/new', [PasswordResetOtpController::class, 'updatePassword'])
        ->middleware('throttle:10,1,fp-new')
        ->name('password.reset.update');
});

Route::middleware('auth')->group(function () {
    // Step 4: Link ID — accessible after step3 creates + logs in the student (FR-REG-06)
    Route::get('register/link-id', [RegistrationWizardController::class, 'step4'])
        ->name('register.link-id');
    Route::post('register/link-id', [RegistrationWizardController::class, 'linkId'])
        ->name('register.link-id.store');
    Route::post('register/link-id/skip', [RegistrationWizardController::class, 'skipLinkId'])
        ->name('register.link-id.skip');

    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    // Breeze's direct PUT /password was removed: it changed the password with
    // no OTP confirmation, bypassing the Change Password flow in routes/web.php.

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
