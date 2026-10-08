<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-123 — FLAGGED SCHEMA CHANGE on `document_release_requests` (amends
     * D-113).
     *
     * A release request no longer carries an attachment: the Director reviews
     * the batch's own signed memo (D-109) instead. The three attachment
     * columns become NULLABLE — requests made before D-123 keep their files
     * and the Director can still open them; new requests leave them NULL.
     *
     * `wants_printed_copies` — the College Admin ticked "The college needs
     * printed copies of the forms"; if the Director approves, the clinic is
     * asked to print them (shown to the clinic by D-125).
     *
     * ->change() alters an existing column. On SQLite (the test suite) Laravel
     * does it by rebuilding the table; nothing has a foreign key pointing AT
     * this table, so the rebuild is safe.
     */
    public function up(): void
    {
        Schema::table('document_release_requests', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->change();
            $table->string('attachment_original_name')->nullable()->change();
            $table->string('attachment_mime')->nullable()->change();
            $table->boolean('wants_printed_copies')->default(false)->after('reason');
        });
    }

    /**
     * Going back would make the attachment columns required again — but a
     * request made after D-123 has no file, and there is no honest value to
     * put there. So down() refuses while any such row exists.
     */
    public function down(): void
    {
        $withoutAttachment = DB::table('document_release_requests')->whereNull('attachment_path')->count();

        if ($withoutAttachment > 0) {
            throw new RuntimeException(
                "D-123 cannot be rolled back: {$withoutAttachment} release request(s) have no attachment, "
                .'and the attachment columns would become required again. Remove or fix those rows first.'
            );
        }

        Schema::table('document_release_requests', function (Blueprint $table) {
            $table->dropColumn('wants_printed_copies');
        });

        Schema::table('document_release_requests', function (Blueprint $table) {
            $table->string('attachment_path')->nullable(false)->change();
            $table->string('attachment_original_name')->nullable(false)->change();
            $table->string('attachment_mime')->nullable(false)->change();
        });
    }
};
