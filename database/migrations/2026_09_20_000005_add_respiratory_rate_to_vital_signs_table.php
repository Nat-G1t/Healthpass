<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-65 — respiratory rate becomes a captured vital (supersedes D-6, which left
 * it blank on the printed form).
 *
 * FLAGGED SCHEMA CHANGE (approved by Nat, 2026-09-18/19): one nullable
 * TINYINT UNSIGNED column on vital_signs. The kiosk has no sensor for it, so
 * it is NULL until the nurse or physician types it on the encode page — which
 * is the one place it is ever written. It lives here, next to the other
 * vitals, because the clinic treats it as one of them: the flags (prompt 07),
 * the analytics and the student's record all read the vitals row.
 *
 * `entry_method` does not change — it describes how the KIOSK captured its
 * readings, and that is still true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vital_signs', function (Blueprint $table) {
            $table->unsignedTinyInteger('respiratory_rate')->nullable()->after('heart_rate_bpm');
        });
    }

    public function down(): void
    {
        Schema::table('vital_signs', function (Blueprint $table) {
            $table->dropColumn('respiratory_rate');
        });
    }
};
