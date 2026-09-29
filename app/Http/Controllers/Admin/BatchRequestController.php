<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopedToManagedCollege;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBatchRequestRequest;
use App\Models\BatchRequest;
use App\Models\BatchRequestStudent;
use App\Models\StudentProfile;
use App\Services\BatchConflictService;
use App\Services\ClinicScheduleService;
use App\Services\ReferenceNumberService;
use App\Services\ScheduleClashService;
use App\Support\AssessmentDocument;
use App\Support\ClearanceDocument;
use App\Support\Programs;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Batch Requests: New Batch form, submission, confirmation and Tracking
 * (FR-ADM-02/03/04/05, BR-05/06/07).
 *
 * create() ships the FULL roster of the admin's college to the page in one
 * scoped query (hybrid search: the server owns the scope, the browser owns
 * the per-keystroke filtering — see the view). Only the columns the picker
 * needs are selected, so even a large college is a small payload.
 */
class BatchRequestController extends Controller
{
    use ScopedToManagedCollege;

    public function __construct(
        private readonly ClinicScheduleService $schedule,
        private readonly ScheduleClashService $clashes,
        private readonly BatchConflictService $conflicts,
    ) {}

    /**
     * Batch Tracking (FR-ADM-05): the college's requests, newest first.
     * Same scoped query shape as the dashboard table.
     *
     * `reviewer` is eager-loaded for the D-36 rejection-reason modal (who
     * rejected it); with() means one extra query for the whole list instead
     * of one per rejected row.
     *
     * Above that list sits the Batch Results card (FR-ADM-12, D-55): every
     * APPROVED batch, newest clinic date first, from its own query. It
     * eager-loads everything Appointment::clearanceProgress() reads, so the
     * page costs the same handful of queries whether a batch has 4 students or
     * 60. The popup rows are built here, per batch, by resultsPopup().
     *
     * D-86: the Batch Results card has its own Batch ID search (`?q=`). It is
     * applied on the SERVER, so it finds a batch on any page, not just the ten
     * on screen. `%` and `_` typed into it are left as LIKE wildcards, exactly
     * as on the nurse dashboard's search; the term itself is a bound parameter.
     *
     * D-87: students whose appointment was withdrawn under the removed
     * per-student withdrawal are left out of the counts and the popup
     * (BatchRequestStudent::scopeNotWithdrawn()).
     */
    public function index(Request $request): View
    {
        $college = $this->managedCollege();
        $search = trim((string) $request->query('q', ''));

        // FR-UI-06: ten per page. `id` breaks ties between batches submitted
        // in the same second, so no row can land on two pages or on none.
        $batchRequests = $college->batchRequests()
            ->with('reviewer:id,name')
            ->withCount(['batchRequestStudents' => fn ($query) => $query->notWithdrawn()])
            ->latest()
            ->orderByDesc('id')
            ->paginate(config('healthpass.ui.rows_per_page'))
            ->withQueryString();

        $approvedBatches = $college->batchRequests()
            ->where('status', 'approved')
            ->when($search !== '', fn ($query) => $query->where('reference_no', 'like', '%'.$search.'%'))
            ->with([
                'batchRequestStudents' => fn ($query) => $query->notWithdrawn()->orderBy('id'),
                'batchRequestStudents.student:id,name',
                'batchRequestStudents.student.studentProfile:id,user_id,student_number',
                'batchRequestStudents.appointment',
                // Only the columns the status and completion rules read. The
                // rest of the clinical record (notes, physician) is never even
                // loaded for this page.
                'batchRequestStudents.appointment.clinicVisit:id,appointment_id',
                'batchRequestStudents.appointment.clinicVisit.clearanceRecord:id,clinic_visit_id,result,encoded_at',
            ])
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            // FR-UI-06: ten per page. Its own page name, because the requests
            // list below pages on this same URL — paging one card must not
            // move the other.
            ->paginate(config('healthpass.ui.rows_per_page'), pageName: 'results_page')
            ->withQueryString();

        // Keyed by batch id, so each row's View button picks its own payload.
        // Built for the current page only — the rows on screen.
        $resultPopups = $approvedBatches
            ->mapWithKeys(fn (BatchRequest $batch): array => [$batch->id => $this->resultsPopup($batch)])
            ->all();

        // The card (and so its search box) shows whenever the college has ANY
        // approved batch — not only when the search found one — so a search
        // with no match can still be changed or cleared.
        $hasApprovedBatches = $search === ''
            ? $approvedBatches->total() > 0
            : $college->batchRequests()->where('status', 'approved')->exists();

        return view('admin.batches.index', compact(
            'college', 'batchRequests', 'approvedBatches', 'resultPopups', 'hasApprovedBatches', 'search',
        ));
    }

