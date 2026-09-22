<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BatchRequest;
use App\Models\ClearanceRecord;
use App\Models\ClinicVisit;

/**
 * Module PRT / D-67 — everything the Medical Clearance document prints, built
 * once and handed to the ONE template (`resources/views/forms/`) that both the
 * browser print and the dompdf PDF render.
 *
 * Why a class and not view logic: four callers render this same document —
 * the nurse's print, the pre-save preview, the clinic's Save as PDF and
 * (prompt 14) the student's download. If each assembled its own view data the
 * four could quietly disagree about an age, a purpose or a physician name, and
 * a clearance is an official record. One builder makes that impossible.
 *
 * A "support class" is just a plain PHP class under `app/Support` — no Laravel
 * base class, no magic. It is called statically because it holds no state.
 */
final class ClearanceDocument
{
    /** The revision this template reproduces (D-67, replaces …-R03). */
    public const FORM_CODE = 'PSU-QSP-OSS-004-FO002-R04';

    /**
     * REMARKS sits on TWO ruled lines that must never move (FR-PRT-05 — the
     * document is one page, always). dompdf runs no JavaScript, so the old
     * shrink-to-fit script is replaced by this server-side rule: wrap the note
     * at the width the current size fits, step the size down by half a point
     * until it takes two lines or fewer, and clip whatever still does not fit
     * at the 7pt floor. Both renderers are handed the SAME two lines and the
     * SAME size, so print and PDF cannot differ.
     */
    private const REMARKS_MAX_PT = 10.0;

    private const REMARKS_MIN_PT = 7.0;

    private const REMARKS_LINES = 2;

    /** Characters that fit one ruled line at REMARKS_MAX_PT (Times, ~4.9in). */
    private const REMARKS_CHARS_PER_LINE = 70;

    /**
     * Every value the Medical Clearance template prints, for a visit and its
     * clearance record.
     *
     * $preview substitutes a transient, never-saved record for the visit's own
     * (the nurse's Preview & Print before Save & Close), so the preview goes
     * down exactly this path.
     *
     * @return array<string, mixed>
     */
    public static function for(ClinicVisit $visit, ?ClearanceRecord $preview = null): array
    {
        $visit->load([
            'student.studentProfile',
            'appointment.batchRequest',  // D-62: the form type's purpose list
            'vitalSigns',
            'screeningResponse',         // pregnancy / LMP (D-22)
            'clearanceRecord',
        ]);

        if ($preview !== null) {
            $visit->setRelation('clearanceRecord', $preview);
        }

        $record = $visit->clearanceRecord;
        $profile = $visit->student?->studentProfile;
        $screening = $visit->screeningResponse;

        // The encode date — today on a pre-save preview, whose transient
        // record is stamped with now(). Never an input on the form.
        $issuedOn = $record->encoded_at ?? now();

        $vitals = $record->printedVitals($visit->vitalSigns);

        return [
            'visit' => $visit,
            'formCode' => self::FORM_CODE,
            'logos' => [
                'seal' => self::logo('pamsu-seal'),
                'bagong' => self::logo('bagong-pilipinas'),
                'oswf' => self::logo('oswf'),
            ],

            // ── Identity (FR-PRT-02; no student number, no college — D-22/D-25)
            'surname' => (string) ($profile?->last_name ?? ''),
            'firstName' => (string) ($profile?->first_name ?? ''),
            'middleName' => (string) ($profile?->middle_name ?? ''),
            'courseYearSection' => trim(implode(', ', array_filter([
                $profile?->course,
                $profile?->year_level,
            ]))),
            'address' => (string) ($profile?->address ?? ''),
            // Age AT the encode date, not today: a reprint years later must
            // still show the age the clearance was issued at.
            'age' => $profile?->date_of_birth
                ? (int) $profile->date_of_birth->diffInYears($issuedOn)
                : null,
            'sex' => $profile?->sex,
            // Only Single and Married have a bubble on the form; any other
            // civil status leaves both blank rather than guessing.
            'isSingle' => strcasecmp((string) $profile?->civil_status, 'Single') === 0,
            'isMarried' => strcasecmp((string) $profile?->civil_status, 'Married') === 0,
            'dateOfBirth' => $profile?->date_of_birth?->format('F j, Y') ?? '',
            'placeOfBirth' => (string) ($profile?->place_of_birth ?? ''),

            // ── Vitals — the clinic's confirmed copy (D-65), in the three
            // column pairs the R04 form lays them out in. R04 has no BMI box.
            'vitals' => [
                'height' => self::vital($vitals, 'height_cm', 'cm'),
                'weight' => self::vital($vitals, 'weight_kg', 'kg'),
                'heartRate' => self::vital($vitals, 'heart_rate_bpm', 'bpm'),
                'bloodPressure' => isset($vitals['bp_systolic'], $vitals['bp_diastolic'])
                    ? self::vital($vitals, 'bp_systolic').'/'.self::vital($vitals, 'bp_diastolic').' mmHg'
                    : '',
                'temperature' => self::vital($vitals, 'temperature_c', '°C'),
                'respiratoryRate' => self::vital($vitals, 'respiratory_rate', 'breaths/min'),
            ],

            // ── Physical Signs Disorder of (D-22/D-63) — the twelve rows as
            // three column groups of four, read DOWN each group like the form.
            'signColumns' => self::signColumns($record),

            // ── REMARKS — Clinic Notes, pre-fitted to the two ruled lines.
            'remarks' => self::remarks($record->nurse_notes),

            // ── Pregnancy / LMP, straight from the kiosk questionnaire (D-22)
            'isPregnant' => $screening?->is_pregnant,
            'lastMenstrualPeriod' => $screening?->is_pregnant
                ? ($screening->last_menstrual_period?->format('F j, Y') ?? '')
                : '',

            // ── Fitness + purpose (D-62: the visit's form type's purposes)
            'result' => $record->result,
            ...self::purposes($visit->formType(), $record),

            // ── Physician block (FR-PRT-04 / D-64) — name and licence print
            // only on a record a physician encoded.
            'physicianName' => $record->physician_name,
            'physicianLicense' => $record->physician_license_no,

            'issuedOn' => $issuedOn->format('F j, Y'),
        ];
    }

