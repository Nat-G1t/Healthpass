<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopedToManagedCollege;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBatchRequestRequest;
use App\Models\StudentProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * New Batch Request (FR-ADM-02/03, BR-06/07).
 *
 * create() ships the FULL roster of the admin's college to the page in one
 * scoped query (hybrid search: the server owns the scope, the browser owns
 * the per-keystroke filtering — see the view). Only the columns the picker
 * needs are selected, so even a large college is a small payload.
 */
class BatchRequestController extends Controller
{
    use ScopedToManagedCollege;

    public function create(): View
    {
        $college = $this->managedCollege();

        $students = $college->studentProfiles()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'student_number', 'first_name', 'middle_name', 'last_name', 'course', 'year_level'])
            ->map(fn (StudentProfile $s): array => [
                'id' => $s->id,
                'number' => $s->student_number,
                'name' => $s->last_name.', '.$s->first_name
                    .($s->middle_name ? ' '.mb_substr($s->middle_name, 0, 1).'.' : ''),
                'course' => $s->course,
                'year' => $s->year_level,
                // Pre-lowercased haystack so the client filter is a plain
                // includes() — matches name in either order, or the number.
                'search' => mb_strtolower("{$s->first_name} {$s->last_name} {$s->student_number}"),
            ])
            ->values();

        return view('admin.batches.create', compact('college', 'students'));
    }

    public function store(StoreBatchRequestRequest $request): RedirectResponse
    {
        // Validation (BR-06/07 + college-scope check on every student id)
        // has already run in the Form Request by the time we get here.
        //
        // TODO (Day 51): persist batch_requests + batch_request_students in
        // one transaction and mint the BR-YYYY-### reference number.
        return redirect()
            ->route('admin.batches.create')
            ->with('status', 'Your batch request passed validation. Saving is not wired up yet — it lands with tomorrow\'s build.');
    }
}
