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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * D-69 migration — the eleventh domain table, `medical_assessments` — as
 * re-keyed by D-126 from `clearance_record_id` to `clinic_visit_id`. The
 * re-key must round-trip (down, then up) on the SQLite test database WITHOUT
 * losing a single row's link to its visit, and a row must survive the JSON
 * casts intact.
 */
class MedicalAssessmentsMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** D-126's re-key — the latest migration that shapes this table. */
    private function rekeyMigration(): Migration
    {
        return require database_path('migrations/2026_10_09_000002_d126_rekey_medical_assessments_to_clinic_visits.php');
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

    public function test_the_rekey_round_trips_and_keeps_every_assessment_linked_to_its_visit(): void
    {
        $record = $this->clearanceRecord();

        // Through the record's relation — which fills clinic_visit_id itself.
        $record->medicalAssessment()->create([
            'medical_history' => ['patient' => ['asthma'], 'family' => [], 'specify' => []],
            'immunizations' => ['given' => ['bcg'], 'others' => null],
            'family_planning_access' => true,
            'surgical_history' => ['procedures' => 'Appendectomy', 'date_done' => '2019'],
        ]);

        $migration = $this->rekeyMigration();

        // Back to the D-69 shape: the row hangs off its clearance record again.
        $migration->down();
        $this->assertFalse(Schema::hasColumn('medical_assessments', 'clinic_visit_id'));
        $this->assertSame($record->id, DB::table('medical_assessments')->value('clearance_record_id'));

        // Forward again: the backfill finds the visit through clearance_records.
        $migration->up();
        $this->assertFalse(Schema::hasColumn('medical_assessments', 'clearance_record_id'));
        $this->assertTrue(Schema::hasColumns('medical_assessments', [
            'clinic_visit_id',
            'interview_vitals',
            'medical_history',
            'immunizations',
            'family_planning_access',
            'surgical_history',
            'menstrual_history',
            'ob_history',
            'physical_exam',
        ]));
        $this->assertSame($record->clinic_visit_id, DB::table('medical_assessments')->value('clinic_visit_id'));
        $this->assertSame('Appendectomy', $record->fresh()->medicalAssessment->surgical_history['procedures']);
    }

    public function test_the_rekey_refuses_to_roll_back_an_interview_with_no_clearance_record(): void
    {
        $record = $this->clearanceRecord();
        $interviewed = $record->clinicVisit->replicate()->fill([
            'reference_no' => 'HP-2026-M002',
            'status' => 'interviewed',
        ]);
        $interviewed->save();
        $interviewed->medicalAssessment()->create(['medical_history' => null]);

        $this->expectException(RuntimeException::class);

        $this->rekeyMigration()->down();
    }

    public function test_the_json_columns_survive_a_round_trip(): void
    {
        $record = $this->clearanceRecord();

        MedicalAssessment::create([
            'clinic_visit_id' => $record->clinic_visit_id,
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
        // Both ways in: the visit's own relation and the record's (D-126).
        $this->assertSame($record->clinic_visit_id, $fresh->clinicVisit->id);
        $this->assertSame($fresh->id, $record->fresh()->medicalAssessment->id);
    }
}
