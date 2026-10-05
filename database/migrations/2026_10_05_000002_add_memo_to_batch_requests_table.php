<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-109: every new batch request carries the college's SIGNED MEMO — one
     * photo, image or PDF the Director reads before scheduling the batch.
     *
     *   memo_path           where the file sits on the private `local` disk
     *                       (storage/app/private/memos/<random name>) — never
     *                       under public/
     *   memo_original_name  the name the admin's file had, for display and
     *                       for the browser's tab title / save-as name
     *   memo_mime           the type the SERVER detected at upload (never the
     *                       browser's claim), sent back as Content-Type
     *
     * All three nullable: every batch submitted before D-109 has no memo, and
     * none is invented — NO backfill.
     */
    public function up(): void
    {
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->string('memo_path')->nullable()->after('reason_detail');
            $table->string('memo_original_name')->nullable()->after('memo_path');
            $table->string('memo_mime')->nullable()->after('memo_original_name');
        });
    }

    public function down(): void
    {
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->dropColumn(['memo_path', 'memo_original_name', 'memo_mime']);
        });
    }
};
