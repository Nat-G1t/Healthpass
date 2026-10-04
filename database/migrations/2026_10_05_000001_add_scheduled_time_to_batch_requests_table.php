<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-108: the Clinic Director now picks each batch's clinic date AND start
     * hour when approving it; the College Admin no longer proposes either.
     * `scheduled_date` already exists (D-5), so the span the Director chose
     * needs only its two D-37-shaped halves:
     *
     *   scheduled_time   the START hour of the span ('H:i:s', like every slot)
     *   scheduled_blocks how many contiguous hours the span occupies
     *
     * Start + count, never an end time — the same reasoning as D-37's
     * requested_time / requested_blocks (see that migration).
     *
     * `requested_date`, `requested_time` and `requested_blocks` are KEPT as
     * legacy columns: they record what a college asked for before D-108. New
     * batches leave them NULL.
     *
     * THE COPY RULE. Under D-36's confirm-only approval a batch was approved
     * exactly on the span its college requested, so for every batch that WAS
     * approved the requested span is a true record of the scheduled one:
     *   - status 'approved', or
     *   - status 'cancelled' with reviewed_at set (D-92: cancelled after the
     *     Director approved it — a rejected batch can never be cancelled, so a
     *     decision stamp on a cancelled row can only be an approval).
     * Pending, rejected and never-approved cancelled rows were never scheduled,
     * so they stay NULL. An approved row with no requested span (pre-D-37)
     * copies NULL — nothing is invented.
     *
     * Plain query builder with a column-to-column UPDATE, so the same SQL runs
     * on MySQL/MariaDB and the SQLite test suite.
     */
    public function up(): void
    {
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->time('scheduled_time')->nullable()->after('scheduled_date');
            $table->unsignedTinyInteger('scheduled_blocks')->nullable()->after('scheduled_time');
        });

        DB::table('batch_requests')
            ->where(fn (Builder $query) => $query
                ->where('status', 'approved')
                ->orWhere(fn (Builder $cancelled) => $cancelled
                    ->where('status', 'cancelled')
                    ->whereNotNull('reviewed_at')))
            ->update([
                'scheduled_time' => DB::raw('requested_time'),
                'scheduled_blocks' => DB::raw('requested_blocks'),
            ]);
    }

    public function down(): void
    {
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->dropColumn(['scheduled_time', 'scheduled_blocks']);
        });
    }
};
