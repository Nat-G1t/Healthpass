<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * D-63 migrations — the nine D-56 Physical Signs columns become the new forms'
 * twelve, on both `screening_responses` and `clearance_records.ps_*`. Both must
 * round-trip (down, then up) on the SQLite test database with rows in the
 * tables. Old answers are discarded by design, so the test checks that rows
 * survive and that nothing is mapped across.
 */
class PhysicalSignsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_KEYS = [
        'skin', 'head', 'eyes', 'ears', 'nose', 'throat',
        'chest_lungs', 'heart', 'abdomen', 'kidney_bladder', 'brain', 'mental_disorder',
    ];

    private const OLD_KEYS = [
        'skin', 'abdomen_git', 'heent', 'gut', 'chest_lungs',
        'extremities', 'heart_cvs', 'neurological', 'breast',
    ];

    private function migration(string $file): Migration
    {
        return require database_path("migrations/{$file}");
    }

    private function visit(): ClinicVisit
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);

        return ClinicVisit::create([
            'reference_no' => 'HP-2026-T001',
            'student_id' => User::factory()->create(['role' => 'student'])->id,
            'college_id' => $college->id,
            'login_method' => 'qr',
            'status' => 'encoded',
            'privacy_consent_at' => now(),
            'checked_in_at' => now(),
        ]);
    }

    /** Prefix each key with ps_. */
    private function ps(array $keys): array
    {
        return array_map(fn (string $key) => "ps_{$key}", $keys);
    }

    public function test_both_migrations_round_trip_with_data(): void
    {
        $visit = $this->visit();
        DB::table('screening_responses')->insert([
            'clinic_visit_id' => $visit->id,
            'skin' => true,
            'mental_disorder' => true,
            'details' => json_encode(['skin' => 'Itchy rash']),
            'is_pregnant' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('clearance_records')->insert([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => User::factory()->create(['role' => 'nurse'])->id,
            'result' => 'Fit',
            'ps_skin' => true,
            'ps_brain' => false,
            'encoded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $screening = $this->migration('2026_09_20_000001_replace_physical_signs_on_screening_responses_table.php');
        $clearance = $this->migration('2026_09_20_000002_replace_physical_signs_on_clearance_records_table.php');

        // ── down: back to the D-56 nine ──
        $clearance->down();
        $screening->down();

        $this->assertTrue(Schema::hasColumns('screening_responses', self::OLD_KEYS));
        $this->assertFalse(Schema::hasColumn('screening_responses', 'mental_disorder'));
        $this->assertTrue(Schema::hasColumns('clearance_records', $this->ps(self::OLD_KEYS)));
        $this->assertFalse(Schema::hasColumn('clearance_records', 'ps_brain'));

        // ── up: forward to the twelve ──
        $screening->up();
        $clearance->up();

        $this->assertTrue(Schema::hasColumns('screening_responses', self::NEW_KEYS));
        $this->assertTrue(Schema::hasColumns('clearance_records', $this->ps(self::NEW_KEYS)));
        foreach (['abdomen_git', 'heent', 'gut', 'extremities', 'heart_cvs', 'neurological', 'breast'] as $old) {
            $this->assertFalse(Schema::hasColumn('screening_responses', $old), $old);
            $this->assertFalse(Schema::hasColumn('clearance_records', "ps_{$old}"), "ps_{$old}");
        }

        // The rows survive; their answers were discarded (never mapped), and
        // the columns D-63 leaves alone keep their values.
        $sr = DB::table('screening_responses')->first();
        foreach (self::NEW_KEYS as $key) {
            $this->assertNull($sr->{$key}, $key);
        }
        $this->assertSame(['skin' => 'Itchy rash'], json_decode($sr->details, true));
        $this->assertSame(0, (int) $sr->is_pregnant);

        $record = DB::table('clearance_records')->first();
        foreach ($this->ps(self::NEW_KEYS) as $column) {
            $this->assertNull($record->{$column}, $column);
        }
        $this->assertSame('Fit', $record->result);
    }
}
