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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * FR-PRT-06 / D-67 — the clinic's "Save as PDF": the SAME Blade template the
 * browser prints, rendered by dompdf and sent as a download. Clinic staff
 * only, encoded visits only, one page, and no `printed_at` stamp — saving a
 * copy is not printing one.
 *
 * dompdf is a pure-PHP HTML-to-PDF renderer, so these tests really do lay out
 * and produce a PDF; nothing is mocked.
 */
class ClearancePdfTest extends TestCase
{
    use RefreshDatabase;

    private function college(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    /** A captured visit with the kiosk's vitals + questionnaire. */
    private function makeVisit(): ClinicVisit
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
            'reference_no' => 'HP-2026-T900',
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
            'respiratory_rate' => 16,
            'entry_method' => 'manual',
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            'is_bmi_flagged' => false,
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
            'last_menstrual_period' => null,
        ]);

        return $visit;
    }

    /** Flip a visit to encoded with its 1:1 clearance record. */
    private function encode(ClinicVisit $visit, User $encoder, array $overrides = []): ClearanceRecord
    {
        $record = ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $encoder->id,
            'result' => 'Fit',
            'purpose' => 'Field Trip/Educational Tour',
            'nurse_notes' => 'Advised rest and hydration.',
            'encoded_vitals' => [
                'height_cm' => 165.0,
                'weight_kg' => 60.0,
                'bmi' => 22.0,
                'temperature_c' => 36.5,
                'bp_systolic' => 115,
                'bp_diastolic' => 75,
                'heart_rate_bpm' => 75,
                'respiratory_rate' => 16,
            ],
            'encoded_at' => now(),
            ...$overrides,
        ]);

        $visit->update(['status' => 'encoded']);

        return $record;
    }

    // ── 1. Access control ────────────────────────────────────────────────────

    public function test_a_nurse_downloads_the_clearance_as_a_pdf(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse);

        $response = $this->actingAs($nurse)
            ->get(route('nurse.visits.pdf', $visit))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringContainsString(
            'attachment; filename=HP-2026-T900-medical-clearance.pdf',
            (string) $response->headers->get('content-disposition')
        );
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_the_physician_can_download_it_too(): void
    {
        $physician = User::factory()->physician()->create();
        $visit = $this->makeVisit();
        $this->encode($visit, $physician, ClearanceRecord::physicianBlockFor($physician));

        $this->actingAs($physician)
            ->get(route('nurse.visits.pdf', $visit))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $visit = $this->makeVisit();
        $this->encode($visit, User::factory()->create(['role' => 'nurse']));

        $this->get(route('nurse.visits.pdf', $visit))->assertRedirect(route('login'));
    }

    /** Only the two clinic roles reach the Clinic Dashboard's routes (D-64). */
    public static function refusedRoles(): array
    {
        return [
            'student' => ['student'],
            'college admin' => ['college_admin'],
            'director' => ['director'],
        ];
    }

    #[DataProvider('refusedRoles')]
    public function test_every_other_role_is_refused(string $role): void
    {
        $visit = $this->makeVisit();
        $this->encode($visit, User::factory()->create(['role' => 'nurse']));

        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get(route('nurse.visits.pdf', $visit))
            ->assertRedirect();
    }

    public function test_a_captured_visit_has_no_pdf(): void
    {
        $visit = $this->makeVisit();   // never encoded

        $this->actingAs(User::factory()->create(['role' => 'nurse']))
            ->get(route('nurse.visits.pdf', $visit))
            ->assertNotFound();
    }

    // ── 2. The document itself ───────────────────────────────────────────────

    /**
     * FR-PRT-05 — one page, always. dompdf writes one `/Type /Page` object per
     * page (the page TREE is `/Type /Pages`, which the lookahead excludes), so
     * counting them counts the pages in the file it just produced.
     */
    public function test_the_pdf_is_exactly_one_page(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);
        $visit = $this->makeVisit();
        // The longest content the form can carry: a clipped note and an
        // "Others" purpose with its specified event.
        $this->encode($visit, $nurse, [
            'nurse_notes' => str_repeat('Persistent cough on exertion, advised follow-up. ', 20),
            'purpose' => 'Others, Specify',
            'purpose_other' => 'Regional quiz bee at PSU Lubao with an overnight stay',
            'ps_chest_lungs' => true,
            'ps_skin' => false,
        ]);

        $pdf = $this->actingAs($nurse)
            ->get(route('nurse.visits.pdf', $visit))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match_all('~/Type\s*/Page(?![s])~', $pdf));
    }

    /** Saving a copy is not printing one (FR-NRS-05 owns `printed_at`). */
    public function test_downloading_the_pdf_does_not_stamp_printed_at(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);
        $visit = $this->makeVisit();
        $record = $this->encode($visit, $nurse);

        $this->actingAs($nurse)
            ->get(route('nurse.visits.pdf', $visit))
            ->assertOk();

        $this->assertNull($record->fresh()->printed_at);
    }

    /** The read-only encode screen offers the download beside Reprint. */
    public function test_the_encode_screen_links_to_the_pdf_once_encoded(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);
        $visit = $this->makeVisit();
        $this->encode($visit, $nurse);

        $this->actingAs($nurse)
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSee('Save as PDF')
            ->assertSee(route('nurse.visits.pdf', $visit), false);
    }
}
