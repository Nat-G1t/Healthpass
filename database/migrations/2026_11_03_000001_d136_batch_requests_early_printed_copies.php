<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-136 — FLAGGED SCHEMA CHANGE on `batch_requests` (approved by Nat,
     * 2026-11-03).
     *
     * A college that needs the forms on paper right after the clinic asks for
     * printed copies ON the batch request, and the Director decides it when
     * approving the batch. Approved, the clinic may print each student's form
     * as soon as it is encoded — before the batch is finished and before any
     * release (D-113). This is the hard-copy side only: Save as PDF and every
     * download still wait for the release.
     *
     *   wants_printed_copies    — the College Admin ticked it on New Batch
     *   printed_copies_approved — the Director left it ticked at approval
     *                             (who and when: the batch's reviewed_by /
     *                             reviewed_at, the same decision)
     *   copies_printed_at / _by — the clinic's Mark as printed (D-125's
     *                             button, for this path)
     *
     * The names mirror the same columns on `document_release_requests`
     * (D-123 / D-125), so both ways of asking for copies read alike.
     *
     * Nothing is backfilled: every earlier batch asked for no early copies,
     * which is exactly what the defaults say.
     */
    public function up(): void
    {
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->boolean('wants_printed_copies')->default(false)->after('memo_mime');
            $table->boolean('printed_copies_approved')->default(false)->after('wants_printed_copies');
            $table->timestamp('copies_printed_at')->nullable()->after('printed_copies_approved');
            $table->foreignId('copies_printed_by')->nullable()->after('copies_printed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('copies_printed_by');
            $table->dropColumn(['wants_printed_copies', 'printed_copies_approved', 'copies_printed_at']);
        });
    }
};
