<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-68 — a Medical Assessment Form batch's kiosk session also captures the
 * form's section I, "Personal / Social History".
 *
 * FLAGGED SCHEMA CHANGE (approved by Nat, 2026-09-19): `screening_responses`
 * gains four columns. The three habit rows are VARCHAR(4) because the paper
 * offers THREE boxes — Yes, No, Quit — which no boolean can hold; the value
 * list is gated by validation (`ScreeningResponse::SOCIAL_HISTORY_VALUES`),
 * not by the column type, so SQLite and MySQL behave identically.
 *
 * All four are NULL on every Medical Clearance visit — that form has no
 * Personal / Social History section, so the questions are never asked and the
 * absence is the honest record. They are also NULL on every visit captured
 * before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screening_responses', function (Blueprint $table) {
            // 'yes' | 'no' | 'quit' — the paper's three boxes.
            $table->string('smoking', 4)->nullable()->after('details');
            $table->string('alcohol', 4)->nullable()->after('smoking');
            $table->string('illicit_drugs', 4)->nullable()->after('alcohol');
            // The paper offers only Yes / No for this one.
            $table->boolean('sexually_active')->nullable()->after('illicit_drugs');
        });
    }

    public function down(): void
    {
        Schema::table('screening_responses', function (Blueprint $table) {
            $table->dropColumn(['smoking', 'alcohol', 'illicit_drugs', 'sexually_active']);
        });
    }
};
