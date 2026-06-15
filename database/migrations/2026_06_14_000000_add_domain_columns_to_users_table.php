<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add HealthPass domain columns to the Breeze-generated users table.
            $table->enum('role', ['student', 'college_admin', 'nurse', 'director'])->after('id');
            $table->string('name', 120)->change();
            $table->string('email', 191)->change();
            // FK to colleges.id is attached in the colleges migration (colleges must exist first)
            $table->unsignedBigInteger('managed_college_id')->nullable()->after('password');
            $table->enum('status', ['active', 'inactive'])->default('active')->after('managed_college_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'managed_college_id', 'status']);
            $table->string('name')->change();
            $table->string('email')->change();
        });
    }
};
