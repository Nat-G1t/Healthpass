<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-56 — the kiosk questionnaire asks the official form's nine "Physical Signs
 * Disorder of" rows instead of the old self-report systems.
 *
 * FLAGGED SCHEMA CHANGE (approved by Nat, 2026-09-14): screening_responses'
 * nine system columns are REPLACED, and a `details` JSON column holds the
 * optional text a student types under a YES answer (keys = question keys).
 *
 * Old answers are discarded BY DESIGN. `skin` is dropped and re-added too, so
 * no row keeps one old answer half-mapped beside eight new blanks.
 *
 * The nine new booleans are NULLABLE at the DB level: SQLite can't add a NOT
 * NULL column without a default, and a default would invent "No" answers for
 * rows that never saw these questions. KioskSubmitRequest still requires all
 * nine for every new visit.
 *
 * Column names equal the clearance_records.ps_* suffixes, so the nurse encode
 * pre-fill is simply ps_<key> ← <key>.
 */
return new class extends Migration
{
    /** The pre-D-56 self-report systems. */
    private const OLD_SYSTEMS = [
        'vision', 'hearing', 'nose', 'skin', 'respiratory',
        'heart', 'digestive', 'bones', 'nervous',
    ];

    /** The official form's rows, in the form's order. */
    private const FORM_QUESTIONS = [
        'skin', 'abdomen_git', 'heent', 'gut', 'chest_lungs',
        'extremities', 'heart_cvs', 'neurological', 'breast',
    ];

    public function up(): void
    {
        Schema::table('screening_responses', function (Blueprint $table) {
            $table->dropColumn(self::OLD_SYSTEMS);
        });

        Schema::table('screening_responses', function (Blueprint $table) {
            foreach (self::FORM_QUESTIONS as $column) {
                $table->boolean($column)->nullable();
            }
            $table->json('details')->nullable();
        });
    }

    public function down(): void
    {
        // Restores the old column SHAPE only — the answers up() discarded
        // cannot come back. default(false) is there because SQLite can't add a
        // NOT NULL column without one, so every row reads all-NO after a
        // rollback. Treat a rollback as dev-only.
        Schema::table('screening_responses', function (Blueprint $table) {
            $table->dropColumn([...self::FORM_QUESTIONS, 'details']);
        });

        Schema::table('screening_responses', function (Blueprint $table) {
            foreach (self::OLD_SYSTEMS as $column) {
                $table->boolean($column)->default(false);
            }
        });
    }
};
