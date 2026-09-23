<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-78: recompute `vital_signs.is_bmi_flagged` on every existing row under the
 * new rule. A BMI is flagged whenever it is outside Normal (18.5–24.9), so
 * BMI < 18.5 or ≥ 25.0, replacing the old "≥ 30.0 only" rule.
 *
 * This DELIBERATELY rewrites history. BR-14 says flags are computed at capture
 * and stored. Nat chose (2026-09-23) to recompute past visits anyway, so past
 * months' analytics count under/overweight visits too. This is a one-time
 * exception, recorded in the D-78 decision row.
 *
 * `bmi` is NOT NULL, so every row is covered. Data only; no schema change.
 */
return new class extends Migration
{
    // Literal numbers on purpose, NOT config(): a migration records what was
    // done on the day it ran. If the thresholds in config/healthpass.php change
    // later, this migration must keep meaning exactly what it did here.
    private const NORMAL_MIN = 18.5;

    private const NORMAL_MAX = 25.0; // exclusive: 24.9 is Normal

    private const OLD_OBESE = 30.0;  // the pre-D-78 rule, for down()

    public function up(): void
    {
        DB::table('vital_signs')
            ->where(fn ($q) => $q->where('bmi', '<', self::NORMAL_MIN)->orWhere('bmi', '>=', self::NORMAL_MAX))
            ->update(['is_bmi_flagged' => true]);

        DB::table('vital_signs')
            ->where('bmi', '>=', self::NORMAL_MIN)
            ->where('bmi', '<', self::NORMAL_MAX)
            ->update(['is_bmi_flagged' => false]);
    }

    public function down(): void
    {
        DB::table('vital_signs')
            ->where('bmi', '>=', self::OLD_OBESE)
            ->update(['is_bmi_flagged' => true]);

        DB::table('vital_signs')
            ->where('bmi', '<', self::OLD_OBESE)
            ->update(['is_bmi_flagged' => false]);
    }
};
