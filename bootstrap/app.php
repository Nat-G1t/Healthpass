<?php

use App\Http\Middleware\EnsureCollegeScope;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\KioskAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
            'kiosk.access' => KioskAccess::class,
            'college.scope' => EnsureCollegeScope::class,
        ]);

        // NOTE: trusted proxies (D-34) are NOT configured here. This closure runs
        // BEFORE the config files are loaded, so config() is unavailable and
        // env() is empty once `php artisan config:cache` has run — the proxy list
        // would silently be empty in production. It is set in
        // App\Providers\AppServiceProvider::boot() instead.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
