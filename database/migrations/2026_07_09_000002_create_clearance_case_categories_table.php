<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-23 — a clearance can have MULTIPLE medical-system case categories
 * (flagged schema change, PRD v1.4: this is table #11, beyond the original
 * locked 10). A child table (one row per record × category) instead of a
 * JSON column so the Director's cases-per-category analytics stay plain,
 * portable JOIN + GROUP BY SQL (MySQL dev / SQLite tests).
 *
 * The old single-value clearance_records.case_category column is migrated
 * into the new table, then dropped.
 */
return new class extends Migration
{
    public function up(): void
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

        // Carry over the existing single-category values (dev data), then
        // drop the old column — the child table is now the only source.
        $existing = DB::table('clearance_records')
            ->whereNotNull('case_category')
            ->get(['id', 'case_category']);

        foreach ($existing as $record) {
            DB::table('clearance_case_categories')->insert([
                'clearance_record_id' => $record->id,
                'case_category' => $record->case_category,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('clearance_records', function (Blueprint $table) {
            $table->dropColumn('case_category');
        });
    }

    public function down(): void
    {
        Schema::table('clearance_records', function (Blueprint $table) {
            $table->string('case_category', 60)->nullable();
        });

        // Best effort: restore each record's FIRST category (the old column
        // could only hold one).
        $rows = DB::table('clearance_case_categories')->orderBy('id')->get();
        foreach ($rows as $row) {
            DB::table('clearance_records')
                ->where('id', $row->clearance_record_id)
                ->whereNull('case_category')
                ->update(['case_category' => $row->case_category]);
        }

        Schema::dropIfExists('clearance_case_categories');
    }
};
