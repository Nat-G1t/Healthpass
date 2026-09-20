{{-- ── Medical Assessment Form — official form PSU-QSP-OSS-004-FO010-R00 ─────
     Module PRT (FR-PRT-07), Decision D-71.

     TWO pages on ONE sheet of US Legal (8.5 × 14in "long bond"), printed
     back-to-back. Most clinic printers cannot duplex, so the encode screen
     offers Print front and Print back as separate buttons: this template
     renders BOTH pages by default (the PDF), or just one when handed
     $side = 'front' | 'back' (the two print buttons).

     Same two-renderer contract as the Medical Clearance (D-67), so the same
     deliberately old-fashioned CSS:

       - layout is TABLES and fixed-width blocks; NO flexbox, NO grid, NO
         JavaScript — dompdf runs none of them;
       - the logos are base64 `data:` URIs (App\Support\ClearanceDocument);
       - Times New Roman for the body, Arial for the letterhead — core PDF
         fonts that every browser also has, so both outputs agree;
       - a ticked box is filled with BORDER, never a background colour:
         Chrome's print dialog leaves "Background graphics" unchecked by
         default and would drop a background fill on an official form.

     Every value arrives from App\Support\AssessmentDocument — the print, the
     pre-save preview, the clinic PDF and (prompt 14) the student download all
     build their data there, so the four can never disagree.
──────────────────────────────────────────────────────────────────────────── --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Medical Assessment Form — {{ $visit->reference_no }}</title>
<style>
    /* Legal portrait (8.5 × 14in). dompdf reads @page too, so the PDF and
       the browser print use the same paper. */
    @page { size: legal portrait; margin: 0.5in 0.7in 0.35in 0.7in; }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    body {
        font-family: "Times New Roman", Times, serif;
        font-size: 10pt;
        line-height: 1.22;
        color: #000;
        background: #fff;
    }

    /* 7.1in = Legal minus the two 0.7in margins, so the sheet maps 1:1 onto
       the page in both renderers and simply centres on screen. */
    .sheet { width: 7.1in; margin: 0 auto; }

    /* The front page ends here when both pages render; a single-side print
       has nothing after it, so the class is only added when it is needed. */
    .page-break { page-break-after: always; }

    b, .lbl { font-weight: bold; }
    .ital { font-style: italic; }
    .center { text-align: center; }
    .right { text-align: right; }

    /* ── Letterhead — no OSWF block on this form (D-71) ──────────────────── */
    .letterhead { width: 100%; border-collapse: collapse; }
    .letterhead td { vertical-align: top; }
    .lh-text .republic { font-family: Arial, Helvetica, sans-serif; font-size: 9.5pt; }
    .lh-text .university {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 18pt;
        letter-spacing: 0.03em;
        line-height: 1.15;
    }
    .lh-text .former { font-family: Arial, Helvetica, sans-serif; font-size: 9pt; }
    .lh-logos { text-align: right; white-space: nowrap; }
    .lh-logos img.seal { height: 42pt; }
    .lh-logos img.bagong { height: 50pt; }

    .rule { border-bottom: 1px solid #000; margin-top: 4pt; }

    .form-title {
        text-align: center;
        margin: 12pt 0 10pt;
        font-size: 13pt;
        font-weight: bold;
        text-decoration: underline;
    }

    /* ── Identity rows ──────────────────────────────────────────────────── */
    .ident { width: 100%; border-collapse: collapse; margin-top: 5pt; }
    .ident td { vertical-align: bottom; }

    .fill {
        border-bottom: 1px solid #000;
        height: 14pt;
        padding: 0 4pt;
        text-align: center;
        white-space: nowrap;
        overflow: hidden;
    }
    .fill.left { text-align: left; }

    .cap { text-align: center; font-weight: bold; font-size: 7.5pt; padding-top: 1pt; }

    .fill-inline {
        display: inline-block;
        border-bottom: 1px solid #000;
        text-align: center;
        white-space: nowrap;
        overflow: hidden;
    }

    /* Choice bubble / box — an empty ring or square drawn as a BORDER, so it
       always prints. `.on` thickens that same border until it meets in the
       middle and the shape is solid; the background colour underneath only
       fills dompdf's hairline corner seams. See the D-67 template for the
       full reasoning — the two forms use the identical recipe. */
    .bb {
        display: inline-block;
        width: 9pt;
        height: 9pt;
        border: 1px solid #000;
        border-radius: 50%;
    }
    .bb.on { width: 0; height: 0; border-width: 4.5pt; background-color: #000; }

    .bx { display: inline-block; width: 7pt; height: 7pt; border: 1px solid #000; }
    .bx.on { width: 0; height: 0; border-width: 3.5pt; background-color: #000; }

    /* ── Physical Signs Disorder of (Self Assessment) ────────────────────── */
    .signs-heading { font-weight: bold; font-style: italic; margin-top: 12pt; }
    /* Auto layout on purpose (not `table-layout: fixed`): dompdf's fixed
       layout ignores the colgroup and splits the grid into equal columns,
       which squeezes the long labels into the YES box. */
    .signs { width: 6.6in; margin: 5pt 0 0 0.2in; border-collapse: collapse; }
    .signs td {
        border: 1px solid #000;
        padding: 1.5pt 3pt;
        height: 13pt;
        font-size: 7pt;
    }
    .signs td.sname { font-weight: bold; white-space: nowrap; }
    .signs td.sbox { text-align: center; }
    .signs tr.head td { font-weight: bold; text-align: center; font-size: 7pt; }

    /* ── Certification ──────────────────────────────────────────────────── */
    .certify { margin-top: 10pt; font-style: italic; text-align: right; }
    .signature { width: 2.4in; margin: 22pt 0 0 auto; }
    .signature .line { border-bottom: 1px solid #000; height: 14pt; }
    .signature .cap { padding-top: 2pt; }

    /* ── Past Medical History & Family History ──────────────────────────── */
    .band {
        margin-top: 12pt;
        text-align: center;
        font-weight: bold;
        font-size: 12pt;
        letter-spacing: 0.02em;
    }
    .history { width: 100%; margin-top: 6pt; border-collapse: collapse; }
    .history td, .history th {
        border: 1px solid #000;
        padding: 2pt 5pt;
        font-size: 8.5pt;
        height: 13.5pt;
    }
    .history th { font-weight: normal; text-align: center; font-size: 9pt; }
    .history td.present { text-align: center; white-space: nowrap; }

    /* ── Back page ──────────────────────────────────────────────────────── */
    /* The two halves carry their widths INLINE on the cells, and this rule
       uses a plain descendant selector: dompdf does not support the `>` child
       combinator, so a `.halves > tbody > tr > td` rule silently applies to
       nothing — the left cell then grows to its widest line and pushes the
       whole right column (sections IV–VI) off the sheet. It renders fine in
       Chrome either way, so this only ever shows up in the PDF. */
    .halves { width: 7.1in; border-collapse: collapse; }
    .halves td { vertical-align: top; }
    .halves td.left-col { padding-right: 14pt; }

    .sec { font-weight: bold; font-size: 10.5pt; }
    .sec-rule { border-bottom: 1px solid #000; margin: 1pt 0 5pt; }
    .sec-gap { margin-top: 11pt; }

    .line-row { margin-top: 2.5pt; }
    .sub { font-style: italic; font-size: 9pt; margin-top: 4pt; }

    /* The immunization boxes, four to a row. Fixed cell widths so the left
       half keeps its 3.45in and dompdf never has to wrap an inline run. */
    .vax { width: 3.3in; border-collapse: collapse; margin-top: 2pt; }
    .vax td { padding: 1pt 0; font-size: 9pt; white-space: nowrap; }

    /* ── Pertinent Physical Examination — three columns of groups ────────── */
    .exam-cols { width: 100%; border-collapse: collapse; margin-top: 5pt; }
    .exam-cols td { vertical-align: top; width: 33.33%; padding-right: 10pt; font-size: 9pt; }
    .exam-group { margin-bottom: 8pt; }
    .exam-group .g-name { font-weight: bold; font-size: 9pt; }
    .exam-group div.f { margin-top: 1.5pt; }

    /* ── Fitness line + purposes ────────────────────────────────────────── */
    .fitness { margin-top: 10pt; font-size: 11pt; }
    .purposes { width: 100%; border-collapse: collapse; margin-top: 4pt; }
    .purposes td { vertical-align: top; width: 33.33%; padding: 2pt 6pt 2pt 0; }

    /* ── Signature footer ───────────────────────────────────────────────── */
    .footer { width: 100%; border-collapse: collapse; margin-top: 26pt; }
    .footer td { vertical-align: bottom; }
    .footer .name {
        border-bottom: 1px solid #000;
        height: 14pt;
        text-align: center;
        font-weight: bold;
        white-space: nowrap;
        overflow: hidden;
    }

    .form-code { margin-top: 10pt; font-size: 7.5pt; }
</style>
</head>
{{-- data-hp-print-doc: the encode page's print script only fires
     window.print() when the iframe holds THIS document (FR-NRS-05). --}}
<body data-hp-print-doc>

{{-- ══════════════════════════ FRONT PAGE ══════════════════════════════════ --}}
@if ($side !== 'back')
<div class="sheet {{ $side === null ? 'page-break' : '' }}">

    <table class="letterhead">
        <tr>
            <td class="lh-text">
                <div class="republic">Republic of the Philippines</div>
                <div class="university">PAMPANGA STATE UNIVERSITY</div>
                <div class="former">(former Don Honorio Ventura State University)</div>
            </td>
            <td class="lh-logos">
                <img class="seal" src="{{ $logos['seal'] }}" alt="Pampanga State University seal">
                <img class="bagong" src="{{ $logos['bagong'] }}" alt="Bagong Pilipinas">
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <div class="form-title">MEDICAL ASSESSMENT FORM</div>

    {{-- ── Identity (FR-PRT-02) ───────────────────────────────────────── --}}
    <table class="ident">
        <tr>
            <td class="lbl" style="width: 42pt;">Name:</td>
            <td>
                <div class="fill">{{ $surname }}</div>
                <div class="cap">SURNAME</div>
            </td>
            <td>
                <div class="fill">{{ $firstName }}</div>
                <div class="cap">FIRST NAME</div>
            </td>
            <td>
                <div class="fill">{{ $middleName }}</div>
                <div class="cap">MIDDLE NAME</div>
            </td>
        </tr>
    </table>

    <table class="ident">
        <tr>
            <td class="lbl" style="width: 132pt;">Course, Year &amp; Section:</td>
            <td><div class="fill">{{ $courseYearSection }}</div></td>
        </tr>
    </table>

    <table class="ident">
        <tr>
            <td class="lbl" style="width: 54pt;">Address:</td>
            <td><div class="fill">{{ $address }}</div></td>
        </tr>
    </table>

    <table class="ident">
        <tr>
            <td class="lbl" style="width: 30pt;">Age:</td>
            <td style="width: 46pt;"><div class="fill">{{ $age }}</div></td>
            <td class="lbl" style="width: 44pt; padding-left: 14pt;">Sex:</td>
            <td style="width: 126pt;">
                <span class="bb{{ $sex === 'M' ? ' on' : '' }}"></span> Male
                <span class="bb{{ $sex === 'F' ? ' on' : '' }}" style="margin-left: 6pt;"></span> Female
            </td>
            <td class="lbl" style="width: 74pt;">Civil Status:</td>
            <td>
                <span class="bb{{ $isSingle ? ' on' : '' }}"></span> Single
                <span class="bb{{ $isMarried ? ' on' : '' }}" style="margin-left: 6pt;"></span> Married
            </td>
        </tr>
    </table>

    <table class="ident">
        <tr>
            <td class="lbl" style="width: 80pt;">Date of Birth:</td>
            <td style="width: 156pt;"><div class="fill">{{ $dateOfBirth }}</div></td>
            <td class="lbl" style="width: 92pt; padding-left: 16pt;">Place of Birth:</td>
            <td><div class="fill">{{ $placeOfBirth }}</div></td>
        </tr>
    </table>

    {{-- ── Physical Signs Disorder of: (Self Assessment) ────────────────────
         The STUDENT's kiosk answers (D-63), not the clinic's ps_* exam
         findings — the student certifies this table with their signature
         below it. An unanswered row leaves both boxes blank. --}}
    <div class="signs-heading">Physical Signs Disorder of: <span class="ital">(Self Assessment)</span></div>
    <table class="signs">
        <colgroup>
            <col style="width: 1.10in"><col style="width: 0.36in"><col style="width: 0.36in">
            <col style="width: 1.56in"><col style="width: 0.36in"><col style="width: 0.36in">
            <col style="width: 1.56in"><col style="width: 0.36in"><col style="width: 0.36in">
        </colgroup>
        <tr class="head">
            @foreach ($selfSignColumns as $column)
                <td class="sname"></td>
                <td class="sbox">YES</td>
                <td class="sbox">NO</td>
            @endforeach
        </tr>
        @for ($row = 0; $row < 4; $row++)
            <tr>
                @foreach ($selfSignColumns as $column)
                    <td class="sname">{{ $column[$row]['label'] }}</td>
                    <td class="sbox"><span class="bx{{ $column[$row]['yes'] ? ' on' : '' }}"></span></td>
                    <td class="sbox"><span class="bx{{ $column[$row]['no'] ? ' on' : '' }}"></span></td>
                @endforeach
            </tr>
        @endfor
    </table>

    {{-- ── Pregnancy — answered at the kiosk (FR-KSK-10); the LMP line fills
         only on a YES. ────────────────────────────────────────────────── --}}
    <div style="margin-top: 8pt;">
        Are you Pregnant?
        <span class="bb{{ $isPregnant === true ? ' on' : '' }}"></span> <b>YES</b>
        <span class="bb{{ $isPregnant === false ? ' on' : '' }}" style="margin-left: 6pt;"></span> <b>NO</b>
        <span class="ital" style="margin-left: 12pt;">If <b class="ital">YES</b>, when is the last menstrual period?</span>
        <span class="fill-inline" style="width: 1.5in; font-size: 9pt;">{{ $lastMenstrualPeriod }}</span>
    </div>

    <div class="certify">I certify that the above informations are true and correct.</div>

    <div class="signature">
        <div class="line"></div>
        <div class="cap">Patient's Signature</div>
    </div>

    {{-- ── PAST MEDICAL HISTORY & FAMILY HISTORY — eighteen rows, two
         ☐ Present columns. Each row's own specify text is printed INSIDE
         its blank by AssessmentDocument. ──────────────────────────────── --}}
    <div class="band">PAST MEDICAL HISTORY &amp; FAMILY HISTORY</div>

    <table class="history">
        <tr>
            <th style="width: 3.1in;">Medical Condition / Disease</th>
            <th style="width: 2.0in;">Past Medical History (Patient)</th>
            <th>Family History (Lineal)</th>
        </tr>
        @foreach ($conditions as $condition)
            <tr>
                <td>{{ $condition['label'] }}</td>
                <td class="present"><span class="bx{{ $condition['patient'] ? ' on' : '' }}"></span> Present</td>
                <td class="present"><span class="bx{{ $condition['family'] ? ' on' : '' }}"></span> Present</td>
            </tr>
        @endforeach
    </table>

    <div class="form-code">{{ $formCode }}</div>

</div>
@endif

{{-- ══════════════════════════ BACK PAGE ═══════════════════════════════════ --}}
@if ($side !== 'front')
<div class="sheet">

    <table class="halves">
        <tr>
            {{-- ── Left half: sections I, II, III ──────────────────────── --}}
            <td class="left-col" style="width: 3.45in;">

                <div class="sec">I. PERSONAL / SOCIAL HISTORY</div>
                <div class="sec-rule"></div>
                {{-- The kiosk's own answers (D-68). Three habit rows take
                     Yes / No / Quit; Sexually Active takes Yes / No only,
                     which is why that row carries no Quit box. --}}
                @foreach ($socialRows as $row)
                    <div class="line-row">
                        {{ $row['label'] }}:
                        <span class="bx{{ $row['answer'] === 'Yes' ? ' on' : '' }}"></span> Yes
                        <span class="bx{{ $row['answer'] === 'No' ? ' on' : '' }}" style="margin-left: 5pt;"></span> No
                        @if ($row['label'] !== 'Sexually Active')
                            <span class="bx{{ $row['answer'] === 'Quit' ? ' on' : '' }}" style="margin-left: 5pt;"></span> Quit
                        @endif
                    </div>
                @endforeach

                <div class="sec sec-gap">II. IMMUNIZATION PROFILE</div>
                <div class="sec-rule"></div>
                {{-- Four boxes to a row, as a TABLE rather than a wrapping
                     line of inline-blocks: dompdf will not break a line
                     between inline-block boxes, so a free-flowing list makes
                     this cell as wide as the whole list and pushes the right
                     column (sections IV–VI) clean off the sheet. A table with
                     stated widths lays out the same in both renderers. --}}
                @foreach ($immunizationGroups as $heading => $group)
                    <div class="sub">{{ strtoupper($heading) }}:</div>
                    <table class="vax">
                        @foreach (array_chunk($group['vaccines'], $group['columns']) as $row)
                            <tr>
                                @foreach ($row as $vaccine)
                                    <td style="width: {{ number_format(3.3 / $group['columns'], 3) }}in;">
                                        <span class="bx{{ $vaccine['given'] ? ' on' : '' }}"></span> {{ $vaccine['label'] }}
                                    </td>
                                @endforeach
                                {{-- Pad the last row so its columns keep the
                                     same widths as the rows above it. --}}
                                @for ($pad = count($row); $pad < $group['columns']; $pad++)
                                    <td></td>
                                @endfor
                            </tr>
                        @endforeach
                    </table>
                @endforeach
                <div class="line-row" style="margin-top: 5pt;">
                    <span class="bx{{ $immunizationOthers !== '' ? ' on' : '' }}"></span> Others:
                    <span class="fill-inline" style="width: 1.5in; font-size: 8.5pt;">{{ $immunizationOthers }}</span>
                </div>

                <div class="sec sec-gap">III. FAMILY PLANNING ACCESS</div>
                <div class="sec-rule"></div>
                <div class="line-row">With access to family planning counseling?</div>
                <div class="line-row">
                    <span class="bx{{ $familyPlanningAccess === true ? ' on' : '' }}"></span> Yes
                    <span class="bx{{ $familyPlanningAccess === false ? ' on' : '' }}" style="margin-left: 8pt;"></span> No
                </div>

            </td>

            {{-- ── Right half: sections IV, V, VI + surgical history ───── --}}
            <td style="width: 3.65in;">

                <div class="sec">IV. PERTINENT PHYSICAL EXAM</div>
                <div class="sec-rule"></div>
                {{-- The clinic's confirmed vitals (D-65). Height is in METRES
                     on this paper, unlike the Medical Clearance. --}}
                <div class="line-row">
                    Height: <span class="fill-inline" style="width: 0.62in;">{{ $exam['heightM'] }}</span> m
                    <span style="margin-left: 14pt;">Weight:</span>
                    <span class="fill-inline" style="width: 0.62in;">{{ $exam['weightKg'] }}</span> kg
                </div>
                <div class="line-row">
                    BP: <span class="fill-inline" style="width: 0.72in;">{{ $exam['bloodPressure'] }}</span> mmHg
                    <span style="margin-left: 14pt;">Temp:</span>
                    <span class="fill-inline" style="width: 0.52in;">{{ $exam['temperatureC'] }}</span> °C
                </div>
                <div class="line-row">
                    HR: <span class="fill-inline" style="width: 0.62in;">{{ $exam['heartRate'] }}</span> /min
                    <span style="margin-left: 14pt;">RR:</span>
                    <span class="fill-inline" style="width: 0.62in;">{{ $exam['respiratoryRate'] }}</span> /min
                </div>

                <div class="sec sec-gap">V. MENSTRUAL HISTORY</div>
                <div class="sec-rule"></div>
                {{-- Female students only (D-70): a male student's row is NULL
                     in the database and every box here prints BLANK — the
                     paper leaves the section empty rather than writing N/A. --}}
                <div class="line-row">
                    Menarche: <span class="fill-inline" style="width: 0.6in;">{{ $menstrual['menarcheAge'] }}</span> yrs old
                </div>
                <div class="line-row">
                    Onset of sexual intercourse:
                    <span class="fill-inline" style="width: 0.6in;">{{ $menstrual['firstIntercourseAge'] }}</span> yrs old
                </div>
                <div class="line-row">
                    Last Menstrual Period (LMP):
                    <span class="fill-inline" style="width: 1.25in; font-size: 8.5pt;">{{ $menstrual['lmp'] }}</span>
                </div>
                <div class="line-row">
                    Period Duration: <span class="fill-inline" style="width: 0.6in;">{{ $menstrual['periodDays'] }}</span> days
                </div>
                <div class="line-row">
                    No. of Pads per Day: <span class="fill-inline" style="width: 0.6in;">{{ $menstrual['padsPerDay'] }}</span>
                </div>
                <div class="line-row">
                    Interval Cycle: <span class="fill-inline" style="width: 0.6in;">{{ $menstrual['cycleDays'] }}</span> days
                </div>
                <div class="line-row">
                    Contraceptive Method Used:
                    <span class="fill-inline" style="width: 1.1in; font-size: 8.5pt;">{{ $menstrual['contraceptive'] }}</span>
                </div>
                <div class="line-row">
                    Menopause:
                    <span class="bx{{ $menstrual['menopause'] === true ? ' on' : '' }}"></span> Yes
                    <span class="bx{{ $menstrual['menopause'] === false ? ' on' : '' }}" style="margin-left: 5pt;"></span> No
                    <span style="margin-left: 8pt;">Age:</span>
                    <span class="fill-inline" style="width: 0.5in;">{{ $menstrual['menopauseAge'] }}</span> yrs
                </div>

                <div class="sec sec-gap">VI. OB/PREGNANCY HISTORY</div>
                <div class="sec-rule"></div>
                <div class="line-row">
                    Gravida: <span class="fill-inline" style="width: 0.3in;">{{ $ob['counts']['gravida'] }}</span>
                    Para: <span class="fill-inline" style="width: 0.3in;">{{ $ob['counts']['para'] }}</span>
                    T: <span class="fill-inline" style="width: 0.26in;">{{ $ob['counts']['term'] }}</span>
                    P: <span class="fill-inline" style="width: 0.26in;">{{ $ob['counts']['preterm'] }}</span>
                    A: <span class="fill-inline" style="width: 0.26in;">{{ $ob['counts']['abortion'] }}</span>
                    L: <span class="fill-inline" style="width: 0.26in;">{{ $ob['counts']['living'] }}</span>
                </div>
                <div class="line-row">
                    Type of Delivery:
                    <span class="fill-inline" style="width: 1.5in; font-size: 8.5pt;">{{ $ob['deliveryType'] }}</span>
                </div>
                <div class="line-row">Pregnancy Induced Hypertension:</div>
                <div class="line-row">
                    <span class="bx{{ $ob['pih'] === true ? ' on' : '' }}"></span> Yes
                    <span class="bx{{ $ob['pih'] === false ? ' on' : '' }}" style="margin-left: 8pt;"></span> No
                </div>

                {{-- Past Surgical History sits under section VI on the paper
                     but is NOT female-only — it applies to every student. --}}
                <div class="line-row" style="margin-top: 11pt;">
                    <b>Past Surgical History / Procedures:</b>
                </div>
                <div class="line-row">
                    <span class="fill-inline left" style="width: 3.1in; font-size: 8.5pt; text-align: left;">{{ $surgical['procedures'] }}</span>
                </div>
                <div class="line-row">
                    Date Done:
                    <span class="fill-inline" style="width: 1.6in; font-size: 8.5pt;">{{ $surgical['dateDone'] }}</span>
                </div>

            </td>
        </tr>
    </table>

    {{-- ── PERTINENT PHYSICAL EXAMINATION — the eight groups A–H in the
         paper's three columns (D-70). ─────────────────────────────────── --}}
    <div class="band">PERTINENT PHYSICAL EXAMINATION</div>

    <table class="exam-cols">
        <tr>
            @foreach ($examColumns as $column)
                <td>
                    @foreach ($column as $group)
                        <div class="exam-group">
                            <div class="g-name">{{ $group['label'] }}</div>
                            @foreach ($group['findings'] as $finding)
                                <div class="f">
                                    <span class="bx{{ $finding['on'] ? ' on' : '' }}"></span> {{ $finding['label'] }}
                                </div>
                            @endforeach
                            <div class="f">
                                <span class="bx{{ $group['others'] !== '' ? ' on' : '' }}"></span> Others:
                                <span class="fill-inline" style="width: 1.0in; font-size: 8pt;">{{ $group['others'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </td>
            @endforeach
        </tr>
    </table>

    {{-- ── The encoded Result + the batch's purpose (D-62). The kiosk never
         shows Fit/Unfit — this document is where it appears. ──────────── --}}
    <div class="fitness">
        He/She is physically / mentally
        <span class="bb{{ $result === 'Fit' ? ' on' : '' }}"></span> <b>FIT</b>
        <span class="bb{{ $result === 'Unfit' ? ' on' : '' }}" style="margin-left: 6pt;"></span> <b>UNFIT</b>
        <b>to undergo in:</b>
    </div>

    {{-- Two purposes per column, exactly as the paper stacks them, with
         "Others, Specify" and its typed event in the third. --}}
    <table class="purposes">
        <tr>
            @foreach (array_chunk($purposes, 2) as $column)
                <td>
                    @foreach ($column as $purpose)
                        <div><span class="bb{{ $purpose['checked'] ? ' on' : '' }}"></span> {{ $purpose['label'] }}</div>
                    @endforeach
                </td>
            @endforeach
            <td>
                <div>
                    <span class="bb{{ $othersChecked ? ' on' : '' }}"></span> {{ $othersLabel }}:
                    <span class="fill-inline" style="width: 1.1in; font-size: 8pt;">{{ $othersText }}</span>
                </div>
            </td>
        </tr>
    </table>

    {{-- ── Signature footer. "Interviewed/Assessed by" is the ENCODER —
         nurse or physician (D-64). The University Physician's name and
         licence print only on a record a PHYSICIAN encoded; a nurse's
         record leaves both for the physician to sign by hand. ─────────── --}}
    <table class="footer">
        <tr>
            <td style="width: 2.0in;">
                <span class="lbl">Date:</span>
                <span class="fill-inline" style="width: 1.3in;">{{ $issuedOn }}</span>
            </td>
            <td style="width: 2.6in; padding: 0 12pt;">
                <div class="name">{{ $encoderName }}</div>
                <div class="center">Interviewed/Assessed by:</div>
            </td>
            <td>
                <div class="name">{{ $physicianName }}</div>
                <div class="center">University Physician</div>
                <div class="center">
                    License No. <span class="fill-inline" style="width: 0.8in;">{{ $physicianLicense }}</span>
                </div>
            </td>
        </tr>
    </table>

    <div class="form-code">{{ $formCode }}</div>

</div>
@endif

</body>
</html>
