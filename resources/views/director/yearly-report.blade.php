{{-- ── Director's Yearly Clearance Report (D-94) ─────────────────────────────────
     Rendered by dompdf (Director\YearlyReportController) and DOWNLOADED as a
     PDF. Counts only — no student is named: every college ("department") has
     a line (took clearance, Fit, Unfit, Male, Female) with its programs'
     lines underneath, zeros included.

     A span of years opens with the span's totals by college, then gives each
     year its own section starting on a new page. One year prints just that
     year's section.

     Same dompdf-safe CSS rules as the College Admin's report
     (resources/views/admin/yearly-report.blade.php): tables for layout, no
     flex or grid, no JavaScript, no Tailwind.

     Every figure comes from App\Services\DirectorYearlyReport; every total is
     the lines under it added up (App\Support\ClearanceCounts).
──────────────────────────────────────────────────────────────────────────────── --}}
@php
    $isSpan = count($years) > 1;
    $firstYear = $years[0]['year'];
    $lastYear = $years[count($years) - 1]['year'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Yearly Clearance Report — All Colleges — {{ $isSpan ? $firstYear.'–'.$lastYear : $firstYear }}</title>
<style>
    /* No `* { margin: 0 }` reset: dompdf applies it to the page box as well. */
    @page { margin: 14mm; }
    body, p { margin: 0; padding: 0; }
    body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 10pt;
        line-height: 1.4;
        color: #1f2430;
    }

    /* ── Header (same wording as the College Admin's report) ────────────── */
    .head { border-bottom: 2px solid #FF8C2A; padding-bottom: 8px; }
    .univ { font-size: 12pt; font-weight: bold; letter-spacing: 0.04em; text-transform: uppercase; }
    .clinic { font-size: 9pt; color: #4B5563; }
    .title { margin-top: 8px; font-size: 15pt; font-weight: bold; color: #C2410C; }
    .scope { margin-top: 2px; font-size: 11pt; font-weight: bold; }
    .period { font-size: 10pt; color: #4B5563; }
    .generated { margin-top: 6px; font-size: 8.5pt; color: #6B7280; }

    /* ── Summary ────────────────────────────────────────────────────────── */
    .summary {
        margin-top: 14px; padding: 8px 12px;
        background: #F6F2ED; border: 1px solid #E6DED4;
    }
    .summary p { margin-top: 2px; }
    .summary .total { font-size: 12pt; }
    .summary .sep { color: #9CA3AF; }

    /* ── Tables ─────────────────────────────────────────────────────────── */
    table.rows { width: 100%; border-collapse: collapse; margin-top: 14px; }
    .rows th, .rows td { padding: 4px 6px; text-align: left; vertical-align: top; }
    /* The header row repeats on every page; a row never splits across two. */
    .rows thead { display: table-header-group; }
    .rows tr { page-break-inside: avoid; }
    .rows thead th {
        border-bottom: 1px solid #9CA3AF;
        font-size: 8.5pt; font-weight: bold; text-transform: uppercase; color: #4B5563;
    }
    .rows tbody td { border-bottom: 1px solid #EAE6E0; font-size: 9pt; }
    .rows .num { width: 11%; text-align: right; white-space: nowrap; }
    /* A college's own line: shaded and bold, so its programs read as under it. */
    .rows tr.college td { background: #F6F2ED; font-weight: bold; border-top: 1px solid #D8D2CA; }
    .rows td.program { padding-left: 18px; color: #374151; }
    .rows tr.total td { border-top: 1px solid #9CA3AF; border-bottom: 0; font-weight: bold; }

    /* ── One section per year ───────────────────────────────────────────── */
    .year { page-break-before: always; }
    .year-title { font-size: 13pt; font-weight: bold; color: #C2410C; }

    /* ── Footer ─────────────────────────────────────────────────────────── */
    .foot {
        margin-top: 18px; padding-top: 6px; border-top: 1px solid #D8D2CA;
        font-size: 8pt; color: #6B7280;
    }
</style>
</head>
<body>

<div class="head">
    <p class="univ">Pampanga State University</p>
    <p class="clinic">University Clinic &middot; HealthPass</p>

    <p class="title">Yearly Clearance Report</p>
    <p class="scope">All colleges and programs</p>
    <p class="period">
        @if ($isSpan)
            January 1, {{ $firstYear }} – December 31, {{ $lastYear }}
        @else
            January 1 – December 31, {{ $firstYear }}
        @endif
    </p>

    <p class="generated">
        Generated {{ $generatedAt->format('F j, Y') }} at {{ $generatedAt->format('g:i A') }}
        by {{ $generatedBy }}
    </p>
</div>

{{-- ── The span's totals by college — only when more than one year was picked ── --}}
@if ($isSpan)
    <div class="summary">
        <p class="total">Took clearance, {{ $firstYear }}–{{ $lastYear }}: <strong>{{ $spanSummary['total'] }}</strong></p>
        <p>
            Fit <strong>{{ $spanSummary['fit'] }}</strong> <span class="sep">&middot;</span>
            Unfit <strong>{{ $spanSummary['unfit'] }}</strong>
        </p>
        <p>
            Male <strong>{{ $spanSummary['male'] }}</strong> <span class="sep">&middot;</span>
            Female <strong>{{ $spanSummary['female'] }}</strong>
        </p>
    </div>

    <table class="rows">
        <thead>
            <tr>
                <th>College</th>
                <th class="num">Took clearance</th>
                <th class="num">Fit</th>
                <th class="num">Unfit</th>
                <th class="num">Male</th>
                <th class="num">Female</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($spanColleges as $college)
                <tr>
                    <td>{{ $college['name'] }} ({{ $college['code'] }})</td>
                    <td class="num">{{ $college['counts']['total'] }}</td>
                    <td class="num">{{ $college['counts']['fit'] }}</td>
                    <td class="num">{{ $college['counts']['unfit'] }}</td>
                    <td class="num">{{ $college['counts']['male'] }}</td>
                    <td class="num">{{ $college['counts']['female'] }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>All colleges</td>
                <td class="num">{{ $spanSummary['total'] }}</td>
                <td class="num">{{ $spanSummary['fit'] }}</td>
                <td class="num">{{ $spanSummary['unfit'] }}</td>
                <td class="num">{{ $spanSummary['male'] }}</td>
                <td class="num">{{ $spanSummary['female'] }}</td>
            </tr>
        </tbody>
    </table>
@endif

{{-- ── One section per year: each college's line, its programs underneath ── --}}
@foreach ($years as $year)
    <div class="{{ $isSpan ? 'year' : '' }}">
        @if ($isSpan)
            <p class="year-title">{{ $year['year'] }}</p>
        @endif

        <div class="summary">
            <p class="total">Took clearance: <strong>{{ $year['summary']['total'] }}</strong></p>
            <p>
                Fit <strong>{{ $year['summary']['fit'] }}</strong> <span class="sep">&middot;</span>
                Unfit <strong>{{ $year['summary']['unfit'] }}</strong>
                <span class="sep">&middot;</span>
                Male <strong>{{ $year['summary']['male'] }}</strong> <span class="sep">&middot;</span>
                Female <strong>{{ $year['summary']['female'] }}</strong>
            </p>
        </div>

        <table class="rows">
            <thead>
                <tr>
                    <th>College / Program</th>
                    <th class="num">Took clearance</th>
                    <th class="num">Fit</th>
                    <th class="num">Unfit</th>
                    <th class="num">Male</th>
                    <th class="num">Female</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($year['colleges'] as $college)
                    <tr class="college">
                        <td>{{ $college['name'] }} ({{ $college['code'] }})</td>
                        <td class="num">{{ $college['counts']['total'] }}</td>
                        <td class="num">{{ $college['counts']['fit'] }}</td>
                        <td class="num">{{ $college['counts']['unfit'] }}</td>
                        <td class="num">{{ $college['counts']['male'] }}</td>
                        <td class="num">{{ $college['counts']['female'] }}</td>
                    </tr>
                    @foreach ($college['programs'] as $program)
                        <tr>
                            <td class="program">{{ $program['program'] }}</td>
                            <td class="num">{{ $program['counts']['total'] }}</td>
                            <td class="num">{{ $program['counts']['fit'] }}</td>
                            <td class="num">{{ $program['counts']['unfit'] }}</td>
                            <td class="num">{{ $program['counts']['male'] }}</td>
                            <td class="num">{{ $program['counts']['female'] }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
@endforeach

<p class="foot">
    Counts encoded clearances (Fit or Unfit) under the college and program recorded at each
    kiosk visit; a student cleared twice counts twice. This report covers data captured by
    HealthPass only — other clinic consultations that never passed through HealthPass are
    not represented.
</p>

</body>
</html>
