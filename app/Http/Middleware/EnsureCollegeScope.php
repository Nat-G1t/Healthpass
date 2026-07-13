<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * College-scope gate for every /admin/* route (FR-AUTH-06, FR-ADM-06).
 *
 * Middleware is a filter Laravel runs before the controller. This one sits on
 * the whole admin route group, after `role:college_admin`, and refuses any
 * admin account whose managed_college_id is null — so no admin page can ever
 * run an unscoped query, even for a mis-provisioned account.
 *
 * It deliberately does NOT decide *which* college. That always comes from
 * auth()->user()->managedCollege (see ScopedToManagedCollege), never from
 * request input, so a tampered form field or URL parameter changes nothing.
 */
class EnsureCollegeScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->managed_college_id === null) {
            abort(403, 'Your account has no assigned college. Please contact the clinic.');
        }

        return $next($request);
    }
}
