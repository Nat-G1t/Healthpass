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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * D-64 — the University Physician shares the Clinic Dashboard (`/nurse/*`)
 * with the nurse: same pages, same encode history, labelled "Clinic". The
 * route group is `role:nurse,physician`; every other role is still refused.
 */
class PhysicianAccessTest extends TestCase
{
    use RefreshDatabase;

    private function college(): College
    {
        return College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
    }

    /** A captured visit with vitals + questionnaire (same shape as PrintFlowTest). */
    private function makeVisit(string $reference): ClinicVisit
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
            'reference_no' => $reference,
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

        ScreeningResponse::create([
            'clinic_visit_id' => $visit->id,
            ...array_fill_keys(array_keys(ScreeningResponse::QUESTIONS), false),
            'is_pregnant' => false,
        ]);

        return $visit;
    }

    /** Encode $visit as $encoder, stamping the physician block the way Save & Close does. */
    private function encode(ClinicVisit $visit, User $encoder): void
    {
        ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => $encoder->id,
            'result' => 'Fit',
            ...ClearanceRecord::physicianBlockFor($encoder),
            'encoded_at' => now(),
        ]);
        $visit->update(['status' => 'encoded']);
    }

    // ── Landing + access ─────────────────────────────────────────────────────

    public function test_physician_login_lands_on_the_clinic_dashboard(): void
    {
        User::factory()->physician()->create([
            'email' => 'physician@healthpass.test',
            'password' => Hash::make('password'),
        ]);

        $this->post(route('login'), [
            'email' => 'physician@healthpass.test',
            'password' => 'password',
        ])->assertRedirect('/nurse/dashboard');
    }

    public function test_physician_can_open_every_clinic_page(): void
    {
        $physician = User::factory()->physician()->create();
        $captured = $this->makeVisit('HP-2026-P001');
        $encoded = $this->makeVisit('HP-2026-P002');
        $this->encode($encoded, $physician);

        $this->actingAs($physician);

        $this->get(route('nurse.dashboard'))->assertOk()
            ->assertSee('Clinic Dashboard')
            ->assertSee('Physician');
        $this->get(route('nurse.queue'))->assertOk();
        $this->getJson(route('nurse.queue.feed'))->assertOk();
        $this->get(route('nurse.visits.encode', $captured))->assertOk()->assertSee('Student remarks');
        $this->get(route('nurse.visits.print', $encoded))->assertOk();
        $this->get(route('nurse.kiosk-devices'))->assertOk();
    }

    public function test_other_roles_are_still_refused(): void
    {
        foreach (['student', 'college_admin', 'director'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->get(route('nurse.dashboard'))
                ->assertRedirect()
                ->assertSessionHas('error');
        }
    }

    // ── Shared encode history (FR-NRS-09) ─────────────────────────────────────

    public function test_history_is_shared_and_shows_a_role_badge_per_encoder(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse', 'name' => 'Head Nurse']);
        $physician = User::factory()->physician('Reynaldo S. Alipio')->create();

        $this->encode($this->makeVisit('HP-2026-P010'), $nurse);
        $this->encode($this->makeVisit('HP-2026-P011'), $physician);

        // Both roles read the SAME rows, each naming who encoded and as what.
        foreach ([$nurse, $physician] as $viewer) {
            $html = $this->actingAs($viewer)->get(route('nurse.dashboard'))->assertOk()->getContent();

            $this->assertStringContainsString('HP-2026-P010', $html);
            $this->assertStringContainsString('HP-2026-P011', $html);
            $this->assertMatchesRegularExpression('~Head Nurse\s*<span[^>]*>\s*Nurse\s*</span>~', $html);
            $this->assertMatchesRegularExpression('~Reynaldo S\. Alipio\s*<span[^>]*>\s*Physician\s*</span>~', $html);
        }
    }

    public function test_read_only_notice_names_the_encoder_and_role(): void
    {
        $physician = User::factory()->physician('Reynaldo S. Alipio')->create();
        $visit = $this->makeVisit('HP-2026-P020');
        $this->encode($visit, $physician);

        $this->actingAs(User::factory()->create(['role' => 'nurse']))
            ->get(route('nurse.visits.encode', $visit))
            ->assertOk()
            ->assertSeeInOrder(['encoded', 'by', 'Reynaldo S. Alipio', '(Physician)']);
    }
}
