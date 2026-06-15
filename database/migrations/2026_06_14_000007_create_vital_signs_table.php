<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vital_signs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_visit_id')->unique()->constrained('clinic_visits')->cascadeOnDelete();
            $table->decimal('height_cm', 5, 1);
            $table->decimal('weight_kg', 5, 1);
            $table->decimal('bmi', 4, 1);
            $table->decimal('temperature_c', 4, 1);
            $table->unsignedSmallInteger('heart_rate_bpm');
            $table->unsignedSmallInteger('bp_systolic');
            $table->unsignedSmallInteger('bp_diastolic');
            $table->enum('entry_method', ['sensor', 'manual', 'mixed'])->default('sensor'); // D-7
            $table->boolean('is_temp_flagged')->default(false);
            $table->boolean('is_bp_flagged')->default(false);
            $table->boolean('is_bmi_flagged')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vital_signs');
    }
};
