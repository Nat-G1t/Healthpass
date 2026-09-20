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
        // D-65: typed at encode, not by the kiosk — NULL until then.
        'respiratory_rate',
        'bp_systolic',
        'bp_diastolic',
        'entry_method',
        'is_temp_flagged',
        'is_bp_flagged',
        'is_bmi_flagged',
        'bp_device_reading',
    ];

    protected function casts(): array
    {
        return [
            'height_cm' => 'decimal:1',
            'weight_kg' => 'decimal:1',
            'bmi' => 'decimal:1',
            'temperature_c' => 'decimal:1',
            'heart_rate_bpm' => 'integer',
            'respiratory_rate' => 'integer',
            'bp_systolic' => 'integer',
            'bp_diastolic' => 'integer',
            'is_temp_flagged' => 'boolean',
            'is_bp_flagged' => 'boolean',
            'is_bmi_flagged' => 'boolean',
            // D-58: the Bluetooth BP monitor's record of the reading, or null.
            'bp_device_reading' => 'array',
        ];
    }

    /**
     * BMI = weight(kg) ÷ height(m)², 1 decimal (FR-KSK-09). The ONE formula:
     * the kiosk submit and the clinic's encode both recompute through it, so a
     * posted BMI is never trusted from either screen.
     */
    public static function computeBmi(float $heightCm, float $weightKg): float
    {
        $metres = $heightCm / 100;

        return round($weightKg / ($metres * $metres), 1);
    }

    // ── Display helpers ──────────────────────────────────────────────────────

    /**
     * One ['label' => …, 'value' => …] pair per tripped flag — the Flagged
     * Anomalies table (FR-ANL-05) renders label and value in separate
     * columns. Reads the STORED flag booleans — thresholds are computed
     * once at kiosk submit, never here.
     *
     * @return list<array{label: string, value: string}>
     */
    public function flagDetails(): array
    {
        $flags = [];

        if ($this->is_bp_flagged) {
            $flags[] = ['label' => 'High Blood Pressure', 'value' => "{$this->bp_systolic}/{$this->bp_diastolic} mmHg"];
        }

        if ($this->is_temp_flagged) {
            $flags[] = ['label' => 'Fever', 'value' => "{$this->temperature_c}°C"];
        }

        if ($this->is_bmi_flagged) {
            $flags[] = ['label' => 'Abnormal BMI', 'value' => (string) $this->bmi];
        }

        return $flags;
    }

    /**
     * Human-readable line per tripped flag, e.g. "High Blood Pressure —
     * 145/92 mmHg" (Director dashboard preview, FR-ANL-01). Same data as
     * flagDetails(), joined — the preview and the anomalies table can
     * never disagree.
     *
     * @return list<string>
     */
    public function flagDescriptions(): array
    {
        return array_map(
            fn (array $flag): string => "{$flag['label']} — {$flag['value']}",
            $this->flagDetails(),
        );
    }

    /**
     * Whether the Bluetooth BP monitor reported an irregular pulse for this
     * visit's reading (D-58). False when BP was typed or came over serial. Not a
     * §7.4 flag — it counts toward no analytics; the nurse simply sees it.
     */
    public function hasIrregularPulse(): bool
    {
        return (bool) ($this->bp_device_reading['flags']['irregular_pulse'] ?? false);
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The kiosk visit these vitals belong to. */
    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }
}
