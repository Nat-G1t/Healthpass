<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StudentRecordFixture;
use Tests\TestCase;

/**
 * FR-STU-07 / D-73 — the student's own record PAGE (the old modal is gone):
 * the vitals the clinic confirmed, the official form's twelve Physical Signs
 * rows and, on a Medical Assessment Form visit, that form's own sections.
 *
 * FR-STU-08 — nothing clinical before encoding: a captured or resting visit
 * has no page at all, and neither does another student's visit. Both are 404,
 * never 403 — a 403 would confirm the id exists.
 */
class RecordPageTest extends TestCase
{
    use RefreshDatabase;
    use StudentRecordFixture;

    // ── 1. Access control ────────────────────────────────────────────────────

    public function test_a_student_sees_their_own_encoded_visit(): void
    {
        $student = $this->makeStudent();
        $visit = $this->encodedVisit($student);

        $this->actingAs($student)
            ->get(route('student.records.show', $visit))
            ->assertOk()
            ->assertSee($visit->reference_no)
            ->assertSee('Fit')
            ->assertSee('Field Trip/Educational Tour')
            // D-64: who encoded it, with their role.
            ->assertSee('Jane Dela Cruz')
            ->assertSee('Nurse')
            ->assertSee('Save as PDF')
            ->assertSee(route('student.records.pdf', $visit), false);
    }

    public function test_another_students_visit_is_not_found(): void
    {
        $owner = $this->makeStudent();
        $visit = $this->encodedVisit($owner);

        $intruder = User::factory()->create(['role' => 'student']);

        $this->actingAs($intruder)
            ->get(route('student.records.show', $visit))
            ->assertNotFound();
    }

    /** FR-STU-08 — the clinic has not encoded it yet, so there is nothing to show. */
    public function test_a_captured_visit_has_no_page(): void
    {
        $student = $this->makeStudent();
        $visit = $this->makeVisit($student, 'clearance', 'captured');

        $this->actingAs($student)
            ->get(route('student.records.show', $visit))
            ->assertNotFound();
    }

    /** D-72 — a resting visit never reached the clinic at all. */
    public function test_a_resting_visit_has_no_page(): void
    {
        $student = $this->makeStudent();
        $visit = $this->makeVisit($student, 'clearance', 'resting');

        $this->actingAs($student)
            ->get(route('student.records.show', $visit))
            ->assertNotFound();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $visit = $this->encodedVisit($this->makeStudent());

        $this->get(route('student.records.show', $visit))->assertRedirect(route('login'));
    }

    public static function otherRoles(): array
    {
        return [
            'college admin' => ['college_admin'],
            'nurse' => ['nurse'],
            'director' => ['director'],
        ];
    }

    /** EnsureRole redirects non-students to their own dashboard — never 200. */
    #[DataProvider('otherRoles')]
    public function test_no_other_role_reaches_the_students_record_page(string $role): void
    {
        $visit = $this->encodedVisit($this->makeStudent());

        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get(route('student.records.show', $visit))
            ->assertRedirect();
    }

    // ── 2. What the page shows ───────────────────────────────────────────────

    public function test_the_page_shows_the_confirmed_vitals_and_the_clinic_notes(): void
    {
        $student = $this->makeStudent();
        $visit = $this->encodedVisit($student);

        $this->actingAs($student)
            ->get(route('student.records.show', $visit))
            ->assertOk()
            ->assertSee('Vital Signs')
            ->assertSee('165.0 cm')
            ->assertSee('115/75 mmHg')
            // D-65: respiratory rate is the clinic's own measurement.
            ->assertSee('16 breaths/min')
            ->assertSee('Advised rest and hydration.');
    }

    /** D-76 — the clearance's REMARKS are the Student remarks; the Assessment has none. */
    public function test_student_remarks_show_on_a_clearance_only(): void
    {
        $student = $this->makeStudent();
        $clearance = $this->encodedVisit($student);
        $assessment = $this->encodedVisit($student, 'assessment');

        $this->actingAs($student)
            ->get(route('student.records.show', $clearance))
            ->assertOk()
            ->assertSee('Student remarks')
            ->assertDontSee('Clinic Notes');

        $this->actingAs($student)
            ->get(route('student.records.show', $assessment))
            ->assertOk()
            ->assertDontSee('Student remarks')
            ->assertDontSee('Clinic Notes');
    }

