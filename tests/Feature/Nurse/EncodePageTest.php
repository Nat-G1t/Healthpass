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
 * FR-NRS-03 — Encode Result ("Doctor's Assessment"):
 * a captured visit opens the editable assessment form (identity + vitals with
 * flags + all questionnaire answers, Result required, Purpose/Notes
 * optional); an encoded visit renders the same screen read-only with Reprint.
 */
class EncodePageTest extends TestCase
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

    /**
     * A captured visit with vitals + questionnaire for a named student.
     *
     * @param  array<string, mixed>  $vitals
     * @param  array<string, mixed>  $screening
     */
    private function makeVisit(string $name = 'Ana Cruz', array $vitals = [], array $screening = []): ClinicVisit
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name]);
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
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => now(),
            'checked_in_at' => now()->subMinutes(5),
        ]);

        VitalSigns::create(array_merge([
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
        ], $vitals));

        ScreeningResponse::create(array_merge([
            'clinic_visit_id' => $visit->id,
            'skin' => false,
            'abdomen_git' => false,
            'heent' => false,
            'gut' => false,
            'chest_lungs' => true,
            'extremities' => false,
            'heart_cvs' => false,
            'neurological' => false,
            'breast' => false,
            'is_pregnant' => false,
            'last_menstrual_period' => null,
        ], $screening));

        return $visit;
    }

    /** Flip a visit to encoded with its 1:1 clearance record (FR-NRS-04 shape). */
    private function encode(ClinicVisit $visit, User $nurse, array $overrides = []): ClearanceRecord
    {
        $record = ClearanceRecord::create(array_merge([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $nurse->id,
            'result' => 'Fit',
            'purpose' => 'On-the-job Training',
            'nurse_notes' => 'Advised rest and hydration.',
            'encoded_at' => now(),
        ], $overrides));

        $visit->update(['status' => 'encoded']);

        return $record;
    }

    // ── 1. Access control ─────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $visit = $this->makeVisit();

        $this->get(route('nurse.visits.encode', $visit))->assertRedirect(route('login'));
    }

    public function test_non_nurse_role_is_redirected_to_own_dashboard(): void
    {
        $visit = $this->makeVisit();
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student)
            ->get(route('nurse.visits.encode', $visit))
            ->assertRedirect('/student/dashboard');
    }

    public function test_unknown_visit_returns_404(): void
    {
        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', 999999))
            ->assertNotFound();
    }

    // ── 2. Captured visit — the editable form ─────────────────────────────────

    public function test_captured_visit_shows_identity_vitals_and_form(): void
    {
        $visit = $this->makeVisit('Ana Cruz');

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee("Doctor's Assessment")
            // Identity block
            ->assertSee('Ana Cruz')
            ->assertSee('College of Computing Studies')
            ->assertSee('3rd Year')
            // Vitals
            ->assertSee('36.5')
            ->assertSee('115/75')
            // Form controls + buttons (stubs today)
            ->assertSee('Fit')
            ->assertSee('Unfit')
            ->assertSee('Purpose')
            ->assertSee('Nurse Notes')
            ->assertSee('Preview & Print')
            ->assertSee('Save & Close')
            ->assertDontSee('Reprint');
    }

    public function test_all_nine_questionnaire_answers_are_shown(): void
    {
        $visit = $this->makeVisit();

        $response = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('Health Questionnaire')
            ->assertSee('Currently pregnant')
            ->assertDontSee('Vision / Eyes');

        // D-56: each of the form's labels now appears twice — on the student's
        // questionnaire card AND on the nurse's Physical Signs fieldset.
        foreach (ScreeningResponse::QUESTIONS as $question) {
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($response->getContent(), e($question['label'])),
                $question['label']
            );
        }
    }

    public function test_a_yes_detail_is_shown_under_its_answer(): void
    {
        $visit = $this->makeVisit('Detail Student', [], [
            'skin' => true,
            'details' => ['skin' => 'Itchy rash on left arm'],
        ]);

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSeeInOrder(['Health Questionnaire', 'SKIN', 'Yes', 'Itchy rash on left arm', 'ABDOMEN (GIT)']);
    }

    public function test_flagged_vital_shows_flag_badge(): void
    {
        $visit = $this->makeVisit('Feverish Student', [
            'temperature_c' => 38.4,
            'is_temp_flagged' => true,
        ]);

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('38.4')
            ->assertSee('Flagged');
    }

    public function test_pregnant_response_shows_lmp_date(): void
    {
        $visit = $this->makeVisit('Expecting Student', [], [
            'is_pregnant' => true,
            'last_menstrual_period' => '2026-05-20',
        ]);

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('May 20, 2026');
    }

    public function test_purpose_options_are_the_locked_prd_list(): void
    {
        $visit = $this->makeVisit();

        $response = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk();

        foreach (ClearanceRecord::PURPOSES as $purpose) {
            $response->assertSee($purpose);
        }
    }

    public function test_kiosk_yes_answer_prechecks_the_matching_physical_sign(): void
    {
        // Student self-reported a skin issue at the kiosk (D-22): the SKIN
        // row opens pre-checked YES for the nurse to confirm or correct.
        $visit = $this->makeVisit('Prefilled Student', [], ['skin' => true]);

        $html = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('~name="ps_skin" value="1"[^>]*checked~', $html);
    }

    public function test_kiosk_no_answer_prechecks_no_on_the_matching_physical_sign(): void
    {
        // skin=false at the kiosk → the SKIN row opens pre-checked NO
        // (D-22 as amended by D-25: the student's answer, YES or NO,
        // pre-fills for the nurse to confirm or correct).
        $visit = $this->makeVisit();

        $html = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('~name="ps_skin" value="0"[^>]*checked~', $html);
        $this->assertDoesNotMatchRegularExpression('~name="ps_skin" value="1"[^>]*checked~', $html);
    }

    public function test_all_nine_physical_signs_precheck_yes_from_the_kiosk(): void
    {
        // D-56: the kiosk asks the form's own rows, so EVERY row — GUT and
        // BREAST included — opens on the student's answer (ps_<key> ← <key>).
        $allYes = array_fill_keys(array_keys(ScreeningResponse::QUESTIONS), true);
        $visit = $this->makeVisit('All Yes', [], $allYes);

        $html = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->getContent();

        foreach (array_keys(ClearanceRecord::PHYSICAL_SIGNS) as $column) {
            $this->assertMatchesRegularExpression('~name="'.$column.'" value="1"[^>]*checked~', $html, $column);
            $this->assertDoesNotMatchRegularExpression('~name="'.$column.'" value="0"[^>]*checked~', $html, $column);
        }
    }

    public function test_all_nine_physical_signs_precheck_no_from_the_kiosk(): void
    {
        $allNo = array_fill_keys(array_keys(ScreeningResponse::QUESTIONS), false);
        $visit = $this->makeVisit('All No', [], $allNo);

        $html = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->getContent();

        foreach (array_keys(ClearanceRecord::PHYSICAL_SIGNS) as $column) {
            $this->assertMatchesRegularExpression('~name="'.$column.'" value="0"[^>]*checked~', $html, $column);
            $this->assertDoesNotMatchRegularExpression('~name="'.$column.'" value="1"[^>]*checked~', $html, $column);
        }

        // No details typed → nothing to pre-fill into Nurse Notes.
        $this->assertMatchesRegularExpression('~<textarea[^>]*name="nurse_notes"[^>]*>\s*</textarea>~', $html);
    }

    public function test_a_null_kiosk_answer_leaves_its_row_blank(): void
    {
        // The columns are nullable (D-56). A NULL answer has nothing to
        // pre-fill, so its row opens fully blank instead of guessing NO.
        $visit = $this->makeVisit('Unanswered Rows', [], ['gut' => null, 'breast' => null]);

        $html = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->getContent();

        foreach (['ps_gut', 'ps_breast'] as $column) {
            $this->assertDoesNotMatchRegularExpression('~name="'.$column.'" value="1"[^>]*checked~', $html);
            $this->assertDoesNotMatchRegularExpression('~name="'.$column.'" value="0"[^>]*checked~', $html);
        }
    }

    // ── 2a. Nurse Notes pre-fill from the YES details (D-56) ──────────────────

    public function test_nurse_notes_prefill_with_the_yes_details_in_form_order(): void
    {
        // Details arrive out of form order; the pre-fill still follows the form.
        $visit = $this->makeVisit('Notes Student', [], [
            'skin' => true,
            'chest_lungs' => true,
            'breast' => true,
            'details' => [
                'breast' => 'Small lump noticed last week',
                'skin' => 'Itchy rash on left arm',
                'chest_lungs' => 'Cough at night',
            ],
        ]);

        $html = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '~<textarea[^>]*name="nurse_notes"[^>]*>SKIN: Itchy rash on left arm\nCHEST/LUNGS: Cough at night\nBREAST: Small lump noticed last week</textarea>~',
            $html
        );
    }

    public function test_old_input_wins_over_the_nurse_notes_prefill(): void
    {
        $visit = $this->makeVisit('Old Input', [], [
            'skin' => true,
            'details' => ['skin' => 'Itchy rash on left arm'],
        ]);

        $this->actingAs($this->nurse())
            ->withSession(['_old_input' => ['nurse_notes' => 'Nurse rewrote this.']])
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('Nurse rewrote this.')
            ->assertDontSee('SKIN: Itchy rash on left arm');
    }

    public function test_an_encoded_record_shows_its_saved_notes_not_the_prefill(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit('Encoded Notes', [], [
            'skin' => true,
            'details' => ['skin' => 'Itchy rash on left arm'],
        ]);
        $this->encode($visit, $nurse, ['nurse_notes' => null]);

        $html = $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('Itchy rash on left arm') // still on the questionnaire card
            ->getContent();

        $this->assertStringNotContainsString('SKIN: Itchy rash on left arm', $html);
        $this->assertMatchesRegularExpression('~<textarea[^>]*name="nurse_notes"[^>]*>\s*</textarea>~', $html);
    }

    public function test_physical_signs_fieldset_shows_all_nine_exam_rows(): void
    {
        $visit = $this->makeVisit();

        $response = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('Physical Signs Disorder of');

        // D-22: the nurse records the physician's exam findings per system.
        foreach (ClearanceRecord::PHYSICAL_SIGNS as $label) {
            $response->assertSee($label);
        }
    }

    // ── 2b. Purpose carried from booking (D-28) ───────────────────────────────

    public function test_purpose_input_is_hidden_when_the_appointment_carries_a_purpose(): void
    {
        // The student chose the purpose at booking — encode shows a read-only
        // echo, not the dropdown, and does not let the nurse re-pick it.
        $visit = $this->makeVisit();
        $appointment = Appointment::factory()->withPurpose('Sports Activities')->create();
        $visit->update(['appointment_id' => $appointment->id]);

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('Chosen by the student at booking')
            ->assertSee('Sports Activities')
            // The editable dropdown's placeholder option is gone.
            ->assertDontSee('— Optional —');
    }

    public function test_walk_in_visit_still_shows_the_purpose_dropdown(): void
    {
        // No appointment (or a purposeless one) → the nurse-entered dropdown
        // stays exactly as before.
        $visit = $this->makeVisit();

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('— Optional —')
            ->assertDontSee('Chosen by the student at booking');
    }

    // ── 3. Encoded visit — read-only + Reprint ────────────────────────────────

    public function test_encoded_visit_shows_saved_physical_signs(): void
    {
        $nurse = $this->nurse();
        // Kiosk says chest/lungs YES, but the nurse saved NOTHING for that
        // row — read-only must show the record, never the prefill.
        $visit = $this->makeVisit('Examined Student', [], ['chest_lungs' => true]);
        $this->encode($visit, $nurse, ['ps_skin' => true, 'ps_heent' => false]);

        $html = $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->getContent();

        // Saved YES/NO answers come back checked (and disabled — read-only).
        $this->assertMatchesRegularExpression('~name="ps_skin" value="1"[^>]*checked~', $html);
        $this->assertMatchesRegularExpression('~name="ps_heent" value="0"[^>]*checked~', $html);
        // ps_chest_lungs was left NULL → no kiosk prefill in read-only mode.
        $this->assertDoesNotMatchRegularExpression('~name="ps_chest_lungs" value="1"[^>]*checked~', $html);
    }

    public function test_encoded_visit_renders_read_only_with_reprint(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit('Encoded Student');
        $this->encode($visit, $nurse);

        $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('already been encoded')
            ->assertSee($nurse->name)
            ->assertSee('Reprint')
            ->assertSee('Advised rest and hydration.')
            ->assertDontSee('Save & Close')
            ->assertDontSee('Preview & Print');
    }

    // ── 4. Queue integration — rows link here ─────────────────────────────────

    public function test_queue_page_links_rows_to_the_encode_screen(): void
    {
        $visit = $this->makeVisit();

        $this->actingAs($this->nurse())
            ->get(route('nurse.queue'))
            ->assertOk()
            ->assertSee(route('nurse.visits.encode', $visit));
    }

    public function test_queue_feed_includes_the_encode_url(): void
    {
        $visit = $this->makeVisit();

        $this->actingAs($this->nurse())
            ->get(route('nurse.queue.feed'))
            ->assertOk()
            ->assertJsonPath('visits.0.encode_url', route('nurse.visits.encode', $visit));
    }

    // ── Bluetooth BP irregular pulse (D-58) ───────────────────────────────────

    public function test_irregular_pulse_from_the_bp_monitor_is_shown_to_the_nurse(): void
    {
        $visit = $this->makeVisit(vitals: [
            'entry_method' => 'sensor',
            'bp_device_reading' => [
                'device_model' => 'A&D UA-651BLE',
                'flags' => ['irregular_pulse' => true],
                'suspect' => false,
            ],
        ]);

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('Irregular pulse')
            ->assertSee('Detected by the blood-pressure monitor during this reading.');
    }

    public function test_no_irregular_pulse_notice_without_a_device_record(): void
    {
        $visit = $this->makeVisit();

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertDontSee('Irregular pulse');
    }
}
