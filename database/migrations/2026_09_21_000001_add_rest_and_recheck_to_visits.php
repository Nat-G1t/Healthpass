<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-72 — kiosk "Rest & re-check". FLAGGED SCHEMA CHANGE (approved by Nat,
 * 2026-09-19):
 *
 *  - `clinic_visits.status` gains a third value, `resting`: a first pass whose
 *    temperature, blood pressure or heart rate was flagged. It is a real row
 *    (so the answers survive the rest) but it is NOT a submitted visit — it
 *    never reaches the queue and it counts nowhere (ClinicVisit::scopeSubmitted).
 *  - `clinic_visits.resting_until` — the SERVER's "come back at" moment. Null
 *    on every visit that never rested.
 *  - `vital_signs.first_reading` — the pre-rest numbers and their flags, kept
 *    for the clinic to see at encode. Null when there was no re-check.
 *
 * `->change()` re-declares an existing column. On MySQL it is an ALTER of the
 * ENUM. SQLite cannot alter a column's CHECK constraint in place, so Laravel
 * REBUILDS the table: copy to a temp table, DROP clinic_visits, rename.
 * Dropping it while vital_signs / screening_responses / clearance_records rows
 * point at it trips SQLite's foreign-key check, so on SQLite the checks are
 * paused around the rebuild — the rows and ids are copied unchanged, so nothing
 * is left dangling. SQLite ignores that pause inside a transaction, which is
 * why this migration opts out of the one Laravel would otherwise wrap it in.
 * (Same shape as the D-64 physician-role migration.)
 */
return new class extends Migration
{
    /** See the class comment: the foreign-key pause needs no open transaction. */
    public $withinTransaction = false;

    private const OLD_STATUSES = ['captured', 'encoded'];

    private const NEW_STATUSES = ['resting', 'captured', 'encoded'];

    public function up(): void
    {
        $this->rebuildingVisits(function (): void {
            Schema::table('clinic_visits', function (Blueprint $table) {
                $table->enum('status', self::NEW_STATUSES)->default('captured')->change();
                // When the student may come back and re-take the flagged
                // reading. Set once, at rest; never re-read from the browser.
                $table->timestamp('resting_until')->nullable()->after('checked_in_at');
            });
        });

        Schema::table('vital_signs', function (Blueprint $table) {
            // The pre-rest reading + its flags, as JSON: one nullable column
            // rather than seven duplicate ones, because nothing ever queries
            // inside it — the clinic only READS it on the encode page.
            $table->json('first_reading')->nullable()->after('bp_device_reading');
        });
    }

    public function down(): void
    {
        // Narrowing the enum would fail (MySQL) or break the CHECK (SQLite) on
        // a resting row, and there is no honest status to move one to: it is
        // neither captured (it must not enter the queue) nor encoded.
        if (DB::table('clinic_visits')->where('status', 'resting')->exists()) {
            throw new RuntimeException('Cannot roll back D-72: resting visits exist. Resolve them by hand first.');
        }

        Schema::table('vital_signs', function (Blueprint $table) {
            $table->dropColumn('first_reading');
        });

        $this->rebuildingVisits(function (): void {
            Schema::table('clinic_visits', function (Blueprint $table) {
                $table->dropColumn('resting_until');
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
