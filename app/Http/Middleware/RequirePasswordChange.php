<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-35: forces a seeded staff account to set its own password before it can use
 * the app.
 *
 * Middleware, for anyone new to Laravel, is a filter every request passes
 * through on its way to a controller — the right place for a rule like "no
 * matter which page you asked for, you go here first".
 *
 * Why this exists: staff accounts are seeded, never self-registered
 * (FR-AUTH-05), so on the hosted deploy (D-34) the nurse, Director, and twelve
 * college admins all start with a one-time password somebody else generated.
 * Until it is replaced, that password is effectively a shared secret sitting in
 * a deploy log. The flag is cleared by the existing OTP-confirmed change-password
 * flow (D-20).
 *
 * Students are unaffected: they self-register and choose their own password, so
 * the column stays false for them.
 */
class RequirePasswordChange
{
    /**
     * Routes a flagged user may still reach — otherwise they would be redirected
     * to the change-password page from the change-password page itself (a loop),
     * or be unable to sign out.
     */
    private const ALLOWED_ROUTES = [
        'password.change',
        'password.change.store',
        'password.change.verify',
        'password.change.verify.submit',
        'password.change.verify.resend',
        'password.change.cancel',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs(self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        // A background fetch (the Live Queue poll, availability lookups) must not
        // be answered with an HTML redirect — the caller would try to parse a
        // login page as JSON. Give it an explicit status instead.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'You must change your password before continuing.',
            ], 403);
        }

        return redirect()->route('password.change')
            ->with('error', 'Please set your own password before using HealthPass.');
    }
}
