<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERF INDEX (ISO 25010 performance-efficiency): the Director analytics module
 * filters every card on `whereBetween('checked_in_at', [monthStart, monthEnd])`
 * — visits-in-scope, vital-sign flags, BMI distribution, by-sex donut — and the
 * per-college charts add a `college_id` filter on top. The only pre-existing
 * index was (status, created_at) for the Live Queue poll, so checked_in_at was
 * unindexed and every analytics sub-query full-scanned clinic_visits, a cost that
 * only grows with each semester of visits.
 *
 * A composite (checked_in_at, college_id) lets the month BETWEEN use an index
 * range seek and also covers the per-college filter as a leading-prefix match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_visits', function (Blueprint $table) {
            $table->index(['checked_in_at', 'college_id'], 'clinic_visits_checked_in_college_idx');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_visits', function (Blueprint $table) {
            $table->dropIndex('clinic_visits_checked_in_college_idx');
        });
    }
};
