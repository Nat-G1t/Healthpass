<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\ClinicVisit;
use App\Models\College;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * D-64 migrations — `users.role` gains `physician` (+ `license_number`), and
 * the clearance_records physician block loses its defaults. The enum change is
 * a `->change()`, which on SQLite REBUILDS the users table (the table every
 * other table points at), so the round trip runs with rows in place and checks
 * they all survive.
 */
class PhysicianRoleMigrationTest extends TestCase
{
    // DatabaseMigrations, not RefreshDatabase: the latter wraps each test in a
    // transaction, inside which SQLite ignores the foreign-key pause the
    // users rebuild relies on — the real `migrate` runs outside one.
    use DatabaseMigrations;

    private function migration(string $file): Migration
    {
        return require database_path("migrations/{$file}");
    }

    private function roleMigration(): Migration
    {
        return $this->migration('2026_09_20_000003_add_physician_role_to_users_table.php');
    }

    private function blockMigration(): Migration
    {
        return $this->migration('2026_09_20_000004_make_physician_block_nullable_on_clearance_records_table.php');
    }

    /** A nurse-encoded record: both physician columns NULL. */
    private function nurseRecord(): void
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);
        $visit = ClinicVisit::create([
            'reference_no' => 'HP-2026-T064',
            'student_id' => User::factory()->create(['role' => 'student'])->id,
            'college_id' => $college->id,
            'login_method' => 'qr',
            'status' => 'encoded',
            'privacy_consent_at' => now(),
            'checked_in_at' => now(),
        ]);

        DB::table('clearance_records')->insert([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => User::factory()->create(['role' => 'nurse'])->id,
            'result' => 'Fit',
            'encoded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertUser(string $role, string $email): void
    {
        DB::table('users')->insert([
            'role' => $role,
            'name' => 'Enum Probe',
            'email' => $email,
            'password' => 'x',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_both_migrations_round_trip_with_data(): void
    {
        $this->nurseRecord();
        $usersBefore = DB::table('users')->count();

        $this->assertNull(DB::table('clearance_records')->value('physician_name'));

        // ── down: back to four roles and the old defaults ──
        $this->blockMigration()->down();
        $this->roleMigration()->down();

        $this->assertFalse(Schema::hasColumn('users', 'license_number'));
        $this->assertSame($usersBefore, DB::table('users')->count(), 'the users rebuild kept every row');
        // The nurse-encoded row was refilled so the old NOT NULL could return.
        $this->assertSame('REYNALDO S. ALIPIO, MD', DB::table('clearance_records')->value('physician_name'));
        $this->assertSame('60252', DB::table('clearance_records')->value('physician_license_no'));

        // The narrowed enum refuses the fifth value again (SQLite CHECK).
        try {
            $this->insertUser('physician', 'refused@healthpass.test');
            $this->fail('A four-value role enum accepted "physician".');
        } catch (QueryException) {
            // expected
        }

        // ── up: forward to five roles ──
        $this->roleMigration()->up();
        $this->blockMigration()->up();

        $this->assertTrue(Schema::hasColumn('users', 'license_number'));
        $this->assertSame($usersBefore, DB::table('users')->count());
        $this->insertUser('physician', 'accepted@healthpass.test');
        $this->assertSame('physician', DB::table('users')->where('email', 'accepted@healthpass.test')->value('role'));

        // The foreign key from clearance_records still resolves after the rebuild.
        $encoder = DB::table('clearance_records')->value('encoded_by');
        $this->assertSame('nurse', DB::table('users')->where('id', $encoder)->value('role'));

        // No default any more: a new record without the block stores NULLs.
        DB::table('clearance_records')->delete();
        $this->nurseRecordWithReference('HP-2026-T065');
        $this->assertNull(DB::table('clearance_records')->value('physician_name'));
        $this->assertNull(DB::table('clearance_records')->value('physician_license_no'));

        $this->removePhysicians();
    }

    public function test_rolling_back_refuses_while_physicians_exist(): void
    {
        User::factory()->physician()->create();

        try {
            $this->roleMigration()->down();
            $this->fail('The rollback ran with a physician account in place.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('physician accounts exist', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('users', 'license_number'), 'nothing was rolled back');
        $this->removePhysicians();
    }

    /**
     * DatabaseMigrations rolls every migration back after each test, and this
     * migration's down() refuses while a physician exists — so clear them.
     */
    private function removePhysicians(): void
    {
        DB::table('users')->where('role', 'physician')->delete();
    }

    private function nurseRecordWithReference(string $reference): void
    {
        $visit = ClinicVisit::firstOrFail();
        $visit->update(['reference_no' => $reference]);

        DB::table('clearance_records')->insert([
            'clinic_visit_id' => $visit->id,
            'encoded_by' => User::where('role', 'nurse')->value('id'),
            'result' => 'Fit',
            'encoded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