    /**
     * The same keys as for(), with nothing a visit supplies — the paper as it
     * comes off the shelf. Used by the College Admin's New Batch Request tiles
     * to show the real form rather than a photograph of it.
     *
     * Everything printed ON the paper stays (letterhead, logos, form code, the
     * twelve sign rows, the purposes list); every name, vital, tick, signature
     * and date is empty. $formType picks whose purposes print, because the
     * Medical Assessment Form reuses this shared half (AssessmentDocument).
     *
     * @return array<string, mixed>
     */
    public static function blank(string $formType = 'clearance'): array
    {
        // An unsaved, empty record: every ps_* column and the purpose are
        // NULL, so the same helpers for() uses leave every box unticked.
        $record = new ClearanceRecord;

        return [
            // Unsaved and empty — the template only reads its reference_no.
            'visit' => new ClinicVisit,
            'formCode' => self::FORM_CODE,
            'logos' => [
                'seal' => self::logo('pamsu-seal'),
                'bagong' => self::logo('bagong-pilipinas'),
                'oswf' => self::logo('oswf'),
            ],

            'surname' => '',
            'firstName' => '',
            'middleName' => '',
            'courseYearSection' => '',
            'address' => '',
            'age' => null,
            'sex' => null,
            'isSingle' => false,
            'isMarried' => false,
            'dateOfBirth' => '',
            'placeOfBirth' => '',

            'vitals' => [
                'height' => '',
                'weight' => '',
                'heartRate' => '',
                'bloodPressure' => '',
                'temperature' => '',
                'respiratoryRate' => '',
            ],

            'signColumns' => self::signColumns($record),
            'remarks' => self::remarks(null),

            'isPregnant' => null,
            'lastMenstrualPeriod' => '',

            'result' => null,
            ...self::purposes($formType, $record),

            'physicianName' => null,
            'physicianLicense' => null,

            'issuedOn' => '',
        ];
    }

    /**
     * One vital as the form prints it. The three vitals stored with a decimal
     * keep it — JSON hands back a bare 37 where the form wants 37.0.
     *
     * @param  array<string, int|float|string|null>  $vitals
     */
    private static function vital(array $vitals, string $key, string $unit = ''): string
    {
        if (! isset($vitals[$key])) {
            return '';
        }

        $decimals = (ClearanceRecord::ENCODED_VITALS[$key]['step'] ?? '1') === '0.1' ? 1 : 0;

        return trim(number_format((float) $vitals[$key], $decimals).' '.$unit);
    }

