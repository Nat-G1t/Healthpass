<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-STU-07 — My Records page: the LIST of the student's clinic visits, each
 *             encoded one linking to its own record page (D-73 — the detail
 *             modal and its embedded record JSON are gone).
 * FR-STU-08 — Fit/Unfit determination is hidden until the clinic encodes the
 *             visit. Captured (pending) visits show "Pending" and have no View.
 */
class RecordsPageTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────────

    private function student(): User
    {
        return User::factory()->create(['role' => 'student']);
    }

    private function nurse(): User
    {
        return User::factory()->create(['role' => 'nurse']);
    }

    /**
     * Creates a captured clinic visit with required vitals + screening rows.
     * No clearance record → shows "Pending" on the records page.
     */
    private function makeCapturedVisit(User $student, string $refNo = 'HP-2026-T001'): ClinicVisit
    {
        $visit = ClinicVisit::create([
            'reference_no' => $refNo,
            'student_id' => $student->id,
            // Capture-time college snapshot (FR-STU-09) — required on every visit.
            'college_id' => College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies'])->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => now(),
            'checked_in_at' => now(),
        ]);

        VitalSigns::create([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 165.0,
            'weight_kg' => 60.0,
            'bmi' => 22.0,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 115,
            'bp_diastolic' => 75,
            'entry_method' => 'manual',
        ]);

        ScreeningResponse::create([
            'clinic_visit_id' => $visit->id,
            'skin' => false,
            'head' => false,
            'eyes' => false,
            'ears' => false,
            'nose' => false,
            'throat' => false,
            'chest_lungs' => false,
            'heart' => false,
            'abdomen' => false,
            'kidney_bladder' => false,
            'brain' => false,
            'mental_disorder' => false,
            'is_pregnant' => false,
        ]);

        return $visit;
    }

    /**
     * Creates an encoded clinic visit: captured + clearance record.
     * Shows Fit/Unfit on the records page (FR-STU-08 satisfied).
     */
    private function makeEncodedVisit(
        User $student,
        User $nurse,
        string $result = 'Fit',
        string $refNo = 'HP-2026-T002',
    ): ClinicVisit {
        $visit = $this->makeCapturedVisit($student, $refNo);

        $visit->update(['status' => 'encoded']);

        ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $nurse->id,
            'result' => $result,
            'physician_name' => 'Test Physician, MD',
            'physician_license_no' => '00000',
            'encoded_at' => now(),
        ]);

        return $visit;
    }

    // ── 1. Access control ─────────────────────────────────────────────────────


    public function test_clearance_history_pages_at_ten(): void
    {
        $student = $this->student();
        foreach (range(1, 12) as $i) {
            $this->makeCapturedVisit($student, sprintf('HP-2026-P%03d', $i));
        }

        $page1 = $this->actingAs($student)->get(route('student.records'))->assertOk();
        $page1->assertSee('Showing 1&ndash;10 of 12', false);
        $this->assertCount(10, $page1->viewData('visits')->items());

        $page2 = $this->actingAs($student)->get(route('student.records').'?page=2')->assertOk();
        $this->assertCount(2, $page2->viewData('visits')->items());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('student.records'))->assertRedirect(route('login'));
    }

    public function test_non_student_role_is_redirected_to_own_dashboard(): void
    {
        // EnsureRole redirects (not 403) — sends the user to their own dashboard.
        $this->actingAs($this->nurse())
            ->get(route('student.records'))
            ->assertRedirect('/nurse/dashboard');
    }

    public function test_student_can_access_records_page(): void
    {
        $this->actingAs($this->student())
            ->get(route('student.records'))
            ->assertOk();
    }

    // ── 2. Scoping — students only see their own visits ───────────────────────

    public function test_student_cannot_see_another_students_reference_no(): void
    {
        $studentA = $this->student();
        $studentB = $this->student();

        $this->makeCapturedVisit($studentB, 'HP-2026-T099');

        $this->actingAs($studentA)
            ->get(route('student.records'))
            ->assertOk()
            ->assertDontSee('HP-2026-T099');
    }

    // ── 3. Pending visit — FR-STU-08 gating ──────────────────────────────────

    public function test_captured_visit_shows_pending_badge(): void
    {
        $student = $this->student();
        $this->makeCapturedVisit($student, 'HP-2026-T010');

        $this->actingAs($student)
            ->get(route('student.records'))
            ->assertOk()
            ->assertSee('HP-2026-T010')
            ->assertSee('Pending');
    }

    public function test_captured_visit_does_not_expose_result(
    ): void {
        $student = $this->student();
        $this->makeCapturedVisit($student, 'HP-2026-T011');

        // Neither "Fit" nor "Unfit" should appear anywhere on the page.
        // "Results pending" (the action column text) should appear instead.
        $response = $this->actingAs($student)->get(route('student.records'));

        // Use assertDontSeeText (strips HTML/attribute values) so we don't
        // false-positive on "Fit" appearing inside Alpine :class attributes.
        // Pending rows show a "Pending" badge and a "—" dash — no result text.
        $response->assertOk()
            ->assertSee('Pending')
            ->assertDontSeeText('Fit')
            ->assertDontSeeText('Unfit');
    }

    // ── 4. Encoded visit — result visible (FR-STU-07 + FR-STU-08) ────────────

    public function test_encoded_fit_visit_shows_result(): void
    {
        $student = $this->student();
        $nurse = $this->nurse();
        $this->makeEncodedVisit($student, $nurse, 'Fit', 'HP-2026-T020');

        $this->actingAs($student)
            ->get(route('student.records'))
            ->assertOk()
            ->assertSee('HP-2026-T020')
            ->assertSee('Fit')
            ->assertDontSee('Results pending');
    }

    public function test_encoded_unfit_visit_shows_result(): void
    {
        $student = $this->student();
        $nurse = $this->nurse();
        $this->makeEncodedVisit($student, $nurse, 'Unfit', 'HP-2026-T021');

        $this->actingAs($student)
            ->get(route('student.records'))
            ->assertOk()
            ->assertSee('HP-2026-T021')
            ->assertSee('Unfit')
            ->assertDontSee('Results pending');
    }

    /** D-73: View is a link to the record page, not a modal trigger. */
    public function test_the_view_action_links_to_the_record_page(): void
    {
        $student = $this->student();
        $visit = $this->makeEncodedVisit($student, $this->nurse(), 'Fit', 'HP-2026-T022');

        $this->actingAs($student)
            ->get(route('student.records'))
            ->assertOk()
            ->assertSee('HP-2026-T022')
            ->assertSee(route('student.records.show', $visit), false);
    }

    /**
     * D-73 — the modal is gone, and with it the record JSON the page used to
     * embed. Nothing clinical beyond the result badge reaches the browser
     * until the student opens a record of their own.
     */
    public function test_the_list_page_no_longer_carries_the_modal(): void
    {
        $student = $this->student();
        $visit = $this->makeEncodedVisit($student, $this->nurse(), 'Fit', 'HP-2026-T023');
        $visit->screeningResponse->update([
            'abdomen' => true,
            'details' => ['abdomen' => 'Stomach pain after meals'],
        ]);

        $this->actingAs($student)
            ->get(route('student.records'))
            ->assertOk()
            ->assertDontSee('recordsPageData')
            ->assertDontSee('openRecord')
            ->assertDontSee('Stomach pain after meals')
            ->assertDontSee('Questionnaire')
            ->assertDontSee('Vital Signs');
    }

    // ── 5. Mixed — pending visit beside encoded visit ─────────────────────────

    public function test_pending_and_encoded_visits_coexist_correctly(): void
    {
        $student = $this->student();
        $nurse = $this->nurse();

        $this->makeCapturedVisit($student, 'HP-2026-T030');
        $this->makeEncodedVisit($student, $nurse, 'Fit', 'HP-2026-T031');

        $response = $this->actingAs($student)->get(route('student.records'));

        $response->assertOk()
            ->assertSee('HP-2026-T030')  // pending visit reference visible
            ->assertSee('HP-2026-T031')  // encoded visit reference visible
            ->assertSee('Pending')        // pending badge present
            ->assertSee('Fit')            // encoded result badge present
            ->assertSee('View');          // encoded action link present
    }

    // -- 6. The list leaks nothing the record page owns (D-68 / D-73) --------

    /** Put a visit on an approved batch that named $formType (D-62). */
    private function attachBatch(ClinicVisit $visit, string $formType): void
    {
        static $seq = 0;
        $seq++;

        $batch = BatchRequest::create([
            'reference_no' => sprintf('BR-2026-%03d', $seq),
            'college_id' => $visit->college_id,
            'requested_by' => $visit->student_id,
            'form_type' => $formType,
            'reason' => $formType === 'assessment' ? 'ojt' : 'fieldtrip',
            'service_type' => 'medical',
            'requested_date' => today()->toDateString(),
            'scheduled_date' => today()->toDateString(),
            'status' => 'approved',
        ]);

        $appointment = Appointment::factory()->create([
            'student_id' => $visit->student_id,
            'source' => 'batch',
            'batch_request_id' => $batch->id,
        ]);

        $visit->update(['appointment_id' => $appointment->id]);
    }

    /**
     * D-62 / D-73 — the Service column names the official FORM the visit's
     * batch chose. Before this it read `service_type` and said "Medical
     * Clearance" on every row, including an Assessment one — dental was gone
     * (D-60), so that column had only ever one value left to print.
     */
    public function test_the_service_column_names_the_visits_form(): void
    {
        $student = $this->student();

        $assessment = $this->makeEncodedVisit($student, $this->nurse(), 'Fit', 'HP-2026-T050');
        $this->attachBatch($assessment, 'assessment');

        $this->actingAs($student)
            ->get(route('student.records'))
            ->assertOk()
            ->assertSee('Medical Assessment Form')
            ->assertDontSee('Medical Clearance');

        $clearance = $this->makeEncodedVisit($student, $this->nurse(), 'Fit', 'HP-2026-T051');
        $this->attachBatch($clearance, 'clearance');

        $this->actingAs($student)
            ->get(route('student.records'))
            ->assertOk()
            ->assertSee('Medical Assessment Form')
            ->assertSee('Medical Clearance');
    }

    /**
     * D-73 — the Personal / Social History belongs to the record PAGE now
     * (RecordPageTest covers it there). The list must not leak it: the same
     * visit's answers appear on neither an Assessment nor a Clearance row.
     */
    public function test_the_list_leaks_no_social_history(): void
    {
        $student = $this->student();
        $visit = $this->makeEncodedVisit($student, $this->nurse(), 'Fit', 'HP-2026-T040');
        $this->attachBatch($visit, 'assessment');
        $visit->screeningResponse->update([
            'smoking' => 'quit',
            'alcohol' => 'no',
            'illicit_drugs' => 'no',
            'sexually_active' => true,
        ]);

        $this->actingAs($student)
            ->get(route('student.records'))
            ->assertOk()
            ->assertDontSee('Personal / Social History', false)
            ->assertDontSee('Illicit Drugs', false);
    }
}
