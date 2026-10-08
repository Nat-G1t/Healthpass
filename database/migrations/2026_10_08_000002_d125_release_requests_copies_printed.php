<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-125 — FLAGGED SCHEMA CHANGE on `document_release_requests` (approved by
     * Nat, 2026-10-08).
     *
     * When the Director approves a request that asked for printed copies
     * (`wants_printed_copies`, D-123), the clinic sees "Print Requested" on
     * its Completed batches card. The clinic presses **Mark as printed** when
     * the copies are done; these two columns record that press:
     *
     *   copies_printed_at — when (NULL until pressed)
     *   copies_printed_by — which nurse or physician (NULL until pressed, or
     *                       if that account is ever deleted)
     *
     * Nothing is backfilled: no request was marked printed before D-125.
     */
    public function up(): void
    {
        Schema::table('document_release_requests', function (Blueprint $table) {
            $table->timestamp('copies_printed_at')->nullable()->after('rejection_message');
            $table->foreignId('copies_printed_by')->nullable()->after('copies_printed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_release_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('copies_printed_by');
            $table->dropColumn('copies_printed_at');
        });
    }
};
