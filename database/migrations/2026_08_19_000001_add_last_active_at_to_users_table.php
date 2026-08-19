<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-48: "Last active" on the Director's Staff Accounts screen.
 *
 * `users.status` answers "is this account allowed in", which is not the same
 * question as "is anybody actually using it". A College Admin who left in June
 * still reads as Active until someone thinks to deactivate them, and the
 * Director had no way to tell. This column is the missing signal.
 *
 * NULLABLE with no default and never backfilled: an account that has not been
 * seen since the column existed has no honest answer, and a made-up timestamp
 * is worse than none. Those rows render "Never" — the same rule
 * `clinic_visits.course` (D-43) and `appointments.scheduled_time` (D-37)
 * already follow for pre-decision rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_active_at');
        });
    }
};
