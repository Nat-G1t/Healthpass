<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Network gate for the public /kiosk route group (security audit fix).
 *
 * Middleware is a filter Laravel runs BEFORE the controller — here we use it to
 * decide whether a request is even allowed to reach any /kiosk endpoint. The
 * kiosk stays auth-less for the person standing AT the terminal (identity is
 * established inside the flow via QR scan / email login), but the NETWORK is now
 * restricted so /kiosk/scan can't be used from the campus LAN (or, after
 * deployment, the whole internet) as a PII oracle against guessable QR tokens.
 *
 * A request is allowed only when:
 *   (a) it comes from loopback — the Pi's own Chromium hitting localhost, OR
 *   (b) it is an authenticated ACTIVE nurse (FR-NRS-06 "Enable Kiosk Mode" from
 *       a staff machine later).
 * Everyone else gets 403.
 *
 * The whole check is skipped when healthpass.kiosk.restrict_access is false
 * (env HEALTHPASS_KIOSK_RESTRICT=false), so a LAN dev/test box can opt out
 * explicitly. It is true by default — secure unless someone turns it off.
 */
class KioskAccess
{
    /**
     * Loopback addresses that identify the Pi's own Chromium (localhost). The
     * IPv4-mapped form can appear on dual-stack hosts, so we accept it too.
     */
    private const LOOPBACK_IPS = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];

    public function handle(Request $request, Closure $next): Response
    {
        // Explicit opt-out for local LAN development/testing.
        if (! config('healthpass.kiosk.restrict_access')) {
            return $next($request);
        }

        if ($this->isLoopback($request) || $this->isActiveNurse($request)) {
            return $next($request);
        }

        abort(403);
    }

    /** The request originates from the machine running the app (the Pi kiosk). */
    private function isLoopback(Request $request): bool
    {
        return in_array($request->ip(), self::LOOPBACK_IPS, true);
    }

    /** An authenticated, active nurse — the only staff role that may open the kiosk. */
    private function isActiveNurse(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && $user->role === 'nurse' && $user->status === 'active';
    }
}
