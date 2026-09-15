<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureCollegeScope;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\KioskAccess;
use App\Http\Middleware\MarkNavSeen;
use App\Http\Middleware\RecordLastActive;
use App\Http\Middleware\RequirePasswordChange;
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
            // D-57: clears a sidebar badge when its page is opened.
            'nav.seen' => MarkNavSeen::class,
        ]);

        // Both run on every web request, appended to the group (not aliased)
        // precisely because it must not be possible to forget them on a route.
        // Order matters: an inactive account is shown the door before anything
        // else, so it is never sent to the change-password screen instead.
        $middleware->web(append: [
            // FR-AUTH-07 (D-47): deactivation takes effect on the NEXT request,
            // not at the next login — an already-open session is ended.
            EnsureAccountIsActive::class,
            // D-35: a seeded staff account cannot reach any page until it has
            // replaced its one-time password.
            RequirePasswordChange::class,
            // D-48: stamps users.last_active_at. LAST, and deliberately so — a
            // request the two gates above turned away is not the account being
            // used, so it must not register as activity.
            RecordLastActive::class,
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
