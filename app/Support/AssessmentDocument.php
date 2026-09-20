<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;
use App\Models\MedicalAssessment;
use App\Models\ScreeningResponse;

/**
 * Module PRT / D-71 — everything the Medical Assessment Form
 * (PSU-QSP-OSS-004-FO010-R00) prints, built once and handed to the ONE
 * template `resources/views/forms/medical-assessment.blade.php`, which both
 * the browser print and the dompdf PDF render.
 *
 * Same idea as App\Support\ClearanceDocument (D-67), and it REUSES it: the
 * letterhead, the identity block, the pregnancy answer, the purposes and the
 * physician block are identical on both papers, so this class calls
 * ClearanceDocument::for() for those and adds only what the Assessment Form
 * has of its own — the eighteen-row history table, the immunization profile,
 * family planning, sections IV–VI, the surgical history and the Pertinent
 * Physical Examination.
 *
 * Kept in its own file rather than as more methods on ClearanceDocument so
 * neither grows past a size Nat and Baldo can read in one sitting; the shared
 * values still have exactly ONE definition, which is what D-71 asked for.
 */
final class AssessmentDocument
{
    /** The revision this template reproduces (D-71). */
    public const FORM_CODE = 'PSU-QSP-OSS-004-FO010-R00';

    /** The two sides the HTML print buttons may ask for; null = both pages. */
    public const SIDES = ['front', 'back'];

    /**
     * Every value the Medical Assessment template prints.
     *
     * $preview / $previewSections substitute the transient, never-saved rows
     * that the pre-save Print front / Print back builds from the encode form,
     * so the preview goes down exactly this path.
     *
     * @param  ?string  $side  'front' | 'back' | null (both pages)
     * @return array<string, mixed>
     */
    public static function for(
        ClinicVisit $visit,
        ?ClearanceRecord $preview = null,
        ?MedicalAssessment $previewSections = null,
        ?string $side = null,
    ): array {
        // The shared half of the document (D-67): letterhead, identity,
        // pregnancy, purposes, physician block, encode date.
        $shared = ClearanceDocument::for($visit, $preview);

        $record = $visit->clearanceRecord;
        $sections = $previewSections ?? $record?->medicalAssessment;
        $screening = $visit->screeningResponse;
        $vitals = $record->printedVitals($visit->vitalSigns);

        // Who encoded it — "Interviewed/Assessed by" on the back page. On a
        // preview the controller has already set this relation to the
        // signed-in user, so loadMissing() leaves it alone.
        $record->loadMissing('encoder');

        return [
            ...$shared,
            'formCode' => self::FORM_CODE,
            'side' => in_array($side, self::SIDES, true) ? $side : null,

            // ── Front page ──────────────────────────────────────────────────
            // The STUDENT's own answers (D-63), not the clinic's ps_* exam:
            // this table is headed "(Self Assessment)" and the student signs
            // under it.
            'selfSignColumns' => self::selfSignColumns($screening),
            'conditions' => self::conditions($sections),

            // ── Back page ───────────────────────────────────────────────────
            'socialRows' => $screening?->socialHistoryRows() ?? self::blankSocialRows(),
            'immunizationGroups' => self::immunizationGroups($sections),
            'immunizationOthers' => (string) ($sections?->immunizations['others'] ?? ''),
            'familyPlanningAccess' => $sections?->family_planning_access,
            'exam' => self::exam($vitals),
            'menstrual' => self::menstrual($sections),
            'ob' => self::ob($sections),
            'surgical' => [
                'procedures' => (string) ($sections?->surgical_history['procedures'] ?? ''),
                'dateDone' => (string) ($sections?->surgical_history['date_done'] ?? ''),
            ],
            'examColumns' => self::examColumns($sections),
            'encoderName' => (string) ($record->encoder?->name ?? ''),
        ];
    }

    /**
     * The twelve "Physical Signs Disorder of: (Self Assessment)" rows as the
     * form's three column groups of four, read DOWN each group. An unanswered
     * row leaves BOTH boxes blank.
     *
     * @return list<list<array{label: string, yes: bool, no: bool}>>
     */
    private static function selfSignColumns(?ScreeningResponse $screening): array
    {
        $rows = [];

        foreach (ScreeningResponse::QUESTIONS as $key => $question) {
            $answer = $screening?->{$key};

            $rows[] = [
                'label' => $question['label'],
                'yes' => $answer === true,
                'no' => $answer === false,
            ];
        }

        return array_chunk($rows, 4);
    }

