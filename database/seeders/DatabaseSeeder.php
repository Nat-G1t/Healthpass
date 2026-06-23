<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // FK-safe order: colleges first, then staff (needs college IDs),
        // then students (needs college IDs).
        $this->call([
            CollegeSeeder::class,
            StaffSeeder::class,
            StudentSeeder::class,
            DemoClinicVisitSeeder::class, // DEV ONLY — remove when kiosk writes real visits
        ]);
    }
}
