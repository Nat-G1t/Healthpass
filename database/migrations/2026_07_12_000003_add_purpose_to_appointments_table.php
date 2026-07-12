<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purpose of Medical Clearance captured at STUDENT self-booking (D-28).
 *
 * Purpose used to live only on `clearance_records`, filled by the nurse at
 * encode time. Students now choose the purpose when they self-book a medical
 * clearance, so it has to be captured on the appointment and carried through
 * to the clearance record when the nurse saves (encode then hides its own
 * purpose input). These two columns hold that student choice.
 *
 *   - `purpose`       one of ClearanceRecord::PURPOSES or the 'Others' line —
 *                     a plain string (not an enum) exactly like the matching
 *                     clearance_records column, so the app-side Rule::in stays
 *                     the single validation gate (SQLite never enforced enums).
 *   - `purpose_other` the free-text event when 'Others' is chosen.
 *
 * Both are NULLABLE on purpose: dental appointments (scheduling-only, no
 * clearance), batch-booked appointments, and any appointment created before
 * this feature carry NO purpose — those fall back to the nurse-entered
 * dropdown on the encode screen.
 *
 * SCHEMA CHANGE flagged per CLAUDE.md: the PRD data dictionary's appointments
 * table gains these two columns — matching data-dictionary + decisions-log
 * (D-28) entries land in docs/HealthPass_PRD.md and docs/HealthPass_Context.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // service_type is the last "shape" column before the status/source
            // bookkeeping columns — keep the student's choice next to it.
            $table->string('purpose', 50)->nullable()->after('service_type');
            $table->string('purpose_other', 120)->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'purpose_other']);
        });
    }
};
