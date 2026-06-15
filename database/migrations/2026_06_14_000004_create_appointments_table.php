<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 20)->unique();   // APT-YYYY-####
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->enum('service_type', ['medical', 'dental']);
            $table->date('scheduled_date');
            $table->enum('status', ['scheduled', 'checked_in', 'completed', 'cancelled'])->default('scheduled');
            $table->enum('source', ['self', 'batch']);
            $table->foreignId('batch_request_id')->nullable()->constrained('batch_requests')->restrictOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['scheduled_date', 'status']); // §6.4: capacity checks + daily appointment lists
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
