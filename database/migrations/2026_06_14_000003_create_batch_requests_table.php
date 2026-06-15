<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 20)->unique();   // BR-YYYY-###
            $table->foreignId('college_id')->constrained('colleges')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->enum('reason', ['graduation', 'ojt', 'enrollment', 'scholarship', 'sports', 'fieldtrip', 'others']);
            $table->text('reason_detail')->nullable();
            $table->enum('service_type', ['medical', 'dental']);
            $table->date('scheduled_date')->nullable();     // D-5: Director-selected date, stamped at approval
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->foreign('reviewed_by')->references('id')->on('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_requests');
    }
};
