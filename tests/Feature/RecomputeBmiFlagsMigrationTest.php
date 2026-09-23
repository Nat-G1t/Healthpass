<?php

namespace Tests\Feature;

use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-78 — the one-time data migration that recomputes `is_bmi_flagged` on
 * every existing row: flagged when BMI < 18.5 or ≥ 25.0 (`bmi` is NOT NULL,
 * so there is no NULL case). down() puts back the old "≥ 30.0" rule.
 */
class RecomputeBmiFlagsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_23_000001_recompute_bmi_flags_d78.php');
    }

    /** One vitals row carrying $bmi and the flag the OLD (≥ 30) rule stored. */
    private function vitalsRow(float $bmi, bool $storedFlag): VitalSigns
    {
        static $seq = 8000;

        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-'.$seq++,
            'student_id' => User::factory()->create(['role' => 'student'])->id,
            'college_id' => $college->id,
            'login_method' => 'qr',
            'status' => 'captured',
            'checked_in_at' => now(),
        ]);

        return VitalSigns::create([
            'clinic_visit_id' => $visit->id,
            'height_cm' => 170.0,
            'weight_kg' => 60.0,
            'bmi' => $bmi,
            'temperature_c' => 36.5,
            'heart_rate_bpm' => 75,
            'bp_systolic' => 110,
            'bp_diastolic' => 70,
            'entry_method' => 'manual',
            'is_bmi_flagged' => $storedFlag,
            'is_temp_flagged' => false,
            'is_bp_flagged' => false,
            'is_hr_flagged' => false,
        ]);
    }

    public function test_up_applies_the_d78_rule_and_down_restores_the_old_one(): void
    {
        // Each row starts with the flag the OLD rule stored.
        $rows = [
            'under' => $this->vitalsRow(17.0, false),
            'normal' => $this->vitalsRow(22.0, false),
            'over' => $this->vitalsRow(26.0, false),
            'obese' => $this->vitalsRow(31.0, true),
        ];

        $flags = fn (): array => array_map(
            fn (VitalSigns $row): bool => (bool) $row->fresh()->is_bmi_flagged,
            $rows,
        );

        $migration = $this->migration();

        $migration->up();
        $this->assertSame(
            ['under' => true, 'normal' => false, 'over' => true, 'obese' => true],
            $flags(),
        );

        $migration->down();
        $this->assertSame(
            ['under' => false, 'normal' => false, 'over' => false, 'obese' => true],
            $flags(),
        );
    }
}
