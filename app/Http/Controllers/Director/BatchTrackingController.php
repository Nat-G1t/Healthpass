<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Actions\CancelBatch;
use App\Exceptions\BatchNotCancellable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Director\CancelBatchRequest;
use App\Models\BatchRequest;
use App\Models\College;
use App\Services\BatchDocumentZip;
use App\Support\BatchCardFilters;
use App\Support\BatchNotifications;
use App\Support\BatchResults;
use App\Support\SignedMemo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
 *              (rejected by the Director, or cancelled by the college — Q20 —
 *              or by the Director — D-129)
 *   results()  an approved batch's results page — the same body the college
 *              sees (D-111), built by the same App\Support\BatchResults
 *   show()     a rejected or cancelled batch's details, students and reason
 *   documents() an approved batch's encoded students' forms (one PDF, or a ZIP for two or more — D-118) —
 *              the Director needs no release request (D-113)
 *   cancel()   D-129: cancel an approved batch before its first hour, with a
 *              reason — the College Admin's own rule and action
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
     *
     * D-130: both cards also filter by MONTH — Batch Results by the clinic
     * date, Rejected and Cancelled by the decision date it sorts on — and
     * Batch Results by form type, status and release request.
     */
    public function index(Request $request): View
    {
        $perPage = config('healthpass.ui.rows_per_page');
        $resultsFilters = BatchCardFilters::fromRequest($request, 'results', ['month', 'form', 'status', 'release']);
        $rejectedFilters = BatchCardFilters::fromRequest($request, 'rejected', ['month']);

        // Card 1: approved, newest clinic date first. statusRelations() is
        // everything resultsStatus() reads, so the card costs the same few
        // queries whether a batch has 4 students or 60.
        $approvedQuery = $resultsFilters->apply(BatchRequest::where('status', 'approved'))
            ->when($resultsFilters->monthBounds(), fn ($query, array $month) => $query
                ->where('scheduled_date', '>=', $month[0])
                ->where('scheduled_date', '<', $month[1]))
            ->with('college:id,code')
            ->with(BatchResults::statusRelations())
            // D-113: the "Release requested" tag — a college is waiting for
            // the Director to review its document release request.
            ->withExists(['releaseRequests as release_requested' => fn ($query) => $query->where('status', 'pending')])
            // D-124: the permanent "Approved Release" tag — the Director has
            // released this batch's documents to its college.
            ->withExists(['releaseRequests as has_approved_release' => fn ($query) => $query->where('status', 'approved')])
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id');

        // D-130: the status is computed, so a status-filtered card is the
        // matching batches themselves, paged by hand — and it is also the
        // list D-131's pager dots read.
        $statusMatches = $resultsFilters->status === ''
            ? null
            : $approvedQuery->get()
                ->filter(fn (BatchRequest $batch) => $batch->resultsStatus() === $resultsFilters->status)
                ->values();

        $approvedBatches = $statusMatches === null
            ? $approvedQuery->clone()->paginate($perPage, pageName: $resultsFilters->pageName())->withQueryString()
            : $this->pageByHand($request, $statusMatches, $resultsFilters, $perPage);

        // Card 2: rejected or cancelled, newest DECISION first — the
        // cancellation for a cancelled batch (even one approved before it was
        // cancelled), the rejection otherwise. COALESCE is the same in MySQL
        // and SQLite, and the SQL is a fixed string with no input in it.
        // D-130: the month filter reads that same decision date, written as
        // plain comparisons so no date function is needed.
        $closedQuery = $rejectedFilters->apply(BatchRequest::whereIn('status', ['rejected', 'cancelled']))
            ->when($rejectedFilters->monthBounds(), fn ($query, array $month) => $query->where(fn ($decided) => $decided
                ->where(fn ($cancelled) => $cancelled
                    ->where('cancelled_at', '>=', $month[0])
                    ->where('cancelled_at', '<', $month[1]))
                ->orWhere(fn ($rejected) => $rejected
                    ->whereNull('cancelled_at')
                    ->where('reviewed_at', '>=', $month[0])
                    ->where('reviewed_at', '<', $month[1]))))
            ->with('college:id,code')
            // D-129: the canceller's role tells "Cancelled by college" from
            // "Cancelled by Director".
            ->with('canceller:id,role')
            ->orderByRaw('COALESCE(cancelled_at, reviewed_at) DESC')
            ->orderByDesc('id');

        $closedBatches = $closedQuery->clone()
            ->paginate($perPage, pageName: $rejectedFilters->pageName())
            ->withQueryString();

        return view('director.tracking.index', [
            'approvedBatches' => $approvedBatches,
            'closedBatches' => $closedBatches,
            // D-120: which rows of both cards carry a notification dot.
            'dots' => BatchNotifications::dots($request->user(), $approvedBatches->getCollection())
                + BatchNotifications::dots($request->user(), $closedBatches->getCollection()),
            // D-131: each card's Previous / Next dot, under the card's filters.
            'resultsPagerDots' => BatchNotifications::pagerDots($request->user(), $statusMatches ?? $approvedQuery, $approvedBatches),
            'rejectedPagerDots' => BatchNotifications::pagerDots($request->user(), $closedQuery, $closedBatches),
            'resultsFilters' => $resultsFilters,
            'rejectedFilters' => $rejectedFilters,
            'colleges' => College::orderBy('code')->get(['id', 'code']),
            // D-130: each card's month dropdown — the months it has a batch in.
            'resultsMonths' => BatchCardFilters::monthOptions(
                BatchRequest::where('status', 'approved')->pluck('scheduled_date'),
            ),
            'rejectedMonths' => BatchCardFilters::monthOptions(
                BatchRequest::whereIn('status', ['rejected', 'cancelled'])->get(['cancelled_at', 'reviewed_at'])
                    ->map(fn (BatchRequest $batch) => $batch->cancelled_at ?? $batch->reviewed_at),
            ),
        ]);
    }

    /**
     * D-130: the Batch Results card filtered by status. resultsStatus() is
     * worked out from every student's appointment, not stored in a column, so
     * SQL cannot filter on it: index() loads the card's batches (already
     * narrowed by the SQL filters) and keeps the picked status in PHP, and
     * this pages that list ourselves. Fine at a clinic's batch counts.
     *
     * @param  Collection<int, BatchRequest>  $matching
     */
    private function pageByHand(Request $request, Collection $matching, BatchCardFilters $filters, int $perPage): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage($filters->pageName());

        return (new LengthAwarePaginator(
            $matching->forPage($page, $perPage)->values(),
            $matching->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'pageName' => $filters->pageName()],
        ))->withQueryString();
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
     * itself, so nothing more is loaded). D-124: an approved request replaces
     * the button with "Documents released on …" — approval is final.
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
            // D-124: once released, the review button gives way to a dated note.
            'approvedRelease' => $batch->releaseRequests->firstWhere('status', 'approved'),
            // Only the review popup shows the memo.
            'memoSize' => $pendingRelease !== null ? SignedMemo::sizeLabel($batch) : null,
            // D-129: the Cancel batch button beside the status — the College
            // Admin's rule (approved, first hour not started, no kiosk visit).
            'canCancel' => $batch->isCancellable(),
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

        $batch->load(['college', 'requester:id,name', 'reviewer:id,name', 'canceller:id,name,role']);

        // Pivot-row order, as on the Review page. notWithdrawn() hides seats
        // pulled before D-87 but keeps a cancelled batch's students (D-92).
        $students = $batch->batchRequestStudents()
            ->notWithdrawn()
            ->with(['student:id,name', 'student.studentProfile:id,user_id,student_number,course,year_level'])
            ->orderBy('id')
            ->get();

        return view('director.tracking.show', compact('batch', 'students'));
    }

    /**
     * D-129: the Director cancels an approved batch, with a written reason
     * (FR-DIRA-12) — on the College Admin's rule and through the College
     * Admin's own action (App\Actions\CancelBatch), so the lock, the
     * isCancellable() re-check, the freed seats and the student emails are
     * one piece of code for both. cancelled_by is the Director.
     *
     * Approved only: a pending batch has Reject on its Review page, so it is
     * a 404 here. (An approved batch can never go back to pending, so checking
     * this before the lock is safe; the lock re-checks everything else.)
     */
    public function cancel(CancelBatchRequest $request, BatchRequest $batch, CancelBatch $cancelBatch): RedirectResponse
    {
        abort_unless($batch->status === 'approved', 404);

        try {
            $count = $cancelBatch->handle($batch, $request->user(), $request->validated('cancellation_reason'));
        } catch (BatchNotCancellable $refusal) {
            // Back to the results page while the batch still has one; a batch
            // its college cancelled meanwhile has none, so Batch Tracking.
            $back = $batch->refresh()->status === 'approved'
                ? route('director.tracking.results', $batch)
                : route('director.tracking.index');

            return redirect($back)->with('error', $refusal->getMessage());
        }

        return redirect()->route('director.tracking.index')->with(
            'status',
            "{$batch->reference_no} has been cancelled. Its {$count} ".Str::plural('appointment', $count)
                .' '.($count === 1 ? 'was' : 'were').' cancelled and each student is being emailed your reason.',
        );
    }
}
