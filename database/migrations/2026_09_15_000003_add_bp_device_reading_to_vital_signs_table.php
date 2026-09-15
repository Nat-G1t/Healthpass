<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-58 — the Bluetooth BP monitor's own record of a kiosk BP reading.
 *
 * FLAGGED SCHEMA CHANGE (approved by Nat, 2026-09-15): one nullable JSON column
 * on vital_signs holding what the A&D UA-651BLE reported alongside the numbers:
 * device_model, raw (hex of the Bluetooth characteristic), taken_at,
 * mean_arterial, received_at, suspect, and the measurement-status flags —
 * among them irregular_pulse, which the nurse sees on the encode page.
 *
 * NULL whenever the BP step was typed or came over the ESP32 serial line, and
 * on every visit captured before D-58 — never backfilled. entry_method does not
 * change: a Bluetooth reading counts as `sensor`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vital_signs', function (Blueprint $table) {
            $table->json('bp_device_reading')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vital_signs', function (Blueprint $table) {
            $table->dropColumn('bp_device_reading');
        });
    }
};
