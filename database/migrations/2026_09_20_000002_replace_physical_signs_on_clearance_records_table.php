<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-63 — the nurse's "Physical Signs Disorder of" findings follow the new
 * forms' twelve rows (FR-NRS-03). FLAGGED SCHEMA CHANGE (approved by Nat,
 * 2026-09-19): the nine D-22 `ps_*` columns are replaced by `ps_<key>` for the
 * twelve keys in ScreeningResponse::QUESTIONS, so the encode pre-fill stays
 * ps_<key> ← <key>.
 *
 * Old findings are discarded BY DESIGN (never mapped). NULL still means "not
 * examined" and leaves the printed row blank.
 */
return new class extends Migration
{
    /** The D-22 / D-56 columns (the old form). */
    private const OLD_COLUMNS = [
        'ps_skin', 'ps_abdomen_git', 'ps_heent', 'ps_gut', 'ps_chest_lungs',
        'ps_extremities', 'ps_heart_cvs', 'ps_neurological', 'ps_breast',
    ];

    /** The new forms' twelve rows. */
    private const NEW_COLUMNS = [
        'ps_skin', 'ps_head', 'ps_eyes', 'ps_ears',
        'ps_nose', 'ps_throat', 'ps_chest_lungs', 'ps_heart',
        'ps_abdomen', 'ps_kidney_bladder', 'ps_brain', 'ps_mental_disorder',
    ];

    public function up(): void
    {
        $this->replaceColumns(self::OLD_COLUMNS, self::NEW_COLUMNS);
    }

    public function down(): void
    {
        // Restores the old column SHAPE only — the findings are gone either way.
        $this->replaceColumns(self::NEW_COLUMNS, self::OLD_COLUMNS);
    }

    /** Drop one set of nullable booleans, then add the other. */
    private function replaceColumns(array $drop, array $add): void
    {
        Schema::table('clearance_records', function (Blueprint $table) use ($drop) {
            $table->dropColumn($drop);
        });

        Schema::table('clearance_records', function (Blueprint $table) use ($add) {
            foreach ($add as $column) {
                $table->boolean($column)->nullable();
            }
        });
    }
};
