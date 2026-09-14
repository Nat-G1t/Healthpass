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
            // DEV ONLY — CCS batches in every state, for Batch Tracking,
            // its Batch Results card (D-55) and the D-52 cancel flow.
            // Runs AFTER DemoClinicVisitSeeder on purpose: that seeder skips
            // itself if any APT-2026-9xxx row exists, so it must go first.
            DemoBatchSeeder::class,
        ]);
    }
}
