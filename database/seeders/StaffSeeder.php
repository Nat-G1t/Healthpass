<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        // Director — sees all colleges, no managed_college_id
        User::create([
            'role' => 'director',
            'name' => 'Clinic Director',
            'email' => 'director@healthpass.test',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        // Nurse — operates the Live Queue and Encode Result screens
        User::create([
            'role' => 'nurse',
            'name' => 'Head Nurse',
            'email' => 'nurse@healthpass.test',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        // One college admin per college — each scoped to their college (FR-AUTH-06)
        $adminColleges = [
            'COE' => 'COE Administrator',
            'CEA' => 'CEA Administrator',
            'CBS' => 'CBS Administrator',
            'CAS' => 'CAS Administrator',
            'CSSP' => 'CSSP Administrator',
            'CCS' => 'CCS Administrator',
            'CHTM' => 'CHTM Administrator',
            'CIT' => 'CIT Administrator',
            'LAW' => 'LAW Administrator',
            'GS' => 'GS Administrator',
            'SHS' => 'SHS Administrator',
            'LHS' => 'LHS Administrator',
        ];

        foreach ($adminColleges as $code => $name) {
            $college = College::where('code', $code)->firstOrFail();

            User::create([
                'role' => 'college_admin',
                'name' => $name,
                'email' => 'admin.'.strtolower($code).'@healthpass.test',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'managed_college_id' => $college->id,
                'status' => 'active',
            ]);
        }
    }
}
