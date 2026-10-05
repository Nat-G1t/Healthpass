<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Models\BatchRequest;
use App\Support\BatchResults;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Director's Batch Tracking page (FR-DIRA-09/10, D-112).
 *
 * Since D-108 a batch leaves the Batch Approvals queue the moment it is
 * decided, so this is where the Director finds it again. Every college's
 * batches — like Batch Approvals there is NO college scope here.
 *
 *   index()    two cards: Batch Results (approved) and Rejected batches
 *              (rejected by the Director, or cancelled by the college — Q20)
 *   results()  an approved batch's results page — the same body the college
 *              sees (D-111), built by the same App\Support\BatchResults
 *   show()     a rejected or cancelled batch's details, students and reason
 */
class BatchTrackingController extends Controller
{
    /**
     * Both cards page at ten (FR-UI-06), each under its own page parameter so
     * paging one never moves the other. `id` breaks ties, so no row lands on
     * two pages or on none.
     *
     * The Batch ID search (`?q=`) filters the Batch Results card only, on the
     * server, so it finds a batch on any page — the same as the College
     * Admin's Batch Results card (FR-ADM-12). The term is a bound parameter.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $perPage = config('healthpass.ui.rows_per_page');

        // Card 1: approved, newest clinic date first. statusRelations() is
        // everything resultsStatus() reads, so the card costs the same few
        // queries whether a batch has 4 students or 60.
        $approvedBatches = BatchRequest::where('status', 'approved')
            ->when($search !== '', fn ($query) => $query->where('reference_no', 'like', '%'.$search.'%'))
            ->with('college:id,code')
            ->with(BatchResults::statusRelations())
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->paginate($perPage, pageName: 'results_page')
            ->withQueryString();

        // Card 2: rejected or cancelled, newest DECISION first — the
        // cancellation for a cancelled batch (even one approved before it was
        // cancelled), the rejection otherwise. COALESCE is the same in MySQL
        // and SQLite, and the SQL is a fixed string with no input in it.
        $closedBatches = BatchRequest::whereIn('status', ['rejected', 'cancelled'])
            ->with('college:id,code')
            ->orderByRaw('COALESCE(cancelled_at, reviewed_at) DESC')
            ->orderByDesc('id')
            ->paginate($perPage, pageName: 'rejected_page')
            ->withQueryString();

        return view('director.tracking.index', compact('approvedBatches', 'closedBatches', 'search'));
    }

    /**
     * An approved batch's results page: the D-111 header, three summary cards
     * and the filterable student table, across colleges. Anything not
     * approved has no results, so it is a 404.
     *
     * OUTCOME ONLY (PRD §6.6): BatchResults::page() builds each row from the
     * student's name, number, status and Fit / Unfit, and the eager-loads
     * select nothing else of the clinical record.
     */
    public function results(BatchRequest $batch): View
    {
        abort_unless($batch->status === 'approved', 404);

        $batch->load('college:id,code,name')->load(BatchResults::pageRelations());

        return view('director.tracking.results', [
            'batch' => $batch,
            'results' => BatchResults::page($batch),
        ]);
    }

    /**
     * A rejected or college-cancelled batch: what the college sent, its
     * students and the written reason — the Director's own for a rejection,
     * the college's for a cancellation. An approved or pending batch is a 404
     * (those have the results page and the Review page).
     */
    public function show(BatchRequest $batch): View
    {
        abort_unless(in_array($batch->status, ['rejected', 'cancelled'], true), 404);

        $batch->load(['college', 'requester:id,name', 'reviewer:id,name', 'canceller:id,name']);

        // Pivot-row order, as on the Review page. notWithdrawn() hides seats
        // pulled before D-87 but keeps a cancelled batch's students (D-92).
        $students = $batch->batchRequestStudents()
            ->notWithdrawn()
            ->with(['student:id,name', 'student.studentProfile:id,user_id,student_number,course,year_level'])
            ->orderBy('id')
            ->get();

        return view('director.tracking.show', compact('batch', 'students'));
    }
}
