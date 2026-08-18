<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA ADD (PRD data dictionary, Decision D-43): a capture-time snapshot of
 * the student's academic PROGRAM on each clinic visit, sitting right beside the
 * college_id snapshot D-17 already added.
 *
 * `student_profiles.course` stays LIVE — a student who shifts program sees the
 * new one everywhere. Per-program reporting must NOT follow them: without a
 * snapshot, one shift would silently restate every past monthly report. So the
 * program is frozen at kiosk submit, exactly the way the college is.
 *
 * NULLABLE and NEVER BACKFILLED. A visit captured before this column existed has
 * no honest answer — the student's current program is a guess, not a record — so
 * those rows stay null and render as "—", the same pattern `appointments`
 * `.scheduled_time` uses for its pre-D-37 rows. (D-17 could backfill because a
 * college is far stickier than a program and that column had to reach NOT NULL;
 * neither is true here.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_visits', function (Blueprint $table) {
            // 120 mirrors student_profiles.course — the value is copied verbatim.
            $table->string('course', 120)->nullable()->after('college_id');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_visits', function (Blueprint $table) {
            $table->dropColumn('course');
        });
    }
};
