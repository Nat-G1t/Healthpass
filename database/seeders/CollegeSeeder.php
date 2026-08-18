<?php

namespace Database\Seeders;

use App\Models\College;
use Illuminate\Database\Seeder;

/**
 * The 11 PSU main-campus units (D-43 — was 12).
 *
 * Senior High School was removed: it is not on the main-campus (bicolor)
 * program offerings, so it is not a unit HealthPass serves. The codes here are
 * the keys of config/programs.php (D-42) — a code with no catalog entry would
 * leave its students with an empty Program dropdown.
 */
class CollegeSeeder extends Seeder
{
    public function run(): void
    {
        $colleges = [
            ['code' => 'COE',  'name' => 'College of Education'],
            ['code' => 'CEA',  'name' => 'College of Architecture and Engineering'],
            ['code' => 'CBS',  'name' => 'College of Business Studies'],
            ['code' => 'CAS',  'name' => 'College of Arts and Science'],
            ['code' => 'CSSP', 'name' => 'College of Social Science and Philosophy'],
            ['code' => 'CCS',  'name' => 'College of Computing Studies'],
            ['code' => 'CHTM', 'name' => 'College of Hospitality and Management'],
            ['code' => 'CIT',  'name' => 'College of Industrial Technology'],
            ['code' => 'LAW',  'name' => 'School of Law'],
            ['code' => 'GS',   'name' => 'Graduate Studies'],
            ['code' => 'LHS',  'name' => 'Laboratory High School'],
        ];

        foreach ($colleges as $college) {
            College::create($college);
        }
    }
}
