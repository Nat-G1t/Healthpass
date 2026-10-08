<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Models\BatchRequest;
use App\Models\College;
use App\Services\BatchDocumentZip;
use App\Support\BatchCardFilters;
use App\Support\BatchNotifications;
use App\Support\BatchResults;
use App\Support\SignedMemo;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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
 *   documents() an approved batch's encoded students' forms (one PDF, or a ZIP for two or more — D-118) —
 *              the Director needs no release request (D-113)
 */
class BatchTrackingController extends Controller
{
    /**
     * Both cards page at ten (FR-UI-06), each under its own page parameter so
     * paging one never moves the other. `id` breaks ties, so no row lands on
     * two pages or on none.
     *
     * D-114: EACH card has its own Batch ID search and College dropdown
     * (BatchCardFilters — keys "results_*" for Batch Results, "rejected_*"
     * for Rejected batches), applied on the server so they find a batch on
     * any page. Every value is a bound parameter.
     */
    public function index(Request $request): View
    {
        $perPage = config('healthpass.ui.rows_per_page');
        $resultsFilters = BatchCardFilters::fromRequest($request, 'results');
        $rejectedFilters = BatchCardFilters::fromRequest($request, 'rejected');

        // Card 1: approved, newest clinic date first. statusRelations() is
        // everything resultsStatus() reads, so the card costs the same few
        // queries whether a batch has 4 students or 60.
        $approvedBatches = $resultsFilters->apply(BatchRequest::where('status', 'approved'))
            ->with('college:id,code')
            ->with(BatchResults::statusRelations())
            // D-113: the "Release requested" tag — a college is waiting for
            // the Director to review its document release request.
            ->withExists(['releaseRequests as release_requested' => fn ($query) => $query->where('status', 'pending')])
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->paginate($perPage, pageName: $resultsFilters->pageName())
            ->withQueryString();

        // Card 2: rejected or cancelled, newest DECISION first — the
        // cancellation for a cancelled batch (even one approved before it was
        // cancelled), the rejection otherwise. COALESCE is the same in MySQL
        // and SQLite, and the SQL is a fixed string with no input in it.
        $closedBatches = $rejectedFilters->apply(BatchRequest::whereIn('status', ['rejected', 'cancelled']))
            ->with('college:id,code')
            ->orderByRaw('COALESCE(cancelled_at, reviewed_at) DESC')
            ->orderByDesc('id')
            ->paginate($perPage, pageName: $rejectedFilters->pageName())
            ->withQueryString();

        return view('director.tracking.index', [
            'approvedBatches' => $approvedBatches,
            'closedBatches' => $closedBatches,
            // D-120: which rows of both cards carry a notification dot.
            'dots' => BatchNotifications::dots($request->user(), $approvedBatches->getCollection())
                + BatchNotifications::dots($request->user(), $closedBatches->getCollection()),
            'resultsFilters' => $resultsFilters,
            'rejectedFilters' => $rejectedFilters,
            'colleges' => College::orderBy('code')->get(['id', 'code']),
        ]);
    }

    /**
     * An approved batch's results page: the D-111 header, three summary cards
     * and the filterable student table, across colleges. Anything not
     * approved has no results, so it is a 404.
     *
     * OUTCOME ONLY (PRD §6.6): BatchResults::page() builds each row from the
     * student's name, number, status and Fit / Unfit, and the eager-loads
     * select nothing else of the clinical record. (An encoded row also
     * carries its visit's id, for D-113's document download.)
     *
     * D-113: the college's document release requests come along too, oldest
     * first — the pending one is what "Review request of documents" opens,
     * and all of them are the small history under the button. D-123: the
     * popup shows the batch's own signed memo (its columns are on $batch
     * itself, so nothing more is loaded).
     */
    public function results(Request $request, BatchRequest $batch): View
    {
        abort_unless($batch->status === 'approved', 404);

        $batch->load('college:id,code,name')
            ->load(BatchResults::pageRelations())
            ->load(['releaseRequests' => fn ($query) => $query->orderBy('id'), 'releaseRequests.requester:id,name']);

        // D-120: opening the batch clears its Batch Results dot.
        BatchNotifications::markSeen($request->user(), $batch, $batch->resultsStatus());

        $pendingRelease = $batch->releaseRequests->firstWhere('status', 'pending');

        return view('director.tracking.results', [
            'batch' => $batch,
            'results' => BatchResults::page($batch),
            'pendingRelease' => $pendingRelease,
            // Only the review popup shows the memo.
            'memoSize' => $pendingRelease !== null ? SignedMemo::sizeLabel($batch) : null,
        ]);
    }

    /**
     * D-113: the Director downloads the selected students' official forms —
     * always allowed for an approved batch, no release request needed (the
     * Director already sees every record). Which students may be in the ZIP is
     * BatchDocumentZip's one shared rule.
     */
    public function documents(Request $request, BatchRequest $batch, BatchDocumentZip $zip): Response
    {
        abort_unless($batch->status === 'approved', 404);

        return $zip->download($batch, $request->input('visits'));
    }

    /**
     * A rejected or college-cancelled batch: what the college sent, its
     * students and the written reason — the Director's own for a rejection,
     * the college's for a cancellation. An approved or pending batch is a 404
     * (those have the results page and the Review page).
     */
    public function show(Request $request, BatchRequest $batch): View
    {
        abort_unless(in_array($batch->status, ['rejected', 'cancelled'], true), 404);

        // D-120: opening a college-cancelled batch clears its dot.
        BatchNotifications::markSeen($request->user(), $batch, $batch->status);

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
