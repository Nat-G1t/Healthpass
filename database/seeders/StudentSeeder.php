<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StudentSeeder extends Seeder
{
    public function run(): void
    {
        // Pre-load all colleges keyed by code — avoids N+1 queries in the loop.
        $colleges = College::all()->keyBy('code');

        foreach ($this->studentData() as $row) {
            $college = $colleges[$row['college']];

            $user = User::create([
                'role'              => 'student',
                'name'              => "{$row['first']} {$row['last']}",
                'email'             => $row['email'],
                'email_verified_at' => now(),
                'password'          => Hash::make('password'),
                'status'            => 'active',
            ]);

            StudentProfile::factory()
                ->forCollege($college)
                ->create([
                    'user_id'        => $user->id,
                    'first_name'     => $row['first'],
                    'last_name'      => $row['last'],
                    'sex'            => $row['sex'],
                    'student_number' => $row['student_no'],
                    'course'         => $row['course'],
                    'year_level'     => $row['year'],
                    'date_of_birth'  => $row['dob'],
                    'qr_token'       => Str::random(32),
                ]);
        }
    }

    /**
     * 30 demo students spread across all 12 colleges.
     * Distribution: CCS×4, COE×3, CEA×3, CBS×3, CAS×3, CSSP×2,
     * CHTM×2, CIT×2, LAW×2, GS×2, SHS×2, LHS×2 = 30
     *
     * Emails are deterministic so they can be listed in docs/dev-notes.md.
     */
    private function studentData(): array
    {
        return [
            // ── CCS — College of Computing Studies (4 students) ──────────────
            [
                'first' => 'Juan',   'last' => 'Santos',    'sex' => 'M',
                'college' => 'CCS',
                'course' => 'Bachelor of Science in Computer Science',
                'year' => '4th Year', 'dob' => '2002-03-15',
                'student_no' => '2021060001',
                'email' => 'juan.santos@psu.edu.ph',
            ],
            [
                'first' => 'Maria',  'last' => 'Reyes',     'sex' => 'F',
                'college' => 'CCS',
                'course' => 'Bachelor of Science in Information Technology',
                'year' => '3rd Year', 'dob' => '2003-07-22',
                'student_no' => '2022060002',
                'email' => 'maria.reyes@psu.edu.ph',
            ],
            [
                'first' => 'Carlo',  'last' => 'Cruz',      'sex' => 'M',
                'college' => 'CCS',
                'course' => 'Bachelor of Science in Information Systems',
                'year' => '2nd Year', 'dob' => '2004-11-09',
                'student_no' => '2023060003',
                'email' => 'carlo.cruz@psu.edu.ph',
            ],
            [
                'first' => 'Angel',  'last' => 'Garcia',    'sex' => 'F',
                'college' => 'CCS',
                'course' => 'Bachelor of Science in Computer Science',
                'year' => '1st Year', 'dob' => '2005-05-18',
                'student_no' => '2024060004',
                'email' => 'angel.garcia@psu.edu.ph',
            ],

            // ── COE — College of Education (3 students) ──────────────────────
            [
                'first' => 'Jose',   'last' => 'Bautista',  'sex' => 'M',
                'college' => 'COE',
                'course' => 'Bachelor of Secondary Education',
                'year' => '4th Year', 'dob' => '2001-09-03',
                'student_no' => '2021010005',
                'email' => 'jose.bautista@psu.edu.ph',
            ],
            [
                'first' => 'Sofia',  'last' => 'Ocampo',    'sex' => 'F',
                'college' => 'COE',
                'course' => 'Bachelor of Elementary Education',
                'year' => '2nd Year', 'dob' => '2004-01-27',
                'student_no' => '2023010006',
                'email' => 'sofia.ocampo@psu.edu.ph',
            ],
            [
                'first' => 'Darwin', 'last' => 'Mendoza',   'sex' => 'M',
                'college' => 'COE',
                'course' => 'Bachelor of Physical Education',
                'year' => '3rd Year', 'dob' => '2003-06-14',
                'student_no' => '2022010007',
                'email' => 'darwin.mendoza@psu.edu.ph',
            ],

            // ── CEA — College of Architecture and Engineering (3 students) ───
            [
                'first' => 'Marco',    'last' => 'Torres',   'sex' => 'M',
                'college' => 'CEA',
                'course' => 'Bachelor of Science in Civil Engineering',
                'year' => '3rd Year', 'dob' => '2002-12-30',
                'student_no' => '2022020008',
                'email' => 'marco.torres@psu.edu.ph',
            ],
            [
                'first' => 'Kristine', 'last' => 'Ramirez',  'sex' => 'F',
                'college' => 'CEA',
                'course' => 'Bachelor of Science in Architecture',
                'year' => '2nd Year', 'dob' => '2004-04-05',
                'student_no' => '2023020009',
                'email' => 'kristine.ramirez@psu.edu.ph',
            ],
            [
                'first' => 'Paolo',    'last' => 'Flores',   'sex' => 'M',
                'college' => 'CEA',
                'course' => 'Bachelor of Science in Electrical Engineering',
                'year' => '4th Year', 'dob' => '2001-08-19',
                'student_no' => '2021020010',
                'email' => 'paolo.flores@psu.edu.ph',
            ],

            // ── CBS — College of Business Studies (3 students) ───────────────
            [
                'first' => 'Ana',      'last' => 'Mercado',  'sex' => 'F',
                'college' => 'CBS',
                'course' => 'Bachelor of Science in Accountancy',
                'year' => '3rd Year', 'dob' => '2002-02-11',
                'student_no' => '2022030011',
                'email' => 'ana.mercado@psu.edu.ph',
            ],
            [
                'first' => 'Kenneth',  'last' => 'Castillo', 'sex' => 'M',
                'college' => 'CBS',
                'course' => 'Bachelor of Science in Business Administration',
                'year' => '2nd Year', 'dob' => '2004-10-07',
                'student_no' => '2023030012',
                'email' => 'kenneth.castillo@psu.edu.ph',
            ],
            [
                'first' => 'Maricel',  'last' => 'Gonzales', 'sex' => 'F',
                'college' => 'CBS',
                'course' => 'Bachelor of Science in Marketing Management',
                'year' => '4th Year', 'dob' => '2001-07-16',
                'student_no' => '2021030013',
                'email' => 'maricel.gonzales@psu.edu.ph',
            ],

            // ── CAS — College of Arts and Science (3 students) ───────────────
            [
                'first' => 'Jayson',  'last' => 'Diaz',    'sex' => 'M',
                'college' => 'CAS',
                'course' => 'Bachelor of Science in Psychology',
                'year' => '2nd Year', 'dob' => '2004-03-22',
                'student_no' => '2023040014',
                'email' => 'jayson.diaz@psu.edu.ph',
            ],
            [
                'first' => 'Camille', 'last' => 'Castro',  'sex' => 'F',
                'college' => 'CAS',
                'course' => 'Bachelor of Arts in Communication',
                'year' => '3rd Year', 'dob' => '2003-09-08',
                'student_no' => '2022040015',
                'email' => 'camille.castro@psu.edu.ph',
            ],
            [
                'first' => 'Erwin',   'last' => 'Ramos',   'sex' => 'M',
                'college' => 'CAS',
                'course' => 'Bachelor of Science in Biology',
                'year' => '1st Year', 'dob' => '2005-12-01',
                'student_no' => '2024040016',
                'email' => 'erwin.ramos@psu.edu.ph',
            ],

            // ── CSSP — College of Social Science and Philosophy (2 students) ─
            [
                'first' => 'Tricia', 'last' => 'Tolentino', 'sex' => 'F',
                'college' => 'CSSP',
                'course' => 'Bachelor of Science in Social Work',
                'year' => '3rd Year', 'dob' => '2003-05-30',
                'student_no' => '2022050017',
                'email' => 'tricia.tolentino@psu.edu.ph',
            ],
            [
                'first' => 'Luis',   'last' => 'Villanueva', 'sex' => 'M',
                'college' => 'CSSP',
                'course' => 'Bachelor of Arts in Sociology',
                'year' => '2nd Year', 'dob' => '2004-08-25',
                'student_no' => '2023050018',
                'email' => 'luis.villanueva@psu.edu.ph',
            ],

            // ── CHTM — College of Hospitality and Management (2 students) ────
            [
                'first' => 'Diane', 'last' => 'Padilla', 'sex' => 'F',
                'college' => 'CHTM',
                'course' => 'Bachelor of Science in Hospitality Management',
                'year' => '2nd Year', 'dob' => '2004-06-12',
                'student_no' => '2023070019',
                'email' => 'diane.padilla@psu.edu.ph',
            ],
            [
                'first' => 'Bryan', 'last' => 'Aquino',  'sex' => 'M',
                'college' => 'CHTM',
                'course' => 'Bachelor of Science in Tourism Management',
                'year' => '3rd Year', 'dob' => '2003-01-17',
                'student_no' => '2022070020',
                'email' => 'bryan.aquino@psu.edu.ph',
            ],

            // ── CIT — College of Industrial Technology (2 students) ──────────
            [
                'first' => 'Jennifer', 'last' => 'Manalo', 'sex' => 'F',
                'college' => 'CIT',
                'course' => 'Bachelor of Industrial Technology major in Electronics',
                'year' => '2nd Year', 'dob' => '2004-07-04',
                'student_no' => '2023080021',
                'email' => 'jennifer.manalo@psu.edu.ph',
            ],
            [
                'first' => 'Rex',      'last' => 'David',  'sex' => 'M',
                'college' => 'CIT',
                'course' => 'Bachelor of Industrial Technology major in Computer Technology',
                'year' => '3rd Year', 'dob' => '2002-11-20',
                'student_no' => '2022080022',
                'email' => 'rex.david@psu.edu.ph',
            ],

            // ── LAW — School of Law (2 students) ────────────────────────────
            [
                'first' => 'Aldrin', 'last' => 'Fernandez', 'sex' => 'M',
                'college' => 'LAW',
                'course' => 'Juris Doctor',
                'year' => '2nd Year', 'dob' => '2000-04-15',
                'student_no' => '2023090023',
                'email' => 'aldrin.fernandez@psu.edu.ph',
            ],
            [
                'first' => 'Rina',   'last' => 'Lim',       'sex' => 'F',
                'college' => 'LAW',
                'course' => 'Juris Doctor',
                'year' => '3rd Year', 'dob' => '1999-08-03',
                'student_no' => '2022090024',
                'email' => 'rina.lim@psu.edu.ph',
            ],

            // ── GS — Graduate Studies (2 students) ──────────────────────────
            [
                'first' => 'Christian', 'last' => 'Tan', 'sex' => 'M',
                'college' => 'GS',
                'course' => 'Master of Science in Computer Science',
                'year' => '1st Year', 'dob' => '1998-02-28',
                'student_no' => '2024100025',
                'email' => 'christian.tan@psu.edu.ph',
            ],
            [
                'first' => 'Sheila', 'last' => 'Go', 'sex' => 'F',
                'college' => 'GS',
                'course' => 'Master of Arts in Education',
                'year' => '2nd Year', 'dob' => '1997-10-10',
                'student_no' => '2023100026',
                'email' => 'sheila.go@psu.edu.ph',
            ],

            // ── SHS — Senior High School (2 students) ───────────────────────
            [
                'first' => 'Jessa', 'last' => 'Chua', 'sex' => 'F',
                'college' => 'SHS',
                'course' => 'STEM Strand',
                'year' => 'Grade 12', 'dob' => '2006-05-21',
                'student_no' => '2023110027',
                'email' => 'jessa.chua@psu.edu.ph',
            ],
            [
                'first' => 'Renz', 'last' => 'Ong', 'sex' => 'M',
                'college' => 'SHS',
                'course' => 'ABM Strand',
                'year' => 'Grade 11', 'dob' => '2007-09-14',
                'student_no' => '2024110028',
                'email' => 'renz.ong@psu.edu.ph',
            ],

            // ── LHS — Laboratory High School (2 students) ───────────────────
            [
                'first' => 'Hazel', 'last' => 'Abalos', 'sex' => 'F',
                'college' => 'LHS',
                'course' => 'General Secondary Education',
                'year' => 'Grade 10', 'dob' => '2008-03-06',
                'student_no' => '2024120029',
                'email' => 'hazel.abalos@psu.edu.ph',
            ],
            [
                'first' => 'Mark', 'last' => 'Pangan', 'sex' => 'M',
                'college' => 'LHS',
                'course' => 'General Secondary Education',
                'year' => 'Grade 9', 'dob' => '2009-11-25',
                'student_no' => '2024120030',
                'email' => 'mark.pangan@psu.edu.ph',
            ],
        ];
    }
}
