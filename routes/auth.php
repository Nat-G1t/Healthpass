<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegistrationWizardController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    // ── Registration wizard (FR-REG-01..08) ─────────────────────────────────
    // Step 1: Consent
    Route::get('register', [RegistrationWizardController::class, 'step1'])
        ->name('register');
    Route::post('register/consent', [RegistrationWizardController::class, 'storeConsent'])
        ->name('register.consent');

    // Step 2: Personal Information
    Route::get('register/info', [RegistrationWizardController::class, 'step2'])
        ->name('register.info');
    Route::post('register/info', [RegistrationWizardController::class, 'storeInfo'])
        ->name('register.info.store');

    // Step 3: Email Verify (FR-REG-04 / FR-REG-05 / Decision D-8)
    Route::get('register/verify', [RegistrationWizardController::class, 'step3'])
        ->name('register.verify');
    Route::post('register/verify', [RegistrationWizardController::class, 'verifyOtp'])
        ->name('register.verify.submit');
    Route::post('register/verify/resend', [RegistrationWizardController::class, 'resendOtp'])
        ->name('register.verify.resend');

    // Legacy Breeze route — removed; RegistrationWizardController handles registration.
    // Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    // Step 4: Link ID — accessible after step3 creates + logs in the student (FR-REG-06)
    Route::get('register/link-id', [RegistrationWizardController::class, 'step4'])
        ->name('register.link-id');

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

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
