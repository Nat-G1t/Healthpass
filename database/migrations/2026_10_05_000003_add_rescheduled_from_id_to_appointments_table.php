<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-110: a student who misses their clinic day gets ONE automatic 2nd
     * chance — a new appointment, same batch, on the nearest day that fits.
     * This column points the new appointment back at the one it replaces.
     *
     * UNIQUE: an appointment has at most one 2nd chance. The index is also
     * what stops two overlapping runs of the 8 PM job from booking the same
     * missed student twice — the second insert fails instead.
     *
     * nullOnDelete(): if the missed appointment is ever deleted, the 2nd
     * chance stays, it just stops pointing anywhere.
     *
     * NULLABLE and NOT backfilled: every appointment before D-110 was a first
     * appointment.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('rescheduled_from_id')
                ->nullable()
                ->after('batch_request_id')
                ->unique()
                ->constrained('appointments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['rescheduled_from_id']);
            $table->dropUnique(['rescheduled_from_id']);
            $table->dropColumn('rescheduled_from_id');
        });
    }
};
