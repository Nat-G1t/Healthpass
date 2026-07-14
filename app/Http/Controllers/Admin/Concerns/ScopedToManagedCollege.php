<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\College;

/**
 * The ONE way an admin controller obtains its college (FR-AUTH-06, BR-05).
 *
 * A trait is PHP's way of sharing methods between classes without inheritance;
 * every controller under App\Http\Controllers\Admin uses this one.
 *
 * Rule for all /admin/* features: start every query from this college's
 * relationships — $this->managedCollege()->batchRequests(),
 * ->studentProfiles(), etc. — so the college_id in the SQL always comes from
 * the authenticated session. NEVER accept a college id (or anything derived
 * from one) out of a form or URL under /admin; there is nothing for a
 * tampered request to override because the request is never consulted.
 */
trait ScopedToManagedCollege
{
    protected function managedCollege(): College
    {
        // Non-null is guaranteed by the `college.scope` middleware on the
        // /admin group, which 403s any admin without an assigned college.
        return auth()->user()->managedCollege;
    }
}