    /** D-63 — the twelve rows, with the detail the student typed under a Yes. */
    public function test_the_page_shows_the_twelve_physical_signs_rows(): void
    {
        $student = $this->makeStudent();
        $visit = $this->encodedVisit($student);
        $visit->screeningResponse->update([
            'abdomen' => true,
            'details' => ['abdomen' => 'Stomach pain after meals'],
        ]);

        $response = $this->actingAs($student)
            ->get(route('student.records.show', $visit))
            ->assertOk();

        foreach (['SKIN', 'HEAD', 'EYES', 'EARS', 'NOSE', 'THROAT', 'HEART', 'ABDOMEN', 'BRAIN', 'MENTAL DISORDER'] as $label) {
            $response->assertSee($label);
        }

        $response->assertSee('Stomach pain after meals');
    }

    /** D-72 — the readings that were re-taken say so. */
    public function test_a_re_checked_visit_labels_the_readings_that_were_re_taken(): void
    {
        $student = $this->makeStudent();
        $visit = $this->encodedVisit($student);
        $visit->vitalSigns->update([
            'first_reading' => [
                'bp_systolic' => 150,
                'bp_diastolic' => 95,
                'heart_rate_bpm' => 88,
                'temperature_c' => 36.9,
                'is_bp_flagged' => true,
                'is_hr_flagged' => false,
                'is_temp_flagged' => false,
                'taken_at' => now()->subMinutes(30)->toIso8601String(),
            ],
        ]);

        $this->actingAs($student)
            ->get(route('student.records.show', $visit))
            ->assertOk()
            ->assertSee('150/95 mmHg')
            ->assertSee('re-checked');
    }

    // ── 3. The Medical Assessment Form's own sections (D-69/D-70) ────────────

    public function test_the_assessment_sections_render_on_an_assessment_visit(): void
    {
        $student = $this->makeStudent();
        $visit = $this->encodedVisit($student, 'assessment');

        $this->actingAs($student)
            ->get(route('student.records.show', $visit))
            ->assertOk()
            ->assertSee('I. Personal / Social History')
            ->assertSee('Illicit Drugs')
            ->assertSee('Quit')
            ->assertSee('Past Medical History &amp; Family History', false)
            ->assertSee('II. Immunization Profile')
            ->assertSee('III. Family Planning Access')
            ->assertSee('Past Surgical History / Procedures')
            ->assertSee('Pertinent Physical Examination');
    }

    public function test_a_clearance_visit_has_none_of_those_sections(): void
    {
        $student = $this->makeStudent();
        $visit = $this->encodedVisit($student, 'clearance');

        $this->actingAs($student)
            ->get(route('student.records.show', $visit))
            ->assertOk()
            ->assertDontSee('I. Personal / Social History')
            ->assertDontSee('II. Immunization Profile')
            ->assertDontSee('Pertinent Physical Examination')
            ->assertDontSee('V. Menstrual History');
    }

    /** D-70 — sections V and VI are the female student's; the paper is blank otherwise. */
    public function test_sections_five_and_six_render_only_for_a_female_student(): void
    {
        $female = $this->makeStudent('F');
        $femaleVisit = $this->encodedVisit($female, 'assessment');

        $this->actingAs($female)
            ->get(route('student.records.show', $femaleVisit))
            ->assertOk()
            ->assertSee('V. Menstrual History')
            ->assertSee('VI. OB/Pregnancy History');

        $male = $this->makeStudent('M');
        $maleVisit = $this->encodedVisit($male, 'assessment');

        $this->actingAs($male)
            ->get(route('student.records.show', $maleVisit))
            ->assertOk()
            ->assertDontSee('V. Menstrual History')
            ->assertDontSee('VI. OB/Pregnancy History');
    }

    /** The clinic's own ps_* findings print on a Clearance, so the student sees them. */
    public function test_a_clearance_page_shows_the_clinics_findings_beside_the_students_answers(): void
    {
        $student = $this->makeStudent();
        $visit = $this->makeVisit($student, 'clearance');
        $this->encode($visit, $this->nurse(), ['ps_skin' => true]);

        $this->actingAs($student)
            ->get(route('student.records.show', $visit))
            ->assertOk()
            ->assertSee('Clinic');
    }
}
