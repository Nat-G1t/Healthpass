<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-STU-07 — My Records page: lists clinic visits, opens a detail modal with
 *             kiosk vitals + 9-system questionnaire.
 * FR-STU-08 — Fit/Unfit determination is hidden until a nurse encodes the visit.
 *             Captured (pending) visits show "Pending" and have no View action.
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
            'vision' => false,
            'hearing' => false,
            'nose' => false,
            'skin' => false,
            'respiratory' => false,
            'heart' => false,
            'digestive' => false,
            'bones' => false,
            'nervous' => false,
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

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('student.records'))->assertRedirect(route('login'));
    }

    public function test_non_student_role_is_redirected_to_own_dashboard(): void
    {
        // EnsureRole redirects (not 403) — sends the user to their own dashboard.
        $this->actingAs($this->nurse())
            ->get(route('student.records'))
            ->assertRedirect('/nurse/queue');
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

    public function test_encoded_visit_data_present_in_page_for_modal(): void
    {
        $student = $this->student();
        $nurse = $this->nurse();
        $visit = $this->makeEncodedVisit($student, $nurse, 'Fit', 'HP-2026-T022');

        // The visitData JSON is embedded in the page for the Alpine modal.
        // Assert that the reference number appears in the serialised data.
        $this->actingAs($student)
            ->get(route('student.records'))
            ->assertOk()
            ->assertSee('HP-2026-T022');
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
            ->assertSee('View');          // encoded action button present
    }
}
