<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
        // D-66: computed at kiosk capture (HR) and at encode (RR).
        'is_hr_flagged',
        'is_rr_flagged',
        'bp_device_reading',
        // D-72: the PRE-REST numbers and their flags, kept when a re-check
        // overwrites the columns above. NULL on a visit that never rested.
        'first_reading',
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
            'is_hr_flagged' => 'boolean',
            'is_rr_flagged' => 'boolean',
            // D-58: the Bluetooth BP monitor's record of the reading, or null.
            'bp_device_reading' => 'array',
            // D-72. Same array cast: written once at re-check, only ever read.
            'first_reading' => 'array',
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

    // ── Flag rules (BR-13/BR-14) ─────────────────────────────────────────────
    // Thresholds live in config/healthpass.php and NOWHERE else. These static
    // helpers are the one implementation of each rule: the kiosk submit, the
    // clinic's encode and the demo seeders all call them, so a threshold change
    // can never leave one screen disagreeing with another.

    /** D-66: > heart_rate_max bpm → "High Heart Rate". No low-HR flag. */
    public static function isHeartRateFlagged(?int $bpm): bool
    {
        if ($bpm === null) {
            return false;
        }

        return $bpm > (int) config('healthpass.thresholds.heart_rate_max');
    }

    /**
     * D-66: outside respiratory_rate_min … respiratory_rate_max breaths/min →
     * "Abnormal Respiratory Rate". Null (not yet typed at encode, D-65) is not
     * a flag — it is simply not measured yet.
     */
    public static function isRespiratoryRateFlagged(?int $rate): bool
    {
        if ($rate === null) {
            return false;
        }

        $thresholds = config('healthpass.thresholds');

        return $rate < (int) $thresholds['respiratory_rate_min']
            || $rate > (int) $thresholds['respiratory_rate_max'];
    }

    // ── Rest & re-check (D-72) ───────────────────────────────────────────────

    /**
     * The re-check step keys for a reading, in the order the kiosk walks them.
     *
     *   'temp' — the temperature step, when the temperature is flagged;
     *   'bp'   — the blood-pressure step, when EITHER the blood pressure or the
     *            heart rate is flagged, because one cuff measurement produces
     *            both, so they are always re-taken together.
     *
     * BMI never appears: resting does not change a height or a weight.
     * An empty list means nothing needs re-taking, which is what makes the
     * /kiosk/rest endpoint refuse the request (D-72 decision 2).
     *
     * @param  array{is_temp_flagged: bool, is_bp_flagged: bool, is_hr_flagged: bool}  $flags
     * @return list<string>
     */
    public static function recheckStepsFor(array $flags): array
    {
        $steps = [];

        if ($flags['is_bp_flagged'] || $flags['is_hr_flagged']) {
            $steps[] = 'bp';
        }

        if ($flags['is_temp_flagged']) {
            $steps[] = 'temp';
        }

        return $steps;
    }

    /** This stored reading's re-check steps (D-72) — see recheckStepsFor(). */
    public function recheckSteps(): array
    {
        return self::recheckStepsFor([
            'is_temp_flagged' => (bool) $this->is_temp_flagged,
            'is_bp_flagged' => (bool) $this->is_bp_flagged,
            'is_hr_flagged' => (bool) $this->is_hr_flagged,
        ]);
    }

    /**
     * D-72 — the one-line summary of the pre-rest reading the encode page shows
     * under the Vital Signs card, e.g. "150/95 mmHg · 37.8 °C". Only the values
     * that were actually FLAGGED are named: those are the ones that were
     * re-taken, and the rest never changed. Null when there was no re-check.
     */
    public function firstReadingSummary(): ?string
    {
        $first = $this->first_reading;

        if (! is_array($first) || $first === []) {
            return null;
        }

        $parts = [];

        if ($first['is_bp_flagged'] ?? false) {
            $parts[] = "{$first['bp_systolic']}/{$first['bp_diastolic']} mmHg";
        }

        if ($first['is_hr_flagged'] ?? false) {
            $parts[] = "{$first['heart_rate_bpm']} bpm";
        }

        if ($first['is_temp_flagged'] ?? false) {
            $parts[] = "{$first['temperature_c']} °C";
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * D-72 — when the pre-rest reading was taken, as "9:05 AM", or null when
     * there was no re-check (or the stored timestamp is missing on a
     * hand-seeded row). Parsed here so the encode view stays free of date code.
     */
    public function firstReadingTakenAtLabel(): ?string
    {
        $takenAt = $this->first_reading['taken_at'] ?? null;

        return $takenAt === null ? null : Carbon::parse($takenAt)->format('g:i A');
    }

    // ── Display helpers ──────────────────────────────────────────────────────

    /**
     * One ['label' => …, 'value' => …] pair per tripped flag — the Flagged
     * Anomalies table (FR-ANL-05) renders label and value in separate
     * columns. Reads the STORED flag booleans — the thresholds are applied
     * once when the reading is written (capture, or encode for the
     * respiratory rate), never here.
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

        // D-66. Heart rate is known from capture; respiratory rate only after
        // the clinic types it at encode, so an un-encoded visit shows neither
        // an RR value nor an RR flag.
        if ($this->is_hr_flagged) {
            $flags[] = ['label' => 'High Heart Rate', 'value' => "{$this->heart_rate_bpm} bpm"];
        }

        if ($this->is_rr_flagged) {
            $flags[] = ['label' => 'Abnormal Respiratory Rate', 'value' => "{$this->respiratory_rate} breaths/min"];
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
