<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-22 — "Physical Signs Disorder of" exam findings (flagged schema change,
 * PRD v1.4). The physician examines the student at the clinic; the nurse
 * records YES/NO per body system on the encode screen (FR-NRS-03), and the
 * printed form (FR-PRT-02) shades its bubbles from these columns.
 *
 * Nullable on purpose: NULL = the row was not examined/answered, and the
 * printed bubbles for that system stay blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clearance_records', function (Blueprint $table) {
            $table->boolean('ps_skin')->nullable();
            $table->boolean('ps_abdomen_git')->nullable();
            $table->boolean('ps_heent')->nullable();
            $table->boolean('ps_gut')->nullable();
            $table->boolean('ps_chest_lungs')->nullable();
            $table->boolean('ps_extremities')->nullable();
            $table->boolean('ps_heart_cvs')->nullable();
            $table->boolean('ps_neurological')->nullable();
            $table->boolean('ps_breast')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clearance_records', function (Blueprint $table) {
            $table->dropColumn([
                'ps_skin', 'ps_abdomen_git', 'ps_heent', 'ps_gut',
                'ps_chest_lungs', 'ps_extremities', 'ps_heart_cvs',
                'ps_neurological', 'ps_breast',
            ]);
        });
    }
};
