<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-32 — the case-category concept is removed with the Medical Cases
 * analytics rescope (PRD v1.11): the nurse now encodes Fit/Unfit only, so
 * table #11 (`clearance_case_categories`, added by D-23) is dropped. Seeded
 * demo rows go with it (approved by Nat, 2026-07-18); the old code remains
 * in git history (merge 6cc6d65).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('clearance_case_categories');
    }

    /**
     * Recreate the table exactly as the original D-23 create-migration
     * built it (2026_07_09_000002). Schema only — the dropped rows are
     * not restorable.
     */
    public function down(): void
    {
        Schema::create('clearance_case_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clearance_record_id')
                ->constrained('clearance_records')
                ->cascadeOnDelete();
            $table->string('case_category', 60);
            // A category can appear once per clearance. Named explicitly —
            // the auto-generated name is 66 chars, over MySQL's 64 limit.
            $table->unique(['clearance_record_id', 'case_category'], 'clearance_case_categories_record_category_unique');
            $table->timestamps();
        });
    }
};
