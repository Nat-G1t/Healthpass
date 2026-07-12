<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-27 — device authorization for the kiosk terminal (FR-NRS-06 "Enable Kiosk
 * Mode"). FLAGGED SCHEMA CHANGE: this is a new table (#12) beyond the schema
 * CLAUDE.md locks at 11 — see the PRD data dictionary + Decisions Log (D-27).
 *
 * A nurse enrolls a browser/terminal as a trusted kiosk DEVICE. We store only a
 * SHA-256 HASH of the device's random token (never the plaintext — like a
 * personal access token); the browser holds the plaintext in a long-lived
 * cookie and presents it on every /kiosk request. KioskAccess accepts a request
 * whose token hashes to an un-revoked row here. Revoking sets `revoked_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosk_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);                 // human label, e.g. "Clinic Pi"
            $table->string('token_hash', 64)->unique();  // sha256 hex of the device token
            // The enrolling nurse. nullOnDelete keeps the device audit row even if
            // the nurse account is later removed (attribution just becomes NULL).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // NULL = active; a timestamp = revoked (access denied from then on).
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_devices');
    }
};
