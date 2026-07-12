<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Others, Specify" purpose on the clearance (official form line, encode
 * screen request): `purpose` becomes a plain string so it can hold 'Others'
 * alongside the four locked PRD values — the app-side Rule::in validation
 * is the real gate (SQLite never enforced the enum anyway) — and
 * `purpose_other` stores the nurse-specified event text.
 *
 * SCHEMA CHANGE flagged per CLAUDE.md: the PRD data dictionary lists
 * `purpose` as a 4-value enum and has no `purpose_other` — the PRD needs a
 * matching decisions-log entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clearance_records', function (Blueprint $table) {
            $table->string('purpose', 50)->nullable()->change();
            $table->string('purpose_other', 120)->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('clearance_records', function (Blueprint $table) {
            $table->dropColumn('purpose_other');
            $table->enum('purpose', [
                'Off Campus Procedure', 'On-the-job Training',
                'Field Trip/Educational Tour', 'Sports Activities',
            ])->nullable()->change();
        });
    }
};
