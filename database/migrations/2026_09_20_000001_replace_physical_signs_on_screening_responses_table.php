<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-63 — both new official forms (Medical Clearance R04, Medical Assessment
 * Form R00) print TWELVE "Physical Signs Disorder of" rows, so the kiosk asks
 * those twelve instead of D-56's nine.
 *
 * FLAGGED SCHEMA CHANGE (approved by Nat, 2026-09-19): the nine D-56 columns
 * are REPLACED by twelve. The lists don't map one-to-one (HEENT splits into
 * five rows; GUT, EXTREMITIES and BREAST disappear), so old answers are
 * discarded BY DESIGN and never mapped. `skin` and `chest_lungs` are dropped
 * and re-added too, so no row keeps two old answers beside ten new blanks.
 *
 * `details` (JSON), `is_pregnant` and `last_menstrual_period` are untouched;
 * `details` is keyed by the new question keys from now on.
 *
 * Nullable for the same reason as D-56: SQLite can't add a NOT NULL column
 * without a default, and a default would invent "No" answers.
 * KioskSubmitRequest still requires all twelve for every new visit.
 */
return new class extends Migration
{
    /** D-56's nine rows (the old form). */
    private const OLD_QUESTIONS = [
        'skin', 'abdomen_git', 'heent', 'gut', 'chest_lungs',
        'extremities', 'heart_cvs', 'neurological', 'breast',
    ];

    /** The new forms' twelve rows, down each of the form's three columns. */
    private const NEW_QUESTIONS = [
        'skin', 'head', 'eyes', 'ears',
        'nose', 'throat', 'chest_lungs', 'heart',
        'abdomen', 'kidney_bladder', 'brain', 'mental_disorder',
    ];

    public function up(): void
    {
        $this->replaceColumns(self::OLD_QUESTIONS, self::NEW_QUESTIONS);
    }

    public function down(): void
    {
        // Restores the D-56 column SHAPE only — the answers are gone either way.
        $this->replaceColumns(self::NEW_QUESTIONS, self::OLD_QUESTIONS);
    }

    /** Drop one set of nullable booleans, then add the other. */
    private function replaceColumns(array $drop, array $add): void
    {
        Schema::table('screening_responses', function (Blueprint $table) use ($drop) {
            $table->dropColumn($drop);
        });

        Schema::table('screening_responses', function (Blueprint $table) use ($add) {
            foreach ($add as $column) {
                $table->boolean($column)->nullable();
            }
        });
    }
};