    /**
     * The twelve exam rows chunked into the form's three column groups. An
     * unanswered row (NULL — not examined) leaves BOTH boxes blank.
     *
     * @return list<list<array{label: string, yes: bool, no: bool}>>
     */
    private static function signColumns(ClearanceRecord $record): array
    {
        $rows = [];

        foreach (ClearanceRecord::PHYSICAL_SIGNS as $column => $label) {
            $rows[] = [
                'label' => $label,
                'yes' => $record->{$column} === true,
                'no' => $record->{$column} === false,
            ];
        }

        return array_chunk($rows, 4);
    }

    /**
     * The Clinic Notes fitted to the form's two ruled lines: the exact lines
     * to print and the point size to print them at. See REMARKS_MAX_PT.
     *
     * @return array{lines: list<string>, fontSize: float}
     */
    public static function remarks(?string $notes): array
    {
        // The notes may arrive with newlines (the YES-detail pre-fill writes
        // one line per sign). The form has ruled lines, not a textarea — flow
        // it as one paragraph so the space is not wasted.
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $notes));

        if ($text === '') {
            return ['lines' => array_fill(0, self::REMARKS_LINES, ''), 'fontSize' => self::REMARKS_MAX_PT];
        }

        for ($size = self::REMARKS_MAX_PT; $size > self::REMARKS_MIN_PT; $size -= 0.5) {
            $lines = self::wrap($text, $size);

            if (count($lines) <= self::REMARKS_LINES) {
                return ['lines' => array_pad($lines, self::REMARKS_LINES, ''), 'fontSize' => $size];
            }
        }

        // At the floor the note is simply longer than the form's two lines:
        // clip it. Growing onto a second page is not an option (FR-PRT-05).
        return [
            'lines' => array_pad(
                array_slice(self::wrap($text, self::REMARKS_MIN_PT), 0, self::REMARKS_LINES),
                self::REMARKS_LINES,
                ''
            ),
            'fontSize' => self::REMARKS_MIN_PT,
        ];
    }

    /** Break the note at the characters one ruled line holds at $fontSize. */
    private static function wrap(string $text, float $fontSize): array
    {
        $charsPerLine = (int) floor(self::REMARKS_CHARS_PER_LINE * self::REMARKS_MAX_PT / $fontSize);

        return explode("\n", wordwrap($text, $charsPerLine, "\n", true));
    }

    /**
     * The fitness line's purpose options (D-62): the visit's FORM TYPE's
     * reason labels, with the saved one shaded. "Others, Specify" is its own
     * entry because it prints the admin's text on a line of its own.
     *
     * @return array{purposes: list<array{label: string, checked: bool}>, othersLabel: string, othersChecked: bool, othersText: string}
     */
    private static function purposes(string $formType, ClearanceRecord $record): array
    {
        $labels = BatchRequest::REASONS_BY_FORM[$formType];
        $othersLabel = $labels[BatchRequest::REASON_OTHERS];
        $isOthers = $record->purpose === $othersLabel;

        $purposes = [];

        foreach ($labels as $key => $label) {
            if ($key === BatchRequest::REASON_OTHERS) {
                continue;
            }

            $purposes[] = ['label' => $label, 'checked' => $record->purpose === $label];
        }

        return [
            'purposes' => $purposes,
            'othersLabel' => $othersLabel,
            'othersChecked' => $isOthers,
            'othersText' => $isOthers ? (string) $record->purpose_other : '',
        ];
    }

    /**
     * A letterhead logo as a base64 `data:` URI.
     *
     * Why not a plain <img src="/images/…">: dompdf would have to fetch that
     * URL over HTTP to draw it, which needs remote access turned on and fails
     * on a box that cannot reach its own domain. A data URI carries the bytes
     * inside the document, so the browser print and the PDF draw the same
     * picture with no network at all.
     *
     * The files under `images/form/print/` are print-sized copies of the
     * originals beside them — the archival Bagong Pilipinas is 3840px wide and
     * would add ~4MB of base64 to every clearance.
     */
    private static function logo(string $name): string
    {
        static $cache = [];

        return $cache[$name] ??= 'data:image/png;base64,'.base64_encode(
            (string) file_get_contents(public_path("images/form/print/{$name}.png"))
        );
    }
}
