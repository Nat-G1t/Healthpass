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

    /**
     * "V. MENSTRUAL HISTORY" — the numeric boxes and the plausibility range
     * each one accepts, `key => [min, max]`. Every field is optional; a value
     * that IS given has to be possible. One list serves the number inputs'
     * min/max attributes, the validation and (from D-71) the print.
     *
     * @var array<string, array{int, int}>
     */
    public const MENSTRUAL_RANGES = [
        'menarche_age' => [5, 25],
        'first_intercourse_age' => [8, 60],
        'period_days' => [1, 15],
        'pads_per_day' => [0, 20],
        'cycle_days' => [10, 90],
        'menopause_age' => [30, 70],
    ];

    /**
     * "VI. OB/PREGNANCY HISTORY" — the GPTPAL counts, all 0–20.
     * `term`, `preterm`, `abortion` and `living` are the paper's T, P, A, L.
     *
     * @var array<string, array{int, int}>
     */
    public const OB_RANGES = [
        'gravida' => [0, 20],
        'para' => [0, 20],
        'term' => [0, 20],
        'preterm' => [0, 20],
        'abortion' => [0, 20],
        'living' => [0, 20],
    ];

    /**
     * "PERTINENT PHYSICAL EXAMINATION" — the eight groups A–H of the back
     * page, each a checkbox list plus its own "Others: ____" line. Group key
     * => the printed heading and the group's findings (key => label, verbatim
     * from the paper).
     *
     * "Essentially Normal" is NOT exclusive with the findings below it: the
     * paper lets the examiner tick both, and inventing a rule the form does
     * not have would lose what the clinician meant.
     *
     * @var array<string, array{label: string, findings: array<string, string>}>
     */
    public const PHYSICAL_EXAM = [
        'heent' => [
            'label' => 'A. HEENT',
            'findings' => [
                'normal' => 'Essentially Normal',
                'abnormal_pupillary_reaction' => 'Abnormal Pupillary Reaction',
                'cervical_lymphadenopathy' => 'Cervical Lymphadenopathy',
                'dry_mucus_membrane' => 'Dry Mucus Membrane',
                'pale_mucosa' => 'Pale Mucosa / Conjunctiva',
                'sunken_eyeball' => 'Sunken Eyeball / Fontanelle',
            ],
        ],
        'chest' => [
            'label' => 'B. CHEST / BREAST / LUNGS',
            'findings' => [
                'normal' => 'Essentially Normal',
                'asymmetrical_expansion' => 'Asymmetrical Chest Expansion',
                'decreased_breath_sounds' => 'Decreased Breath Sounds',
                'wheezes_crackles_rales' => 'Wheezes / Crackles / Rales',
                'breast_lumps' => 'Lumps over Breast Tissue',
                'intercostal_retractions' => 'Intercostal Retractions',
            ],
        ],
        'cardiovascular' => [
            'label' => 'C. CARDIOVASCULAR SYSTEM',
            'findings' => [
                'normal' => 'Essentially Normal',
                'apex_beat_displacement' => 'Displacement of Apex Beat',
                'heaves_thrills_murmurs' => 'Heaves / Thrills / Murmurs',
                'irregular_rhythm' => 'Irregular Rhythm / Tachycardia',
                'muffled_heart_sounds' => 'Muffled Heart Sounds',
                'pericardial_bulge' => 'Pericardial Bulge',
            ],
        ],
        'abdominal' => [
            'label' => 'D. ABDOMINAL REGION',
            'findings' => [
                'normal' => 'Essentially Normal',
                'rigidity_tenderness' => 'Abdominal Rigidity / Tenderness',
                'hyperactive_bowel_sounds' => 'Hyperactive Bowel Sounds',
                'palpable_masses' => 'Palpable Masses / Organomegaly',
                'tympanitic_dull_abdomen' => 'Tympanitic Dull Abdomen',
                'uterine_contractions' => 'Visible Uterine Contractions',
            ],
        ],
        'genitourinary' => [
            'label' => 'E. GENITOURINARY SYSTEM',
            'findings' => [
                'normal' => 'Essentially Normal',
                'blood_stained_internal_exam' => 'Blood Stained Internal Exam',
                'cervical_dilation' => 'Cervical Dilation Observed',
                'abnormal_discharge' => 'Presence of Abnormal Discharge',
            ],
        ],
        'dre' => [
            'label' => 'F. DIGITAL RECTAL EXAMINATION (DRE)',
            'findings' => [
                'normal' => 'Essentially Normal',
                'enlarged_prostate' => 'Enlarged Prostate Gland',
                'palpable_mass_stricture' => 'Palpable Mass / Stricture',
                'hemorrhoids' => 'Hemorrhoids (Internal/External)',
                'pus_or_blood' => 'Presence of PUS / Blood',
                'not_applicable' => 'Not Applicable',
            ],
        ],
        'skin' => [
            'label' => 'G. SKIN & EXTREMITIES',
            'findings' => [
                'normal' => 'Essentially Normal',
                'digital_clubbing' => 'Digital Clubbing / Cyanosis',
                'cold_clammy_skin' => 'Cold Clammy Skin / Mottling',
                'edema' => 'Edema / Severe Swelling',
                'decreased_mobility' => 'Decreased Range of Mobility',
                'pale_nailbeds' => 'Pale Nailbeds / Weak Pulses',
            ],
        ],
        'neurological' => [
            'label' => 'H. NEUROLOGICAL EXAMINATION',
            'findings' => [
                'normal' => 'Essentially Normal',
                'abnormal_gait' => 'Abnormal Gait / Poor Coordination',
                'abnormal_sensation' => 'Abnormal Motion Sense / Sensation',
                'abnormal_reflexes' => 'Abnormal Reflexes',
                'poor_muscle_tone' => 'Poor Muscle Tone / Strength',
            ],
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

    /**
     * The eight Pertinent Physical Examination group keys, in the paper's
     * order — the allow-list the save filters a posted `physical_exam` with.
     *
     * @return list<string>
     */
    public static function physicalExamGroups(): array
    {
        return array_keys(self::PHYSICAL_EXAM);
    }

    /**
     * The finding keys one exam group allows. A posted key outside its own
     * group is meaningless ("enlarged_prostate" under HEENT) and is dropped.
     *
     * @return list<string>
     */
    public static function findingKeys(string $group): array
    {
        return array_keys(self::PHYSICAL_EXAM[$group]['findings'] ?? []);
    }

    protected $fillable = [
        'clearance_record_id',
        'medical_history',
        'immunizations',
        'family_planning_access',
        'surgical_history',
        // D-70 — sections V, VI and the Pertinent Physical Examination.
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

    /** True when this exam group recorded the given finding (D-70). */
    public function hasFinding(string $group, string $key): bool
    {
        // data_get() copes with physical_exam being NULL, which it is on every
        // row encoded before D-70.
        return in_array($key, (array) data_get($this->physical_exam, "{$group}.findings", []), true);
    }

    /** The "Others" text typed under an exam group, or null. */
    public function findingOthers(string $group): ?string
    {
        return data_get($this->physical_exam, "{$group}.others");
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** The encoded clearance record these sections belong to. */
    public function clearanceRecord(): BelongsTo
    {
        return $this->belongsTo(ClearanceRecord::class);
    }
}
