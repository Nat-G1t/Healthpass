<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-96: each batch student is told a time to ARRIVE inside their hour, a
     * 3-minute kiosk budget apart (8:00, 8:03, 8:06 …), so a whole hour's
     * students no longer queue outside at once.
     *
     * STORED, not computed on the fly: the time is emailed at approval, so it
     * must never move afterwards. Ranking students at read time would shift
     * everyone after a cancelled batch — and the email would be wrong.
     *
     * Canonical 'H:i:s' like scheduled_time (CLAUDE.md), so MySQL TIME and the
     * SQLite test suite compare it identically.
     *
     * NULLABLE and deliberately NOT backfilled: appointments approved before
     * D-96 were emailed with the hour only; they keep showing just that.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->time('arrival_time')->nullable()->after('scheduled_time');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('arrival_time');
        });
    }
};
