<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-37: a batch now books a contiguous BLOCK of one-hour clinic slots —
     * the admin picks the start hour, the system computes the span as
     * ceil(students / hourly_capacity) blocks.
     *
     * Two columns, and only two:
     *
     *   requested_time   the admin-chosen START hour of the span
     *   requested_blocks how many contiguous hours the span occupies
     *
     * WHY A BLOCK COUNT AND NOT AN END TIME (the prompt's "minimal
     * representation" call): the span is start + length, and length in *slot
     * units* is the thing every consumer actually wants — the Form Request
     * loops `blocks` times to check each hour, the fan-out loops `blocks`
     * times to distribute students, the view prints "(3 slots)". A stored end
     * time would have to be re-divided by the slot length at every one of
     * those call sites, and it carries an ambiguity a count does not
     * (is 10:00 AM the start of the last hour, or the end of it?). Storing
     * both would be two facts that can disagree. Note the count is also *not*
     * derivable after the fact: students can be removed from a college roster,
     * so recomputing ceil(students/12) later could silently shrink an
     * already-approved span.
     *
     * Both NULLABLE: batches submitted before D-37 have no time span. Like
     * D-36's NULL requested_date they cannot be approved — there is nothing to
     * confirm — and the Director is directed to reject-and-resubmit.
     */
    public function up(): void
    {
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->time('requested_time')->nullable()->after('requested_date');
            $table->unsignedTinyInteger('requested_blocks')->nullable()->after('requested_time');
        });
    }

    public function down(): void
    {
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->dropColumn(['requested_time', 'requested_blocks']);
        });
    }
};
