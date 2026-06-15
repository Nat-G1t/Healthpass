<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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
        Route::get('/dashboard', fn () => view('student.dashboard'))->name('dashboard');
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