    /**
     * "PAST MEDICAL HISTORY & FAMILY HISTORY" — the eighteen rows with the
     * typed specify text printed INSIDE the row's own blank, as the paper
     * reads it: "Allergy (Specify: seafood)".
     *
     * @return list<array{label: string, patient: bool, family: bool}>
     */
    private static function conditions(?MedicalAssessment $sections): array
    {
        $rows = [];

        foreach (MedicalAssessment::CONDITIONS as $key => $condition) {
            $rows[] = [
                'label' => self::conditionLabel($condition, $sections?->specifyFor($key)),
                'patient' => (bool) $sections?->hasCondition('patient', $key),
                'family' => (bool) $sections?->hasCondition('family', $key),
            ];
        }

        return $rows;
    }

    /**
     * One history row's printed label. The blank is filled in place wherever
     * the paper has one, and stays an empty rule when nothing was typed.
     *
     * @param  array{label: string, specify: ?string}  $condition
     */
    private static function conditionLabel(array $condition, ?string $text): string
    {
        $label = $condition['label'];

        if ($condition['specify'] === null) {
            return $label;
        }

        // Underscores so an unfilled row still looks like the paper.
        $value = filled($text) ? (string) $text : '__________';

        // "Hypertension (Highest BP: ___ mmHg)" — the blank sits mid-label.
        if (str_contains($label, '___')) {
            return str_replace('___', $value, $label);
        }

        // "Allergy (Specify)" → "Allergy (Specify: seafood)".
        if (str_ends_with($label, '(Specify)')) {
            return substr($label, 0, -1).': '.$value.')';
        }

        // "Others" → "Others: sinusitis".
        return $label.': '.$value;
    }

    /**
     * Section I with nothing answered — a visit captured before D-68 has no
     * social history at all. The rows still PRINT, blank, because the paper
     * has them.
     *
     * @return list<array{label: string, answer: ?string}>
     */
    private static function blankSocialRows(): array
    {
        $rows = array_map(
            fn (string $label): array => ['label' => $label, 'answer' => null],
            array_values(ScreeningResponse::SOCIAL_HISTORY),
        );

        $rows[] = ['label' => ScreeningResponse::SEXUALLY_ACTIVE_LABEL, 'answer' => null];

        return $rows;
    }

    /**
     * A vaccine label longer than this cannot share a quarter-width cell with
     * three others — "Pneumococcal Vaccine" is the one that cannot.
     */
    private const IMMUNIZATION_SHORT_LABEL = 12;

    /**
     * "II. IMMUNIZATION PROFILE" — the paper's groups, each vaccine with
     * whether it was ticked, plus how many boxes that group fits on a row.
     *
     * The column count is here and not in the template because the template
     * must give the cells a FIXED width: dompdf will not break a line between
     * inline-block boxes, so a free-flowing list would widen the whole column.
     * A group whose longest label is long gets two columns instead of four.
     *
     * @return array<string, array{columns: int, vaccines: list<array{label: string, given: bool}>}>
     */
    private static function immunizationGroups(?MedicalAssessment $sections): array
    {
        $groups = [];

        foreach (MedicalAssessment::IMMUNIZATIONS as $heading => $vaccines) {
            $rows = [];

            foreach ($vaccines as $key => $label) {
                $rows[] = [
                    'label' => $label,
                    'given' => (bool) $sections?->hasImmunization($key),
                ];
            }

            $longest = max(array_map(static fn (array $v): int => mb_strlen($v['label']), $rows));

            $groups[$heading] = [
                'columns' => $longest > self::IMMUNIZATION_SHORT_LABEL ? 2 : 4,
                'vaccines' => $rows,
            ];
        }

        return $groups;
    }

