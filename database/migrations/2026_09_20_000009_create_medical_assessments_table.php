<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-69 — the Medical Assessment Form's own encode sections.
 *
 * FLAGGED SCHEMA CHANGE (approved by Nat, 2026-09-19): a NEW domain table,
 * `medical_assessments` — the eleventh. It is 1:0..1 with `clearance_records`
 * and exists ONLY for visits whose batch chose the Medical Assessment Form
 * (D-62): a Medical Clearance encode writes no row at all, which is why these
 * sections are not a dozen more nullable columns on `clearance_records`.
 *
 * One JSON column per section of the paper. The sections are recorded and
 * printed whole — nothing filters or aggregates on a single condition — so
 * JSON is the honest shape, and it keeps the twelve-plus checkbox rows of the
 * paper out of the schema. MySQL and SQLite both store and read JSON, so the
 * test suite and the dev database behave identically.
 *
 * `menstrual_history`, `ob_history` and `physical_exam` are created empty here
 * even though prompt 11 is what fills them — one migration for one table beats
 * an ALTER a week later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_assessments', function (Blueprint $table) {
            $table->id();

            // 1:0..1 with the clearance record: unique, and restricted on
            // delete so an encoded record can never leave an orphan section
            // behind (the same rule clearance_records uses on clinic_visits).
            $table->foreignId('clearance_record_id')
                ->unique()
                ->constrained()
                ->restrictOnDelete();

            // {"patient": [keys], "family": [keys], "specify": {key: text}}
            $table->json('medical_history')->nullable();
            // {"given": [keys], "others": text|null}
            $table->json('immunizations')->nullable();
            // III. Family Planning Access — Yes / No, NULL = unanswered.
            $table->boolean('family_planning_access')->nullable();
            // {"procedures": text|null, "date_done": text|null}
            $table->json('surgical_history')->nullable();

            // Sections V, VI and the Pertinent Physical Examination — written
            // by prompt 11 (D-70), created now so it needs no migration.
            $table->json('menstrual_history')->nullable();
            $table->json('ob_history')->nullable();
            $table->json('physical_exam')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_assessments');
    }
};
