<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Parses the TRUSTED_PROXIES env value into the list Laravel's proxy trust
 * expects (D-34, hosted internet deploy).
 *
 * Background for anyone new to this: on the hosted shape a **reverse proxy**
 * (nginx, a load balancer, Cloudflare) sits in front of the app, so PHP sees
 * every request arriving from the PROXY's IP address. The visitor's real IP and
 * the original `https` scheme travel in `X-Forwarded-For` / `X-Forwarded-Proto`
 * headers. Laravel ignores those headers unless it is told which proxies to
 * trust — because any visitor can send them, and trusting them blindly lets a
 * visitor claim to be any IP they like.
 *
 * Why HealthPass cares specifically:
 *  - Every per-IP throttle (registration OTP, kiosk scan/login/submit) would
 *    otherwise share ONE bucket keyed to the proxy's IP — one user hitting a
 *    limit would lock out the whole campus.
 *  - Without `X-Forwarded-Proto`, Laravel builds `http://` links on an `https://`
 *    site, breaking email-verification and OTP links.
 *  - `KioskAccess` loopback trust reads `$request->ip()`; a proxy on the same
 *    box makes every visitor look like `127.0.0.1` (see config/healthpass.php).
 */
final class TrustedProxies
{
    /**
     * Turn a comma-separated proxy list into an array of IPs/CIDR ranges.
     *
     * Returns an empty array when unset/blank, which means "trust nothing" —
     * the correct default for the Pi-local shape, where there is no proxy.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException when the wildcard '*' is used.
     */
    public static function parse(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $proxies = array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $proxy): bool => $proxy !== ''
        ));

        // Fail fast and loudly rather than deploy a spoofable app. '*' trusts
        // EVERY client's X-Forwarded-For, so a visitor could claim to be
        // 127.0.0.1 and — with loopback trust on — walk straight into /kiosk.
        if (in_array('*', $proxies, true)) {
            throw new InvalidArgumentException(
                'TRUSTED_PROXIES must list the real proxy IP addresses, never "*". '
                .'A wildcard lets any visitor forge X-Forwarded-For and spoof their IP. '
                .'See docs/deployment-hosted.md.'
            );
        }

        return $proxies;
    }
}
