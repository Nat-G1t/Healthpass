<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-52 — a College Admin may cancel their own batch request while it is
 * still PENDING (FR-ADM-11).
 *
 * FLAGGED SCHEMA CHANGE per CLAUDE.md: `batch_requests` gains a fourth
 * `status` value plus two nullable columns. NO new table — the canon stays
 * at 10 — and the PRD data dictionary carries the matching entry.
 *
 * WHY TWO COLUMNS RATHER THAN NONE. The College Activity Log (FR-ADM-10,
 * D-49) is DERIVED from these rows: nothing in the app writes an audit trail
 * anywhere, so a cancellation can only appear on that timeline if the row
 * itself carries WHO did it and WHEN. The existing fields could not serve:
 *
 *  - `reviewed_by` / `reviewed_at` mean "the Director's decision" in the
 *    Director's list, the Activity Log and the rejection modal alike;
 *    overloading them would force all three to special-case a value that is
 *    not a decision.
 *  - `requested_by` is the SUBMITTER, and since D-47 a college can have more
 *    than one admin — deriving the actor from it would name the wrong person.
 *
 * Both columns are nullable and are NEVER backfilled: every batch that
 * exists today was not cancelled, and NULL says exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Added first, so the enum change below rebuilds the table (SQLite)
        // on its final shape rather than rebuilding it twice.
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('rejection_reason');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            $table->foreign('cancelled_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('batch_requests', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])
                ->default('pending')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancelled_at', 'cancelled_by']);
        });

        Schema::table('batch_requests', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])
                ->default('pending')
                ->change();
        });
    }
};