    /**
     * One batch's Batch Results popup (FR-ADM-12, D-55), as the plain array the
     * view embeds with Js::from().
     *
     * OUTCOME ONLY (PRD §6.6): each row is the student's name, number, hour,
     * the clearanceProgress() key and Fit/Unfit — nothing else. No vitals,
     * screening answers, nurse notes, physician details or clinic-visit
     * reference go into it, so none of them can reach the page source. The view
     * owns the wording of each status key.
     *
     * @return array{ref: string, form: string, date: string, span: string, students: list<array{name: string, number: string, hour: string, status: ?string, result: ?string}>}
     */
    private function resultsPopup(BatchRequest $batch): array
    {
        return [
            'ref' => $batch->reference_no,
            'form' => $batch->formTypeLabel(),   // D-62
            'date' => $batch->scheduled_date?->format('l, F j, Y') ?? '—',
            'span' => $batch->requestedSpanLabel(),
            'students' => $batch->batchRequestStudents
                ->map(fn (BatchRequestStudent $row): array => [
                    'name' => $row->student?->name ?? 'Unknown student',
                    'number' => $row->student?->studentProfile?->student_number ?? '—',
                    'hour' => $row->appointment?->timeRangeLabel() ?? '—',
                    'status' => $row->appointment?->clearanceProgress(),
                    'result' => $row->appointment?->clearanceResult(),
                ])
                ->values()
                ->all(),
        ];
    }

    public function create(): View
    {
        $college = $this->managedCollege();

        // Only students whose account is active: a deactivated (e.g.
        // graduated) student cannot be put on a batch — the Form Request
        // refuses them too.
        $students = $college->studentProfiles()
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
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

        return view('admin.batches.create', [
            'college' => $college,
            'students' => $students,
            // FR-ADM-03: the Program filter lists the college's WHOLE catalog
            // (the same list registration uses), not just programs that
            // already have students — so an empty program can be chosen too.
            'programs' => Programs::forCollege($college->id),
            // D-37: the start-hour options and the numbers the live span
            // preview needs — all derived from config, never typed in the view.
            'slots' => array_map(fn (string $slot): array => [
                'value' => $slot,
                'label' => $this->schedule->label($slot),
            ], $this->schedule->slots()),
            'hourlyCapacity' => $this->schedule->hourlyCapacity(),
            'maxBatchSize' => $this->schedule->maxBatchSize(),
            // BR-23: hours that have already ended TODAY, computed on the
            // SERVER clock. The browser only compares the picked date against
            // `today` — it never decides what "now" is (same rule as BR-20).
            'today' => today()->toDateString(),
            'elapsedSlotsToday' => $this->schedule->elapsedSlots(today()->toDateString()),
            // D-54: the mini calendar's first month and its greyed-out days —
            // the same rules, from the same service, as the student calendar.
            'year' => today()->year,
            'month' => today()->month,
            'fullDays' => $this->schedule->fullDaysForMonth(today()->year, today()->month),
            'cutoffDays' => $this->schedule->cutoffDaysForMonth(today()->year, today()->month),
            'bookingDays' => config('healthpass.booking_days'),
        ]);
    }

    /**
     * JSON for the New Batch mini calendar (FR-ADM-04, D-54): the month's FULL
     * days and the BR-20 cutoff day, fetched when the admin changes month.
     *
     * Exactly the two lists the student booking calendar gets, from the same
     * ClinicScheduleService methods, so the two calendars can never disagree
     * about which days are open. It reads no college data, so there is nothing
     * here to scope.
     */
    public function availability(Request $request): JsonResponse
    {
        $year = max(now()->year, min((int) $request->query('year', now()->year), now()->year + 2));
        $month = max(1, min((int) $request->query('month', now()->month), 12));

        return response()->json([
            'full_days' => $this->schedule->fullDaysForMonth($year, $month),
            'cutoff_days' => $this->schedule->cutoffDaysForMonth($year, $month),
        ]);
    }

    /**
     * Card 1's form tiles (D-62): the official form's front page, blank, as a
     * full HTML document for the tile's <iframe>. It renders the SAME template
     * the clinic prints, so the preview can never drift from the real paper.
     *
     * The route only matches the two form types; the default arm is a second
     * guard so an unknown type is a 404, never a fallback form.
     */
    public function formPreview(string $formType): View
    {
        return match ($formType) {
            'clearance' => view('forms.medical-clearance', ClearanceDocument::blank()),
            'assessment' => view('forms.medical-assessment', AssessmentDocument::blank('front')),
            default => abort(404),
        };
    }