    /**
     * "IV. PERTINENT PHYSICAL EXAM" — the clinic's confirmed vitals (D-65) in
     * the units THIS paper asks for. Height is the one that differs from the
     * Medical Clearance: metres, not centimetres.
     *
     * @param  array<string, int|float|string|null>  $vitals
     * @return array<string, string>
     */
    private static function exam(array $vitals): array
    {
        return [
            'heightM' => isset($vitals['height_cm'])
                ? number_format((float) $vitals['height_cm'] / 100, 2)
                : '',
            'weightKg' => self::number($vitals, 'weight_kg', 1),
            'bloodPressure' => isset($vitals['bp_systolic'], $vitals['bp_diastolic'])
                ? self::number($vitals, 'bp_systolic').'/'.self::number($vitals, 'bp_diastolic')
                : '',
            'temperatureC' => self::number($vitals, 'temperature_c', 1),
            'heartRate' => self::number($vitals, 'heart_rate_bpm'),
            'respiratoryRate' => self::number($vitals, 'respiratory_rate'),
        ];
    }

    /**
     * "V. MENSTRUAL HISTORY" — every box as the string it prints. A male
     * student's row is NULL in the database and prints BLANK, never "N/A":
     * the paper simply leaves the section empty for him.
     *
     * @return array<string, mixed>
     */
    private static function menstrual(?MedicalAssessment $sections): array
    {
        $history = $sections?->menstrual_history ?? [];

        return [
            'menarcheAge' => self::text($history, 'menarche_age'),
            'firstIntercourseAge' => self::text($history, 'first_intercourse_age'),
            'lmp' => self::date($history, 'lmp'),
            'periodDays' => self::text($history, 'period_days'),
            'padsPerDay' => self::text($history, 'pads_per_day'),
            'cycleDays' => self::text($history, 'cycle_days'),
            'contraceptive' => self::text($history, 'contraceptive'),
            'menopauseAge' => self::text($history, 'menopause_age'),
            // A real nullable boolean: the template ticks Yes, No or neither,
            // and "neither" is what an unanswered box means.
            'menopause' => $history['menopause'] ?? null,
        ];
    }

    /**
     * "VI. OB/PREGNANCY HISTORY" — the GPTPAL counts and the two lines under
     * them. Blank for a male student, exactly like section V.
     *
     * @return array<string, mixed>
     */
    private static function ob(?MedicalAssessment $sections): array
    {
        $history = $sections?->ob_history ?? [];

        $counts = [];

        foreach (array_keys(MedicalAssessment::OB_RANGES) as $field) {
            $counts[$field] = self::text($history, $field);
        }

        return [
            'counts' => $counts,
            'deliveryType' => self::text($history, 'delivery_type'),
            'pih' => $history['pih'] ?? null,
        ];
    }

    /**
     * "PERTINENT PHYSICAL EXAMINATION" — the eight groups A–H laid out in the
     * paper's THREE columns (A–C | D–F | G–H).
     *
     * @return list<list<array{label: string, findings: list<array{label: string, on: bool}>, others: string}>>
     */
    private static function examColumns(?MedicalAssessment $sections): array
    {
        $groups = [];

        foreach (MedicalAssessment::PHYSICAL_EXAM as $key => $group) {
            $findings = [];

            foreach ($group['findings'] as $finding => $label) {
                $findings[] = [
                    'label' => $label,
                    'on' => (bool) $sections?->hasFinding($key, $finding),
                ];
            }

            $groups[] = [
                'label' => $group['label'],
                'findings' => $findings,
                'others' => (string) ($sections?->findingOthers($key) ?? ''),
            ];
        }

        return array_chunk($groups, 3);
    }

    /**
     * A stored value as printable text; an unanswered box prints empty.
     *
     * @param  array<string, mixed>  $values
     */
    private static function text(array $values, string $key): string
    {
        return isset($values[$key]) ? (string) $values[$key] : '';
    }

    /**
     * A stored Y-m-d as the form writes dates, or empty.
     *
     * @param  array<string, mixed>  $values
     */
    private static function date(array $values, string $key): string
    {
        return filled($values[$key] ?? null)
            ? date('F j, Y', (int) strtotime((string) $values[$key]))
            : '';
    }

    /**
     * A vital as a number with the decimals this form prints it at.
     *
     * @param  array<string, int|float|string|null>  $vitals
     */
    private static function number(array $vitals, string $key, int $decimals = 0): string
    {
        return isset($vitals[$key]) ? number_format((float) $vitals[$key], $decimals) : '';
    }
}
