<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-66 — heart rate and respiratory rate join the flagged vitals (BR-13/BR-14).
 *
 * FLAGGED SCHEMA CHANGE (approved by Nat, 2026-09-18/19): two BOOLEAN NOT NULL
 * DEFAULT false columns on vital_signs, beside the three flags that were always
 * there. They are stored rather than computed on read for the same reason the
 * others are — ClinicVisit::scopeFlagged(), the Director's anomaly counts and
 * the analytics tiles all query them in SQL, and a threshold the clinic later
 * tunes must not silently re-write what past screenings recorded.
 *
 * `is_hr_flagged` is written at KIOSK CAPTURE (SubmitKioskVisit), next to the
 * other three. `is_rr_flagged` is written at ENCODE, because respiratory_rate
 * itself is only typed there (D-65) — until then it stays false, and the
 * default is what makes every pre-D-66 row read as "not flagged" rather than
 * NULL. Both rules live on App\Models\VitalSigns so nothing can drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vital_signs', function (Blueprint $table) {
            $table->boolean('is_hr_flagged')->default(false)->after('is_bmi_flagged');
            $table->boolean('is_rr_flagged')->default(false)->after('is_hr_flagged');
        });
    }

    public function down(): void
    {
        Schema::table('vital_signs', function (Blueprint $table) {
            $table->dropColumn(['is_hr_flagged', 'is_rr_flagged']);
        });
    }
};
