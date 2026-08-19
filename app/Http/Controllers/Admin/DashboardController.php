<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopedToManagedCollege;
use App\Http\Controllers\Controller;
use App\Support\TransferNotice;
use Illuminate\View\View;

/**
 * Admin Dashboard (FR-ADM-01): college-scope banner, four stat cards, and
 * the college's batch request table. Every query hangs off managedCollege()
 * so the scope is enforced server-side (FR-AUTH-06 / FR-ADM-06).
 */
class DashboardController extends Controller
{
    use ScopedToManagedCollege;

    public function __invoke(): View
    {
        $college = $this->managedCollege();

        // One grouped COUNT instead of three separate count queries.
        // Portable SQL only (runs on SQLite in tests, MySQL in dev/prod).
        $batchesByStatus = $college->batchRequests()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $stats = [
            'students' => $college->studentProfiles()->count(),
            'batches' => (int) $batchesByStatus->sum(),
            'pending' => (int) $batchesByStatus->get('pending', 0),
            'approved' => (int) $batchesByStatus->get('approved', 0),
        ];

        $batchRequests = $college->batchRequests()
            ->withCount('batchRequestStudents')
            ->latest()
            ->get();

        // D-50: if the Director moved this admin to another college, say so once,
        // here, on the page they land on after signing in. pull() returns the
        // notice AND clears it, so it cannot reappear on the next visit. Null
        // for the overwhelmingly common case of no pending transfer.
        $transferNotice = TransferNotice::pull(auth()->user());

        return view('admin.dashboard', compact('college', 'stats', 'batchRequests', 'transferNotice'));
    }
}
