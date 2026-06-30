<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA ADD (PRD data dictionary, Decision D-17): capture-time snapshot of the
 * student's college on each clinic visit. student_profiles.college_id stays LIVE
 * (a transfer re-scopes the student to the new College Admin), but analytics must
 * stay transfer-proof — a past flag/case keeps the college it was recorded under.
 * SubmitKioskVisit freezes this value at check-in (FR-STU-09, FR-ANL-05/08).
 *
 * The column is ultimately NOT NULL, but pre-existing visits (captured before this
 * feature) have no snapshot. We add it nullable, backfill each visit from its
 * student's CURRENT college — the best proxy available retroactively — then
 * tighten to NOT NULL so every future visit must carry a snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add nullable so the column can exist before historical rows are filled.
        Schema::table('clinic_visits', function (Blueprint $table) {
            $table->foreignId('college_id')
                ->nullable()
                ->after('student_id')
                ->constrained('colleges')
                ->restrictOnDelete();
        });

        // 2. Backfill existing visits from the student's current college.
        //    Correlated subquery — portable across SQLite (tests) and MySQL (dev).
        DB::statement(<<<'SQL'
            UPDATE clinic_visits
            SET college_id = (
                SELECT sp.college_id
                FROM student_profiles sp
                WHERE sp.user_id = clinic_visits.student_id
            )
            WHERE college_id IS NULL
        SQL);

        // 3. Enforce NOT NULL going forward (the FK constraint stays in place).
        Schema::table('clinic_visits', function (Blueprint $table) {
            $table->unsignedBigInteger('college_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('clinic_visits', function (Blueprint $table) {
            $table->dropForeign(['college_id']);
            $table->dropColumn('college_id');
        });
    }
};
