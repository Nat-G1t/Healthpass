<?php

use App\Http\Controllers\Auth\PasswordChangeController;
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

    // ── Change Password — OTP-confirmed, all four roles ──────────────────────
    // Submitting stages the new hash + emails a code; nothing changes until the
    // code is verified. Throttle prefixes: authed users key rate limits by user
    // id with no path, so each endpoint needs its own bucket (see routes/auth.php).
    Route::get('/password/change', [PasswordChangeController::class, 'show'])
        ->name('password.change');
    // Sends real email — backstop throttle on top of the 60s resend cooldown
    // enforced inside the controller.
    Route::post('/password/change', [PasswordChangeController::class, 'store'])
        ->middleware('throttle:10,1,pwc-store')
        ->name('password.change.store');
    Route::get('/password/change/verify', [PasswordChangeController::class, 'showVerify'])
        ->name('password.change.verify');
    Route::post('/password/change/verify', [PasswordChangeController::class, 'verify'])
        ->middleware('throttle:10,1,pwc-verify')
        ->name('password.change.verify.submit');
    Route::post('/password/change/verify/resend', [PasswordChangeController::class, 'resend'])
        ->middleware('throttle:3,5,pwc-resend')
        ->name('password.change.verify.resend');
    Route::post('/password/change/cancel', [PasswordChangeController::class, 'cancel'])
        ->name('password.change.cancel');
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
        // Kiosk Tutorial (FR-STU-11): static walkthrough, no data to prepare —
        // Route::view renders the Blade view directly, no controller needed.
        Route::view('/tutorial', 'student.tutorial')->name('tutorial');
        Route::get('/id-profile', [StudentProfileController::class, 'show'])->name('id-profile');
        Route::patch('/id-profile', [StudentProfileController::class, 'update'])->name('id-profile.update');
        Route::post('/id-profile/link-id', [StudentProfileController::class, 'linkId'])->name('id-profile.link-id');
        Route::get('/id-profile/verify-email', [StudentProfileController::class, 'showEmailVerification'])->name('id-profile.verify-email');
        // throttle:10,1 caps OTP guesses (per-code 5-attempt cap still applies inside
        // verifyEmail); resend is throttle:3,5 — it sends real email to the new address,
        // so this is the mail-bomb chokepoint (3 per 5 minutes). The 3rd arg is a bucket
        // prefix: for an authed user the rate-limit key is sha1(user id) with no path, so
        // without distinct prefixes submit + resend would share one counter and the tight
        // resend cap would trip on the user's own verify attempts.
        Route::post('/id-profile/verify-email', [StudentProfileController::class, 'verifyEmail'])->middleware('throttle:10,1,idp-verify')->name('id-profile.verify-email.submit');
        Route::post('/id-profile/verify-email/resend', [StudentProfileController::class, 'resendEmailOtp'])->middleware('throttle:3,5,idp-resend')->name('id-profile.verify-email.resend');
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
//
// Auth-less for the person AT the terminal, but the NETWORK is restricted by the
// `kiosk.access` middleware (security audit fix): only the Pi's own loopback or an
// authenticated active nurse may reach these endpoints — everyone else gets 403.
// This stops /kiosk/scan being used from the LAN/internet as a PII oracle against
// guessable QR tokens. Toggle off with HEALTHPASS_KIOSK_RESTRICT=false for LAN dev.
Route::prefix('kiosk')->name('kiosk.')->middleware('kiosk.access')->group(function () {
    Route::get('/', [KioskController::class, 'index'])->name('index');
    // Fresh CSRF token for self-healing (see KioskController@token). A GET, so
    // it needs no token itself; the kiosk calls it to recover from a stale token
    // (page outlived its session) and retry, instead of dead-ending on a 419.
    Route::get('/token', [KioskController::class, 'token'])->name('token');
    Route::post('/scan', [KioskController::class, 'scan'])
        ->middleware('throttle:30,1')
        ->name('scan');
    // Email fallback login (FR-KSK-02). Tighter throttle than scan — this is a
    // credential check, so we cap brute-force attempts per kiosk IP.
    Route::post('/login', [KioskController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('login');
    // Final submit (FR-KSK-12): re-validates server-side and writes the
    // clinic_visits + vital_signs + screening_responses trio in one transaction,
    // returning the minted HP-YYYY-#### for the Complete screen.
    Route::post('/submit', [KioskController::class, 'submit'])
        ->middleware('throttle:20,1')
        ->name('submit');
    // Forget the server-side kiosk identity (kiosk.* session keys). The Alpine
    // reset() calls this on every abandon/finish path so a bound student never
    // lingers into the next session. Same throttle as scan — it is unauthenticated.
    Route::post('/reset', [KioskController::class, 'reset'])
        ->middleware('throttle:30,1')
        ->name('reset');
    // Discreet staff exit (FR-KSK-16): the 5-tap corner gesture opens a prompt
    // for a nurse's credentials; a valid nurse is logged in and the kiosk hands
    // off to the nurse queue. Same tight throttle as login — it is a credential
    // check on a public terminal, so brute-force attempts are capped per IP.
    Route::post('/exit', [KioskController::class, 'exit'])
        ->middleware('throttle:10,1')
        ->name('exit');
});

// ── Dev component showcase (local only) ──────────────────────────────────────
if (app()->isLocal()) {
    Route::get('/dev/components', fn () => view('dev.components'))->name('dev.components');
}

require __DIR__.'/auth.php';
