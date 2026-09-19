<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-64 — the physician block prints only when the physician's own account
 * encoded the record. FLAGGED SCHEMA CHANGE (approved by Nat, 2026-09-18/19):
 * `physician_name` / `physician_license_no` lose their column defaults
 * (REYNALDO S. ALIPIO, MD / 60252) and become nullable. NULL means a nurse
 * encoded, and the form prints a blank line for a wet signature.
 *
 * The encode stamps both columns from the encoder
 * (ClearanceRecord::physicianBlockFor()). Rows written before this change keep
 * the values they already have.
 */
return new class extends Migration
{
    private const OLD_NAME = 'REYNALDO S. ALIPIO, MD';

    private const OLD_LICENSE = '60252';

    public function up(): void
    {
        Schema::table('clearance_records', function (Blueprint $table) {
            $table->string('physician_name', 120)->nullable()->default(null)->change();
            $table->string('physician_license_no', 20)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // The old columns were NOT NULL — refill nurse-encoded rows with the old
        // defaults first, or the change below fails on them.
        DB::table('clearance_records')->whereNull('physician_name')->update(['physician_name' => self::OLD_NAME]);
        DB::table('clearance_records')->whereNull('physician_license_no')->update(['physician_license_no' => self::OLD_LICENSE]);

        Schema::table('clearance_records', function (Blueprint $table) {
            $table->string('physician_name', 120)->nullable(false)->default(self::OLD_NAME)->change();
            $table->string('physician_license_no', 20)->nullable(false)->default(self::OLD_LICENSE)->change();
        });
    }
};
