{{-- ── Printable Medical Clearance (Module PRT, FR-PRT-01..04 / BR-17) ─────────
     Field-for-field reproduction of official form DHVSU-QSP-OSS-004-FO002-R03
     "MEDICAL CLEARANCE" (scan: docs/forms/official form.png). Standalone HTML
     document — no app layout, no Vite bundle — because FR-NRS-05 loads it in
     an iframe and calls window.print() on it, so it must print clean alone.
     Layout fidelity to the scan beats prettiness (SM-3 sign-off is a
     side-by-side print comparison).

     What is POPULATED is the FR-PRT-02 list as amended July 9, 2026 (D-22):
     identity (NO Student No. — the official form has no such field), vitals,
     the Physical Signs YES/NO bubbles from the NURSE-ENCODED physician exam
     findings (clearance_records.ps_*; unanswered rows print blank), the
     pregnancy/LMP line from the kiosk questionnaire, the encoded Result,
     Purpose where set, encode date, and nurse notes under REMARKS. Prints
     BLANK on purpose:
       - Respiratory Rate — not a captured vital (FR-PRT-03 / D-6)
       - Case Category — the physician hand-writes case details under
         REMARKS, judging the shaded YES physical signs (D-22)

     Scan divergence, flagged: the official form has no BMI box, but
     FR-PRT-02 requires it — BMI is a third vitals-grid row. (College is NOT
     printed — D-25 removed the earlier rides-the-course-value divergence.)
──────────────────────────────────────────────────────────────────────────────── --}}
@php
    $profile = $visit->student?->studentProfile;
    $vs      = $visit->vitalSigns;
    $sr      = $visit->screeningResponse;
    $record  = $visit->clearanceRecord;

    // Age at ENCODE date, not "today" — a reprint years later must still show
    // the age the clearance was issued at.
    $age = ($profile?->date_of_birth && $record->encoded_at)
        ? (int) $profile->date_of_birth->diffInYears($record->encoded_at)
        : null;

    // YES/NO bubble dot for a physical-sign column — nurse-recorded exam
    // findings (D-22). NULL = not examined → both bubbles stay blank.
    $dot = fn (string $column, bool $forYes) => $record->{$column} !== null && $record->{$column} === $forYes ? '●' : '';

    $signLabels = \App\Models\ClearanceRecord::PHYSICAL_SIGNS;

    $isSingle  = strcasecmp((string) $profile?->civil_status, 'Single') === 0;
    $isMarried = strcasecmp((string) $profile?->civil_status, 'Married') === 0;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Medical Clearance — {{ $visit->reference_no }}</title>
