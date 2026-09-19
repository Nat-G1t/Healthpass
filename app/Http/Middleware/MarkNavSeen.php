<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\NavBadges;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-57: opening a "since last seen" page marks it seen, which clears its
 * sidebar badge (FR-UI-05).
 *
 * Used as ->middleware('nav.seen:student.records'). The part after the colon is
 * a MIDDLEWARE PARAMETER: Laravel passes it to handle() as an extra argument,
 * so one class serves all four pages. It is the page's route name, which is
 * also its key in users.nav_seen_at.
 *
 * ORDER MATTERS. The sidebar renders inside $next(), while the page is being
 * built, so the new stamp must already be on the user before that — otherwise
 * the badge for the very page being opened would still show. It is only SAVED
 * afterwards, and only if the page came back successfully.
 */
class MarkNavSeen
{
    public function handle(Request $request, Closure $next, string $key): Response
    {
        // A typo in routes/web.php fails loudly instead of silently never clearing.
        $role = NavBadges::SEEN_PAGES[$key]
            ?? throw new InvalidArgumentException("[{$key}] is not a badged page — see NavBadges::SEEN_PAGES.");

        $user = $request->user();

        // Only a GET by the role the badge belongs to. The route groups already
        // enforce the role; checking it here keeps the stamp honest if a route
        // is ever moved.
        if ($user === null || $user->role !== $role || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $navSeen = NavBadges::stamped($user, $key);

        // In memory only for now — this is what the sidebar reads while rendering.
        $user->setAttribute('nav_seen_at', $navSeen)->syncOriginalAttribute('nav_seen_at');

        $response = $next($request);

        if ($response->isSuccessful()) {
            NavBadges::store($user, $navSeen);
        }

        return $response;
    }
}
