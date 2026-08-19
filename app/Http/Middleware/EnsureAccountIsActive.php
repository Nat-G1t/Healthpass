<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-AUTH-07: an inactive account cannot use the app — including one that was
 * already signed in when it was deactivated.
 *
 * Middleware, for anyone new to Laravel, is a filter every request passes
 * through on its way to a controller. This one is appended to the whole web
 * group, so there is no route it can be forgotten on.
 *
 * Why it exists: `LoginRequest::authenticate()` refuses an inactive account at
 * the door, but that check runs exactly once, at login. Nothing re-read
 * `users.status` afterwards, so a session opened while the account was active
 * stayed usable until it expired on its own — up to `SESSION_LIFETIME` (two
 * hours by default). That was invisible while deactivation was a manual database
 * edit; D-47 gave the Director a Deactivate button and made it the system's only
 * form of removal, so "revoked" has to mean revoked on the very next request.
 *
 * Deactivating therefore ends the session rather than merely blocking the next
 * login: log out, flush the session, and issue a fresh CSRF token — the same
 * three steps Breeze's own logout takes.
 */
class EnsureAccountIsActive
{
    /** Shown on the login page. Same wording LoginRequest uses, so the two paths read alike. */
    private const MESSAGE = 'Your account is inactive. Please contact the clinic for assistance.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->status === 'active') {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // A background fetch (the Live Queue poll, availability lookups) must not
        // be answered with an HTML redirect — the caller would try to parse a
        // login page as JSON. Give it an explicit status instead, exactly as
        // RequirePasswordChange does.
        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        // Flashed AFTER invalidate(), so it lands in the new session and survives
        // to the login page.
        return redirect()->route('login')->with('error', self::MESSAGE);
    }
}
