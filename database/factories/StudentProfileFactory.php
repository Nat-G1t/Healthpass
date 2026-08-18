<?php

namespace Database\Factories;

use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Programs;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StudentProfile>
 *
 * Generates realistic Philippine-appropriate student profile data. Programs and
 * year levels come from config/programs.php (D-42), so a factory-made student
 * is always one the registration and profile-edit forms would accept.
 * Use forCollege($college) to constrain to a specific college's catalog.
 * Pair with User::factory() for the parent user.
 *
 * Example (in tests):
 *   StudentProfile::factory()->forCollege($ccs)->count(5)->create();
 */
class StudentProfileFactory extends Factory
{
    protected $model = StudentProfile::class;

    private static array $maleFirstNames = [
        'Juan', 'Jose', 'Miguel', 'Carlo', 'Marco', 'Paolo', 'Luis', 'Christian',
        'Nico', 'Rex', 'Mark', 'John', 'Kenneth', 'Darwin', 'Jayson', 'Erwin',
        'Aldrin', 'Bryan', 'Renz', 'Kristoffer',
    ];

    private static array $femaleFirstNames = [
        'Maria', 'Ana', 'Sofia', 'Kristine', 'Maricel', 'Angel', 'Camille',
        'Tricia', 'Diane', 'Jennifer', 'Marisol', 'Rhea', 'Carla', 'Rina',
        'Sheila', 'Jessa', 'Lovely', 'Hazel', 'Patricia', 'Aileen',
    ];

    private static array $lastNames = [
        'Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza',
        'Torres', 'Ramirez', 'Flores', 'Mercado', 'Castillo', 'Gonzales',
        'Diaz', 'Castro', 'Ramos', 'Tolentino', 'Villanueva', 'Padilla',
        'Aquino', 'Manalo', 'David', 'Fernandez', 'Lim', 'Tan', 'Go',
        'Chua', 'Ong', 'Abalos', 'Pangan',
    ];

    private static array $placesOfBirth = [
        'Angeles City, Pampanga', 'City of San Fernando, Pampanga',
        'Mabalacat City, Pampanga', 'Guagua, Pampanga', 'Porac, Pampanga',
        'Mexico, Pampanga', 'Arayat, Pampanga', 'Floridablanca, Pampanga',
        'Magalang, Pampanga', 'Bacolor, Pampanga',
    ];

    private static array $addresses = [
        'Block 5, Lot 12, Villa Verde Subd., Mabalacat City, Pampanga',
        'Lot 7, Block 3, Diamond Homes, Angeles City, Pampanga',
        '123 Holy Rosary St., Brgy. San Nicolas, City of San Fernando, Pampanga',
        '45 Purok 4, Brgy. Dolores, City of San Fernando, Pampanga',
        '78 MacArthur Highway, Brgy. Dau, Mabalacat City, Pampanga',
        '12 Rizal St., Guagua, Pampanga',
        '99 Magsaysay Ave., Angeles City, Pampanga',
        'Brgy. Sto. Cristo, Arayat, Pampanga',
        '56 B. Serrano St., Mexico, Pampanga',
        'Sitio Malupa, Brgy. Talimundoc, Porac, Pampanga',
    ];

    public function definition(): array
    {
        $sex = $this->faker->randomElement(['M', 'F']);
        $firstName = $sex === 'M'
            ? $this->faker->randomElement(self::$maleFirstNames)
            : $this->faker->randomElement(self::$femaleFirstNames);
        $lastName = $this->faker->randomElement(self::$lastNames);
        $collegeId = (int) (College::inRandomOrder()->value('id') ?? 1);

        return [
            // Creates a student user if user_id is not provided via override.
            'user_id' => User::factory()->state([
                'role' => 'student',
                'name' => "{$firstName} {$lastName}",
                'email_verified_at' => now(),
                'status' => 'active',
            ]),
            'college_id' => $collegeId,
            'student_number' => $this->faker->unique()->numerify('20##3#####'),
            'first_name' => $firstName,
            'middle_name' => $this->faker->randomElement(self::$lastNames),
            'last_name' => $lastName,
            'sex' => $sex,
            'course' => $this->programFor($collegeId),
            'year_level' => $this->yearLevelFor($collegeId),
            'date_of_birth' => $this->faker->dateTimeBetween('-25 years', '-18 years')->format('Y-m-d'),
            'place_of_birth' => $this->faker->randomElement(self::$placesOfBirth),
            'civil_status' => 'Single',
            'address' => $this->faker->randomElement(self::$addresses),
            'qr_token' => Str::random(32),
            'privacy_consent_at' => now()->subDays($this->faker->numberBetween(1, 60)),
        ];
    }

    /**
     * Scope to a specific college: sets college_id and picks a program + year
     * level that college actually offers.
     */
    public function forCollege(College $college): static
    {
        return $this->state(fn (array $attributes) => [
            'college_id' => $college->id,
            'course' => $this->programFor((int) $college->id),
            'year_level' => $this->yearLevelFor((int) $college->id),
        ]);
    }

    /**
     * A program that college offers, read from config/programs.php through
     * App\Support\Programs (D-42).
     *
     * The factory used to carry its own hand-written course list, which had
     * already drifted from what the forms accept — it offered CAS students
     * "Bachelor of Science in Psychology", a CSSP program. One catalog, one
     * source. The fallback only fires for a college with no catalog entry.
     */
    private function programFor(int $collegeId): string
    {
        $programs = Programs::forCollege($collegeId);

        return $programs === []
            ? 'Bachelor of Science in Information Technology'
            : $this->faker->randomElement($programs);
    }

    /**
     * A year level KEY that college offers — '1'…'5', or '7'…'10' for junior
     * high. Keys, not the "3rd Year" display labels the factory used to write:
     * the app stores and validates the key, so a seeded student carrying a label
     * could not save the profile edit modal at all ("Please select a valid year
     * level"). The labels live in the config beside the keys (D-42).
     */
    private function yearLevelFor(int $collegeId): string
    {
        $keys = Programs::yearLevelKeys($collegeId);

        return $keys === [] ? '1' : $this->faker->randomElement($keys);
    }
}
