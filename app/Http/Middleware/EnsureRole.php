<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Maps each role to its home dashboard URL.
     * Update this map when new roles are added.
     */
    private const DASHBOARDS = [
        'student' => '/student/dashboard',
        'college_admin' => '/admin/dashboard',
        // D-44: the dashboard (encode history + stat tiles), not the queue —
        // the queue is still one click away in the sidebar.
        'nurse' => '/nurse/dashboard',
        // D-64: the physician shares the nurse's Clinic Dashboard.
        'physician' => '/nurse/dashboard',
        'director' => '/director/dashboard',
    ];

    /**
     * Middleware parameters: `role:nurse,physician` in a route arrives here as
     * extra arguments after $next — one string per comma-separated value — so
     * `string ...$roles` collects them all into an array (D-64).
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        if (! in_array($user->role, $roles, true)) {
            return redirect(self::dashboardFor($user))
                ->with('error', 'You do not have permission to access that area.');
        }

        return $next($request);
    }

    /**
     * Returns the home dashboard URL for a given user.
     * Falls back to /dashboard if the role is unrecognised.
     */
    public static function dashboardFor(User $user): string
    {
        return self::DASHBOARDS[$user->role] ?? '/dashboard';
    }
}
