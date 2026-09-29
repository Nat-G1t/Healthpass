<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-92 — a College Admin now writes WHY they cancel a batch (FR-ADM-11), and
 * may cancel an APPROVED one until its first clinic hour starts.
 *
 * FLAGGED SCHEMA CHANGE per CLAUDE.md: `batch_requests` gains one nullable
 * column. NO new table, no new status — `cancelled` already exists (D-52).
 *
 * The reason is read by the Clinic Director on Batch Approvals and, for an
 * approved batch, emailed to every student whose appointment it cancels. It
 * sits beside `rejection_reason` (the Director's pushback, D-36) rather than
 * reusing it: that column means "the Director turned this down", and the
 * Batch Tracking and Activity Log screens already read it that way.
 *
 * Nullable and NEVER backfilled: batches cancelled before D-92 had no reason
 * to give, and NULL says exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->dropColumn('cancellation_reason');
        });
    }
};
