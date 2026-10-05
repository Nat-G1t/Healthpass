<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\College;
use App\Services\BatchDocumentZip;
use App\Support\BatchCardFilters;
use App\Support\BatchResults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The clinic's Upcoming Batches page (FR-NRS-12, D-114) — nurse and physician
 * alike, like every Clinic Dashboard page (D-64). Every college's batches, so
 * there is NO college scope here.
 *
 * Clinic staff already hold the full records, so there is no release request
 * to wait for (the College Admin's D-113 path) and none to review (the
 * Director's): the download tools are always on.
 *
 *   index()     two cards of APPROVED batches: Upcoming (a clinic day today or
 *               later — the first day or a 2nd-chance day, D-110 — soonest
 *               first) and Past (the rest, newest first), each with its own
 *               College dropdown and Batch ID search
 *   results()   an approved batch's results page — the D-111 body every role
 *               shares, plus the encode page's Print (front / back) and PDF
 *               buttons on each encoded row
 *   documents() the selected students' forms as one ZIP — D-113's service,
 *               and so its one rule for which students may be in it
 */
class BatchController extends Controller
{
    /**
     * Both cards page at ten (FR-UI-06), each under its own page parameter so
     * paging one never moves the other. Each card also has its OWN Batch ID
     * search and College dropdown (BatchCardFilters, keys "upcoming_*" and
     * "past_*"), applied on the server as bound parameters.
     *
     * "Today" comes from the server clock and goes into the SQL as a plain
     * date string — no date function in raw SQL, so MySQL and the SQLite test
     * suite agree. whereDate() is written by Laravel for each database.
     */
    public function index(Request $request): View
    {
        $perPage = config('healthpass.ui.rows_per_page');
        $today = today()->toDateString();
        $upcomingFilters = BatchCardFilters::fromRequest($request, 'upcoming');
        $pastFilters = BatchCardFilters::fromRequest($request, 'past');

        // A clinic day today or later. A batch's appointments carry its first
        // day AND any 2nd-chance day (D-110 books those on the same batch).
        $hasClinicDayFromToday = fn (Builder $appointments) => $appointments->whereDate('scheduled_date', '>=', $today);

        // Card 1: Upcoming, soonest clinic hour first.
        $upcomingBatches = $this->approvedBatches($upcomingFilters)
            ->where(fn (Builder $query) => $query
                ->whereDate('scheduled_date', '>=', $today)
                ->orWhereHas('appointments', $hasClinicDayFromToday))
            ->orderBy($this->nextClinicHour($today, 'scheduled_date'))
            ->orderBy($this->nextClinicHour($today, 'scheduled_time'))
            ->orderBy('id')
            ->paginate($perPage, pageName: $upcomingFilters->pageName())
            ->withQueryString();

        // Card 2: Past — exactly the approved batches card 1 leaves out,
        // newest clinic date first. whereNull() keeps a batch with no date
        // here rather than in neither card.
        $pastBatches = $this->approvedBatches($pastFilters)
            ->where(fn (Builder $query) => $query
                ->whereNull('scheduled_date')
                ->orWhereDate('scheduled_date', '<', $today))
            ->whereDoesntHave('appointments', $hasClinicDayFromToday)
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->paginate($perPage, pageName: $pastFilters->pageName())
            ->withQueryString();

        return view('nurse.batches.index', [
            'upcomingBatches' => $upcomingBatches,
            'pastBatches' => $pastBatches,
            'upcomingFilters' => $upcomingFilters,
            'pastFilters' => $pastFilters,
            'colleges' => College::orderBy('code')->get(['id', 'code']),
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
     */
    public function results(BatchRequest $batch): View
    {
        abort_unless($batch->status === 'approved', 404);

        $batch->load('college:id,code,name')->load(BatchResults::pageRelations());

        $results = BatchResults::page($batch);

        $rows = array_map(fn (array $row): array => [
            ...$row,
            'print_url' => $row['visit_id'] === null ? null : route('nurse.visits.print.reprint', $row['visit_id']),
            'pdf_url' => $row['visit_id'] === null ? null : route('nurse.visits.pdf', $row['visit_id']),
        ], $results['rows']);

        return view('nurse.batches.results', [
            'batch' => $batch,
            'results' => [...$results, 'rows' => $rows],
        ]);
    }

    /**
     * D-113's select & download for the clinic — always allowed for an
     * approved batch (the clinic already sees every record, Q22: "what if the
     * college requested to the clinic to print the copies?"). Which students
     * may be in the ZIP is BatchDocumentZip's one shared rule.
     */
    public function documents(Request $request, BatchRequest $batch, BatchDocumentZip $zip): BinaryFileResponse
    {
        abort_unless($batch->status === 'approved', 404);

        return $zip->download($batch, $request->input('visits'));
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
