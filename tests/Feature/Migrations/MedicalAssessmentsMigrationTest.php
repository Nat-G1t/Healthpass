<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\MedicalAssessment;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * D-69 migration — the eleventh domain table, `medical_assessments`. It must
 * round-trip (down, then up) on the SQLite test database, and a row must
 * survive the JSON casts intact.
 */
class MedicalAssessmentsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_20_000009_create_medical_assessments_table.php');
    }

    private function clearanceRecord(): ClearanceRecord
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $student = User::factory()->create(['role' => 'student']);

        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-M001',
            'student_id' => $student->id,
            'college_id' => $college->id,
            'login_method' => 'qr',
            'status' => 'encoded',
            'privacy_consent_at' => now(),
            'checked_in_at' => now(),
        ]);

        return ClearanceRecord::create([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => User::factory()->create(['role' => 'nurse'])->id,
            'result' => 'Fit',
            'encoded_at' => now(),
        ]);
    }

    public function test_the_migration_round_trips(): void
    {
        $record = $this->clearanceRecord();

        MedicalAssessment::create([
            'clearance_record_id' => $record->id,
            'medical_history' => ['patient' => ['asthma'], 'family' => [], 'specify' => []],
            'immunizations' => ['given' => ['bcg'], 'others' => null],
            'family_planning_access' => true,
            'surgical_history' => ['procedures' => 'Appendectomy', 'date_done' => '2019'],
        ]);

        $migration = $this->migration();

        $migration->down();
        $this->assertFalse(Schema::hasTable('medical_assessments'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('medical_assessments', [
            'clearance_record_id',
            'medical_history',
            'immunizations',
            'family_planning_access',
            'surgical_history',
            // Written by prompt 11 (D-70) — created now so it needs no ALTER.
            'menstrual_history',
            'ob_history',
            'physical_exam',
        ]));
    }

    public function test_the_json_columns_survive_a_round_trip(): void
    {
        $record = $this->clearanceRecord();

        MedicalAssessment::create([
            'clearance_record_id' => $record->id,
            'medical_history' => [
                'patient' => ['allergy', 'asthma'],
                'family' => ['hypertension'],
                'specify' => ['allergy' => 'Peanuts'],
            ],
            'immunizations' => ['given' => ['bcg', 'child_none'], 'others' => 'Typhoid'],
            'family_planning_access' => false,
            'surgical_history' => ['procedures' => null, 'date_done' => 'Grade 5'],
        ]);

        $fresh = MedicalAssessment::firstOrFail();

        $this->assertSame(['allergy', 'asthma'], $fresh->medical_history['patient']);
        $this->assertSame('Peanuts', $fresh->specifyFor('allergy'));
        $this->assertTrue($fresh->hasCondition('family', 'hypertension'));
        $this->assertTrue($fresh->hasImmunization('child_none'));
        $this->assertFalse($fresh->family_planning_access);
        $this->assertSame('Grade 5', $fresh->surgical_history['date_done']);
        $this->assertSame($record->id, $fresh->clearanceRecord->id);
    }
}
