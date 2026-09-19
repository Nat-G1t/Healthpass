<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

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
 * FR-NRS-04 — Save & Close: one transaction creates the 1:1 clearance record
 * (encoded_by / encoded_at / pre-filled physician block per §7.5), flips the
 * visit to `encoded` (BR-11) and, if the visit came from a booked appointment,
 * marks that appointment `completed` (FR-NRS-07).
 *
 * Encoding is one-time: a re-submit must NOT create a second record — it gets
 * a friendly redirect to the read-only view instead. BR-16: result required,
 * purpose optional. BR-18/FR-STU-08: the result becomes visible in the
 * student's My Records only after this save.
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

    /** A captured visit with vitals + questionnaire; no appointment unless given (a legacy, pre-D-61 walk-in row). */
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
            'skin' => false,
            'abdomen_git' => false,
            'heent' => false,
            'gut' => false,
            'chest_lungs' => false,
            'extremities' => false,
            'heart_cvs' => false,
            'neurological' => false,
            'breast' => false,
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

    public function test_a_posted_purpose_is_ignored_on_a_visit_with_no_batch(): void
    {
        // D-62: no purpose rule — a posted purpose never reaches validated(),
        // so it cannot be saved; with no batch the record's purpose is NULL.
        $visit = $this->makeVisit();

        $this->save($this->nurse(), $visit, [
            'result' => 'Fit',
            'purpose' => 'Vacation',
            'purpose_other' => 'tampered',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('clearance_records', [
            'clinic_visit_id' => $visit->id,
            'purpose' => null,
            'purpose_other' => null,
        ]);
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

    public function test_a_visit_with_no_appointment_encodes_with_result_only(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit(); // legacy row: no appointment

        $this->save($nurse, $visit, ['result' => 'Fit'])
            ->assertRedirect(route('nurse.queue'))
            ->assertSessionHas('status');

        $this->assertSame('encoded', $visit->fresh()->status);
        // Purpose optional (BR-16); physician block pre-filled (§7.5).
        $this->assertDatabaseHas('clearance_records', [
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $nurse->id,
            'result' => 'Fit',
            'purpose' => null,
            'physician_name' => 'REYNALDO S. ALIPIO, MD',
            'physician_license_no' => '60252',
        ]);
        $this->assertNotNull($visit->fresh()->clearanceRecord->encoded_at);
    }

    public function test_full_payload_is_saved(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();

        $this->save($nurse, $visit, [
            'result' => 'Unfit',
            'nurse_notes' => 'Advised rest and follow-up in one week.',
        ])->assertRedirect(route('nurse.queue'));

        $this->assertDatabaseHas('clearance_records', [
            'clinic_visit_id' => $visit->id,
            'result' => 'Unfit',
            'nurse_notes' => 'Advised rest and follow-up in one week.',
        ]);
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

    // ── 7. D-62: the batch reason is the purpose ──────────────────────────────

    /** A batch appointment with this form + reason (and specify text). */
    private function batchAppointment(string $formType, string $reason, ?string $detail = null): Appointment
    {
        static $seq = 1;

        $batch = BatchRequest::create([
            'reference_no' => sprintf('BR-2026-%03d', $seq++),
            'college_id' => $this->college()->id,
            'requested_by' => $this->nurse()->id,
            'form_type' => $formType,
            'reason' => $reason,
            'reason_detail' => $detail,
            'service_type' => 'medical',
            'requested_date' => today()->toDateString(),
            'scheduled_date' => today()->toDateString(),
            'status' => 'approved',
        ]);

        return Appointment::factory()->medical()->create([
            'source' => 'batch',
            'batch_request_id' => $batch->id,
        ]);
    }

    public function test_save_copies_the_batch_reason_label_as_the_purpose(): void
    {
        $visit = $this->makeVisit($this->batchAppointment('assessment', 'ojt'));

        $this->save($this->nurse(), $visit, ['result' => 'Fit'])
            ->assertRedirect(route('nurse.queue'));

        $this->assertDatabaseHas('clearance_records', [
            'clinic_visit_id' => $visit->id,
            'purpose' => 'On-the-job Training',
            'purpose_other' => null,
        ]);
    }

    public function test_an_others_batch_copies_its_specify_text(): void
    {
        $visit = $this->makeVisit($this->batchAppointment('clearance', 'others', 'Regional quiz bee at PSU Lubao'));

        $this->save($this->nurse(), $visit, ['result' => 'Fit']);

        $this->assertDatabaseHas('clearance_records', [
            'clinic_visit_id' => $visit->id,
            'purpose' => 'Others, Specify',
            'purpose_other' => 'Regional quiz bee at PSU Lubao',
        ]);
    }

    public function test_a_posted_purpose_never_overrides_the_batch(): void
    {
        // A crafted request cannot re-pick the purpose the college chose.
        $visit = $this->makeVisit($this->batchAppointment('clearance', 'fieldtrip'));

        $this->save($this->nurse(), $visit, [
            'result' => 'Fit',
            'purpose' => 'On-the-job Training',
            'purpose_other' => 'tampered',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('clearance_records', [
            'clinic_visit_id' => $visit->id,
            'purpose' => 'Field Trip/Educational Tour',
            'purpose_other' => null,
        ]);
    }

    public function test_the_batch_purpose_reaches_the_printed_form(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit($this->batchAppointment('clearance', 'outbound'));

        $this->save($nurse, $visit, ['result' => 'Fit']);

        $html = $this->actingAs($nurse)
            ->get(route('nurse.visits.print', $visit))
            ->assertOk()
            ->getContent();

        // The saved label's bubble is the shaded one.
        $this->assertMatchesRegularExpression('~<span class="bb">●</span> Outbound Activities~', $html);
    }

    public function test_the_print_preview_uses_the_batch_purpose_too(): void
    {
        // Preview & Print (before save) must show exactly what Save stores.
        $nurse = $this->nurse();
        $visit = $this->makeVisit($this->batchAppointment('clearance', 'others', 'Quiz bee'));

        $html = $this->actingAs($nurse)
            ->post(route('nurse.visits.print.preview', $visit), ['result' => 'Fit', 'purpose' => 'On-the-job Training'])
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('~<span class="bb">●</span> Others, Specify:~', $html);
        $this->assertStringContainsString('Quiz bee', $html);
    }
}
