<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\KioskDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Network gate for the public /kiosk route group (security audit fix, D-27).
 *
 * Middleware is a filter Laravel runs BEFORE the controller — here we use it to
 * decide whether a request is even allowed to reach any /kiosk endpoint. The
 * kiosk stays auth-less for the person standing AT the terminal (identity is
 * established inside the flow via QR scan / email login), but the NETWORK is
 * restricted so /kiosk/scan can't be used from the LAN (or the whole internet)
 * as a PII oracle against guessable QR tokens.
 *
 * A request is allowed only when ONE of these holds:
 *   (a) it carries a valid, un-revoked DEVICE token — a browser a nurse enrolled
 *       via "Enable Kiosk Mode" (FR-NRS-06); the token rides a long-lived cookie,
 *       or is provisioned once via a `?device_token=…` query param (Pi launcher),
 *   (b) it is an authenticated ACTIVE nurse (browsing from a staff machine), OR
 *   (c) it comes from loopback AND config allows loopback (the Pi-local shape).
 * Everyone else gets a friendly branded 403 page (kiosk.restricted).
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

        // Provisioning path: /kiosk?device_token=… (the Pi launcher bakes the
        // token into KIOSK_URL because its incognito Chromium wipes cookies each
        // launch). Validate it, set the cookie, and redirect to the CLEAN URL so
        // the token never lingers in the address bar, history, or referrer.
        if ($this->isTokenProvisioningRequest($request)) {
            $token = (string) $request->query('device_token');

            if (KioskDevice::findActiveByToken($token) !== null) {
                return redirect($request->url())->withCookie($this->deviceCookie($request, $token));
            }
            // Invalid token in the URL — fall through to the checks below (the
            // request may still be an authorized nurse/loopback), else 403.
        }

        if ($this->hasValidDeviceCookie($request)
            || $this->isActiveNurse($request)
            || $this->isAllowedLoopback($request)) {
            return $next($request);
        }

        return $this->deny($request);
    }

    /** A GET carrying a `device_token` query param — the one-time provisioning link. */
    private function isTokenProvisioningRequest(Request $request): bool
    {
        return $request->isMethod('GET') && $request->filled('device_token');
    }

    /** The browser presents an enrolled device token via its (encrypted) cookie. */
    private function hasValidDeviceCookie(Request $request): bool
    {
        $token = $request->cookie(KioskDevice::COOKIE);

        return is_string($token) && KioskDevice::findActiveByToken($token) !== null;
    }

    /** An authenticated, active nurse — the only staff role that may open the kiosk. */
    private function isActiveNurse(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && $user->role === 'nurse' && $user->status === 'active';
    }

    /**
     * The request originates from the machine running the app (the Pi kiosk),
     * AND the deployment allows loopback (false on the hosted internet shape —
     * see config comment on the reverse-proxy spoofing risk).
     */
    private function isAllowedLoopback(Request $request): bool
    {
        return (bool) config('healthpass.kiosk.allow_loopback')
            && in_array($request->ip(), self::LOOPBACK_IPS, true);
    }

    /** Build the long-lived, HttpOnly, SameSite=Lax device cookie (Secure on HTTPS). */
    private function deviceCookie(Request $request, string $token): Cookie
    {
        // cookie(name, value, minutes, path, domain, secure, httpOnly, raw, sameSite)
        return cookie(
            KioskDevice::COOKIE,
            $token,
            KioskDevice::COOKIE_LIFETIME_MINUTES,
            '/',
            null,
            $request->secure(),   // Secure flag only when served over HTTPS
            true,                 // HttpOnly — JS can't read it
            false,
            'lax',
        );
    }

    /**
     * Friendly branded 403. We return a DEDICATED view with a 403 status rather
     * than abort(403) — abort() would render the app-wide errors/403 page (which
     * we don't want to add just for the kiosk). AJAX/JSON callers get JSON so the
     * kiosk front-end never tries to parse an HTML page as a scan result.
     */
    private function deny(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'message' => 'This terminal is restricted to clinic staff.',
            ], 403);
        }

        $user = $request->user();

        return response()->view('kiosk.restricted', [
            // Send non-staff back somewhere useful: their dashboard if signed in,
            // otherwise the login page.
            'backUrl' => $user !== null ? EnsureRole::dashboardFor($user) : route('login'),
            'backLabel' => $user !== null ? 'Back to your dashboard' : 'Go to login',
        ], 403);
    }
}
