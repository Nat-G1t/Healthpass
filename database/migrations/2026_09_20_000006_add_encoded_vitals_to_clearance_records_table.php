<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-65 — the vitals the clinic CONFIRMED at encode.
 *
 * FLAGGED SCHEMA CHANGE (approved by Nat, 2026-09-18/19): one nullable JSON
 * column on clearance_records holding
 * {height_cm, weight_kg, bmi, temperature_c, bp_systolic, bp_diastolic,
 *  heart_rate_bpm, respiratory_rate}.
 *
 * The kiosk's own reading in `vital_signs` is never overwritten (BR-14) — it
 * is what the screening measured, and the flags and analytics describe it.
 * When the nurse or physician corrects a value at the clinic, the corrected
 * copy lands here and it is this copy that prints and that the student sees.
 *
 * NULL on every record encoded before D-65 — never backfilled; those forms
 * print their kiosk reading, as they always did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clearance_records', function (Blueprint $table) {
            $table->json('encoded_vitals')->nullable()->after('nurse_notes');
        });
    }

    public function down(): void
    {
        Schema::table('clearance_records', function (Blueprint $table) {
            $table->dropColumn('encoded_vitals');
        });
    }
};
