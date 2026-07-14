<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-29 (supersedes D-5's director-picked date): the College Admin
     * proposes the clinic date when submitting the batch — they know the
     * cohort's event (OJT start, graduation, field trip); the Director
     * confirms or adjusts it at approval, which still stamps the FINAL
     * date into `scheduled_date`.
     *
     * Nullable because batches submitted before D-29 have no requested
     * date; the Director's approve modal falls back to today for those.
     */
    public function up(): void
    {
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->date('requested_date')->nullable()->after('service_type');
        });
    }

    public function down(): void
    {
        Schema::table('batch_requests', function (Blueprint $table) {
            $table->dropColumn('requested_date');
        });
    }
};
