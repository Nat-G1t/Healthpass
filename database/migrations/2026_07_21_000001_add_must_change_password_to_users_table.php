<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-35: staff accounts are seeded (FR-AUTH-05 — there is no staff registration),
 * so on the hosted internet deploy they arrive with a one-time password the
 * account owner has never chosen. This flag forces them through the existing
 * OTP-confirmed change-password screen (D-20) before they can use the app.
 *
 * Defaults to FALSE so students — who self-register and pick their own password
 * — and every existing account are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
