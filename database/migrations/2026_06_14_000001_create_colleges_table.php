<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colleges', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();   // CCS, CEA, CBS, CAS, CEduc, CHTM
            $table->string('name', 120);
            $table->timestamps();
        });

        // Deferred FK: users.managed_college_id → colleges.id
        // Defined here (not in the users migration) because colleges must exist first.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('managed_college_id')
                ->references('id')->on('colleges')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['managed_college_id']);
        });

        Schema::dropIfExists('colleges');
    }
};
