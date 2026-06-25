<?php

use App\Http\Controllers\Kiosk\KioskController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Student\BookAppointmentController;
use App\Http\Controllers\Student\DashboardController as StudentDashboardController;
use App\Http\Controllers\Student\ProfileController as StudentProfileController;
use App\Http\Controllers\Student\RecordsController as StudentRecordsController;
use App\Http\Middleware\EnsureRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (Auth::check()) {
        return redirect(EnsureRole::dashboardFor(Auth::user()));
    }

    return redirect()->route('login');
});

// ── Profile (any authenticated user) ────────────────────────────────────────

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ── Student (FR-AUTH-03) ─────────────────────────────────────────────────────

Route::middleware(['auth', 'role:student'])
    ->prefix('student')
    ->name('student.')
    ->group(function () {
        Route::get('/dashboard', StudentDashboardController::class)->name('dashboard');
        Route::get('/appointments', [BookAppointmentController::class, 'show'])->name('appointments');
        Route::get('/appointments/availability', [BookAppointmentController::class, 'availability'])->name('appointments.availability');
        Route::post('/appointments', [BookAppointmentController::class, 'store'])->name('appointments.store');
        Route::get('/appointments/{appointment}/confirmed', [BookAppointmentController::class, 'confirmed'])->name('appointments.confirmed');
        Route::delete('/appointments/{appointment}', [BookAppointmentController::class, 'cancel'])->name('appointments.cancel');
        Route::get('/records', StudentRecordsController::class)->name('records');
        Route::get('/id-profile', [StudentProfileController::class, 'show'])->name('id-profile');
        Route::patch('/id-profile', [StudentProfileController::class, 'update'])->name('id-profile.update');
        Route::post('/id-profile/link-id', [StudentProfileController::class, 'linkId'])->name('id-profile.link-id');
        Route::get('/id-profile/verify-email', [StudentProfileController::class, 'showEmailVerification'])->name('id-profile.verify-email');
        Route::post('/id-profile/verify-email', [StudentProfileController::class, 'verifyEmail'])->name('id-profile.verify-email.submit');
        Route::post('/id-profile/verify-email/resend', [StudentProfileController::class, 'resendEmailOtp'])->name('id-profile.verify-email.resend');
        Route::post('/id-profile/verify-email/cancel', [StudentProfileController::class, 'cancelEmailChange'])->name('id-profile.verify-email.cancel');
    });

// ── College Admin (FR-AUTH-03) ───────────────────────────────────────────────

Route::middleware(['auth', 'role:college_admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/dashboard', fn () => view('admin.dashboard'))->name('dashboard');
    });

// ── Nurse (FR-AUTH-03) ───────────────────────────────────────────────────────

Route::middleware(['auth', 'role:nurse'])
    ->prefix('nurse')
    ->name('nurse.')
    ->group(function () {
        Route::get('/queue', fn () => view('nurse.queue'))->name('queue');
    });

// ── Director (FR-AUTH-03) ────────────────────────────────────────────────────

Route::middleware(['auth', 'role:director'])
    ->prefix('director')
    ->name('director.')
    ->group(function () {
        Route::get('/dashboard', fn () => view('director.dashboard'))->name('dashboard');
    });

// ── Kiosk (Module KSK, FR-KSK-01..16) — PUBLIC clinic terminal ───────────────
// No auth: identity is established inside the flow (QR scan / email login),
// not via a logged-in session. On the Pi this is opened full-screen.
Route::prefix('kiosk')->name('kiosk.')->group(function () {
    Route::get('/', [KioskController::class, 'index'])->name('index');
    Route::post('/scan', [KioskController::class, 'scan'])
        ->middleware('throttle:30,1')
        ->name('scan');
    // Email fallback login (FR-KSK-02). Tighter throttle than scan — this is a
    // credential check, so we cap brute-force attempts per kiosk IP.
    Route::post('/login', [KioskController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('login');
    // Final submit (FR-KSK-11 review → stub). The full transactional write of
    // clinic_visits + vital_signs + screening_responses is FR-KSK-12 (a later
    // week); for now this acknowledges the payload so the flow reaches Complete.
    Route::post('/submit', [KioskController::class, 'submit'])
        ->middleware('throttle:20,1')
        ->name('submit');
});

// ── Dev component showcase (local only) ──────────────────────────────────────
if (app()->isLocal()) {
    Route::get('/dev/components', fn () => view('dev.components'))->name('dev.components');
}

require __DIR__.'/auth.php';
