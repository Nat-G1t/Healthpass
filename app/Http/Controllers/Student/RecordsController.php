<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\ClinicVisit;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FR-STU-07 — My Records: the list of the student's clinic visits, each
 * encoded one opening its own RECORD PAGE (D-73 — the old detail modal is
 * gone): the vitals the clinic confirmed, the official form's twelve Physical
 * Signs rows and, on a Medical Assessment Form visit, that form's own
 * sections.
 *
 * D-106 — the student reads the record here but cannot download the official
 * document: the Save as PDF action (FR-STU-15) is removed. The document stays
 * with the clinic and the Director.
 *
 * FR-STU-08 — nothing clinical before encoding: only an `encoded` visit has a
 * page at all. A captured or resting one is a 404, exactly like a visit
 * belonging to somebody else.
 */
class RecordsController extends Controller
{
    public function index(Request $request): View
    {
        // Ownership is implicit: scoped through the authenticated user's relation,
        // so no cross-student leakage is possible.
        $visits = $request->user()->clinicVisits()
            // D-72: a resting visit has not been submitted to the clinic yet,
            // so the student sees nothing for it - not even that it exists.
            ->submitted()
            ->with([
                // D-62: batchRequest carries the form type, which names the
                // official form on every row.
                'appointment:id,service_type,batch_request_id',
                'appointment.batchRequest:id,form_type',
                'clearanceRecord',
            ])
            ->latest()
            // FR-UI-06: ten per page; id breaks created_at ties.
            ->orderByDesc('id')
            ->paginate(config('healthpass.ui.rows_per_page'))
            ->withQueryString();

        return view('student.records', compact('visits'));
    }

    /**
     * One visit's full record (D-73). Everything is read-only; the page is
     * rendered from the SAME rows the official document prints, so a student
     * reading the screen and a clinic reading the paper see one record.
     */
    public function show(Request $request, string $visit): View
    {
        $visit = $this->ownedEncodedVisit($request, $visit);

        $visit->load([
            'student.studentProfile',
            'appointment.batchRequest',
            'vitalSigns',
            'screeningResponse',
            // D-64: "Encoded by <name> (<role>)". D-69: the Medical Assessment
            // Form's own sections, absent on a Medical Clearance record.
            'clearanceRecord.encoder',
            'clearanceRecord.medicalAssessment',
        ]);

        return view('student.record', compact('visit'));
    }

    /**
     * The ownership rule the record page obeys.
     *
     * The lookup runs THROUGH the signed-in student's own relation, so another
     * student's visit id simply is not found — a 404, never a 403. A 403 would
     * confirm the id exists and belongs to someone; a 404 says nothing at all.
     *
     * `findOrFail()` is Eloquent's "fetch by id or 404". Route-model binding is
     * deliberately not used here: it would look the visit up globally first,
     * and the point is that this student's records are the only table there is.
     */
    private function ownedEncodedVisit(Request $request, string $visitId): ClinicVisit
    {
        return $request->user()->clinicVisits()
            // FR-STU-08: captured (awaiting the clinic) and resting (D-72)
            // visits have no record to show.
            ->where('status', 'encoded')
            ->findOrFail($visitId);
    }
}
