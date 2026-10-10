<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Services\BatchDocumentZip;
use App\Support\BatchCardFilters;
use App\Support\BatchNotifications;
use App\Support\BatchResults;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The clinic's batch pages — nurse and physician alike, like every Clinic
 * Dashboard page (D-64). Every college's batches, so there is NO college
 * scope here.
 *
 *   index()         Upcoming Batches (FR-NRS-12, D-114): APPROVED batches
 *                   with a clinic day today or later — the first day or a
 *                   2nd-chance day, D-110 — soonest first, and the Clinic
 *                   Calendar (D-119)
 *   completed()     Batch Results (FR-NRS-13, D-125): one "Completed batches"
 *                   card — every other approved batch, newest first — with
 *                   the Print Requested / Printed tag and a per-user row dot
 *   results()       an approved batch's results page — the D-111 body every
 *                   role shares, plus the encode page's Print (front / back)
 *                   and PDF buttons on each encoded row, and (D-125) the
 *                   printed-copies banner with Mark as printed
 *   documents()     the selected students' forms (one PDF, or a ZIP for two or
 *                   more — D-118) — D-113's service, and so its one rule for
 *                   which students may be in it
 *   copiesPrinted() D-125's Mark as printed
 *
 * D-125 (O2): the clinic prints and downloads a batch's forms only once the
 * Director has approved a release of that batch — the buttons are drawn
 * disabled before then, and the server refuses them too (here for the
 * download, PrintClearanceController for each form, both through the release
 * rule ClinicVisit::isReleasedForPrint() reads).
 */
class BatchController extends Controller
{
    /**
     * Paged at ten (FR-UI-06) with its own Batch ID search and College
     * dropdown (BatchCardFilters, keys "upcoming_*"), applied on the server
     * as bound parameters.
     *
     * "Today" comes from the server clock and goes into the SQL as a plain
     * date string — no date function in raw SQL, so MySQL and the SQLite test
     * suite agree. whereDate() is written by Laravel for each database.
     */
    public function index(Request $request): View
    {
        $today = today()->toDateString();
        $filters = BatchCardFilters::fromRequest($request, 'upcoming');

        $upcomingBatches = $this->approvedBatches($filters)
            ->where(fn (Builder $query) => $query
                ->whereDate('scheduled_date', '>=', $today)
                ->orWhereHas('appointments', $this->hasClinicDayFrom($today)))
            ->orderBy($this->nextClinicHour($today, 'scheduled_date'))
            ->orderBy($this->nextClinicHour($today, 'scheduled_time'))
            ->orderBy('id')
            ->paginate(config('healthpass.ui.rows_per_page'), pageName: $filters->pageName())
            ->withQueryString();

        return view('nurse.batches.index', [
            'upcomingBatches' => $upcomingBatches,
            'upcomingFilters' => $filters,
            'colleges' => College::orderBy('code')->get(['id', 'code']),
        ]);
    }

    /**
     * D-125 — Batch Results: the "Completed batches" card, D-114's old Past
     * card moved here unchanged — exactly the approved batches Upcoming
     * leaves out, newest clinic date first, under the same "past_*" keys.
     * whereNull() keeps a batch with no date here rather than on neither page.
     *
     * Each row's approved release request comes along in ONE query (the Print
     * Requested / Printed tag), and its dot from BatchNotifications::dots().
     */
    public function completed(Request $request): View
    {
        $today = today()->toDateString();
        $filters = BatchCardFilters::fromRequest($request, 'past');

        $completedQuery = $this->approvedBatches($filters)
            ->where(fn (Builder $query) => $query
                ->whereNull('scheduled_date')
                ->orWhereDate('scheduled_date', '<', $today))
            ->whereDoesntHave('appointments', $this->hasClinicDayFrom($today))
            ->with('approvedReleaseRequest')
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id');

        $completedBatches = $completedQuery->clone()
            ->paginate(config('healthpass.ui.rows_per_page'), pageName: $filters->pageName())
            ->withQueryString();

        return view('nurse.batches.completed', [
            'completedBatches' => $completedBatches,
            'completedFilters' => $filters,
            'colleges' => College::orderBy('code')->get(['id', 'code']),
            'dots' => BatchNotifications::dots($request->user(), $completedBatches->getCollection()),
            // D-131: Previous / Next carry a dot when another page holds one.
            'pagerDots' => BatchNotifications::pagerDots($request->user(), $completedQuery, $completedBatches),
        ]);
    }

