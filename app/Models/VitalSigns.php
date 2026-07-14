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

    // ── Display helpers ──────────────────────────────────────────────────────

    /**
     * Human-readable line per tripped flag, e.g. "High Blood Pressure —
     * 145/92 mmHg" (Director dashboard preview, FR-ANL-01; same labels as
     * the Flagged Anomalies stat cards, FR-ANL-05). Reads the STORED flag
     * booleans — thresholds are computed once at kiosk submit, never here.
     *
     * @return list<string>
     */
    public function flagDescriptions(): array
    {
        $flags = [];

        if ($this->is_bp_flagged) {
            $flags[] = "High Blood Pressure — {$this->bp_systolic}/{$this->bp_diastolic} mmHg";
        }

        if ($this->is_temp_flagged) {
            $flags[] = "Fever — {$this->temperature_c}°C";
        }

        if ($this->is_bmi_flagged) {
            $flags[] = "Abnormal BMI — {$this->bmi}";
        }

        return $flags;
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The kiosk visit these vitals belong to. */
    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }
}
