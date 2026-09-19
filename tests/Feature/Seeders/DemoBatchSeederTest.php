<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\Appointment;
use App\Models\BatchRequest;
use App\Models\ClinicVisit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoBatchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the DEV batch seed on SQLite (the suite's engine).
 *
 * Three things this has to keep true, because all three have bitten a seeder
 * in this project before:
 *
 *  1. a fresh seed must not crash,
 *  2. it must be idempotent — a second `db:seed` must not double the data,
 *  3. its reserved reference bands must stay clear of DemoClinicVisitSeeder's
 *     APT-2026-9xxx / HP-2026-90xx, whose own idempotency guards would
 *     otherwise make that seeder silently skip itself.
 *
 * Plus the point of the fixture: every state the two new College Admin
 * features need must actually be present after a seed.
 */
class DemoBatchSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_fresh_seed_produces_the_six_demo_batches(): void
    {
        $this->assertSame(6, BatchRequest::where('reference_no', 'like', 'BR-2026-90%')->count());
    }

    public function test_every_batch_state_is_represented(): void
    {
        $byStatus = BatchRequest::where('reference_no', 'like', 'BR-2026-90%')
            ->get()->groupBy('status')->map->count();

        $this->assertSame(2, $byStatus['pending']);
        $this->assertSame(2, $byStatus['approved']);
        $this->assertSame(1, $byStatus['rejected']);
        $this->assertSame(1, $byStatus['cancelled']);
    }

    public function test_the_cancelled_batch_carries_both_d52_columns(): void
    {
        $cancelled = BatchRequest::where('status', 'cancelled')->firstOrFail();

        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertNotNull($cancelled->cancelled_by);

        // A cancellation is not a decision — the Director's fields stay empty.
        $this->assertNull($cancelled->reviewed_by);
        $this->assertNull($cancelled->reviewed_at);
    }

    public function test_the_results_batch_covers_every_roster_outcome(): void
    {
        $batch = BatchRequest::where('reference_no', 'BR-2026-903')->firstOrFail();

        $progress = $batch->batchRequestStudents
            ->map(fn ($row) => $row->appointment?->clearanceProgress())
            ->sort()->values()->all();

        // One of each — the whole point of this fixture.
        $this->assertSame(['absent', 'completed', 'completed', 'in_clinic'], $progress);

        $results = $batch->batchRequestStudents
            ->map(fn ($row) => $row->appointment?->clearanceResult())
            ->filter()->sort()->values()->all();

        $this->assertSame(['Fit', 'Unfit'], $results);
    }

    public function test_the_upcoming_batch_has_a_withdrawn_seat_and_two_live_ones(): void
    {
        $batch = BatchRequest::where('reference_no', 'BR-2026-904')->firstOrFail();

        $progress = $batch->batchRequestStudents
            ->map(fn ($row) => $row->appointment?->clearanceProgress())
            ->sort()->values()->all();

        $this->assertSame(['awaiting', 'awaiting', 'withdrawn'], $progress);
    }

    public function test_pending_batches_have_no_appointments(): void
    {
        $pendingIds = BatchRequest::where('status', 'pending')->pluck('id');

        $this->assertSame(0, Appointment::whereIn('batch_request_id', $pendingIds)->count());
    }

    public function test_the_reserved_bands_do_not_collide_with_the_clinic_visit_seeder(): void
    {
        // DemoClinicVisitSeeder skips itself when ANY APT-2026-9xxx exists, so
        // this seeder must mint none. Same story for HP-2026-90xx. (Since D-61
        // that seeder's own spread appointments are batch appointments too, so
        // this looks only at the batches THIS seeder owns, BR-2026-90x.)
        $ownBatchIds = BatchRequest::where('reference_no', 'like', 'BR-2026-90%')->pluck('id');

        $this->assertSame(
            0,
            Appointment::where('reference_no', 'like', 'APT-2026-9%')
                ->whereIn('batch_request_id', $ownBatchIds)->count(),
        );

        $this->assertSame(
            0,
            ClinicVisit::where('reference_no', 'like', 'HP-2026-85%')
                ->where('reference_no', 'like', 'HP-2026-90%')->count(),
        );

        // And the other seeder still ran — its own bands are intact.
        $this->assertTrue(ClinicVisit::where('reference_no', 'like', 'HP-2026-90%')->exists());
    }

    public function test_seeding_twice_does_not_duplicate(): void
    {
        $before = BatchRequest::count();

        $this->seed(DemoBatchSeeder::class);

        $this->assertSame($before, BatchRequest::count());
    }

    public function test_the_ccs_admin_can_open_every_seeded_batch(): void
    {
        $admin = User::where('role', 'college_admin')
            ->whereHas('managedCollege', fn ($query) => $query->where('code', 'CCS'))
            ->firstOrFail();

        $batches = BatchRequest::where('reference_no', 'like', 'BR-2026-90%')->get();

        foreach ($batches as $batch) {
            $this->actingAs($admin)->get("/admin/batches/{$batch->id}")->assertOk();
        }

        $this->actingAs($admin)->get('/admin/batches')->assertOk();
        $this->actingAs($admin)->get('/admin/activity')->assertOk();
    }
}
