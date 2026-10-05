<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-120 — FLAGGED SCHEMA CHANGE, table #13: what each College Admin and
     * the Director last SAW of each batch, for the notification dots on their
     * Batch Tracking rows and the sidebar badge (App\Support\BatchNotifications).
     *
     * A batch's results status is never stored — BatchRequest::resultsStatus()
     * works it out from the clock and the students (D-111) — so a "the status
     * changed" dot needs the status the viewer last saw to compare with. One
     * row per (viewer, batch), written when they open the batch from its View
     * button:
     *
     *   seen_status — the status they were shown ('scheduled', 'in_progress',
     *                 … for an approved batch; 'rejected' / 'cancelled')
     *   seen_at     — when; a release request made or decided after it is news
     *
     * No row = never opened. cascadeOnDelete() on both links: the row means
     * nothing once its viewer or its batch is gone.
     */
    public function up(): void
    {
        Schema::create('batch_seen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_request_id')->constrained()->cascadeOnDelete();
            $table->string('seen_status', 30);
            $table->timestamp('seen_at');

            $table->unique(['user_id', 'batch_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_seen');
    }
};
