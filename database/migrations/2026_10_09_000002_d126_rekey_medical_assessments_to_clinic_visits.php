<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-126 — FLAGGED SCHEMA CHANGE (approved by Nat, 2026-10-08).
 *
 * The nurse now saves the Medical Assessment interview BEFORE any clearance
 * record exists (the physician creates that at encode), so the sections can no
 * longer hang off `clearance_records`:
 *
 *  - `medical_assessments` is RE-KEYED from `clearance_record_id` to
 *    `clinic_visit_id` (unique — one interview per visit — and restricted on
 *    delete, the same rule clearance_records uses). Every existing row is
 *    backfilled through `clearance_records.clinic_visit_id`, so no assessment
 *    loses its visit. `clearance_record_id` is then dropped.
 *  - `medical_assessments.interview_vitals` (JSON) — the vitals + respiratory
 *    rate the nurse confirmed, in the shape of `clearance_records.encoded_vitals`.
 *    The physician's page pre-fills from it; the physician's save still writes
 *    `encoded_vitals`, which is what prints.
 *
 * Built in small steps so MySQL/MariaDB never has to alter a column that
 * already carries a foreign key: add it bare and nullable, backfill, make it
 * NOT NULL, and only then add the unique index and the foreign key.
 */
return new class extends Migration
{
    /** SQLite rebuilds the table for change()/dropForeign — keep it out of a transaction like D-72. */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('medical_assessments', function (Blueprint $table) {
            $table->unsignedBigInteger('clinic_visit_id')->nullable()->after('id');
            $table->json('interview_vitals')->nullable()->after('physical_exam');
        });

        // A correlated subquery — portable to MySQL and SQLite alike.
        DB::statement(
            'UPDATE medical_assessments SET clinic_visit_id = ('
            .'SELECT clearance_records.clinic_visit_id FROM clearance_records '
            .'WHERE clearance_records.id = medical_assessments.clearance_record_id)'
        );

        $this->pausingForeignKeys(function (): void {
            Schema::table('medical_assessments', function (Blueprint $table) {
                $table->unsignedBigInteger('clinic_visit_id')->nullable(false)->change();
            });

            Schema::table('medical_assessments', function (Blueprint $table) {
                $table->unique('clinic_visit_id');
                $table->foreign('clinic_visit_id')->references('id')->on('clinic_visits')->restrictOnDelete();
            });

            Schema::table('medical_assessments', function (Blueprint $table) {
                $table->dropForeign(['clearance_record_id']);
                $table->dropUnique(['clearance_record_id']);
                $table->dropColumn('clearance_record_id');
            });
        });
    }

    public function down(): void
    {
        // A row the nurse saved but no physician has encoded yet has no
        // clearance record to hang off — rolling back would orphan it.
        $orphans = DB::table('medical_assessments')
            ->whereNotExists(fn ($query) => $query->from('clearance_records')
                ->whereColumn('clearance_records.clinic_visit_id', 'medical_assessments.clinic_visit_id'))
            ->exists();

        if ($orphans) {
            throw new RuntimeException('Cannot roll back D-126: some assessments have no clearance record yet (interviewed, not encoded).');
        }

        Schema::table('medical_assessments', function (Blueprint $table) {
            $table->unsignedBigInteger('clearance_record_id')->nullable()->after('id');
        });

        DB::statement(
            'UPDATE medical_assessments SET clearance_record_id = ('
            .'SELECT clearance_records.id FROM clearance_records '
            .'WHERE clearance_records.clinic_visit_id = medical_assessments.clinic_visit_id)'
        );

        $this->pausingForeignKeys(function (): void {
            Schema::table('medical_assessments', function (Blueprint $table) {
                $table->unsignedBigInteger('clearance_record_id')->nullable(false)->change();
            });

            Schema::table('medical_assessments', function (Blueprint $table) {
                $table->unique('clearance_record_id');
                $table->foreign('clearance_record_id')->references('id')->on('clearance_records')->restrictOnDelete();
            });

            Schema::table('medical_assessments', function (Blueprint $table) {
                $table->dropForeign(['clinic_visit_id']);
                $table->dropUnique(['clinic_visit_id']);
                $table->dropColumn(['clinic_visit_id', 'interview_vitals']);
            });
        });
    }

    /** Run $change with SQLite's foreign-key checks paused (a no-op elsewhere). */
    private function pausingForeignKeys(callable $change): void
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
