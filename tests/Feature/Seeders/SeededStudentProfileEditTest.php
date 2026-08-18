<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\StudentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REGRESSION (D-43, section D of the prompt): a SEEDED student must be able to
 * open My ID & Profile, edit, and save.
 *
 * The bug: the app stores `year_level` as a KEY ('1'…'5') and validates against
 * that set, but the seeder and factory wrote DISPLAY LABELS ('3rd Year',
 * 'Grade 11'). Nothing re-validates a profile on read, so the mismatch was
 * invisible until a demo account tried to save — every seeded student then hit
 * "Please select a valid year level", including during a defense demo. The
 * seeder and factory now write keys; these two students are the two shapes of
 * the bug, an ordinary 1–4 college and junior high's grade numbers.
 */
class SeededStudentProfileEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Colleges + students only — the demo visits are irrelevant here and
        // seeding them costs ~250 rows per test.
        $this->seed(CollegeSeeder::class);
        $this->seed(StudentSeeder::class);
    }

    public function test_a_seeded_student_can_save_their_profile_unchanged(): void
    {
        $this->assertSeededStudentCanSave('juan.santos@psu.edu.ph');
    }

    public function test_a_seeded_junior_high_student_can_save_their_profile(): void
    {
        // LHS stores Grades 7–10 as year_level keys '7'…'10' (D-42) — the case
        // a hardcoded 1–5 rule and a "Grade 10" label both got wrong.
        $this->assertSeededStudentCanSave('hazel.abalos@psu.edu.ph');
    }

    /**
     * Re-submits the student's own stored values. Nothing changes, so any
     * failure here is the stored data failing its own validation rules.
     */
    private function assertSeededStudentCanSave(string $email): void
    {
        $student = User::where('email', $email)->firstOrFail();
        $profile = StudentProfile::where('user_id', $student->id)->firstOrFail();

        $response = $this->actingAs($student)->patch(route('student.id-profile.update'), [
            'first_name' => $profile->first_name,
            'middle_name' => $profile->middle_name,
            'last_name' => $profile->last_name,
            'email' => $student->email,
            'college_id' => $profile->college_id,
            'course' => $profile->course,
            'year_level' => $profile->year_level,
            'date_of_birth' => $profile->date_of_birth->toDateString(),
            'place_of_birth' => $profile->place_of_birth,
            'civil_status' => $profile->civil_status,
            'address' => $profile->address,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('student.id-profile'));
    }
}
