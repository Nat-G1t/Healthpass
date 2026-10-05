<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-113 — FLAGGED SCHEMA CHANGE, table #12: a College Admin's request that
     * the Director release a finished batch's official documents to the
     * college.
     *
     * One row per request. A rejected request stays as history and the
     * college may ask again, so a batch can have several rows — but at most
     * ONE `pending` at a time (checked under a lock when a request is made,
     * not by the schema). Once one is `approved` the batch's documents are
     * released for good (D-113: an approval never expires).
     *
     * The attachment is a file on the PRIVATE `local` disk
     * (storage/app/private/release-requests/), like the D-109 signed memo:
     * its path, the name it had on the admin's device (shown, and used as the
     * browser's save-as name) and the type the SERVER detected at upload.
     *
     * cascadeOnDelete(): the requests belong to their batch — nothing deletes
     * a batch today, but if one ever is, its requests go with it. The user
     * links keep Laravel's default (refuse to delete a user who made or
     * reviewed one), like batch_requests.cancelled_by.
     *
     * The (batch_request_id, status) index serves the two questions asked on
     * every results page: "is one pending?" and "is one approved?".
     */
    public function up(): void
    {
        Schema::create('document_release_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->text('reason');
            $table->string('attachment_path');
            $table->string('attachment_original_name');
            $table->string('attachment_mime');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_message')->nullable();
            $table->timestamps();

            $table->index(['batch_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_release_requests');
    }
};