<style>
    /* Serif stack matching the official form's typeface (Cambria-like). */
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body {
        font-family: Cambria, 'Book Antiqua', Georgia, 'Times New Roman', serif;
        font-size: 10.5pt;
        color: #1a1a1a;
        background: #fff;
        max-width: 7.6in;
        margin: 0 auto;
        padding: 0.35in 0.25in;
    }
    /* Letter default (the clinic's stock); picking A4 in the print dialog
       overrides the paper and the fluid layout reflows — the content is
       sized to fit BOTH heights (Letter is the shorter page, so it is the
       binding constraint either way). */
    @page { size: letter portrait; margin: 10mm 14mm; }
    @media print {
        html, body { height: auto; }
        body { padding: 0; max-width: none; }
        /* One page exactly (FR-PRT-05): nothing in this document may split
           or spill — a stray overflow becomes a clipped line, never page 2. */
        body { page-break-after: avoid; }
    }

    b, strong, .lbl { font-weight: bold; }

    /* A filled-in value sitting on the form's answer line. */
    .line {
        display: inline-block;
        border-bottom: 1px solid #1a1a1a;
        text-align: center;
        min-height: 1.2em;
        padding: 0 0.4em;
    }

    /* Yes/No & choice bubbles. Filled = a printed dot glyph, not a CSS
       background, so it survives "print without background graphics". */
    .bb {
        display: inline-block;
        width: 0.95em; height: 0.95em;
        border: 1px solid #1a1a1a;
        border-radius: 50%;
        vertical-align: -0.12em;
        text-align: center;
        line-height: 0.85em;
        font-size: 0.9em;
    }

    /* ── Letterhead ── */
    .letterhead { display: flex; justify-content: space-between; align-items: flex-start; }
    .lh-left .republic { font-size: 10pt; }
    .lh-left h1 { font-size: 17pt; font-weight: bold; letter-spacing: 0.02em; }
    .lh-left .former { font-size: 10pt; }
    .lh-logos { display: flex; align-items: center; gap: 10px; }
    .lh-logos img.seal { height: 62px; }
    .lh-logos img.bagong { height: 70px; }
    .office { display: flex; align-items: center; gap: 10px; margin-top: 5px; }
    .office img { height: 54px; }
    .office .office-name { font-size: 12.5pt; line-height: 1.25; }
    .header-rule { border-bottom: 1px solid #1a1a1a; margin-top: 6px; }
    .header-rule + .header-rule { margin-top: 2px; }

    /* ── Title ── */
    .form-title {
        text-align: center;
        margin: 12px 0 10px;
        font-size: 14pt;
        font-weight: bold;
        letter-spacing: 0.35em;
        text-decoration: underline;
        text-underline-offset: 3px;
    }

    /* ── Identity rows ── */
    .row { display: flex; align-items: flex-end; gap: 6px; margin-top: 8px; }
    .grow { flex: 1; }
    .name-cell { display: inline-block; text-align: center; }
    .name-cell .sub {
        display: block;
        font-weight: bold;
        font-size: 9.5pt;
        margin-top: 1px;
        border: 0;
    }
    .name-cell .line { display: block; }

    /* ── Vitals grid ── */
    .vitals { width: 92%; margin: 12px auto 0; border-collapse: collapse; }
    .vitals td { padding: 2px 0; width: 33.3%; }

    /* ── Physical signs ── */
    .signs-heading { font-style: italic; font-weight: bold; margin-top: 12px; }
    .signs { width: 88%; margin: 6px auto 0; border-collapse: collapse; }
    .signs td, .signs th { padding: 1px 4px; font-size: 10pt; text-align: left; }
    .signs th { text-align: center; font-weight: bold; }
    .signs td.bubbles { text-align: center; width: 44px; }
    .signs td.spacer { width: 28px; }
    .if-yes { font-style: italic; margin-top: 6px; }
    .if-yes b { font-style: italic; }

    /* ── Remarks: nurse notes over the form's two ruled lines. A FIXED
       two-line box (FR-PRT-05: long notes must never grow the page): the two
       rules are absolutely-positioned strips, the text overlays them on the
       same line rhythm, auto-shrunk by the script at the bottom and then
       clipped by overflow:hidden. Heights in px, not em, so the box cannot
       move when the text shrinks. ── */
    .remarks-lines { flex: 1; position: relative; height: 42px; }
    .remarks-lines .ruled { position: absolute; left: 0; right: 0; height: 21px; border-bottom: 1px solid #1a1a1a; }
    .remarks-lines .ruled + .ruled { top: 21px; }
    .remarks-text {
        position: absolute;
        inset: 0;
        line-height: 21px;
        padding: 0 0.3em;
        overflow: hidden;
    }

    /* ── Fitness + purpose ── */
    .purposes { margin: 4px 0 0 52%; list-style: none; }
    .purposes li { margin-top: 2px; }

    /* ── Physician block (FR-PRT-04 / BR-17) ── */
    .physician { margin: 14px 0 0 34px; display: inline-block; text-align: center; }
    .physician .sig-space { height: 34px; }        /* blank line for wet signing */
    .physician .name {
        font-weight: bold;
        border-bottom: 1px solid #1a1a1a;
        padding: 0 8px;
    }
    .physician .title { font-size: 10pt; margin-top: 2px; }
    .print-date { margin: 10px 0 0 34px; }

    .form-code { margin-top: 10px; font-size: 9pt; }
</style>
    @include('partials.favicon')
</head>
{{-- data-hp-print-doc: the encode screen's print script only fires
     window.print() when the iframe holds THIS document (FR-NRS-05). --}}
<body data-hp-print-doc>

    {{-- ── Letterhead ─────────────────────────────────────────────────────── --}}
    <div class="letterhead">
        <div class="lh-left">
            <p class="republic">Republic of the Philippines</p>
            <h1>PAMPANGA STATE UNIVERSITY</h1>
            <p class="former">(former Don Honorio Ventura State University)</p>
        </div>
        <div class="lh-logos">
            <img class="seal" src="{{ asset('images/form/pamsu-seal.png') }}" alt="Pampanga State University Seal">
            <img class="bagong" src="{{ asset('images/form/bagong-pilipinas.png') }}" alt="Bagong Pilipinas">
        </div>
    </div>
    <div class="office">
        <img src="{{ asset('images/form/oswf.png') }}" alt="Office of Student Welfare and Formation">
        <div class="office-name">
            Office of Student Welfare and Formation<br>
            Health Services Unit
        </div>
    </div>
    <div class="header-rule"></div>
    <div class="header-rule"></div>

    <h2 class="form-title">MEDICAL CLEARANCE</h2>

    {{-- ── Student identity (FR-PRT-02) ──────────────────────────────────────── --}}
    {{-- gap 0 so the three segments read as the scan's one continuous rule --}}
    <div class="row" style="gap: 0;">
        <span class="lbl" style="margin-right: 5px;">Name:</span>
        <span class="name-cell" style="width: 34%;">
            <span class="line">{{ $profile->last_name ?? '' }}</span>
            <span class="sub">SURNAME</span>
        </span>
        <span class="name-cell" style="width: 34%;">
            <span class="line">{{ $profile->first_name ?? '' }}</span>
            <span class="sub">FIRST NAME</span>
        </span>
        <span class="name-cell grow">
            <span class="line">{{ $profile->middle_name ?? '' }}</span>
            <span class="sub">MIDDLE NAME</span>
        </span>
    </div>

    {{-- Course + year only — the official form has no college box and the
         college is NOT printed (D-25 dropped the old rides-the-course-value
         divergence). No Student No. anywhere: the form has no such field
         (D-22). Degree names are long — smaller type keeps one line. --}}
    <div class="row">
        <span class="lbl">Course, Year &amp; Section:</span>
        <span class="line" style="width: 64%; font-size: 8.5pt;">{{ $profile->course ?? '' }}, {{ $profile->year_level ?? '' }}</span>
    </div>

    <div class="row">
        <span class="lbl">Address:</span>
        <span class="line grow">{{ $profile->address ?? '' }}</span>
    </div>

    <div class="row">
        <span class="lbl">Age:</span>
        <span class="line" style="width: 50px;">{{ $age }}</span>
        <span class="lbl" style="margin-left: 26px;">Sex:</span>
        <span class="bb">{{ $profile?->sex === 'M' ? '●' : '' }}</span> Male
        <span class="bb" style="margin-left: 8px;">{{ $profile?->sex === 'F' ? '●' : '' }}</span> Female
        <span class="lbl" style="margin-left: 26px;">Civil Status:</span>
        <span class="bb">{{ $isSingle ? '●' : '' }}</span> Single
        <span class="bb" style="margin-left: 8px;">{{ $isMarried ? '●' : '' }}</span> Married
    </div>

    <div class="row">
        <span class="lbl">Date of Birth:</span>
        <span class="line" style="width: 26%;">{{ $profile?->date_of_birth?->format('F j, Y') }}</span>
        <span class="lbl" style="margin-left: 20px;">Place of Birth:</span>
        <span class="line grow">{{ $profile->place_of_birth ?? '' }}</span>
    </div>

    {{-- ── Vitals (FR-PRT-02) — capture-time server-frozen values ─────────────
         Respiratory Rate intentionally blank (FR-PRT-03 / D-6). BMI row is a
         scan divergence: no BMI box on the official form, FR-PRT-02 needs it. --}}
    <table class="vitals">
        <tr>
            <td><span class="lbl">Height:</span> <span class="line" style="min-width: 70px;">{{ $vs ? $vs->height_cm.' cm' : '' }}</span></td>
            <td><span class="lbl">Heart Rate:</span> <span class="line" style="min-width: 80px;">{{ $vs ? $vs->heart_rate_bpm.' bpm' : '' }}</span></td>
            <td><span class="lbl">Temperature:</span> <span class="line" style="min-width: 70px;">{{ $vs ? $vs->temperature_c.' °C' : '' }}</span></td>
        </tr>
        <tr>
            <td><span class="lbl">Weight:</span> <span class="line" style="min-width: 70px;">{{ $vs ? $vs->weight_kg.' kg' : '' }}</span></td>
            <td><span class="lbl">Blood Pressure:</span> <span class="line" style="min-width: 80px;">{{ $vs ? $vs->bp_systolic.'/'.$vs->bp_diastolic.' mmHg' : '' }}</span></td>
            <td><span class="lbl">Respiratory Rate:</span> <span class="line" style="min-width: 60px;"></span></td>
        </tr>
        <tr>
            <td><span class="lbl">BMI:</span> <span class="line" style="min-width: 70px;">{{ $vs?->bmi }}</span></td>
            <td></td>
            <td></td>
        </tr>
    </table>

    {{-- ── Physical Signs Disorder of — the physician examines the student and
         the nurse records the findings on the encode screen (FR-NRS-03/D-22);
         these bubbles shade from clearance_records.ps_*. An unanswered row
         prints blank. The physician hand-writes details under REMARKS. --}}
    <p class="signs-heading">Physical Signs Disorder of:</p>
    <table class="signs">
        <tr>
            <td></td><th>YES</th><th>NO</th>
            <td class="spacer"></td>
            <td></td><th>YES</th><th>NO</th>
        </tr>
        @foreach ([
            ['ps_skin',        'ps_extremities'],
            ['ps_abdomen_git', 'ps_heart_cvs'],
            ['ps_heent',       'ps_neurological'],
            ['ps_gut',         'ps_breast'],
            ['ps_chest_lungs', null],
        ] as [$left, $right])
            <tr>
                <td><b>* {{ $signLabels[$left] }}</b></td>
                <td class="bubbles"><span class="bb">{{ $dot($left, true) }}</span></td>
                <td class="bubbles"><span class="bb">{{ $dot($left, false) }}</span></td>
                <td class="spacer"></td>
                @if ($right)
                    <td><b>* {{ $signLabels[$right] }}</b></td>
                    <td class="bubbles"><span class="bb">{{ $dot($right, true) }}</span></td>
                    <td class="bubbles"><span class="bb">{{ $dot($right, false) }}</span></td>
                @else
                    <td></td><td></td><td></td>
                @endif
            </tr>
        @endforeach
    </table>

    <p class="if-yes">If <b>YES</b>, give details under Remarks.</p>

    {{-- ── Remarks — nurse notes only; case details are the physician's manual
         annotation against the shaded YES physical signs (D-22) ─────────────── --}}
    {{-- flex-start: the label belongs on the FIRST ruled line, as on the scan --}}
    <div class="row" style="margin-top: 6px; align-items: flex-start;">
        <span class="lbl">REMARKS:</span>
        <div class="remarks-lines">
            <div class="ruled"></div>
            <div class="ruled"></div>
            <div class="remarks-text" id="remarks-text">{{ $record->nurse_notes ?? '' }}</div>
        </div>
    </div>

    {{-- Pregnancy — already answered at the kiosk (FR-KSK-10), so it prints
         shaded; the LMP date fills only on a YES (D-22). --}}
    <div class="row" style="margin-top: 10px;">
        <span>Are you Pregnant</span>
        <span class="bb">{{ $sr?->is_pregnant === true ? '●' : '' }}</span> <b>YES</b>
        <span class="bb" style="margin-left: 6px;">{{ $sr?->is_pregnant === false ? '●' : '' }}</span> <b>NO</b>
        <span style="margin-left: 14px; font-style: italic;">If <b>YES</b>, when is the last menstrual period?</span>
        <span class="line" style="width: 130px;">{{ $sr?->is_pregnant ? $sr->last_menstrual_period?->format('F j, Y') : '' }}</span>
    </div>

    {{-- ── The encoded Result + Purpose (FR-PRT-02, BR-16 optional purpose) ──── --}}
    <div class="row" style="margin-top: 10px;">
        <span>He/She is physically / mentally</span>
        <span class="bb">{{ $record->result === 'Fit' ? '●' : '' }}</span> <b>FIT</b>
        <span class="bb" style="margin-left: 6px;">{{ $record->result === 'Unfit' ? '●' : '' }}</span> <b>UNFIT</b>
        <span>to undergo in:</span>
    </div>
    <ul class="purposes">
        @foreach (\App\Models\ClearanceRecord::PURPOSES as $purpose)
            <li><span class="bb">{{ $record->purpose === $purpose ? '●' : '' }}</span> {{ $purpose }}</li>
        @endforeach
        @php $isOthers = $record->purpose === \App\Models\ClearanceRecord::PURPOSE_OTHERS; @endphp
        {{-- nowrap + hidden: a long specified event clips on the line instead
             of wrapping and growing the one-page layout (FR-PRT-05). --}}
        <li><span class="bb">{{ $isOthers ? '●' : '' }}</span> Others, Specify:
            <span class="line" style="max-width: 180px; font-size: 8.5pt; white-space: nowrap; overflow: hidden; vertical-align: bottom; min-width: 120px;">{{ $isOthers ? $record->purpose_other : '' }}</span></li>
    </ul>

    {{-- ── Physician block — pre-printed identity, blank line for wet signing
         (FR-PRT-04 / BR-17); values come from the record, whose DB defaults
         are the single source (§7.5). Date = encode date (FR-PRT-02). ──────── --}}
    <div class="physician">
        <div class="sig-space"></div>
        <div class="name">{{ $record->physician_name }}</div>
        <div class="title">University Physician<br>License No. {{ $record->physician_license_no }}</div>
    </div>

    <div class="print-date">
        <span class="lbl">Date:</span>
        <span class="line" style="min-width: 130px;">{{ $record->encoded_at?->format('F j, Y') }}</span>
    </div>

    <p class="form-code">DHVSU-QSP-OSS-004-FO002-R03</p>

    <script>
        // FR-PRT-05 — long nurse notes must not push the form to page 2: the
        // remarks box is fixed at two ruled lines; shrink the type until the
        // text fits (floor 7pt), then overflow:hidden clips the rest. The
        // line-height stays fixed so the text keeps sitting on the rules.
        (function () {
            var el = document.getElementById('remarks-text');
            if (!el) return;
            for (var size = 10; size >= 7 && el.scrollHeight > el.clientHeight; size -= 0.5) {
                el.style.fontSize = size + 'pt';
            }
        })();
    </script>
</body>
</html>
