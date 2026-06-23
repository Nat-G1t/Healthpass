<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Student\BookAppointmentController;
use App\Http\Controllers\Student\DashboardController as StudentDashboardController;
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
        Route::get('/records', fn () => view('student.stub', ['page' => 'My Records']))->name('records');
        Route::get('/id-profile', fn () => view('student.stub', ['page' => 'My ID & Profile']))->name('id-profile');
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

// ── Dev component showcase (local only) ──────────────────────────────────────
if (app()->isLocal()) {
    Route::get('/dev/components', fn () => view('dev.components'))->name('dev.components');
}

require __DIR__.'/auth.php';
