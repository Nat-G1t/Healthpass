<?php

namespace Database\Seeders;

use App\Models\College;
use Illuminate\Database\Seeder;

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
            ['code' => 'SHS',  'name' => 'Senior High School'],
            ['code' => 'LHS',  'name' => 'Laboratory High School'],
        ];

        foreach ($colleges as $college) {
            College::create($college);
        }
    }
}
