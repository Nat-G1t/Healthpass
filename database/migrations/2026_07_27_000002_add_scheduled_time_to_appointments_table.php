<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-37: every appointment now sits in a one-hour clinic slot, and capacity
     * is counted per slot (12) as well as per day (120).
     *
     * NULLABLE ON PURPOSE — and deliberately NOT backfilled. Appointments
     * created before D-37 belong to no slot; inventing 07:00 for them would
     * invent twelve fake bookings in the first hour of every past clinic day
     * and corrupt the very counts this column exists to make correct. They
     * keep NULL, render as "—", and are still counted by the DAILY cap.
     * New bookings require a time (StoreAppointmentRequest).
     *
     * The composite index is what makes the per-slot count cheap: the existing
     * index(['scheduled_date','status']) can't answer "how many at 09:00 on the
     * 14th?" without scanning the whole day. Column order matters —
     * (date, time, status) also serves date-only lookups as a leftmost prefix,
     * so it covers the daily count too.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->time('scheduled_time')->nullable()->after('scheduled_date');

            $table->index(
                ['scheduled_date', 'scheduled_time', 'status'],
                'appointments_date_time_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_date_time_status_index');
            $table->dropColumn('scheduled_time');
        });
    }
};