    /**
     * Persist a validated batch (FR-ADM-04): one batch_requests row plus one
     * batch_request_students row per student, atomically. Validation (BR-06/07
     * + the college-scope check on EVERY student id) has already run in the
     * Form Request by the time we get here.
     */
    public function store(StoreBatchRequestRequest $request, ReferenceNumberService $refs): RedirectResponse
    {
        $college = $this->managedCollege();

        // The form posts student_profile ids (that's what the roster picker
        // knows), but the pivot stores USER ids (data dictionary:
        // batch_request_students.student_id → users) — translate here.
        // Keyed user id => profile id: a D-54 clash is found by user id but
        // reported back to the form under the profile id it posted.
        $profileIdsByUserId = StudentProfile::whereIn('id', $request->validated('students'))
            ->pluck('id', 'user_id');
        $studentUserIds = $profileIdsByUserId->keys();

        // D-37: the span is DERIVED from the roster size, never posted.
        $startSlot = $request->validated('requested_time');
        $requestedDate = $request->validated('requested_date');
        $blocks = $this->schedule->blocksFor($studentUserIds->count());

        // One transaction: the reference number, the batch row and all pivot
        // rows commit together or not at all. generateBatchRef() is called
        // INSIDE so its sequence lock holds until this commit (see the
        // service's concurrency notes).
        $batch = DB::transaction(function () use ($request, $refs, $college, $studentUserIds, $profileIdsByUserId, $startSlot, $requestedDate, $blocks): BatchRequest {
            // The Form Request already checked that every hour in the span has
            // room, but that read is unlocked and races with another batch
            // being approved into the last seats of one of those hours.
            // Re-check HERE under a row lock.
            $span = $this->schedule->span($startSlot, $blocks);
            $fullSlots = $this->schedule->fullSlotsIn($requestedDate, $span, $studentUserIds->count(), lock: true);

            if ($fullSlots !== []) {
                throw ValidationException::withMessages([
                    'requested_time' => sprintf(
                        'The %s slot no longer has room for this batch — it filled up while you were submitting. Please choose a different start time or date.',
                        $this->schedule->label($fullSlots[0]),
                    ),
                ]);
            }

            // D-54 / D-77 / BR-25: the same race for the clash rule — another batch
            // holding one of these students may be submitted between the Form
            // Request's read and this insert. Re-read under the lock, and
            // report it with the same `clashes.*` keys, so the New Batch popup
            // opens either way.
            $clashes = $this->clashes->clashesForBatch($studentUserIds->all(), $requestedDate, lock: true);

            if ($clashes !== []) {
                throw ValidationException::withMessages(
                    StoreBatchRequestRequest::clashErrors($clashes, $profileIdsByUserId->all()),
                );
            }

            $batch = BatchRequest::create([
                'reference_no' => $refs->generateBatchRef(),
                'college_id' => $college->id, // from the session scope — never the request (BR-05)
                'requested_by' => $request->user()->id,
                'form_type' => $request->validated('form_type'),   // D-62
                'reason' => $request->validated('reason'),
                'reason_detail' => $request->validated('reason_detail'),
                // D-60: dental is gone. The server always writes 'medical' and
                // never reads a service type from the request body.
                'service_type' => 'medical',
                'requested_date' => $requestedDate,   // D-29
                'requested_time' => $startSlot,        // D-37 span start
                'requested_blocks' => $blocks,         // D-37 span length, in hours
                'status' => 'pending',
            ]);

            $batch->batchRequestStudents()->createMany(
                $studentUserIds->map(fn ($userId): array => ['student_id' => $userId])->all(),
            );

            return $batch;
        });

        return redirect()->route('admin.batches.confirmation', $batch);
    }

    /**
     * Post-submit confirmation screen (FR-ADM-04). Fetched through the
     * managed college's relationship, so another college's batch id is a
     * plain 404 (FR-ADM-06) — ids can't be enumerated across colleges.
     *
     * D-88: it also lists the students on the batch (the same roster query as
     * the Batch Roster page) and offers the D-52 Cancel while the batch is
     * pending, so the admin can check everything they sent and withdraw it on
     * the spot if something is wrong.
     *
     * D-90: while pending, a heads-up when another request for the same hours
     * was submitted FIRST and the clinic can't fit both — if the Director
     * approves that one first, this one will be refused. It never says whose
     * request it is; `freeStarts` are the start hours that would still fit, so
     * the admin can cancel and resubmit for one of them.
     */
    public function confirmation(int $batchId): View
    {
        $batch = $this->managedCollege()->batchRequests()
            ->withCount('batchRequestStudents')
            ->findOrFail($batchId);

        $earlier = $this->conflicts->conflictsWith($batch)
            ->filter(fn (BatchRequest $other): bool => $other->submittedBefore($batch))
            ->values();

        $freeStarts = $earlier->isEmpty() ? null : array_map(
            fn (string $slot): string => $this->schedule->startLabel($slot),
            $this->conflicts->freeStartsAfter($batch, $earlier),
        );

        return view('admin.batches.confirmation', [
            'batch' => $batch,
            'rows' => $this->rosterPage($batch),
            'freeStarts' => $freeStarts,
        ]);
    }

