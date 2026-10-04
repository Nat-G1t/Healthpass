<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\College;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * D-108 migration — `batch_requests.scheduled_time` + `scheduled_blocks`.
 *
 * The copy rule: only batches that WERE approved (status 'approved', or
 * 'cancelled' after approval — reviewed_at set, D-92) get their requested span
 * copied into the scheduled one, because under D-36's confirm-only approval
 * the two were the same. Everything else stays NULL.
 */
class ScheduledTimeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_05_000001_add_scheduled_time_to_batch_requests_table.php');
    }

    /** A pre-D-108 row, written with the query builder (the model expects the new columns). */
    private function legacyBatch(string $reference, string $status, bool $reviewed): void
    {
        $college = College::firstOrCreate(['code' => 'CCS'], ['name' => 'College of Computing Studies']);

        DB::table('batch_requests')->insert([
            'reference_no' => $reference,
            'college_id' => $college->id,
            'requested_by' => User::factory()->create(['role' => 'college_admin'])->id,
            'form_type' => 'clearance',
            'reason' => 'fieldtrip',
            'service_type' => 'medical',
            'requested_date' => '2026-10-07',
            'requested_time' => '09:00:00',
            'requested_blocks' => 2,
            'scheduled_date' => $status === 'approved' || ($status === 'cancelled' && $reviewed) ? '2026-10-07' : null,
            'status' => $status,
            'reviewed_at' => $reviewed ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0: ?string, 1: ?int} */
    private function scheduledSpan(string $reference): array
    {
        $row = DB::table('batch_requests')->where('reference_no', $reference)->first();

        return [$row->scheduled_time, $row->scheduled_blocks === null ? null : (int) $row->scheduled_blocks];
    }

    public function test_only_approved_batches_get_their_requested_span_copied(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('batch_requests', 'scheduled_time'));

        $this->legacyBatch('BR-2026-001', 'approved', reviewed: true);
        $this->legacyBatch('BR-2026-002', 'pending', reviewed: false);
        $this->legacyBatch('BR-2026-003', 'rejected', reviewed: true);
        $this->legacyBatch('BR-2026-004', 'cancelled', reviewed: true);   // cancelled after approval (D-92)
        $this->legacyBatch('BR-2026-005', 'cancelled', reviewed: false);  // withdrawn while pending (D-52)

        $migration->up();

        $this->assertTrue(Schema::hasColumns('batch_requests', ['scheduled_time', 'scheduled_blocks']));
        $this->assertSame(['09:00:00', 2], $this->scheduledSpan('BR-2026-001'));
        $this->assertSame(['09:00:00', 2], $this->scheduledSpan('BR-2026-004'));
        $this->assertSame([null, null], $this->scheduledSpan('BR-2026-002'));
        $this->assertSame([null, null], $this->scheduledSpan('BR-2026-003'));
        $this->assertSame([null, null], $this->scheduledSpan('BR-2026-005'));

        // The legacy columns are kept as they were.
        $this->assertSame('09:00:00', DB::table('batch_requests')->where('reference_no', 'BR-2026-002')->value('requested_time'));
    }

    public function test_an_approved_batch_with_no_requested_span_copies_nothing(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->legacyBatch('BR-2026-010', 'approved', reviewed: true);
        DB::table('batch_requests')->where('reference_no', 'BR-2026-010')
            ->update(['requested_time' => null, 'requested_blocks' => null]);   // pre-D-37

        $migration->up();

        $this->assertSame([null, null], $this->scheduledSpan('BR-2026-010'));
    }
}
