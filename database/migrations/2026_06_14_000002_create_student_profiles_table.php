<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('college_id')->constrained('colleges')->restrictOnDelete();
            $table->string('student_number', 20)->unique();
            $table->string('first_name', 80);
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80);
            $table->enum('sex', ['M', 'F']);
            $table->string('course', 120);
            $table->string('year_level', 20);
            $table->date('date_of_birth');
            $table->string('place_of_birth', 120);
            $table->enum('civil_status', ['Single', 'Married', 'Widowed', 'Separated']);
            $table->text('address');
            $table->string('qr_token', 64)->unique();
            $table->timestamp('privacy_consent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
