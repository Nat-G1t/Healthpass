<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopedToManagedCollege;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBatchRequestRequest;
use App\Models\BatchRequest;
use App\Models\StudentProfile;
use App\Services\ReferenceNumberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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

    /**
     * Batch Tracking (FR-ADM-05): the college's requests, newest first.
     * Same scoped query shape as the dashboard table.
     */
    public function index(): View
    {
        $college = $this->managedCollege();

        $batchRequests = $college->batchRequests()
            ->withCount('batchRequestStudents')
            ->latest()
            ->get();

        return view('admin.batches.index', compact('college', 'batchRequests'));
    }

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
        $studentUserIds = StudentProfile::whereIn('id', $request->validated('students'))
            ->pluck('user_id');

        // One transaction: the reference number, the batch row and all pivot
        // rows commit together or not at all. generateBatchRef() is called
        // INSIDE so its sequence lock holds until this commit (see the
        // service's concurrency notes).
        $batch = DB::transaction(function () use ($request, $refs, $college, $studentUserIds): BatchRequest {
            $batch = BatchRequest::create([
                'reference_no' => $refs->generateBatchRef(),
                'college_id' => $college->id, // from the session scope — never the request (BR-05)
                'requested_by' => $request->user()->id,
                'reason' => $request->validated('reason'),
                'reason_detail' => $request->validated('reason_detail'),
                'service_type' => $request->validated('service_type'),
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
     */
    public function confirmation(int $batchId): View
    {
        $batch = $this->managedCollege()->batchRequests()
            ->withCount('batchRequestStudents')
            ->findOrFail($batchId);

        return view('admin.batches.confirmation', ['batch' => $batch]);
    }
}
