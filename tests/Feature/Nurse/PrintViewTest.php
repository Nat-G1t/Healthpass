<?php

declare(strict_types=1);

namespace Tests\Feature\Nurse;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\ScreeningResponse;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module PRT (FR-PRT-01..04, BR-17) — printable Medical Clearance
 * (DHVSU-QSP-OSS-004-FO002-R03): nurse-only, encoded visits only, populated
 * per FR-PRT-02 as amended by D-22 (physical signs + pregnancy shade from the
 * kiosk questionnaire; no Student No.), Respiratory Rate intentionally blank,
 * physician block pre-printed from the record's stored defaults.
 */
class PrintViewTest extends TestCase
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
     * A captured visit with vitals + questionnaire.
     *
     * @param  array<string, mixed>  $screening
     */
    private function makeVisit(array $screening = []): ClinicVisit
    {
        $student = User::factory()->create(['role' => 'student', 'name' => 'Ana Cruz']);
        $student->studentProfile()->create([
            'college_id' => $this->college()->id,
            'student_number' => '2023-000111',
            'first_name' => 'Ana',
            'middle_name' => 'Reyes',
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
            'reference_no' => 'HP-2026-T001',
            'student_id' => $student->id,
            'college_id' => $this->college()->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'privacy_consent_at' => now(),
            'checked_in_at' => now()->subMinutes(5),
        ]);

        VitalSigns::create([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 165.0,
            'weight_kg' => 60.0,
            'bmi' => 21.5,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 115,
            'bp_diastolic' => 75,
            'entry_method' => 'manual',
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            'is_bmi_flagged' => false,
        ]);

        ScreeningResponse::create(array_merge([
            'clinic_visit_id' => $visit->id,
            'vision' => false,
            'hearing' => false,
            'nose' => false,
            'skin' => false,
            'respiratory' => true,
            'heart' => false,
            'digestive' => false,
            'bones' => false,
            'nervous' => false,
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
        $this->encode($visit, $this->nurse());

        $this->get(route('nurse.visits.print', $visit))->assertRedirect(route('login'));
    }

    public function test_non_nurse_role_is_redirected_to_own_dashboard(): void
    {
        $visit = $this->makeVisit();
        $this->encode($visit, $this->nurse());
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student)
            ->get(route('nurse.visits.print', $visit))
            ->assertRedirect('/student/dashboard');
    }

    public function test_unknown_visit_returns_404(): void
    {
        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.print', 999999))
            ->assertNotFound();
    }

    public function test_captured_not_yet_encoded_visit_returns_404(): void
    {
        $visit = $this->makeVisit();

        $this->actingAs($this->nurse())
            ->get(route('nurse.visits.print', $visit))
            ->assertNotFound();
    }

    // ── 2. Populated fields (FR-PRT-02) ───────────────────────────────────────

    public function test_form_populates_identity_vitals_result_and_encode_date(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $record = $this->encode($visit, $nurse);

        $this->actingAs($nurse)
            ->get(route('nurse.visits.print', $visit))
            ->assertOk()
            // Form identity (FR-PRT-01)
            ->assertSee('MEDICAL CLEARANCE')
            ->assertSee('Office of Student Welfare and Formation')
            ->assertSee('Health Services Unit')
            ->assertSee('DHVSU-QSP-OSS-004-FO002-R03')
            // Student identity — name split into the form's three segments
            ->assertSeeInOrder(['Cruz', 'SURNAME', 'Ana', 'FIRST NAME', 'Reyes', 'MIDDLE NAME'])
            ->assertSee('Bachelor of Science in Information Technology')
            ->assertSee('3rd Year')
            // College is NOT printed (D-25): the official form has no college
            // box, and it no longer rides the Course, Year & Section value.
            ->assertDontSee('College of Computing Studies')
            ->assertSee('Bacolor, Pampanga')
            ->assertSee('May 10, 2004')
            ->assertSee('San Fernando')
            // Age computed from DOB at the encode date, not registration
            ->assertSee((string) (int) $visit->student->studentProfile->date_of_birth->diffInYears($record->encoded_at))
            // Vitals incl. BMI (FR-PRT-02)
            ->assertSee('165.0 cm')
            ->assertSee('60.0 kg')
            ->assertSee('21.5')
            ->assertSee('36.5 °C', false)
            ->assertSee('115/75 mmHg')
            ->assertSee('75 bpm')
            // Respiratory Rate present on the form but intentionally blank (FR-PRT-03)
            ->assertSee('Respiratory Rate')
            // Result + purpose + notes
            ->assertSee('FIT')
            ->assertSee('On-the-job Training')
            ->assertSee('Advised rest and hydration.')
            // Encode date on the form's Date line
            ->assertSee($record->encoded_at->format('F j, Y'))
            // NOT printed (D-22): the official form has no Student No. field.
            ->assertDontSee('Student No.')
            ->assertDontSee('2023-000111');
    }

    // ── 3. Physical signs + pregnancy shading (D-22) ──────────────────────────

    public function test_physical_signs_bubbles_shade_from_the_encoded_exam_findings(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        // The nurse recorded the physician's exam: chest/lungs YES, skin NO,
        // GUT not examined (NULL). Not the kiosk questionnaire (D-22).
        $this->encode($visit, $nurse, [
            'ps_chest_lungs' => true,
            'ps_skin' => false,
        ]);

        $html = $this->actingAs($nurse)
            ->get(route('nurse.visits.print', $visit))
            ->assertOk()
            ->getContent();

        // ps_chest_lungs=true → CHEST/LUNGS: YES shaded, NO blank
        $this->assertMatchesRegularExpression(
            '~CHEST/LUNGS</b></td>\s*<td class="bubbles"><span class="bb">●</span></td>\s*<td class="bubbles"><span class="bb"></span></td>~u',
            $html
        );
        // ps_skin=false → SKIN: YES blank, NO shaded
        $this->assertMatchesRegularExpression(
            '~SKIN</b></td>\s*<td class="bubbles"><span class="bb"></span></td>\s*<td class="bubbles"><span class="bb">●</span></td>~u',
            $html
        );
        // ps_gut NULL (not examined) → both bubbles blank
        $this->assertMatchesRegularExpression(
            '~GUT</b></td>\s*<td class="bubbles"><span class="bb"></span></td>\s*<td class="bubbles"><span class="bb"></span></td>~u',
            $html
        );
    }

    public function test_kiosk_questionnaire_does_not_shade_the_physical_signs(): void
    {
        $nurse = $this->nurse();
        // Student self-reported a respiratory issue at the kiosk, but the
        // nurse recorded no exam findings → CHEST/LUNGS must stay blank.
        $visit = $this->makeVisit(['respiratory' => true]);
        $this->encode($visit, $nurse);

        $html = $this->actingAs($nurse)
            ->get(route('nurse.visits.print', $visit))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '~CHEST/LUNGS</b></td>\s*<td class="bubbles"><span class="bb"></span></td>\s*<td class="bubbles"><span class="bb"></span></td>~u',
            $html
        );
    }

    public function test_pregnant_answer_shades_yes_and_prints_lmp(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit(['is_pregnant' => true, 'last_menstrual_period' => '2026-05-20']);
        $this->encode($visit, $nurse);

        $html = $this->actingAs($nurse)
            ->get(route('nurse.visits.print', $visit))
            ->assertOk()
            ->assertSee('May 20, 2026')
            ->getContent();

        $this->assertMatchesRegularExpression(
            '~Are you Pregnant</span>\s*<span class="bb">●</span>\s*<b>YES</b>~u',
            $html
        );
    }

    public function test_not_pregnant_answer_shades_no_and_leaves_lmp_blank(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();          // is_pregnant false by default
        $this->encode($visit, $nurse);

        $html = $this->actingAs($nurse)
            ->get(route('nurse.visits.print', $visit))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '~Are you Pregnant</span>\s*<span class="bb"></span>\s*<b>YES</b>\s*<span class="bb"[^>]*>●</span>\s*<b>NO</b>~u',
            $html
        );
    }

    public function test_physician_block_is_preprinted_from_record_defaults(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse);

        // The migration defaults (§7.5) fill physician_name / license — the
        // view must read them from the record, not hardcode them.
        $this->actingAs($nurse)
            ->get(route('nurse.visits.print', $visit))
            ->assertOk()
            ->assertSee('REYNALDO S. ALIPIO, MD')
            ->assertSee('University Physician')
            ->assertSee('License No. 60252');
    }

    public function test_all_locked_purposes_print_as_options(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse, ['purpose' => null, 'nurse_notes' => null]);

        $response = $this->actingAs($nurse)
            ->get(route('nurse.visits.print', $visit))
            ->assertOk();

        // The purpose list is pre-printed on the form even when none is set.
        foreach (ClearanceRecord::PURPOSES as $purpose) {
            $response->assertSee($purpose);
        }
        $response->assertSee('Others, Specify');
        $response->assertDontSee('Case Category:');
    }

    public function test_others_purpose_shades_its_bubble_and_prints_the_specified_event(): void
    {
        $nurse = $this->nurse();
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse, [
            'purpose' => ClearanceRecord::PURPOSE_OTHERS,
            'purpose_other' => 'Regional quiz bee at PSU Lubao',
        ]);

        $html = $this->actingAs($nurse)
            ->get(route('nurse.visits.print', $visit))
            ->assertOk()
            ->assertSee('Regional quiz bee at PSU Lubao')
            ->getContent();

        $this->assertMatchesRegularExpression(
            '~<span class="bb">●</span> Others, Specify:~u',
            $html
        );
    }
}