    /**
     * One page of a batch's roster (FR-UI-06): ten per page, in a stable order
     * (id is unique), with each student's generated appointment eager-loaded —
     * a page is 4 queries whatever the batch size. Shared by the Batch Roster
     * and the Submitted page (D-88). D-87 hides the students an earlier
     * withdrawal removed.
     */
    private function rosterPage(BatchRequest $batch): LengthAwarePaginator
    {
        return $batch->batchRequestStudents()
            ->notWithdrawn()
            ->with([
                'student:id,name',
                'student.studentProfile:id,user_id,student_number,course,year_level',
                'appointment',
            ])
            ->orderBy('id')
            ->paginate(config('healthpass.ui.rows_per_page'))
            ->withQueryString();
    }

    /**
     * Batch roster (FR-ADM-07, D-40): who is in this batch and — once the
     * Director has approved it — the appointment each student was given.
     *
     * Batch Tracking (index) has only ever shown a student COUNT, so before
     * D-40 there was nowhere in the app to see the roster at all, let alone act
     * on one row of it. This was that page, and it was where the admin withdrew
     * a single student's appointment — until D-87 removed that action.
     *
     * D-53 put each student's progress and Fit/Unfit result here as well; D-55
     * moved both to the Batch Results popup on Batch Tracking (FR-ADM-12), so
     * this page is back to who is booked and when — and no longer loads any
     * part of the clinical record. D-87 hides the students an earlier
     * withdrawal removed (BatchRequestStudent::scopeNotWithdrawn()).
     *
     * Scoped the same way as confirmation(): fetched through the managed
     * college's relationship, so another college's batch id 404s (FR-ADM-06).
     */
    public function show(int $batchId): View
    {
        $batch = $this->managedCollege()->batchRequests()
            ->with([
                'reviewer:id,name',
                // D-52: who cancelled it, for the cancelled-batch notice.
                'canceller:id,name',
            ])
            ->findOrFail($batchId);

        // The page header and summary describe the WHOLE roster, never just
        // the page on screen, so the count is its own query.
        return view('admin.batches.show', [
            'batch' => $batch,
            'rows' => $this->rosterPage($batch),
            'totalCount' => $batch->batchRequestStudents()->notWithdrawn()->count(),
        ]);
    }

    /**
     * Cancel a whole PENDING batch request (FR-ADM-11, D-52).
     *
     * A college submits a batch and then the cohort's event moves, or the
     * roster turns out wrong. Before D-52 the only way out was to ask the
     * Director to REJECT it — which left a rejection on the college's record
     * for something the college itself wanted withdrawn. This is that
     * withdrawal, and it reads as the college's own action on Batch Tracking
     * and on the Activity Log alike.
     *
     * PENDING ONLY, and deliberately so. Approval fans out one appointment per
     * student and emails every one of them (BR-08, FR-STU-12). A whole-batch
     * cancel after approval would be a silent mass-cancellation, so it is not
     * offered — and since D-87 neither is a per-student one.
     *
     * Guards, all resolved server-side:
     *   - the batch is fetched through the managed college, so another
     *     college's id is a plain 404 (FR-ADM-06)
     *   - BatchRequest::isCancellable() — the SAME rule the page used to decide
     *     whether to draw the button — is re-read under a ROW LOCK, so a
     *     double-click, or a race with the Director approving it in the next
     *     tab, cannot slip past the unlocked read the page did
     *
     * Nothing else has to be unwound: no appointments exist yet, so no clinic
     * seat is held and no student has been told anything.
     */
    public function cancel(Request $request, int $batchId): RedirectResponse
    {
        $batch = $this->managedCollege()->batchRequests()->findOrFail($batchId);

        $wasCancelled = DB::transaction(function () use ($batch, $request): bool {
            $locked = BatchRequest::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isCancellable()) {
                return false;
            }

            // cancelled_by is the admin who PRESSED THE BUTTON, not the batch's
            // original requester — a college can have more than one admin
            // (D-47), and the Activity Log names the person who acted.
            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
            ]);

            return true;
        });

        if (! $wasCancelled) {
            return redirect()->route('admin.batches.index')->with(
                'error',
                "{$batch->reference_no} can no longer be cancelled — the Clinic Director has already decided on it.",
            );
        }

        return redirect()->route('admin.batches.index')->with(
            'status',
            "{$batch->reference_no} has been cancelled. It is no longer waiting for the Clinic Director.",
        );
    }
}
