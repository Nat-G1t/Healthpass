<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_visits', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 20)->unique();   // HP-YYYY-####
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->enum('login_method', ['qr', 'email']);
            $table->enum('status', ['captured', 'encoded'])->default('captured');
            $table->timestamp('privacy_consent_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']); // §6.4: Live Queue polling (every 3–5 s)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_visits');
    }
};
