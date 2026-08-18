<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Programs;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-43 — Senior High School is not a PSU main-campus unit, so it is gone from
 * the college list: 12 units become 11. SHS was wired into four seeders and an
 * admin account, so this guards the whole removal rather than just the one row.
 *
 * It also guards the invariant that made the removal necessary in the first
 * place: every seeded college must have a config/programs.php entry (D-42), or
 * its students get an empty Program dropdown they cannot register through.
 */
class CollegeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_eleven_colleges_are_seeded(): void
    {
        $this->seed(CollegeSeeder::class);

        $this->assertSame(11, College::count());
    }

    public function test_senior_high_school_is_gone(): void
    {
        $this->seed(CollegeSeeder::class);

        $this->assertFalse(College::where('code', 'SHS')->exists());
        $this->assertFalse(College::where('name', 'like', '%Senior High%')->exists());
    }

    public function test_every_seeded_college_has_a_program_catalog_entry(): void
    {
        $this->seed(CollegeSeeder::class);

        foreach (College::all() as $college) {
            $this->assertNotEmpty(
                Programs::forCollege($college->id),
                "College {$college->code} has no config/programs.php entry."
            );
            $this->assertNotEmpty(
                Programs::yearLevelsForCollege($college->id),
                "College {$college->code} has no year levels."
            );
        }
    }

    public function test_full_seed_leaves_no_shs_account_or_student(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(11, College::count());
        $this->assertFalse(User::where('email', 'like', '%shs%')->exists());
        $this->assertSame(28, StudentProfile::count());
    }

    /**
     * Every seeded student must carry a program their college actually offers.
     * The seeder's hand-written list had drifted — a CAS student was on a CSSP
     * program — which the free-text `course` column happily stored and D-42's
     * validation now rejects.
     */
    public function test_every_seeded_student_has_a_valid_program_for_their_college(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (StudentProfile::all() as $profile) {
            $this->assertTrue(
                Programs::isValid($profile->college_id, $profile->course),
                "Seeded student {$profile->student_number} has program \"{$profile->course}\" "
                ."which college id {$profile->college_id} does not offer."
            );
        }
    }
}
