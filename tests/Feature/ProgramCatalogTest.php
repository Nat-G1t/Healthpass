<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-42 — the program catalog is college-dependent and the SERVER decides.
 *
 * The cascading dropdowns on registration Step 2 and the profile Edit modal
 * are convenience only; these tests drive the endpoints directly, the way a
 * hand-edited form would, and prove the request classes refuse anything the
 * submitted college does not offer.
 */
class ProgramCatalogTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function college(string $code, string $name): College
    {
        return College::firstOrCreate(['code' => $code], ['name' => $name]);
    }

    private function ccs(): College
    {
        return $this->college('CCS', 'College of Computing Studies');
    }

    private function cea(): College
    {
        return $this->college('CEA', 'College of Architecture and Engineering');
    }

    private function graduateStudies(): College
    {
        return $this->college('GS', 'Graduate Studies');
    }

    /** A complete, valid Step 2 payload; override to exercise one field. */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'student_number' => '2024-00001',
            'college_id' => $this->ccs()->id,
            'sex' => 'M',
            'course' => 'Bachelor of Science in Information Technology',
            'year_level' => '1',
            'date_of_birth' => '2003-05-15',
            'place_of_birth' => 'Angeles City, Pampanga',
            'civil_status' => 'Single',
            'address' => '123 Rizal St., Angeles City',
            'email' => 'juan@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ], $overrides);
    }

    /** POSTs Step 2 with consent already granted (Step 1 is not under test). */
    private function submitRegistration(array $overrides = [])
    {
        return $this->withSession(['reg.consent_at' => now()->toIso8601String()])
            ->post(route('register.info.store'), $this->registrationPayload($overrides));
    }

    /** A CCS student whose profile carries a real CCS program. */
    private function ccsStudent(): User
    {
        $user = User::factory()->create([
            'role' => 'student',
            'name' => 'Juan Cruz',
            'email' => 'juan@example.com',
        ]);

        StudentProfile::factory()->create([
            'user_id' => $user->id,
            'college_id' => $this->ccs()->id,
            'course' => 'Bachelor of Science in Information Technology',
            'year_level' => '3',
        ]);

        return $user->fresh();
    }

    /** A complete, valid profile-edit payload for the CCS student above. */
    private function profilePayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'email' => 'juan@example.com',
            'college_id' => $this->ccs()->id,
            'course' => 'Bachelor of Science in Information Technology',
            'year_level' => '3',
            'date_of_birth' => '2003-05-15',
            'place_of_birth' => 'Angeles City, Pampanga',
            'civil_status' => 'Single',
            'address' => '123 Rizal St., Angeles City',
        ], $overrides);
    }

    // ── Registration (FR-REG-03) ─────────────────────────────────────────────

    public function test_registration_rejects_a_program_belonging_to_another_college(): void
    {
        $this->cea(); // the program below is CEA's, but CCS is submitted

        $this->submitRegistration(['course' => 'Bachelor of Science in Civil Engineering'])
            ->assertSessionHasErrors('course');
    }

    public function test_registration_rejects_a_year_level_the_college_does_not_offer(): void
    {
        // CCS runs 1–4; only Engineering/Architecture has a 5th year.
        $this->submitRegistration(['year_level' => '5'])
            ->assertSessionHasErrors('year_level');

        // Graduate Studies runs 1–2, so a 3rd year does not exist there.
        $this->submitRegistration([
            'college_id' => $this->graduateStudies()->id,
            'course' => 'Master in Information Technology',
            'year_level' => '3',
        ])->assertSessionHasErrors('year_level');
    }

    public function test_registration_accepts_a_valid_college_program_and_year(): void
    {
        $this->submitRegistration()
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('register.verify'));
    }

    public function test_registration_with_no_college_blames_the_college_not_the_program(): void
    {
        $response = $this->submitRegistration(['college_id' => '']);

        $response->assertSessionHasErrors([
            'course' => 'Select your college first, then choose your program.',
        ]);
    }

    public function test_registration_step_two_ships_the_catalog_and_disables_the_program_select(): void
    {
        $this->ccs();

        $response = $this->withSession(['reg.consent_at' => now()->toIso8601String()])
            ->get(route('register.info'));

        $response->assertOk();
        // The cascade's data source and its disabled-until-a-college-is-chosen binding.
        $response->assertSee('Bachelor of Science in Information Systems', escape: false);
        $response->assertSee('x-bind:disabled="! collegeId"', escape: false);
    }

    // ── Profile edit (FR-STU-09) ─────────────────────────────────────────────

    public function test_profile_edit_rejects_a_cross_college_program(): void
    {
        $student = $this->ccsStudent();
        $this->cea();

        $this->actingAs($student)->patch(
            route('student.id-profile.update'),
            $this->profilePayload(['course' => 'Bachelor of Science in Civil Engineering'])
        )->assertSessionHasErrors('course');

        // Nothing saved — the profile keeps its CCS program.
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $student->id,
            'course' => 'Bachelor of Science in Information Technology',
        ]);
    }

    public function test_transfer_keeping_the_old_colleges_program_is_rejected(): void
    {
        $student = $this->ccsStudent();
        $cea = $this->cea();

        // Same program, new college — the program is not offered there.
        $this->actingAs($student)->patch(
            route('student.id-profile.update'),
            $this->profilePayload(['college_id' => $cea->id])
        )->assertSessionHasErrors('course');

        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $student->id,
            'college_id' => $this->ccs()->id,
        ]);
    }

    public function test_transfer_is_accepted_when_the_program_changes_too(): void
    {
        $student = $this->ccsStudent();
        $cea = $this->cea();

        $this->actingAs($student)->patch(
            route('student.id-profile.update'),
            $this->profilePayload([
                'college_id' => $cea->id,
                'course' => 'Bachelor of Science in Architecture',
                'year_level' => '5', // CEA's fifth year exists; CCS's does not
            ])
        )->assertSessionHasNoErrors();

        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $student->id,
            'college_id' => $cea->id,
            'course' => 'Bachelor of Science in Architecture',
            'year_level' => '5',
        ]);
    }
}
