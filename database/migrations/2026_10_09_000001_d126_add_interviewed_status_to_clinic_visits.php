<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-126 — separate nurse and physician queues. FLAGGED SCHEMA CHANGE
 * (approved by Nat, 2026-10-08):
 *
 *  - `clinic_visits.status` gains `interviewed`: a Medical Assessment visit
 *    the nurse has interviewed and submitted. It waits in the PHYSICIAN's
 *    queue, and only the physician's encode moves it on to `encoded`
 *    (BR-11: resting → captured → interviewed → encoded).
 *  - `clinic_visits.interviewed_by` — the nurse who submitted it. Prints under
 *    "Interviewed/Assessed by" on the Medical Assessment Form. `nullOnDelete`:
 *    removing a staff account must not delete a student's visit.
 *  - `clinic_visits.interviewed_at` — when the nurse submitted it. The
 *    physician queue orders by it (first come, first served into THAT queue).
 *
 * Same shape as the D-72 migration: on SQLite the enum `->change()` rebuilds
 * the table, so the foreign-key checks are paused around it (the rows and ids
 * are copied unchanged), and that pause needs no open transaction.
 */
return new class extends Migration
{
    /** See the class comment: the foreign-key pause needs no open transaction. */
    public $withinTransaction = false;

    private const OLD_STATUSES = ['resting', 'captured', 'encoded'];

    private const NEW_STATUSES = ['resting', 'captured', 'interviewed', 'encoded'];

    public function up(): void
    {
        $this->rebuildingVisits(function (): void {
            Schema::table('clinic_visits', function (Blueprint $table) {
                $table->enum('status', self::NEW_STATUSES)->default('captured')->change();
            });

            Schema::table('clinic_visits', function (Blueprint $table) {
                $table->foreignId('interviewed_by')->nullable()->after('resting_until')
                    ->constrained('users')->nullOnDelete();
                $table->timestamp('interviewed_at')->nullable()->after('interviewed_by');
            });
        });
    }

    public function down(): void
    {
        // An interviewed visit has no honest older status: it is not captured
        // (the nurse already submitted it) and not encoded (no result exists).
        if (DB::table('clinic_visits')->where('status', 'interviewed')->exists()) {
            throw new RuntimeException('Cannot roll back D-126: interviewed visits exist. Encode or resolve them by hand first.');
        }

        $this->rebuildingVisits(function (): void {
            Schema::table('clinic_visits', function (Blueprint $table) {
                $table->dropForeign(['interviewed_by']);
                $table->dropColumn(['interviewed_by', 'interviewed_at']);
            });

            Schema::table('clinic_visits', function (Blueprint $table) {
                $table->enum('status', self::OLD_STATUSES)->default('captured')->change();
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
