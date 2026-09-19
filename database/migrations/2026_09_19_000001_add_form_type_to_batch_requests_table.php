<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-62 — every batch names the clinic form its students will take, and the
 * batch reason becomes the purpose printed on that form.
 *
 * FLAGGED SCHEMA CHANGE per CLAUDE.md (no new table — the canon stays at 10):
 *
 *  - `batch_requests.form_type` ENUM('clearance','assessment'), default
 *    'clearance'. Every batch submitted before D-62 used the Medical
 *    Clearance, so the default is the truth for them — nothing to backfill.
 *  - `batch_requests.reason` ENUM → VARCHAR(30). The allowed keys now depend
 *    on the form type (BatchRequest::REASONS_BY_FORM), which an enum cannot
 *    express, so validation is the gate — the same approach D-24 took for
 *    `clearance_records.purpose`.
 *  - `appointments.purpose` / `purpose_other` are dropped. They held the
 *    student's own purpose at self-booking (D-28); since D-61 nothing can set
 *    them, and the purpose now comes from the batch.
 */
return new class extends Migration
{
    /** The pre-D-62 `reason` enum, restored by down(). */
    private const LEGACY_REASONS = ['graduation', 'ojt', 'enrollment', 'scholarship', 'sports', 'fieldtrip', 'others'];

    public function up(): void
    {
        // Added first, so the column change below rebuilds the table (SQLite)
        // on its final shape rather than rebuilding it twice.
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->enum('form_type', ['clearance', 'assessment'])->default('clearance')->after('requested_by');
        });

        Schema::table('batch_requests', function (Blueprint $table) {
            $table->string('reason', 30)->change();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'purpose_other']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('purpose', 50)->nullable()->after('service_type');
            $table->string('purpose_other', 120)->nullable()->after('purpose');
        });

        // The new D-62 keys (outbound, off_campus, rle) don't exist in the old
        // enum — fold them into 'others' so the column change can't fail on
        // MySQL, keeping any specify text the admin typed.
        DB::table('batch_requests')
            ->whereNotIn('reason', self::LEGACY_REASONS)
            ->update(['reason' => 'others']);

        Schema::table('batch_requests', function (Blueprint $table) {
            $table->dropColumn('form_type');
        });

        Schema::table('batch_requests', function (Blueprint $table) {
            $table->enum('reason', self::LEGACY_REASONS)->change();
        });
    }
};
