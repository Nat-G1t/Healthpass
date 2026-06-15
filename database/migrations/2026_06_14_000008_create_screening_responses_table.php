<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screening_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_visit_id')->unique()->constrained('clinic_visits')->cascadeOnDelete();
            $table->boolean('vision');
            $table->boolean('hearing');
            $table->boolean('nose');
            $table->boolean('skin');
            $table->boolean('respiratory');
            $table->boolean('heart');
            $table->boolean('digestive');
            $table->boolean('bones');
            $table->boolean('nervous');
            $table->boolean('is_pregnant');
            $table->date('last_menstrual_period')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screening_responses');
    }
};
