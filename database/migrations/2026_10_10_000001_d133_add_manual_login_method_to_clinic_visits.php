<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-133 — Manual Screening. FLAGGED SCHEMA CHANGE (approved by Nat,
 * 2026-10-08):
 *
 *  - `clinic_visits.login_method` gains `manual`: a visit the nurse typed in
 *    on the Manual Screening page because the kiosk was down. The student
 *    never scanned a QR code or signed in with an email, so neither old value
 *    would be honest.
 *
 * Same shape as the D-72 and D-126 migrations: on SQLite the enum `->change()`
 * rebuilds the table, so the foreign-key checks are paused around it (the rows
 * and ids are copied unchanged), and that pause needs no open transaction.
 */
return new class extends Migration
{
    /** See the class comment: the foreign-key pause needs no open transaction. */
    public $withinTransaction = false;

    private const OLD_METHODS = ['qr', 'email'];

    private const NEW_METHODS = ['qr', 'email', 'manual'];

    public function up(): void
    {
        $this->rebuildingVisits(function (): void {
            Schema::table('clinic_visits', function (Blueprint $table) {
                $table->enum('login_method', self::NEW_METHODS)->change();
            });
        });
    }

    public function down(): void
    {
        // A manual visit has no honest older value: the student neither
        // scanned a QR code nor signed in with an email.
        if (DB::table('clinic_visits')->where('login_method', 'manual')->exists()) {
            throw new RuntimeException('Cannot roll back D-133: manual-screening visits exist. Resolve them by hand first.');
        }

        $this->rebuildingVisits(function (): void {
            Schema::table('clinic_visits', function (Blueprint $table) {
                $table->enum('login_method', self::OLD_METHODS)->change();
            });
        });
    }

    /** Run $change with SQLite's foreign-key checks paused (a no-op elsewhere). */
    private function rebuildingVisits(callable $change): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $change();

            return;
        }

        Schema::disableForeignKeyConstraints();

        try {
            $change();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
};
