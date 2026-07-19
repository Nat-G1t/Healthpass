<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\ClinicVisit;
use App\Models\College;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoClinicVisitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the DEV seed pipeline on SQLite (the suite's engine):
 *
 *  1. a fresh seed must not crash — clinic_visits.college_id went NOT NULL
 *     with the D-17 snapshot migration, and the demo seeder used to omit it
 *     (regression check), and
 *  2. the analytics spread must actually cover all 12 colleges and both
 *     sexes, so the Director analytics (FR-ANL-04) have meaningful data
 *     in dev.
 */
class DemoClinicVisitSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_fresh_seed_gives_every_visit_a_college_snapshot(): void
    {
        $this->assertGreaterThan(0, ClinicVisit::count());
        $this->assertSame(0, ClinicVisit::whereNull('college_id')->count());
    }

    public function test_every_college_has_encoded_visits(): void
    {
        // Encoded visits grouped by the capture-time college snapshot
        // (D-17) — the rows the Director analytics count.
        $collegesWithVisits = DB::table('clinic_visits')
            ->where('clinic_visits.status', 'encoded')
            ->distinct()
            ->count('clinic_visits.college_id');

        $this->assertSame(12, College::count());
        $this->assertSame(College::count(), $collegesWithVisits);
    }

    public function test_both_sexes_appear_among_encoded_visits(): void
    {
        $sexes = DB::table('clinic_visits')
            ->join('student_profiles', 'student_profiles.user_id', '=', 'clinic_visits.student_id')
            ->where('clinic_visits.status', 'encoded')
            ->distinct()
            ->pluck('student_profiles.sex');

        $this->assertEqualsCanonicalizing(['M', 'F'], $sexes->all());
    }

    public function test_demo_seeder_is_idempotent(): void
    {
        $before = ClinicVisit::count();

        $this->seed(DemoClinicVisitSeeder::class);

        $this->assertSame($before, ClinicVisit::count());
    }
}
