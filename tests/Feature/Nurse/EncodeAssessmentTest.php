<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\MedicalAssessment;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EncodePayload;
use Tests\TestCase;

/**
 * FR-NRS-10 / D-69 — the Medical Assessment Form's own encode sections.
 *
 * A visit whose batch chose the Medical Assessment Form (D-62) records the
 * paper's histories, immunization profile, family planning access and surgical
 * history in `medical_assessments` — one row per Assessment encode, and none at
 * all for a Medical Clearance. The form type is the SERVER's resolution of the
 * visit's batch; nothing in the request body can change it.
 */
class EncodeAssessmentTest extends TestCase
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
     * A captured visit on an approved batch that uses the given form (D-62).
     * The student's sex decides whether sections V and VI apply (D-70).
     */
    private function makeVisit(string $formType, string $sex = 'F'): ClinicVisit
    {
        static $seq = 1;

        $student = User::factory()->create(['role' => 'student', 'name' => 'Ana Cruz']);
        $student->studentProfile()->create([
            'college_id' => $this->college()->id,
            'student_number' => fake()->unique()->numerify('2023-######'),
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'sex' => $sex,
            'course' => 'Bachelor of Science in Information Technology',
            'year_level' => '3rd Year',
            'date_of_birth' => '2004-05-10',
            'place_of_birth' => 'San Fernando',
            'civil_status' => 'Single',
            'address' => 'Bacolor, Pampanga',
            'qr_token' => fake()->unique()->sha256(),
        ]);

        $batch = BatchRequest::create([
            'reference_no' => sprintf('BR-2026-%03d', $seq),
            'college_id' => $this->college()->id,
            'requested_by' => $this->nurse()->id,
            'form_type' => $formType,
            'reason' => $formType === 'assessment' ? 'ojt' : 'fieldtrip',
            'service_type' => 'medical',
            'requested_date' => today()->toDateString(),
            'scheduled_date' => today()->toDateString(),
            'status' => 'approved',
        ]);

        $appointment = Appointment::factory()->medical()->create([
            'student_id' => $student->id,
            'source' => 'batch',
            'batch_request_id' => $batch->id,
        ]);

        $visit = ClinicVisit::create([
            'reference_no' => sprintf('HP-2026-A%03d', $seq++),
            'student_id' => $student->id,
            'college_id' => $this->college()->id,
            'appointment_id' => $appointment->id,
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
            ...array_fill_keys(array_keys(ScreeningResponse::QUESTIONS), false),
            'is_pregnant' => false,
        ]);

        return $visit;
    }

    /**
     * A filled-in Assessment payload: two conditions with specify texts, three
     * immunizations, family planning Yes and a surgical history.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function assessmentPayload(array $overrides = []): array
    {
        return EncodePayload::make([
            'medical_history' => [
                'patient' => ['asthma', 'allergy'],
                'family' => ['hypertension'],
                'specify' => ['allergy' => 'Peanuts', 'hypertension' => '160/100'],
            ],
            'immunizations' => [
                'given' => ['bcg', 'hepb1', 'hpv'],
                'others' => 'Typhoid (2024)',
            ],
            'family_planning_access' => '1',
            'surgical_history' => [
                'procedures' => 'Appendectomy',
                'date_done' => 'Grade 5',
            ],
            ...$overrides,
        ]);
    }

    /** POST Save & Close as a nurse. @param array<string, mixed> $payload */
    private function save(ClinicVisit $visit, array $payload)
    {
        return $this->actingAs($this->nurse())
            ->from(route('nurse.visits.encode', $visit))
            ->post(route('nurse.visits.encode.store', $visit), $payload);
    }

    // ── 1. Storage ────────────────────────────────────────────────────────────

    public function test_an_assessment_encode_stores_the_three_json_shapes(): void
    {
        $visit = $this->makeVisit('assessment');

        $this->save($visit, $this->assessmentPayload())->assertRedirect(route('nurse.queue'));

        $assessment = ClearanceRecord::firstOrFail()->medicalAssessment;

        $this->assertNotNull($assessment);
        // Stored in the FORM's order, not the order the boxes were posted in.
        $this->assertSame([
            'patient' => ['allergy', 'asthma'],
            'family' => ['hypertension'],
            'specify' => ['allergy' => 'Peanuts', 'hypertension' => '160/100'],
        ], $assessment->medical_history);
        $this->assertSame([
            'given' => ['bcg', 'hepb1', 'hpv'],
            'others' => 'Typhoid (2024)',
        ], $assessment->immunizations);
        $this->assertTrue($assessment->family_planning_access);
        $this->assertSame([
            'procedures' => 'Appendectomy',
            'date_done' => 'Grade 5',
        ], $assessment->surgical_history);
    }

    public function test_an_untouched_assessment_section_saves_empty_rather_than_failing(): void
    {
        // Every field is optional (Nat, 2026-09-18) — only Result and the seven
        // vitals are required, so a student with nothing to report still saves.
        $visit = $this->makeVisit('assessment');

        $this->save($visit, EncodePayload::make())->assertRedirect(route('nurse.queue'));

        $assessment = ClearanceRecord::firstOrFail()->medicalAssessment;

        $this->assertSame(['patient' => [], 'family' => [], 'specify' => []], $assessment->medical_history);
        $this->assertSame(['given' => [], 'others' => null], $assessment->immunizations);
        // NULL is "not answered" — it is not a No.
        $this->assertNull($assessment->family_planning_access);
        $this->assertSame(['procedures' => null, 'date_done' => null], $assessment->surgical_history);
    }

    public function test_a_clearance_encode_creates_no_medical_assessment_row(): void
    {
        $visit = $this->makeVisit('clearance');

        // Even when the sections are posted, a Medical Clearance stores none:
        // that paper has no such section, and the rules never validate them.
        $this->save($visit, $this->assessmentPayload())->assertRedirect(route('nurse.queue'));

        $this->assertDatabaseCount('medical_assessments', 0);
    }

    public function test_the_physical_signs_rows_are_not_saved_for_an_assessment(): void
    {
        // The Assessment prints the STUDENT'S own answers, so the clinic never
        // encodes ps_* — a posted one is dropped (D-69).
        $visit = $this->makeVisit('assessment');

        $this->save($visit, $this->assessmentPayload(['ps_skin' => '1', 'ps_heart' => '0']))
            ->assertRedirect(route('nurse.queue'));

        $record = ClearanceRecord::firstOrFail();

        $this->assertNull($record->ps_skin);
        $this->assertNull($record->ps_heart);
    }

    public function test_an_assessment_encode_without_nurse_notes_saves_it_null(): void
    {
        // D-76: the box is not rendered on this form, so the key is absent
        // (the payload helper never sends it).
        $visit = $this->makeVisit('assessment');

        $this->save($visit, $this->assessmentPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('nurse.queue'));

        $this->assertNull(ClearanceRecord::firstOrFail()->nurse_notes);
    }

    public function test_a_posted_nurse_notes_is_dropped_on_an_assessment(): void
    {
        // FO010-R00 has no Remarks line — a hand-built POST cannot fill it.
        $visit = $this->makeVisit('assessment');

        $this->save($visit, $this->assessmentPayload(['nurse_notes' => 'Forged remark']))
            ->assertRedirect(route('nurse.queue'));

        $this->assertNull(ClearanceRecord::firstOrFail()->nurse_notes);
    }

    public function test_a_clearance_encode_still_saves_the_physical_signs_rows(): void
    {
        $visit = $this->makeVisit('clearance');

        $this->save($visit, EncodePayload::make(['ps_skin' => '1', 'ps_heart' => '0']))
            ->assertRedirect(route('nurse.queue'));

        $record = ClearanceRecord::firstOrFail();

        $this->assertTrue($record->ps_skin);
        $this->assertFalse($record->ps_heart);
    }

    // ── 2. Validation ─────────────────────────────────────────────────────────

    public function test_a_forged_condition_key_is_rejected(): void
    {
        $visit = $this->makeVisit('assessment');

        $this->save($visit, $this->assessmentPayload([
            'medical_history' => ['patient' => ['asthma', 'lycanthropy']],
        ]))->assertSessionHasErrors('medical_history.patient.1');

        $this->assertDatabaseCount('medical_assessments', 0);
    }

    public function test_a_forged_immunization_key_is_rejected(): void
    {
        $visit = $this->makeVisit('assessment');

        $this->save($visit, $this->assessmentPayload([
            'immunizations' => ['given' => ['not_a_vaccine']],
        ]))->assertSessionHasErrors('immunizations.given.0');

        $this->assertDatabaseCount('medical_assessments', 0);
    }

    public function test_a_specify_text_for_a_row_without_a_box_is_rejected(): void
    {
        // Only the rows the paper gives a blank line may carry a text.
        $visit = $this->makeVisit('assessment');

        $this->save($visit, $this->assessmentPayload([
            'medical_history' => ['patient' => ['asthma'], 'specify' => ['asthma' => 'since birth']],
        ]))->assertSessionHasErrors('medical_history.specify');
    }

    public function test_a_specify_text_over_its_limit_is_rejected(): void
    {
        $visit = $this->makeVisit('assessment');

        $this->save($visit, $this->assessmentPayload([
            'medical_history' => [
                'patient' => ['allergy'],
                'specify' => ['allergy' => str_repeat('a', MedicalAssessment::SPECIFY_MAX_LENGTH + 1)],
            ],
        ]))->assertSessionHasErrors('medical_history.specify.allergy');
    }

    public function test_a_surgical_history_over_its_limits_is_rejected(): void
    {
        $visit = $this->makeVisit('assessment');

        $this->save($visit, $this->assessmentPayload([
            'surgical_history' => [
                'procedures' => str_repeat('b', MedicalAssessment::PROCEDURES_MAX_LENGTH + 1),
                'date_done' => str_repeat('c', MedicalAssessment::DATE_DONE_MAX_LENGTH + 1),
            ],
        ]))->assertSessionHasErrors(['surgical_history.procedures', 'surgical_history.date_done']);
    }

    // ── 3. The encode screen ──────────────────────────────────────────────────

    public function test_the_sections_render_only_for_an_assessment_visit(): void
    {
        $assessment = $this->makeVisit('assessment');
        $clearance = $this->makeVisit('clearance');
        $nurse = $this->nurse();

        $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', $assessment))
            ->assertOk()
            ->assertSee('Past Medical History &amp; Family History', false)
            ->assertSee('II. Immunization Profile')
            ->assertSee('III. Family Planning Access')
            ->assertSee('Past Surgical History / Procedures')
            ->assertSee('Hypertension (Highest BP: ___ mmHg)')
            ->assertSee('Pneumococcal Vaccine')
            // The clinic does not re-encode the twelve rows on this form.
            ->assertSee('Physical Signs Disorder of (Self Assessment)')
            ->assertDontSee('name="ps_skin"', false);

        $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', $clearance))
            ->assertOk()
            ->assertDontSee('II. Immunization Profile')
            ->assertDontSee('Past Surgical History / Procedures')
            ->assertSee('Health Questionnaire')
            ->assertSee('name="ps_skin"', false);
    }

    public function test_the_read_only_view_shows_the_saved_checks(): void
    {
        $visit = $this->makeVisit('assessment');
        $this->save($visit, $this->assessmentPayload());

        $html = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            // The saved texts come back in their boxes.
            ->assertSee('value="Peanuts"', false)
            ->assertSee('value="Typhoid (2024)"', false)
            ->assertSee('value="Appendectomy"', false)
            ->assertSee('value="Grade 5"', false)
            ->getContent();

        // A ticked box comes back checked AND disabled; an unticked one only
        // disabled. The attributes sit on separate lines, so match loosely.
        $this->assertMatchesRegularExpression(
            '/medical_history\[patient\]\[\]"\s+value="allergy".*?checked\s+disabled/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/immunizations\[given\]\[\]" value="bcg".*?checked\s+disabled/s',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/medical_history\[family\]\[\]"\s+value="allergy"[^>]*checked/s',
            $html,
        );

        // The Yes / No pair re-checks its saved answer — a numeric array key
        // would arrive as an int and never match the '1' the helper returns.
        $this->assertMatchesRegularExpression(
            '/family_planning_access" value="1".*?checked\s+disabled/s',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/family_planning_access" value="0"[^>]*checked/s',
            $html,
        );
    }

    // ── 4. Sections V and VI — female students only (D-70) ────────────────────

    /**
     * A filled-in V + VI + physical examination payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function femalePayload(array $overrides = []): array
    {
        return $this->assessmentPayload([
            'menstrual_history' => [
                'menarche_age' => '13',
                'first_intercourse_age' => '',
                'lmp' => '2026-09-01',
                'period_days' => '5',
                'pads_per_day' => '0',
                'cycle_days' => '28',
                'contraceptive' => 'None',
                'menopause' => '0',
                'menopause_age' => '',
            ],
            'ob_history' => [
                'gravida' => '1',
                'para' => '1',
                'term' => '1',
                'preterm' => '0',
                'abortion' => '0',
                'living' => '1',
                'delivery_type' => 'Normal spontaneous delivery',
                'pih' => '1',
            ],
            'physical_exam' => [
                'heent' => ['findings' => ['normal'], 'others' => null],
                'dre' => ['findings' => ['not_applicable'], 'others' => 'Deferred'],
            ],
            ...$overrides,
        ]);
    }

    public function test_a_female_encode_stores_the_menstrual_and_ob_history(): void
    {
        $visit = $this->makeVisit('assessment', 'F');

        $this->save($visit, $this->femalePayload())->assertRedirect(route('nurse.queue'));

        $assessment = ClearanceRecord::firstOrFail()->medicalAssessment;

        $this->assertSame([
            'menarche_age' => 13,
            // An untouched box is null, not 0 — and 0 pads a day is a real
            // answer, which is why pads_per_day below is 0 and not null.
            'first_intercourse_age' => null,
            'lmp' => '2026-09-01',
            'period_days' => 5,
            'pads_per_day' => 0,
            'cycle_days' => 28,
            'contraceptive' => 'None',
            'menopause' => false,
            'menopause_age' => null,
        ], $assessment->menstrual_history);

        $this->assertSame([
            'gravida' => 1,
            'para' => 1,
            'term' => 1,
            'preterm' => 0,
            'abortion' => 0,
            'living' => 1,
            'delivery_type' => 'Normal spontaneous delivery',
            'pih' => true,
        ], $assessment->ob_history);
    }

    public function test_a_male_encode_stores_null_for_both_sections_even_when_posted(): void
    {
        // The greyed inputs are a courtesy; THIS is the rule (D-70).
        $visit = $this->makeVisit('assessment', 'M');

        $this->save($visit, $this->femalePayload())->assertRedirect(route('nurse.queue'));

        $assessment = ClearanceRecord::firstOrFail()->medicalAssessment;

        $this->assertNull($assessment->menstrual_history);
        $this->assertNull($assessment->ob_history);
        // The examination is NOT sex-gated, so it still saves.
        $this->assertSame(['normal'], $assessment->physical_exam['heent']['findings']);
    }

    public function test_an_untouched_female_section_saves_all_nulls(): void
    {
        $visit = $this->makeVisit('assessment', 'F');

        $this->save($visit, EncodePayload::make())->assertRedirect(route('nurse.queue'));

        $assessment = ClearanceRecord::firstOrFail()->medicalAssessment;

        $this->assertSame(
            array_fill_keys(array_keys($assessment->menstrual_history), null),
            $assessment->menstrual_history,
        );
        $this->assertSame(
            array_fill_keys(array_keys($assessment->ob_history), null),
            $assessment->ob_history,
        );
    }

    public function test_a_value_outside_its_range_is_rejected(): void
    {
        $visit = $this->makeVisit('assessment', 'F');

        $this->save($visit, $this->femalePayload([
            'menstrual_history' => ['menarche_age' => '2', 'cycle_days' => '200'],
            'ob_history' => ['gravida' => '25'],
        ]))->assertSessionHasErrors([
            'menstrual_history.menarche_age',
            'menstrual_history.cycle_days',
            'ob_history.gravida',
        ]);

        $this->assertDatabaseCount('medical_assessments', 0);
    }

    public function test_a_last_menstrual_period_in_the_future_is_rejected(): void
    {
        $visit = $this->makeVisit('assessment', 'F');

        $this->save($visit, $this->femalePayload([
            'menstrual_history' => ['lmp' => today()->addDay()->toDateString()],
        ]))->assertSessionHasErrors('menstrual_history.lmp');
    }

    public function test_the_last_menstrual_period_prefills_from_the_kiosk(): void
    {
        $visit = $this->makeVisit('assessment', 'F');
        $visit->screeningResponse->update(['last_menstrual_period' => '2026-09-05']);

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('name="menstrual_history[lmp]"', false)
            ->assertSee('value="2026-09-05"', false);
    }

    // ── 5. Pertinent Physical Examination (D-70) ──────────────────────────────

    public function test_the_examination_stores_all_eight_groups(): void
    {
        $visit = $this->makeVisit('assessment', 'F');

        $this->save($visit, $this->femalePayload())->assertRedirect(route('nurse.queue'));

        $exam = ClearanceRecord::firstOrFail()->medicalAssessment->physical_exam;

        $this->assertSame(MedicalAssessment::physicalExamGroups(), array_keys($exam));
        $this->assertSame(['findings' => ['not_applicable'], 'others' => 'Deferred'], $exam['dre']);
        // A group nobody ticked is recorded empty, not missing.
        $this->assertSame(['findings' => [], 'others' => null], $exam['skin']);
    }

    public function test_an_unknown_exam_group_or_finding_key_is_dropped(): void
    {
        $visit = $this->makeVisit('assessment', 'F');

        $this->save($visit, $this->femalePayload([
            'physical_exam' => [
                // A finding borrowed from another group, and a group that does
                // not exist at all — both are meaningless, so both are dropped.
                'heent' => ['findings' => ['normal', 'enlarged_prostate'], 'others' => null],
                'wizardry' => ['findings' => ['normal'], 'others' => null],
            ],
        ]))->assertRedirect(route('nurse.queue'));

        $exam = ClearanceRecord::firstOrFail()->medicalAssessment->physical_exam;

        $this->assertSame(['normal'], $exam['heent']['findings']);
        $this->assertArrayNotHasKey('wizardry', $exam);
    }

    public function test_an_exam_others_text_over_its_limit_is_rejected(): void
    {
        $visit = $this->makeVisit('assessment', 'F');

        $this->save($visit, $this->femalePayload([
            'physical_exam' => [
                'heent' => [
                    'findings' => [],
                    'others' => str_repeat('z', MedicalAssessment::SPECIFY_MAX_LENGTH + 1),
                ],
            ],
        ]))->assertSessionHasErrors('physical_exam.heent.others');
    }

    // ── 6. The encode screen (D-70) ───────────────────────────────────────────

    public function test_a_male_student_sees_the_two_sections_greyed_and_disabled(): void
    {
        $visit = $this->makeVisit('assessment', 'M');

        $html = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('V. Menstrual History')
            ->assertSee('VI. OB/Pregnancy History')
            ->assertSee('For female students only')
            // The examination is open to everyone.
            ->assertSee('F. DIGITAL RECTAL EXAMINATION (DRE)')
            ->getContent();

        $this->assertMatchesRegularExpression('/menstrual_history\[menarche_age\]"[^>]*\sdisabled\s+class=/s', $html);
        $this->assertMatchesRegularExpression('/ob_history\[gravida\]"[^>]*\sdisabled\s+class=/s', $html);
        // @disabled emits a BARE `disabled`, and Tailwind's disabled:
        // variants put that word inside every class attribute too — so the
        // patterns anchor on the attribute's own position in the tag.
        $this->assertDoesNotMatchRegularExpression(
            '/physical_exam\[heent\]\[findings\]\[\]" value="normal"[^>]*\sdisabled>/s',
            $html,
        );
    }

    public function test_a_female_student_gets_the_two_sections_enabled(): void
    {
        $visit = $this->makeVisit('assessment', 'F');

        $html = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertDontSee('For female students only')
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/menstrual_history\[menarche_age\]"[^>]*\sdisabled\s+class=/s',
            $html,
        );
    }

    public function test_the_read_only_view_shows_the_saved_sections(): void
    {
        $visit = $this->makeVisit('assessment', 'F');
        $this->save($visit, $this->femalePayload());

        $html = $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('value="Normal spontaneous delivery"', false)
            ->assertSee('value="Deferred"', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/physical_exam\[dre\]\[findings\]\[\]" value="not_applicable".*?checked\s+disabled/s',
            $html,
        );
        $this->assertMatchesRegularExpression('/ob_history\[pih\]" value="1".*?checked\s+disabled/s', $html);
    }

    public function test_a_clearance_visit_shows_no_examination_and_stores_none(): void
    {
        $visit = $this->makeVisit('clearance', 'F');

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertDontSee('Pertinent Physical Examination')
            ->assertDontSee('V. Menstrual History');

        $this->save($visit, $this->femalePayload())->assertRedirect(route('nurse.queue'));

        $this->assertDatabaseCount('medical_assessments', 0);
    }
}
