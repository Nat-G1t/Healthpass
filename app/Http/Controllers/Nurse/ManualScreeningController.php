<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Actions\Nurse\SubmitManualScreening;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nurse\ManualScreeningRequest;
use App\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * D-133 — Manual Screening: when the kiosk is down, the nurse finds one of
 * today's scheduled students who hasn't used the kiosk yet (Q12), types in
 * everything the kiosk would collect (plus the interview for a Medical
 * Assessment, Q11) and sends them to the physician's queue.
 *
 * Nurse only (`role:nurse` on the routes). "Findable" is ONE definition,
 * Appointment::scopeManuallyScreenable(), used by the search, the form page
 * and — under the row lock — the save.
 */
class ManualScreeningController extends Controller
{
    /** The search needs at least this many characters before it queries. */
    public const MIN_SEARCH_LENGTH = 2;

    /** At most this many students are listed for one search. */
    public const MAX_RESULTS = 20;

    /** A longer search box value is cut to this — nobody's name or ID is longer. */
    private const MAX_SEARCH_LENGTH = 60;

    /** GET /nurse/manual-screening — one search box, matches by name or student ID. */
    public function index(Request $request): View
    {
        $search = mb_substr(trim((string) $request->query('q', '')), 0, self::MAX_SEARCH_LENGTH);
        $hasSearched = mb_strlen($search) >= self::MIN_SEARCH_LENGTH;

        return view('nurse.manual-screening.index', [
            'search' => $search,
            'hasSearched' => $hasSearched,
            'results' => $hasSearched ? $this->search($search) : new Collection,
        ]);
    }

    /** GET /nurse/manual-screening/{appointment} — the screening form. */
    public function show(Appointment $appointment): View|RedirectResponse
    {
        if (! $appointment->isManuallyScreenable()) {
            return redirect()->route('nurse.manual.index')
                ->with('error', SubmitManualScreening::refusalFor($appointment));
        }

        $appointment->load(['student.studentProfile.college', 'batchRequest']);

        return view('nurse.manual-screening.show', [
            'appointment' => $appointment,
            'student' => $appointment->student,
            'profile' => $appointment->student->studentProfile,
            'formType' => $appointment->formType(),
            'isFemale' => (bool) $appointment->student->studentProfile?->isFemale(),
        ]);
    }

    /**
     * POST /nurse/manual-screening/{appointment} — Send / Submit to physician.
     * ManualScreeningRequest has validated everything and refused an
     * appointment that is no longer screenable; the action re-checks that
     * under the row lock, so a kiosk visit written a moment ago still wins.
     */
    public function store(ManualScreeningRequest $request, Appointment $appointment, SubmitManualScreening $submit): RedirectResponse
    {
        $visit = $submit->handle($appointment, $request->user(), $request->screeningData(), $request->interviewData());

        if ($visit === null) {
            return redirect()->route('nurse.manual.index')
                ->with('error', SubmitManualScreening::refusalFor($appointment));
        }

        return redirect()->route('nurse.queue')
            ->with('status', "{$appointment->student->name} sent to the physician — {$visit->reference_no}.");
    }

    /**
     * Today's screenable appointments whose student's name or student ID
     * contains $search. whereHas() compiles to an EXISTS subquery, and the
     * pair sits inside one where() group so the orWhere cannot widen the
     * "findable" scope. `%` and `_` typed by the nurse are left as LIKE
     * wildcards rather than escaped (the ESCAPE clause differs between MySQL
     * and SQLite — same call as the Clinic Dashboard search); the term itself
     * is a bound parameter.
     *
     * @return Collection<int, Appointment>
     */
    private function search(string $search): Collection
    {
        $term = '%'.$search.'%';

        return Appointment::query()
            ->manuallyScreenable()
            ->where(fn (Builder $match) => $match
                ->whereHas('student', fn (Builder $student) => $student->where('name', 'like', $term))
                ->orWhereHas('student.studentProfile', fn (Builder $profile) => $profile->where('student_number', 'like', $term)))
            ->with(['student:id,name', 'student.studentProfile:id,user_id,student_number,college_id', 'student.studentProfile.college:id,name', 'batchRequest:id,reference_no,form_type'])
            ->orderBy('scheduled_time')
            ->orderBy('arrival_time')
            ->orderBy('id')
            ->limit(self::MAX_RESULTS)
            ->get();
    }
}
