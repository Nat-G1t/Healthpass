<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * D-69 — the Medical Assessment Form's own sections, one row per Assessment
 * encode (`clearance_records` 1:0..1). A Medical Clearance encode creates no
 * row: that paper has none of these sections.
 *
 * The lists below are the form's labels VERBATIM (PSU-QSP-OSS-004-FO010-R00).
 * They are constants so the encode screen, the validation and — from prompt 12
 * — the printed document all read the SAME list and cannot drift apart.
 */
class MedicalAssessment extends Model
{
    use HasFactory;

    /**
     * "PAST MEDICAL HISTORY & FAMILY HISTORY" — the eighteen rows of the front
     * page's table, in the paper's order. `specify` is the placeholder for the
     * row's free-text box, or NULL when the row has none.
     *
     * The key is what the JSON stores; the label is what prints.
     *
     * @var array<string, array{label: string, specify: ?string}>
     */
    public const CONDITIONS = [
        'allergy' => ['label' => 'Allergy (Specify)', 'specify' => 'Allergen'],
        'asthma' => ['label' => 'Asthma', 'specify' => null],
        'cancer' => ['label' => 'Cancer (Specify)', 'specify' => 'Type'],
        'stroke' => ['label' => 'Cerebrovascular Disease (Stroke)', 'specify' => null],
        'heart_disease' => ['label' => 'Coronary Artery Disease (Heart Disease)', 'specify' => null],
        'diabetes' => ['label' => 'Diabetes Mellitus', 'specify' => null],
        'copd' => ['label' => 'Emphysema / COPD', 'specify' => null],
        'epilepsy' => ['label' => 'Epilepsy / Seizure Disorder', 'specify' => null],
        'hepatitis' => ['label' => 'Hepatitis (Specify)', 'specify' => 'Type'],
        'hyperlipidemia' => ['label' => 'Hyperlipidemia (High Cholesterol)', 'specify' => null],
        'hypertension' => ['label' => 'Hypertension (Highest BP: ___ mmHg)', 'specify' => 'Highest BP'],
        'peptic_ulcer' => ['label' => 'Peptic Ulcer Disease', 'specify' => null],
        'pneumonia' => ['label' => 'Pneumonia', 'specify' => null],
        'thyroid' => ['label' => 'Thyroid Disease', 'specify' => null],
        'ptb' => ['label' => 'PTB (Pulmonary Tuberculosis - Extra PTB)', 'specify' => null],
        'uti' => ['label' => 'Urinary Tract Infection (Chronic/Recurrent)', 'specify' => null],
        'mental_illness' => ['label' => 'Mental Illnesses / Conditions', 'specify' => null],
        'others' => ['label' => 'Others', 'specify' => 'Specify'],
    ];

    /**
     * The two checkbox columns of that same table. Column key => printed
     * heading; the key is also what `medical_history` stores.
     *
     * @var array<string, string>
     */
    public const HISTORY_COLUMNS = [
        'patient' => 'Past Medical History (Patient)',
        'family' => 'Family History (Lineal)',
    ];

    /**
     * "II. IMMUNIZATION PROFILE" — the back page's four groups, group heading
     * => key => label. The two "None" boxes sit in different groups and mean
     * different things, so they are separate keys (`child_none`, `adult_none`).
     * The group's "Others: ____" line is free text, stored on its own.
     *
     * @var array<string, array<string, string>>
     */
    public const IMMUNIZATIONS = [
        'For Children' => [
            'bcg' => 'BCG',
            'opv1' => 'OPV1',
            'opv2' => 'OPV2',
            'opv3' => 'OPV3',
            'dpt1' => 'DPT1',
            'dpt2' => 'DPT2',
            'dpt3' => 'DPT3',
            'measles' => 'Measles',
            'hepb1' => 'HepB1',
            'hepb2' => 'HepB2',
            'hepb3' => 'HepB3',
            'varicella' => 'Varicella',
            'child_none' => 'None',
        ],
        'For Adults' => [
            'hpv' => 'HPV',
            'mmr' => 'MMR',
            'adult_none' => 'None',
        ],
        'For Elderly & Immunocompromised' => [
            'pneumococcal' => 'Pneumococcal Vaccine',
            'influenza' => 'Influenza',
        ],
    ];

    /** Longest text each free-text box on this form may hold. */
    public const SPECIFY_MAX_LENGTH = 60;

    public const IMMUNIZATION_OTHERS_MAX_LENGTH = 120;

    public const PROCEDURES_MAX_LENGTH = 200;

    /**
     * "Date Done" is free text, not a date: patients recall "2019" or "Grade
     * 5", and a date picker would force a precision nobody has.
     */
    public const DATE_DONE_MAX_LENGTH = 40;

    /**
     * Every condition key — the allow-list the validation and the save use, so
     * a forged checkbox value can never reach the JSON column.
     *
     * @return list<string>
     */
    public static function conditionKeys(): array
    {
        return array_keys(self::CONDITIONS);
    }

    /**
     * Only the condition rows that carry a free-text box. A specify text for
     * any other row is meaningless and is dropped.
     *
     * @return list<string>
     */
    public static function specifyKeys(): array
    {
        return array_keys(array_filter(
            self::CONDITIONS,
            fn (array $condition): bool => $condition['specify'] !== null,
        ));
    }

    /**
     * Every immunization key across the four groups, flattened.
     *
     * @return list<string>
     */
    public static function immunizationKeys(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::IMMUNIZATIONS)));
    }

    protected $fillable = [
        'clearance_record_id',
        'medical_history',
        'immunizations',
        'family_planning_access',
        'surgical_history',
        // Prompt 11 (D-70) fills these three.
        'menstrual_history',
        'ob_history',
        'physical_exam',
    ];

    /**
     * The `array` cast turns a JSON column into a PHP array when read and back
     * into JSON when saved, so nothing in the app has to call json_encode().
     */
    protected function casts(): array
    {
        return [
            'medical_history' => 'array',
            'immunizations' => 'array',
            // Nullable: NULL means the box was left unanswered.
            'family_planning_access' => 'boolean',
            'surgical_history' => 'array',
            'menstrual_history' => 'array',
            'ob_history' => 'array',
            'physical_exam' => 'array',
        ];
    }

    /**
     * True when this row's `medical_history` records the condition under the
     * given column — the one place the JSON shape is read, shared by the
     * encode screen and (from prompt 12) the print.
     */
    public function hasCondition(string $column, string $key): bool
    {
        return in_array($key, $this->medical_history[$column] ?? [], true);
    }

    /** The text typed beside a condition, or null. */
    public function specifyFor(string $key): ?string
    {
        return $this->medical_history['specify'][$key] ?? null;
    }

    /** True when the immunization was given. */
    public function hasImmunization(string $key): bool
    {
        return in_array($key, $this->immunizations['given'] ?? [], true);
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The encoded clearance record these sections belong to. */
    public function clearanceRecord(): BelongsTo
    {
        return $this->belongsTo(ClearanceRecord::class);
    }
}
