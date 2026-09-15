<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-57: the unread badges on the sidebar menu (FR-UI-05).
 *
 * One JSON object per account holding WHEN the user last opened each badged
 * page, plus when a student finished the Kiosk Tutorial, e.g.
 *   {"student.records": "2026-09-15 09:05:00", "student.tutorial_completed": "…"}
 * A badge counts what happened after its page's stamp. App\Support\NavBadges
 * owns the keys and is the only code that reads or writes the column.
 *
 * NULLABLE and never backfilled: a key that was never stamped falls back to the
 * account's created_at, so everything since the account existed reads as
 * unread — the honest answer for someone who has never opened the page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('nav_seen_at')->nullable()->after('last_active_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('nav_seen_at');
        });
    }
};
