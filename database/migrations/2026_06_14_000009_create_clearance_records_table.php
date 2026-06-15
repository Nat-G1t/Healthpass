<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clearance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_visit_id')->unique()->constrained('clinic_visits')->restrictOnDelete();
            $table->unsignedBigInteger('encoded_by');
            $table->foreign('encoded_by')->references('id')->on('users')->restrictOnDelete();
            $table->enum('result', ['Fit', 'Unfit']);
            $table->enum('case_category', [
                'Alimentary System', 'Respiratory System', 'Musculo-Skeletal System',
                'Integumentary System', 'Urinary System', 'Metabolic Endocrine System',
                'Cardiovascular System', 'Eyes, Ears, Nose & Throat Disorders',
            ])->nullable();
            $table->enum('purpose', [
                'Off Campus Procedure', 'On-the-job Training',
                'Field Trip/Educational Tour', 'Sports Activities',
            ])->nullable();
            $table->text('nurse_notes')->nullable();
            $table->string('physician_name', 120)->default('REYNALDO S. ALIPIO, MD');
            $table->string('physician_license_no', 20)->default('60252');
            $table->timestamp('encoded_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clearance_records');
    }
};
