<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-36: rejection now requires a written reason, so the College Admin
     * can see WHY their cohort was turned down and resubmit accordingly.
     * This is also the escape hatch that replaces D-29's date-adjust — a
     * Director who can't take the requested date rejects with "date
     * unavailable, please resubmit for <X>" instead of silently moving it.
     *
     * Nullable because batches rejected before D-36 have no reason; the
     * required/min:10/max:500 rule lives in RejectBatchRequest, not here.
     *
     * `batch_requests` already exists (§6.3 migration order) — this is a
     * table alter, so it just needs to run after the create migration.
     */
    public function up(): void
    {
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
