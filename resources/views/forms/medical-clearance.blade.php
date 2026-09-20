{{-- ── Medical Clearance — official form PSU-QSP-OSS-004-FO002-R04 ───────────
     Module PRT (FR-PRT-01..06 / BR-17), Decision D-67.

     ONE template, TWO renderers. The clinic prints this in the browser
     (hidden iframe + window.print(), FR-NRS-05) and downloads the SAME
     document as a PDF through dompdf (FR-PRT-06). dompdf is a pure-PHP
     HTML-to-PDF renderer — nothing is installed on the server and no
     headless browser runs — but it understands only a subset of CSS, so this
     file is deliberately old-fashioned:

       - layout is TABLES, block and inline-block with fixed widths;
         NO flexbox, NO grid, NO JavaScript (dompdf runs neither);
       - the logos are base64 `data:` URIs (App\Support\ClearanceDocument) so
         neither renderer has to fetch a URL;
       - fonts are "Times New Roman"/Times for the body and Arial/Helvetica
         for the letterhead — both are core PDF fonts and both exist in every
         browser, so the two outputs agree;
       - a chosen bubble or box is filled SOLID, and the fill is made of
         BORDER, not a background colour: Chrome's print dialog leaves
         "Background graphics" unchecked by default, which would drop a
         background fill and print an empty box on the official form.
         Borders always print, in both renderers;
       - REMARKS is pre-wrapped and pre-sized SERVER-SIDE
         (ClearanceDocument::remarks) because dompdf cannot run the old
         shrink-to-fit script.

     Every value arrives from App\Support\ClearanceDocument — the print, the
     preview, the clinic PDF and (prompt 14) the student download all build
     their data there, so the four can never disagree.

     One page, always (FR-PRT-05): nothing here may wrap onto a page 2.
     R04 has no BMI box, so BMI is not printed (it stays on My Records).
──────────────────────────────────────────────────────────────────────────── --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Medical Clearance — {{ $visit->reference_no }}</title>
<style>
    /* Letter portrait; the margins are the scan's (~0.9in sides). dompdf
       reads @page too, so the PDF and the print use the same paper. */
    @page { size: letter portrait; margin: 0.6in 0.9in 0.3in 0.9in; }

    /* border-box so a declared width means the SAME thing in both renderers:
       Chrome squeezes an over-wide table, dompdf grows it past the margin. */
    * { margin: 0; padding: 0; box-sizing: border-box; }

    html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    body {
        font-family: "Times New Roman", Times, serif;
        font-size: 10.5pt;
        line-height: 1.25;
        color: #000;
        background: #fff;
    }

    /* The printable column. 6.7in = Letter minus the two 0.9in margins, so
       the sheet maps 1:1 onto the page in both renderers; on screen (the
       print iframe) it simply centres. */
    .sheet { width: 6.7in; margin: 0 auto; }

    b, .lbl { font-weight: bold; }
    .ital { font-style: italic; }

    /* ── Letterhead ─────────────────────────────────────────────────────── */
    .letterhead { width: 100%; border-collapse: collapse; }
    .letterhead td { vertical-align: top; }
    .lh-text .republic { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; }
    .lh-text .university {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 18pt;
        letter-spacing: 0.03em;
        line-height: 1.15;
    }
    .lh-text .former { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; }
    .lh-logos { text-align: right; white-space: nowrap; }
    .lh-logos img.seal { height: 44pt; }
    .lh-logos img.bagong { height: 54pt; }

    .office { width: 100%; border-collapse: collapse; margin-top: 3pt; }
    .office td { vertical-align: middle; }
    .office-logo { width: 46pt; }
    .office-logo img { height: 38pt; }
    .office-name {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 12.5pt;
        font-weight: bold;
        line-height: 1.2;
    }

    .rule { border-bottom: 1px solid #000; margin-top: 4pt; }

    /* ── Title ──────────────────────────────────────────────────────────── */
    .form-title {
        text-align: center;
        margin: 12pt 0 10pt;
        font-size: 13pt;
        font-weight: bold;
        letter-spacing: 0.2em;
        text-decoration: underline;
    }

    /* ── Identity rows ──────────────────────────────────────────────────── */
    .ident { width: 100%; border-collapse: collapse; margin-top: 6pt; }
    .ident td { vertical-align: bottom; }

    /* A value sitting on the form's answer line. */
    .fill {
        border-bottom: 1px solid #000;
        height: 14pt;
        padding: 0 4pt;
        text-align: center;
        white-space: nowrap;
        overflow: hidden;
    }
    .fill.left { text-align: left; }

    /* The small bold caption under the three Name segments. */
    .cap { text-align: center; font-weight: bold; font-size: 8pt; padding-top: 1pt; }

    /* A short answer line inside a sentence. */
    .fill-inline {
        display: inline-block;
        border-bottom: 1px solid #000;
        text-align: center;
        white-space: nowrap;
        overflow: hidden;
    }

    /* Choice bubble: an empty ring, drawn as a BORDER so it always prints.
       `.on` thickens that same border to half the bubble's width, which
       closes the ring into a SOLID disc — still a border, so it survives
       printing with "Background graphics" off (Chrome's default) exactly as
       the empty ring does. A background colour would not. */
    .bb {
        display: inline-block;
        width: 9pt;
        height: 9pt;
        border: 1px solid #000;
        border-radius: 50%;
    }
    /* width/height 0 so the border is ALL there is: it meets in the middle
       and the disc is solid. The fill is stated as border ALONE because
       Chrome (border-box) and dompdf (content-box) disagree about whether a
       border sits inside a stated width — this is the one form both agree on.
       The background colour underneath is belt-and-braces: it fills the
       hairline seams where dompdf joins its four corner arcs, and with
       "Background graphics" off the border still fills the disc on its own. */
    .bb.on { width: 0; height: 0; border-width: 4.5pt; background-color: #000; }

    /* ── Vitals — three column pairs, as R04 lays them out ──────────────── */
    .vitals { width: 6.1in; margin: 10pt 0 0 0.4in; border-collapse: collapse; }
    .vitals td { vertical-align: bottom; padding-top: 4pt; }
    .vitals td.lbl { white-space: nowrap; padding-right: 3pt; }

    /* ── Physical Signs Disorder of ─────────────────────────────────────── */
    .signs-heading { font-weight: bold; font-style: italic; margin-top: 12pt; }
    /* Column widths: see the colgroup on the table itself. */
    .signs {
        width: 6.1in;
        margin: 5pt 0 0 0.25in;
        border-collapse: collapse;
    }
    .signs td {
        border: 1px solid #000;
        padding: 1.5pt 3pt;
        height: 13pt;
        font-size: 7pt;
    }
    .signs td.sname { font-weight: bold; white-space: nowrap; }
    .signs td.sbox { text-align: center; }
    .signs tr.head td { font-weight: bold; text-align: center; font-size: 7pt; }

    /* A YES/NO box in the signs grid — square, same rules as .bb: `.on`
       thickens the border until the box is solid. */
    .bx {
        display: inline-block;
        width: 7pt;
        height: 7pt;
        border: 1px solid #000;
    }
    .bx.on { width: 0; height: 0; border-width: 3.5pt; background-color: #000; }

    .if-yes { font-style: italic; margin-top: 7pt; }

    /* ── REMARKS — two ruled lines, pre-wrapped and pre-sized server-side.
         Fixed heights in pt so the box cannot move when the type shrinks. ── */
    .remarks { width: 100%; border-collapse: collapse; margin-top: 7pt; }
    .remarks td { vertical-align: bottom; }
    .rline {
        border-bottom: 1px solid #000;
        height: 15pt;
        padding: 0 3pt;
        white-space: nowrap;
        overflow: hidden;
    }
    .rline2 { width: 4.9in; margin-top: 5pt; }

    /* ── Statement rows ─────────────────────────────────────────────────── */
    .row { margin-top: 12pt; }

    .purposes { margin: 5pt 0 0 3.25in; }
    .purposes div { margin-top: 3pt; }

    /* ── Physician block (FR-PRT-04 / D-64) ─────────────────────────────── */
    .physician { width: 2.2in; margin: 18pt 0 0 0.2in; }
    .physician .sig-space { height: 30pt; }   /* blank space for a wet signature */
    .physician .name {
        border-bottom: 1px solid #000;
        height: 14pt;
        text-align: center;
        font-weight: bold;
        white-space: nowrap;
        overflow: hidden;
    }
    .physician .title { padding-left: 10pt; padding-top: 2pt; }
    .physician .license { padding-left: 10pt; }

    .date-line { margin-top: 14pt; }
    .form-code { margin-top: 8pt; font-size: 7.5pt; }
</style>
</head>
{{-- data-hp-print-doc: the print-frame script (partials/print-frame) only
     fires window.print() when the iframe holds THIS document (FR-NRS-05). --}}
<body data-hp-print-doc>
<div class="sheet">

    {{-- ── Letterhead ─────────────────────────────────────────────────── --}}
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

    <table class="office">
        <tr>
            <td class="office-logo">
                <img src="{{ $logos['oswf'] }}" alt="Office of Student Welfare and Formation">
            </td>
            <td class="office-name">
                Office of Student Welfare and Formation<br>
                Health Services Unit
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <div class="form-title">MEDICAL CLEARANCE</div>

    {{-- ── Identity (FR-PRT-02) — no student number and no college: R04 has
         neither field (D-22 / D-25). ──────────────────────────────────── --}}
    <table class="ident">
        <tr>
            <td class="lbl" style="width: 44pt;">Name:</td>
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
            <td class="lbl" style="width: 128pt;">Course, Year &amp; Section:</td>
            <td style="width: 290pt;"><div class="fill">{{ $courseYearSection }}</div></td>
            <td></td>
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
            <td style="width: 44pt;"><div class="fill">{{ $age }}</div></td>
            <td class="lbl" style="width: 44pt; padding-left: 14pt;">Sex:</td>
            <td style="width: 122pt;">
                <span class="bb{{ $sex === 'M' ? ' on' : '' }}"></span> Male
                <span class="bb{{ $sex === 'F' ? ' on' : '' }}" style="margin-left: 6pt;"></span> Female
            </td>
            <td class="lbl" style="width: 72pt;">Civil Status:</td>
            <td>
                <span class="bb{{ $isSingle ? ' on' : '' }}"></span> Single
                <span class="bb{{ $isMarried ? ' on' : '' }}" style="margin-left: 6pt;"></span> Married
            </td>
        </tr>
    </table>

    <table class="ident">
        <tr>
            <td class="lbl" style="width: 80pt;">Date of Birth:</td>
            <td style="width: 150pt;"><div class="fill">{{ $dateOfBirth }}</div></td>
            <td class="lbl" style="width: 92pt; padding-left: 16pt;">Place of Birth:</td>
            <td><div class="fill">{{ $placeOfBirth }}</div></td>
        </tr>
    </table>

    {{-- ── Vitals — the clinic's confirmed copy (D-65), Respiratory Rate
         included. Three column pairs, exactly as R04 lays them out. ────── --}}
    <table class="vitals">
        <tr>
            <td class="lbl">Height:</td>
            <td style="width: 78pt;"><div class="fill">{{ $vitals['height'] }}</div></td>
            <td class="lbl" style="padding-left: 16pt;">Heart Rate:</td>
            <td style="width: 80pt;"><div class="fill">{{ $vitals['heartRate'] }}</div></td>
            <td class="lbl" style="padding-left: 16pt;">Temperature:</td>
            <td style="width: 78pt;"><div class="fill">{{ $vitals['temperature'] }}</div></td>
        </tr>
        <tr>
            <td class="lbl">Weight:</td>
            <td><div class="fill">{{ $vitals['weight'] }}</div></td>
            <td class="lbl" style="padding-left: 16pt;">Blood Pressure:</td>
            <td><div class="fill">{{ $vitals['bloodPressure'] }}</div></td>
            <td class="lbl" style="padding-left: 16pt;">Respiratory Rate:</td>
            <td><div class="fill" style="font-size: 8.5pt;">{{ $vitals['respiratoryRate'] }}</div></td>
        </tr>
    </table>

    {{-- ── Physical Signs Disorder of (D-22 / D-63) — the twelve rows in the
         form's three column groups of four, read DOWN each group. The boxes
         shade from the CLINIC's exam findings (clearance_records.ps_*), never
         from the kiosk questionnaire; an unanswered row stays blank. ───── --}}
    <div class="signs-heading">Physical Signs Disorder of:</div>
    {{-- The colgroup asks for the form's own proportions — a narrow first
         label column (its rows are SKIN…EARS) and wider ones for
         KIDNEY/BLADDER and MENTAL DISORDER: 1.00 + 1.48 + 1.48 +
         (6 x 0.34) = 6.0in, inside the table's 6.1in.

         Deliberately NOT `table-layout: fixed`: dompdf's fixed layout ignores
         these widths and splits the grid into nine equal columns, which
         squeezes the long labels into the YES box. Auto layout sizes the
         columns from their content in BOTH renderers and the two agree. --}}
    <table class="signs">
        <colgroup>
            <col style="width: 1.00in"><col style="width: 0.34in"><col style="width: 0.34in">
            <col style="width: 1.48in"><col style="width: 0.34in"><col style="width: 0.34in">
            <col style="width: 1.48in"><col style="width: 0.34in"><col style="width: 0.34in">
        </colgroup>
        <tr class="head">
            @foreach ($signColumns as $column)
                <td class="sname"></td>
                <td class="sbox">YES</td>
                <td class="sbox">NO</td>
            @endforeach
        </tr>
        @for ($row = 0; $row < 4; $row++)
            <tr>
                @foreach ($signColumns as $column)
                    <td class="sname">{{ $column[$row]['label'] }}</td>
                    <td class="sbox"><span class="bx{{ $column[$row]['yes'] ? ' on' : '' }}"></span></td>
                    <td class="sbox"><span class="bx{{ $column[$row]['no'] ? ' on' : '' }}"></span></td>
                @endforeach
            </tr>
        @endfor
    </table>

    <div class="if-yes">If <b>YES</b>, give details under Remarks.</div>

    {{-- ── REMARKS — the Clinic Notes, already wrapped to these two ruled
         lines and sized to fit by ClearanceDocument::remarks(). ────────── --}}
    <table class="remarks">
        <tr>
            <td class="lbl" style="width: 62pt;">REMARKS:</td>
            <td><div class="rline" style="font-size: {{ $remarks['fontSize'] }}pt;">{{ $remarks['lines'][0] }}</div></td>
        </tr>
    </table>
    <div class="rline rline2" style="font-size: {{ $remarks['fontSize'] }}pt;">{{ $remarks['lines'][1] }}</div>

    {{-- ── Pregnancy — answered at the kiosk (FR-KSK-10); the LMP line fills
         only on a YES (D-22). ─────────────────────────────────────────── --}}
    <div class="row">
        Are you Pregnant
        <span class="bb{{ $isPregnant === true ? ' on' : '' }}"></span> <b>YES</b>
        <span class="bb{{ $isPregnant === false ? ' on' : '' }}" style="margin-left: 6pt;"></span> <b>NO</b>
        <span class="ital" style="margin-left: 12pt;">If <b class="ital">YES</b>, when is the last menstrual period?</span>
        <span class="fill-inline" style="width: 1.3in; font-size: 9pt;">{{ $lastMenstrualPeriod }}</span>
    </div>

    {{-- ── The encoded Result + the batch's purpose (D-62). The kiosk never
         shows Fit/Unfit — this document is where it appears. ──────────── --}}
    <div class="row">
        He/She is physically / mentally
        <span class="bb{{ $result === 'Fit' ? ' on' : '' }}"></span> <b>FIT</b>
        <span class="bb{{ $result === 'Unfit' ? ' on' : '' }}" style="margin-left: 6pt;"></span> <b>UNFIT</b>
        <b>to participate in:</b>
    </div>

    <div class="purposes">
        @foreach ($purposes as $purpose)
            <div><span class="bb{{ $purpose['checked'] ? ' on' : '' }}"></span> {{ $purpose['label'] }}</div>
        @endforeach
        {{-- nowrap + overflow hidden: a long specified event is clipped on its
             line rather than wrapping and growing the page (FR-PRT-05). --}}
        <div>
            <span class="bb{{ $othersChecked ? ' on' : '' }}"></span> {{ $othersLabel }}:
            <span class="fill-inline" style="width: 1.5in; font-size: 8.5pt;">{{ $othersText }}</span>
        </div>
    </div>

    {{-- ── Physician block (FR-PRT-04 / BR-17, D-64) — a blank line for the
         wet signature either way; the name and licence print only on a
         record a PHYSICIAN encoded, and a nurse's record leaves both for the
         physician to fill in by hand. ─────────────────────────────────── --}}
    <div class="physician">
        <div class="sig-space"></div>
        <div class="name">{{ $physicianName }}</div>
        <div class="title">University Physician</div>
        <div class="license">
            License No. <span class="fill-inline" style="width: 0.8in;">{{ $physicianLicense }}</span>
        </div>
    </div>

    {{-- The encode date (today on a pre-save preview) — never an input. --}}
    <div class="date-line">
        <span class="lbl">Date:</span>
        <span class="fill-inline" style="width: 1.4in;">{{ $issuedOn }}</span>
    </div>

    <div class="form-code">{{ $formCode }}</div>

</div>
</body>
</html>
