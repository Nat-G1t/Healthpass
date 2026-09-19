<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\BatchRequest;
use App\Models\College;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * D-62 migration — `batch_requests.form_type` + `reason` enum → varchar, and
 * `appointments.purpose*` dropped. It must round-trip (down, then up) on the
 * SQLite test database with rows in the table, since both directions rebuild
 * `batch_requests` there.
 */
class FormTypeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_19_000001_add_form_type_to_batch_requests_table.php');
    }

    private function batch(string $reference, string $formType, string $reason): BatchRequest
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);

        return BatchRequest::create([
            'reference_no' => $reference,
            'college_id' => $college->id,
            'requested_by' => User::factory()->create(['role' => 'college_admin'])->id,
            'form_type' => $formType,
            'reason' => $reason,
            'service_type' => 'medical',
            'status' => 'pending',
        ]);
    }

    public function test_the_migration_round_trips_with_data(): void
    {
        $this->batch('BR-2026-001', 'clearance', 'fieldtrip');
        $this->batch('BR-2026-002', 'assessment', 'rle'); // a key the old enum lacks

        $migration = $this->migration();

        // ── down: back to the pre-D-62 shape ──
        $migration->down();

        $this->assertFalse(Schema::hasColumn('batch_requests', 'form_type'));
        $this->assertTrue(Schema::hasColumns('appointments', ['purpose', 'purpose_other']));
        // 'rle' has no place in the old enum, so it folds into 'others'.
        $this->assertSame('others', DB::table('batch_requests')->where('reference_no', 'BR-2026-002')->value('reason'));
        $this->assertSame('fieldtrip', DB::table('batch_requests')->where('reference_no', 'BR-2026-001')->value('reason'));

        // ── up: forward again ──
        $migration->up();

        $this->assertTrue(Schema::hasColumn('batch_requests', 'form_type'));
        $this->assertFalse(Schema::hasColumn('appointments', 'purpose'));
        $this->assertFalse(Schema::hasColumn('appointments', 'purpose_other'));
        // Existing rows take the column default — every pre-D-62 batch was a clearance.
        $this->assertSame(['clearance'], DB::table('batch_requests')->distinct()->pluck('form_type')->all());

        // The varchar reason takes the new D-62 keys.
        $this->batch('BR-2026-003', 'assessment', 'off_campus');
        $this->assertSame('off_campus', DB::table('batch_requests')->where('reference_no', 'BR-2026-003')->value('reason'));
    }
}
