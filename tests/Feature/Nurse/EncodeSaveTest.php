<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\Appointment;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-NRS-04 — Save & Close: one transaction creates the 1:1 clearance record
 * (encoded_by / encoded_at / pre-filled physician block per §7.5), flips the
 * visit to `encoded` (BR-11) and, if the visit came from a booked appointment,
 * marks that appointment `completed` (FR-NRS-07).
 *
 * Encoding is one-time: a re-submit must NOT create a second record — it gets
 * a friendly redirect to the read-only view instead. BR-16: result required,
 * category/purpose optional. BR-18/FR-STU-08: the result becomes visible in
 * the student's My Records only after this save.
 */
class EncodeSaveTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function nurse(): User
    {
        return User::factory()->create(['role' => 'nurse']);
    }

    private function college(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    /** A captured walk-in visit (no appointment) with vitals + questionnaire. */
    private function makeVisit(?Appointment $appointment = null): ClinicVisit
    {
        $student = User::factory()->create(['role' => 'student', 'name' => 'Ana Cruz']);
        $student->studentProfile()->create([
            'college_id' => $this->college()->id,
            'student_number' => fake()->unique()->numerify('2023-######'),
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'sex' => 'F',
            'course' => 'Bachelor of Science in Information Technology',
            'year_level' => '3rd Year',
            'date_of_birth' => '2004-05-10',
            'place_of_birth' => 'San Fernando',
            'civil_status' => 'Single',
            'address' => 'Bacolor, Pampanga',
            'qr_token' => fake()->unique()->sha256(),
        ]);

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.fake()->unique()->numerify('T###'),
            'student_id' => $student->id,
            'college_id' => $this->college()->id,
            'appointment_id' => $appointment?->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => now(),
            'checked_in_at' => now()->subMinutes(5),
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
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            'is_bmi_flagged' => false,
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
            'last_menstrual_period' => null,
        ]);

        return $visit;
    }

    /** POST Save & Close as a nurse. @param array<string, mixed> $payload */
    private function save(User $nurse, ClinicVisit $visit, array $payload = ['result' => 'Fit'])
    {
        return $this->actingAs($nurse)
            ->from(route('nurse.visits.encode', $visit))
            ->post(route('nurse.visits.encode.store', $visit), $payload);
    }

    // ── 1. Access control ─────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $visit = $this->makeVisit();

        $this->post(route('nurse.visits.encode.store', $visit), ['result' => 'Fit'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('clearance_records', 0);
    }

    public function test_non_nurse_cannot_encode(): void
    {
        $visit = $this->makeVisit();
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student)
            ->post(route('nurse.visits.encode.store', $visit), ['result' => 'Fit'])
            ->assertRedirect('/student/dashboard');

        $this->assertDatabaseCount('clearance_records', 0);
    }

    // ── 2. Validation (BR-16) ─────────────────────────────────────────────────

    public function test_missing_result_is_blocked(): void
    {
        $visit = $this->makeVisit();

        $this->save($this->nurse(), $visit, [])
            ->assertRedirect(route('nurse.visits.encode', $visit))
            ->assertSessionHasErrors('result');

        $this->assertDatabaseCount('clearance_records', 0);
        $this->assertSame('captured', $visit->fresh()->status);
    }

    public function test_result_outside_fit_unfit_is_blocked(): void
    {
        $visit = $this->makeVisit();

        $this->save($this->nurse(), $visit, ['result' => 'Maybe'])
            ->assertSessionHasErrors('result');

        $this->assertDatabaseCount('clearance_records', 0);
    }

    public function test_case_categories_and_purpose_must_come_from_the_locked_lists(): void
    {
        $visit = $this->makeVisit();

        $this->save($this->nurse(), $visit, [
            'result' => 'Fit',
            'case_categories' => ['Made-up System'],
            'purpose' => 'Vacation',
        ])->assertSessionHasErrors(['case_categories.0', 'purpose']);

        $this->assertDatabaseCount('clearance_records', 0);
    }

    public function test_duplicate_case_categories_are_blocked(): void
    {
        $visit = $this->makeVisit();

        $this->save($this->nurse(), $visit, [
            'result' => 'Fit',
            'case_categories' => ['Respiratory System', 'Respiratory System'],
        ])->assertSessionHasErrors('case_categories.0');

        $this->assertDatabaseCount('clearance_records', 0);
    }

    public function test_invalid_physical_sign_value_is_blocked(): void
    {
        $visit = $this->makeVisit();

        $this->save($this->nurse(), $visit, ['result' => 'Fit', 'ps_skin' => '2'])
            ->assertSessionHasErrors('ps_skin');

        $this->assertDatabaseCount('clearance_records', 0);
    }

    public function test_physical_signs_findings_persist_on_save(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();

        // D-22: the nurse records the physician's exam findings; answered
        // rows persist, unanswered rows stay NULL (print as blank bubbles).
        $this->save($nurse, $visit, [
            'result' => 'Fit',
            'ps_skin' => '0',
            'ps_chest_lungs' => '1',
        ])->assertRedirect(route('nurse.queue'));

        $record = ClearanceRecord::firstWhere('clinic_visit_id', $visit->id);
        $this->assertFalse($record->ps_skin);
        $this->assertTrue($record->ps_chest_lungs);
        $this->assertNull($record->ps_gut);
    }

    // ── 3. The happy path (FR-NRS-04) ─────────────────────────────────────────

    public function test_walk_in_visit_encodes_with_result_only(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit(); // walk-in: no appointment

        $this->save($nurse, $visit, ['result' => 'Fit'])
            ->assertRedirect(route('nurse.queue'))
            ->assertSessionHas('status');

        $this->assertSame('encoded', $visit->fresh()->status);
        // Categories/purpose optional (BR-16); physician block pre-filled (§7.5).
        $this->assertDatabaseHas('clearance_records', [
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $nurse->id,
            'result' => 'Fit',
            'purpose' => null,
            'physician_name' => 'REYNALDO S. ALIPIO, MD',
            'physician_license_no' => '60252',
        ]);
        $this->assertDatabaseCount('clearance_case_categories', 0);
        $this->assertNotNull($visit->fresh()->clearanceRecord->encoded_at);
    }

    public function test_full_payload_is_saved(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();

        // A case can span several systems (D-23) — each persists as its own
        // child row so the Director's analytics can count per category.
        $this->save($nurse, $visit, [
            'result' => 'Unfit',
            'case_categories' => ['Respiratory System', 'Cardiovascular System'],
            'purpose' => 'On-the-job Training',
            'nurse_notes' => 'Advised rest and follow-up in one week.',
        ])->assertRedirect(route('nurse.queue'));

        $this->assertDatabaseHas('clearance_records', [
            'clinic_visit_id' => $visit->id,
            'result' => 'Unfit',
            'purpose' => 'On-the-job Training',
            'nurse_notes' => 'Advised rest and follow-up in one week.',
        ]);

        $record = ClearanceRecord::firstWhere('clinic_visit_id', $visit->id);
        $this->assertEqualsCanonicalizing(
            ['Respiratory System', 'Cardiovascular System'],
            $record->categoryNames()
        );
    }

    public function test_encoded_visit_vanishes_from_the_queue_feed(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();

        $this->save($nurse, $visit);

        $this->actingAs($nurse)
            ->get(route('nurse.queue.feed'))
            ->assertOk()
            ->assertJsonCount(0, 'visits');
    }

    // ── 4. Linked appointment → completed (FR-NRS-07) ─────────────────────────

    public function test_linked_appointment_is_marked_completed(): void
    {
        $appointment = Appointment::factory()->medical()->create();
        $visit = $this->makeVisit($appointment);

        $this->save($this->nurse(), $visit);

        $this->assertSame('completed', $appointment->fresh()->status);
    }

    public function test_validation_failure_leaves_the_appointment_untouched(): void
    {
        $appointment = Appointment::factory()->medical()->create();
        $visit = $this->makeVisit($appointment);

        $this->save($this->nurse(), $visit, []);

        $this->assertSame('scheduled', $appointment->fresh()->status);
    }

    // ── 5. Idempotency — a visit is encoded EXACTLY once ──────────────────────

    public function test_double_encode_is_safe_and_redirects_to_the_read_only_view(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();

        $this->save($nurse, $visit, ['result' => 'Fit']);

        // Re-submit (stale tab / double click): friendly redirect, no 2nd row,
        // and the first record is untouched.
        $this->save($nurse, $visit, ['result' => 'Unfit'])
            ->assertRedirect(route('nurse.visits.encode', $visit))
            ->assertSessionHas('status');

        $this->assertDatabaseCount('clearance_records', 1);
        $this->assertSame('Fit', $visit->fresh()->clearanceRecord->result);
    }

    public function test_db_unique_constraint_backstops_the_application_guard(): void
    {
        // Simulate the race the status check can miss: a clearance row already
        // exists while the visit still reads `captured`. The DB unique on
        // clinic_visit_id must win and the nurse must get the friendly
        // redirect, not a 500.
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $nurse->id,
            'result' => 'Fit',
            'encoded_at' => now(),
        ]);

        $this->save($nurse, $visit, ['result' => 'Unfit'])
            ->assertRedirect(route('nurse.visits.encode', $visit))
            ->assertSessionHas('status');

        $this->assertDatabaseCount('clearance_records', 1);
        $this->assertSame('Fit', $visit->fresh()->clearanceRecord->result);
    }

    // ── 6. Student visibility (FR-STU-08 / BR-18) ─────────────────────────────

    public function test_result_appears_in_my_records_only_after_encoding(): void
    {
        $visit = $this->makeVisit();
        $student = $visit->student;

        // Before encoding: the row is Pending, no Fit/Unfit badge anywhere
        // (text assertions ignore the page's Alpine attribute bindings).
        $this->actingAs($student)
            ->get(route('student.records'))
            ->assertOk()
            ->assertSeeText('Pending')
            ->assertDontSeeText('Fit');

        $this->save($this->nurse(), $visit, ['result' => 'Fit']);

        $this->actingAs($student)
            ->get(route('student.records'))
            ->assertOk()
            ->assertSeeText('Fit')
            ->assertDontSeeText('Pending');
    }

    // ── 7. "Others, Specify" purpose ──────────────────────────────────────────

    public function test_others_purpose_saves_with_its_specify_text(): void
    {
        $visit = $this->makeVisit();

        $this->save($this->nurse(), $visit, [
            'result' => 'Fit',
            'purpose' => ClearanceRecord::PURPOSE_OTHERS,
            'purpose_other' => 'Regional quiz bee at PSU Lubao',
        ])->assertRedirect(route('nurse.queue'));

        $this->assertDatabaseHas('clearance_records', [
            'clinic_visit_id' => $visit->id,
            'purpose' => 'Others',
            'purpose_other' => 'Regional quiz bee at PSU Lubao',
        ]);
    }

    public function test_others_purpose_without_specify_text_is_blocked(): void
    {
        $visit = $this->makeVisit();

        $this->save($this->nurse(), $visit, [
            'result' => 'Fit',
            'purpose' => ClearanceRecord::PURPOSE_OTHERS,
        ])->assertSessionHasErrors('purpose_other');

        $this->assertDatabaseCount('clearance_records', 0);
    }

    public function test_stray_specify_text_is_dropped_for_a_listed_purpose(): void
    {
        // The nurse typed a specify text, then switched back to a listed
        // purpose — prepareForValidation clears the leftover.
        $visit = $this->makeVisit();

        $this->save($this->nurse(), $visit, [
            'result' => 'Fit',
            'purpose' => 'Sports Activities',
            'purpose_other' => 'leftover text',
        ])->assertRedirect(route('nurse.queue'));

        $this->assertDatabaseHas('clearance_records', [
            'clinic_visit_id' => $visit->id,
            'purpose' => 'Sports Activities',
            'purpose_other' => null,
        ]);
    }
}
