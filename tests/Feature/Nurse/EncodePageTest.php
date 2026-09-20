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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FR-NRS-03 — Encode Result ("Doctor's Assessment"):
 * a captured visit opens the editable assessment form (identity + vitals with
 * flags + all questionnaire answers, Result required, Notes optional, the
 * batch's purpose read-only — D-62); an encoded visit renders the same screen
 * read-only with Reprint.
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
            'head' => false,
            'eyes' => false,
            'ears' => false,
            'nose' => false,
            'throat' => false,
            'chest_lungs' => true,
            'heart' => false,
            'abdomen' => false,
            'kidney_bladder' => false,
            'brain' => false,
            'mental_disorder' => false,
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

    /** D-62: put the visit on an approved batch with this form + reason. */
    private function attachBatch(ClinicVisit $visit, string $formType, string $reason, ?string $detail = null): BatchRequest
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
        $appointment = Appointment::factory()->medical()->create([
            'student_id' => $visit->student_id,
            'source' => 'batch',
            'batch_request_id' => $batch->id,
        ]);
        $visit->update(['appointment_id' => $appointment->id]);

        return $batch;
    }

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
            // Vitals — editable inputs since D-65, so the kiosk's reading
            // arrives as each box's value, not as one "115/75" line.
            ->assertSee('value="36.5"', false)
            ->assertSee('name="bp_systolic"', false)
            ->assertSee('name="bp_diastolic"', false)
            ->assertSee('name="respiratory_rate"', false)
            // Form controls + buttons (stubs today)
            ->assertSee('Fit')
            ->assertSee('Unfit')
            ->assertSee('Purpose')
            ->assertSee('Clinic Notes')
            ->assertSee('Preview & Print')
            ->assertSee('Save & Close')
            ->assertDontSee('Reprint');
    }

    public function test_all_twelve_questionnaire_answers_are_shown(): void
    {
        $visit = $this->makeVisit();

        $response = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('Health Questionnaire')
            ->assertSee('Currently pregnant')
            ->assertDontSee('Vision / Eyes');

        // D-56/D-63: each of the form's labels appears twice — on the student's
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
            ->assertSeeInOrder(['Health Questionnaire', 'SKIN', 'Yes', 'Itchy rash on left arm', 'HEAD']);
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

    public function test_the_header_shows_the_form_type_and_the_batch_purpose_read_only(): void
    {
        // D-62: the purpose is the batch reason — shown, never picked.
        $visit = $this->makeVisit();
        $this->attachBatch($visit, 'assessment', 'others', 'Regional quiz bee at PSU Lubao');

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('data-form-type="assessment"', false)
            ->assertSee('Medical Assessment Form')
            ->assertSee('Others, Specify: Regional quiz bee at PSU Lubao')
            ->assertSee("From the college's batch request", false)
            // No purpose picker any more.
            ->assertDontSee('name="purpose"', false)
            ->assertDontSee('name="purpose_other"', false);
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

    public function test_all_twelve_physical_signs_precheck_yes_from_the_kiosk(): void
    {
        // D-56/D-63: the kiosk asks the form's own rows, so EVERY row opens on
        // the student's answer (ps_<key> ← <key>).
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

    public function test_all_twelve_physical_signs_precheck_no_from_the_kiosk(): void
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

    public function test_each_kiosk_answer_prefills_its_own_physical_sign_row(): void
    {
        // D-63: the pre-fill is 1:1 by key. Alternate YES/NO down the list so a
        // row reading its neighbour's answer (an off-by-one or a mis-keyed
        // column) would show the opposite value and fail.
        $answers = [];
        foreach (array_keys(ScreeningResponse::QUESTIONS) as $i => $key) {
            $answers[$key] = $i % 2 === 0;
        }
        $visit = $this->makeVisit('Alternating', [], $answers);

        $html = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            array_map(fn (string $key) => 'ps_'.$key, array_keys(ScreeningResponse::QUESTIONS)),
            array_keys(ClearanceRecord::PHYSICAL_SIGNS),
            'The two lists must share keys and order.'
        );

        foreach ($answers as $key => $yes) {
            $checked = $yes ? '1' : '0';
            $unchecked = $yes ? '0' : '1';
            $this->assertMatchesRegularExpression('~name="ps_'.$key.'" value="'.$checked.'"[^>]*checked~', $html, $key);
            $this->assertDoesNotMatchRegularExpression('~name="ps_'.$key.'" value="'.$unchecked.'"[^>]*checked~', $html, $key);
        }
    }

    public function test_a_null_kiosk_answer_leaves_its_row_blank(): void
    {
        // The columns are nullable (D-56). A NULL answer has nothing to
        // pre-fill, so its row opens fully blank instead of guessing NO.
        $visit = $this->makeVisit('Unanswered Rows', [], ['kidney_bladder' => null, 'mental_disorder' => null]);

        $html = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->getContent();

        foreach (['ps_kidney_bladder', 'ps_mental_disorder'] as $column) {
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
            'mental_disorder' => true,
            'details' => [
                'mental_disorder' => 'Feeling anxious lately',
                'skin' => 'Itchy rash on left arm',
                'chest_lungs' => 'Cough at night',
            ],
        ]);

        $html = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '~<textarea[^>]*name="nurse_notes"[^>]*>SKIN: Itchy rash on left arm\nCHEST/LUNGS: Cough at night\nMENTAL DISORDER: Feeling anxious lately</textarea>~',
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

    public function test_physical_signs_fieldset_shows_all_twelve_exam_rows(): void
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

    // ── 2b. D-62: a visit with no batch ───────────────────────────────────────

    public function test_a_visit_with_no_batch_reads_as_a_clearance_with_no_purpose(): void
    {
        // A legacy visit with no appointment: Medical Clearance, "Not specified".
        $visit = $this->makeVisit();

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('data-form-type="clearance"', false)
            ->assertSee('Not specified')
            ->assertDontSee('name="purpose"', false);
    }

    // ── 3. Encoded visit — read-only + Reprint ────────────────────────────────

    public function test_encoded_visit_shows_saved_physical_signs(): void
    {
        $nurse = $this->nurse();
        // Kiosk says chest/lungs YES, but the nurse saved NOTHING for that
        // row — read-only must show the record, never the prefill.
        $visit = $this->makeVisit('Examined Student', [], ['chest_lungs' => true]);
        $this->encode($visit, $nurse, ['ps_skin' => true, 'ps_eyes' => false]);

        $html = $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->getContent();

        // Saved YES/NO answers come back checked (and disabled — read-only).
        $this->assertMatchesRegularExpression('~name="ps_skin" value="1"[^>]*checked~', $html);
        $this->assertMatchesRegularExpression('~name="ps_eyes" value="0"[^>]*checked~', $html);
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

    public function test_queue_feed_carries_the_form_type(): void
    {
        $assessment = $this->makeVisit('Assessment Student');
        $this->attachBatch($assessment, 'assessment', 'ojt');
        $this->makeVisit('Legacy Student'); // no batch → clearance

        $this->actingAs($this->nurse())
            ->get(route('nurse.queue.feed'))
            ->assertOk()
            ->assertJsonPath('visits.0.form_type', 'assessment')
            ->assertJsonPath('visits.1.form_type', 'clearance');
    }

    public function test_queue_page_shows_a_form_type_badge_per_row(): void
    {
        $visit = $this->makeVisit('Assessment Student');
        $this->attachBatch($visit, 'assessment', 'ojt');
        $this->makeVisit('Legacy Student');

        $this->actingAs($this->nurse())
            ->get(route('nurse.queue'))
            ->assertOk()
            ->assertSeeInOrder(['Assessment Student', 'Assessment', 'Legacy Student', 'Clearance']);
    }

    public function test_the_queue_feed_does_not_query_per_row_for_the_form_type(): void
    {
        // Eager-loaded in scopeLiveQueue: the query count must not grow with
        // the number of rows (no N+1 on appointment → batchRequest).
        $nurse = $this->nurse();
        $count = function () use ($nurse): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($nurse)->get(route('nurse.queue.feed'))->assertOk();
            // SELECTs only: the first request also stamps users.last_active_at.
            $n = collect(DB::getQueryLog())
                ->filter(fn (array $q): bool => str_starts_with($q['query'], 'select'))
                ->count();
            DB::disableQueryLog();

            return $n;
        };

        $this->attachBatch($this->makeVisit('One'), 'assessment', 'ojt');
        $one = $count();

        $this->attachBatch($this->makeVisit('Two'), 'clearance', 'fieldtrip');
        $this->attachBatch($this->makeVisit('Three'), 'assessment', 'rle');
        $this->assertSame($one, $count());
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

    // -- Personal / Social History card (D-68) --------------------------------

    public function test_an_assessment_visit_shows_the_personal_social_history_card(): void
    {
        $visit = $this->makeVisit();
        $this->attachBatch($visit, 'assessment', 'ojt');
        $visit->screeningResponse->update([
            'smoking' => 'quit',
            'alcohol' => 'yes',
            'illicit_drugs' => 'no',
            'sexually_active' => true,
        ]);

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('Personal / Social History (from the kiosk)')
            ->assertSee('Smoking')
            ->assertSee('Quit')
            ->assertSee('Illicit Drugs')
            ->assertSee('Sexually Active');
    }

    public function test_a_clearance_visit_shows_no_personal_social_history_card(): void
    {
        // The Medical Clearance has no such section, so the student was never
        // asked and the clinic is shown nothing.
        $visit = $this->makeVisit();
        $this->attachBatch($visit, 'clearance', 'fieldtrip');

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertDontSee('Personal / Social History');
    }

    public function test_an_assessment_visit_captured_before_d68_renders_dashes(): void
    {
        // Four NULLs must render as "-" rather than crash or read as "No".
        $visit = $this->makeVisit();
        $this->attachBatch($visit, 'assessment', 'sports');

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('Personal / Social History (from the kiosk)')
            ->assertDontSee('Quit');
    }
}
