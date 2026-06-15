<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VitalSigns extends Model
{
    use HasFactory;

    protected $fillable = [
        'clinic_visit_id',
        'height_cm',
        'weight_kg',
        'bmi',
        'temperature_c',
        'heart_rate_bpm',
        'bp_systolic',
        'bp_diastolic',
        'entry_method',
        'is_temp_flagged',
        'is_bp_flagged',
        'is_bmi_flagged',
    ];

    protected function casts(): array
    {
        return [
            'height_cm' => 'decimal:1',
            'weight_kg' => 'decimal:1',
            'bmi' => 'decimal:1',
            'temperature_c' => 'decimal:1',
            'heart_rate_bpm' => 'integer',
            'bp_systolic' => 'integer',
            'bp_diastolic' => 'integer',
            'is_temp_flagged' => 'boolean',
            'is_bp_flagged' => 'boolean',
            'is_bmi_flagged' => 'boolean',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The kiosk visit these vitals belong to. */
    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }
}
