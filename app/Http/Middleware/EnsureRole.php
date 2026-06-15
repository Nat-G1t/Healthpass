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
        'student'       => '/student/dashboard',
        'college_admin' => '/admin/dashboard',
        'nurse'         => '/nurse/queue',
        'director'      => '/director/dashboard',
    ];

    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        if ($user->role !== $role) {
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
