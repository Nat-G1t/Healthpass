<?php

namespace Database\Factories;

use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StudentProfile>
 *
 * Generates realistic Philippine-appropriate student profile data.
 * Use forCollege($college) to constrain to college-appropriate
 * courses and year levels. Pair with User::factory() for the parent user.
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

    private static array $courses = [
        'COE'  => ['Bachelor of Secondary Education', 'Bachelor of Elementary Education', 'Bachelor of Physical Education'],
        'CEA'  => ['Bachelor of Science in Civil Engineering', 'Bachelor of Science in Architecture', 'Bachelor of Science in Electrical Engineering'],
        'CBS'  => ['Bachelor of Science in Accountancy', 'Bachelor of Science in Business Administration', 'Bachelor of Science in Marketing Management'],
        'CAS'  => ['Bachelor of Science in Psychology', 'Bachelor of Arts in Communication', 'Bachelor of Science in Biology'],
        'CSSP' => ['Bachelor of Science in Social Work', 'Bachelor of Arts in Sociology', 'Bachelor of Science in Political Science'],
        'CCS'  => ['Bachelor of Science in Computer Science', 'Bachelor of Science in Information Technology', 'Bachelor of Science in Information Systems'],
        'CHTM' => ['Bachelor of Science in Hospitality Management', 'Bachelor of Science in Tourism Management'],
        'CIT'  => ['Bachelor of Industrial Technology major in Electronics', 'Bachelor of Industrial Technology major in Computer Technology', 'Bachelor of Industrial Technology major in Automotive Technology'],
        'LAW'  => ['Juris Doctor'],
        'GS'   => ['Master of Science in Computer Science', 'Master of Arts in Education', 'Master of Public Administration'],
        'SHS'  => ['STEM Strand', 'ABM Strand', 'HUMSS Strand'],
        'LHS'  => ['General Secondary Education'],
    ];

    private static array $yearLevels = [
        'COE'  => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
        'CEA'  => ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'],
        'CBS'  => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
        'CAS'  => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
        'CSSP' => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
        'CCS'  => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
        'CHTM' => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
        'CIT'  => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
        'LAW'  => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
        'GS'   => ['1st Year', '2nd Year'],
        'SHS'  => ['Grade 11', 'Grade 12'],
        'LHS'  => ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'],
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

        return [
            // Creates a student user if user_id is not provided via override.
            'user_id' => User::factory()->state([
                'role' => 'student',
                'name' => "{$firstName} {$lastName}",
                'email_verified_at' => now(),
                'status' => 'active',
            ]),
            'college_id' => College::inRandomOrder()->value('id') ?? 1,
            'student_number' => $this->faker->unique()->numerify('20##3#####'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'sex' => $sex,
            'course' => 'Bachelor of Science in Information Technology',
            'year_level' => $this->faker->randomElement(['1st Year', '2nd Year', '3rd Year', '4th Year']),
            'date_of_birth' => $this->faker->dateTimeBetween('-25 years', '-18 years')->format('Y-m-d'),
            'place_of_birth' => $this->faker->randomElement(self::$placesOfBirth),
            'civil_status' => 'Single',
            'address' => $this->faker->randomElement(self::$addresses),
            'qr_token' => Str::random(32),
            'privacy_consent_at' => now()->subDays($this->faker->numberBetween(1, 60)),
        ];
    }

    /**
     * Scope to a specific college: sets college_id and picks a
     * course + year level appropriate for that college's programs.
     */
    public function forCollege(College $college): static
    {
        $courses = self::$courses[$college->code] ?? ['General Studies'];
        $yearLevels = self::$yearLevels[$college->code] ?? ['1st Year', '2nd Year', '3rd Year', '4th Year'];

        return $this->state(function (array $attributes) use ($college, $courses, $yearLevels) {
            return [
                'college_id' => $college->id,
                'course' => $this->faker->randomElement($courses),
                'year_level' => $this->faker->randomElement($yearLevels),
            ];
        });
    }
}
