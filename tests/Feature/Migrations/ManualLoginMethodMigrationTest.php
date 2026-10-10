<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\College;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * D-133 migration — `clinic_visits.login_method` gains `manual`. The enum
 * change is a `->change()`, which on SQLite REBUILDS clinic_visits (the table
 * vital_signs and screening_responses point at), so the round trip runs with
 * rows in place and checks they survive.
 */
class ManualLoginMethodMigrationTest extends TestCase
{
    // DatabaseMigrations, not RefreshDatabase: the latter wraps each test in a
    // transaction, inside which SQLite ignores the foreign-key pause the
    // rebuild relies on — the real `migrate` runs outside one.
    use DatabaseMigrations;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_10_000001_d133_add_manual_login_method_to_clinic_visits.php');
    }

    private function insertVisit(string $reference, string $loginMethod): int
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);

        return DB::table('clinic_visits')->insertGetId([
            'reference_no' => $reference,
            'student_id' => User::factory()->create(['role' => 'student'])->id,
            'college_id' => $college->id,
            'login_method' => $loginMethod,
            'status' => 'captured',
            'checked_in_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_up_accepts_manual_and_a_round_trip_keeps_every_row(): void
    {
        $visitId = $this->insertVisit('HP-2026-T133', 'qr');
        DB::table('vital_signs')->insert([
            'clinic_visit_id' => $visitId,
            'height_cm' => 165, 'weight_kg' => 60, 'bmi' => 22.0, 'temperature_c' => 36.5,
            'heart_rate_bpm' => 75, 'bp_systolic' => 115, 'bp_diastolic' => 75,
            'entry_method' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // ── down: back to qr / email, which refuse `manual` (SQLite CHECK) ──
        $this->migration()->down();

        try {
            $this->insertVisit('HP-2026-T134', 'manual');
            $this->fail('A two-value login_method enum accepted "manual".');
        } catch (QueryException) {
            // expected
        }

        // ── up: `manual` is accepted, and the rebuilt table kept its rows ──
        $this->migration()->up();

        $this->insertVisit('HP-2026-T135', 'manual');
        $this->assertSame('manual', DB::table('clinic_visits')->where('reference_no', 'HP-2026-T135')->value('login_method'));
        $this->assertSame('qr', DB::table('clinic_visits')->where('id', $visitId)->value('login_method'));
        $this->assertSame($visitId, DB::table('vital_signs')->value('clinic_visit_id'), 'the vitals still point at their visit');

        $this->removeManualVisits();
    }

    public function test_rolling_back_refuses_while_manual_visits_exist(): void
    {
        $this->insertVisit('HP-2026-T136', 'manual');

        try {
            $this->migration()->down();
            $this->fail('The rollback ran with a manual-screening visit in place.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('manual-screening visits exist', $e->getMessage());
        }

        $this->assertSame(1, DB::table('clinic_visits')->where('login_method', 'manual')->count(), 'nothing was rolled back');
        $this->removeManualVisits();
    }

    /**
     * DatabaseMigrations rolls every migration back after each test, and this
     * migration's down() refuses while a manual visit exists — so clear them.
     */
    private function removeManualVisits(): void
    {
        DB::table('clinic_visits')->where('login_method', 'manual')->delete();
    }
}