    /**
     * An approved batch's results page: the D-111 header (naming the college),
     * three summary cards and the filterable student table, built by the same
     * App\Support\BatchResults as the college's and the Director's pages, so
     * every role sees the same numbers. Anything not approved is a 404.
     *
     * The table stays OUTCOME ONLY (PRD §6.6) — no vitals on this page. An
     * encoded row also carries the two URLs its document buttons use, exactly
     * the encode page's: the clinic's Reprint (FR-NRS-05 — prints in the
     * page's hidden frame and re-stamps printed_at; a Medical Assessment posts
     * side=front / side=back) and Save as PDF (FR-PRT-06). Built here with
     * route(), so the browser never assembles a URL.
     *
     * D-125: opening the batch clears this user's row dot. The printing tools
     * work only once the batch is released; the banner shows a printed-copies
     * request and Mark as printed.
     */
    public function results(Request $request, BatchRequest $batch): View
    {
        abort_unless($batch->status === 'approved', 404);

        $batch->load('college:id,code,name')
            ->load(BatchResults::pageRelations())
            ->load('approvedReleaseRequest.copiesPrinter:id,name');

        BatchNotifications::markSeen($request->user(), $batch, $batch->resultsStatus());

        $results = BatchResults::page($batch);

        $rows = array_map(fn (array $row): array => [
            ...$row,
            'print_url' => $row['visit_id'] === null ? null : route('nurse.visits.print.reprint', $row['visit_id']),
            'pdf_url' => $row['visit_id'] === null ? null : route('nurse.visits.pdf', $row['visit_id']),
        ], $results['rows']);

        return view('nurse.batches.results', [
            'batch' => $batch,
            'results' => [...$results, 'rows' => $rows],
            // The release rule, read off the relation loaded above (any
            // approved request) — the same answer as hasReleasedDocuments().
            'release' => $batch->approvedReleaseRequest,
            'back' => $this->backLink($request),
            'markPrintedUrl' => route('nurse.batches.copies-printed', ['batch' => $batch, ...$this->fromQuery($request)]),
        ]);
    }

    /**
     * D-113's select & download for the clinic — once the Director has
     * released the batch (D-125, O2), else a 403. Which students may be in
     * the ZIP is BatchDocumentZip's one shared rule.
     */
    public function documents(Request $request, BatchRequest $batch, BatchDocumentZip $zip): Response
    {
        abort_unless($batch->status === 'approved', 404);
        abort_unless($batch->hasReleasedDocuments(), 403, ClinicVisit::NOT_RELEASED_HINT);

        return $zip->download($batch, $request->input('visits'));
    }

    /**
     * D-125 — Mark as printed: the clinic has printed the copies the college
     * asked for. Re-checked on the LOCKED release row — lockForUpdate() holds
     * it until the transaction ends, so two presses at once cannot both stamp
     * it: the request must be approved, want copies, and not be marked yet.
     * A second press is a friendly no-op.
     */
    public function copiesPrinted(Request $request, BatchRequest $batch): RedirectResponse
    {
        abort_unless($batch->status === 'approved', 404);

        $outcome = DB::transaction(function () use ($request, $batch): string {
            $release = $batch->approvedReleaseRequest()->lockForUpdate()->first();

            if ($release === null || ! $release->asksClinicToPrint()) {
                return 'refused';
            }

            if ($release->copies_printed_at !== null) {
                return 'already';
            }

            $release->update(['copies_printed_at' => now(), 'copies_printed_by' => $request->user()->id]);

            return 'marked';
        });

        $back = redirect()->route('nurse.batches.results', ['batch' => $batch, ...$this->fromQuery($request)]);

        return match ($outcome) {
            'marked' => $back->with('status', 'Printed copies marked done.'),
            'already' => $back->with('status', 'The printed copies were already marked done.'),
            'refused' => $back->with('error', 'This batch has no approved request for printed copies.'),
        };
    }

    /**
     * Approved batches of every college, narrowed by one card's filters, with
     * what each row shows. statusRelations() is everything resultsStatus()
     * and secondChanceStart() read, so a card costs the same few queries
     * whether a batch has 4 students or 200.
     */
    private function approvedBatches(BatchCardFilters $filters): Builder
    {
        return $filters->apply(BatchRequest::where('status', 'approved'))
            ->with('college:id,code')
            ->with(BatchResults::statusRelations());
    }

    /**
     * D-125 — back to the page View was clicked on: Batch Results when the
     * link said `from=completed`, else Upcoming Batches. Built only from route
     * names — never from a queried URL or url()->previous() — so it cannot be
     * turned into an open redirect (as EncodeController::backLink()).
     *
     * @return array{url: string, label: string}
     */
    private function backLink(Request $request): array
    {
        return $this->fromQuery($request) !== []
            ? ['url' => route('nurse.batch-results'), 'label' => 'Batch Results']
            : ['url' => route('nurse.batches.index'), 'label' => 'Upcoming Batches'];
    }

    /**
     * The whitelisted `from` value, carried through Mark as printed and back.
     *
     * @return array{from?: string}
     */
    private function fromQuery(Request $request): array
    {
        return $request->query('from') === 'completed' ? ['from' => 'completed'] : [];
    }

    /** A batch's appointments on $today or later — its first day or a 2nd-chance day (D-110 books those on the same batch). */
    private function hasClinicDayFrom(string $today): Closure
    {
        return fn (Builder $appointments) => $appointments->whereDate('scheduled_date', '>=', $today);
    }

    /**
     * One column ('scheduled_date' or 'scheduled_time') of the batch's
     * soonest appointment from today on — its first day or its 2nd-chance
     * day. Used as a subquery in ORDER BY, so the DATABASE sorts the whole
     * list and the pager stays right; it is plain SQL in both MySQL and SQLite.
     */
    private function nextClinicHour(string $today, string $column): Builder
    {
        return Appointment::select($column)
            ->whereColumn('appointments.batch_request_id', 'batch_requests.id')
            ->whereDate('scheduled_date', '>=', $today)
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time')
            ->limit(1);
    }
}
