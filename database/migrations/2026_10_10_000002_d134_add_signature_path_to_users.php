<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-134 — FLAGGED SCHEMA CHANGE on `users` (approved by Nat, 2026-10-08).
     *
     * signature_path — where the nurse's or physician's PNG signature sits on
     *                  the PRIVATE `local` disk (storage/app/private/signatures/…,
     *                  a random file name). NULL until they upload one on the
     *                  My Signature page, and for every other role.
     *
     * Nothing is backfilled: nobody had a signature before D-134, and a form
     * with no signature on file simply prints its blank line, as it always has.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('license_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('signature_path');
        });
    }
};
