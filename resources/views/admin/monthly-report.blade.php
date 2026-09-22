{{-- ── Printable Monthly Clinic Report (FR-ADM-09, D-46) ───────────────────────
     The artifact a college HANDS OVER, as opposed to the screen it looks at
     (FR-ADM-08 / admin/analytics.blade.php). Same six card builders, same
     scope, rendered as TABLES: charts do not print reliably and a report has
     to be readable on paper.

     Standalone HTML document — no app sidebar, no Vite bundle, all CSS inline
     — the same shape as nurse/print.blade.php, because the page's only job is
     to print clean on its own. It is loaded into the hidden print frame on
     the analytics page (partials/print-frame), which prints it in place — the
     admin never leaves Analytics and the print dialog is the preview.

     Every number is server-computed by App\Services\ClinicAnalytics under the
     SAME scope as the on-screen page, so the printout cannot disagree with it.
     Thresholds ride in on $flagTiles' captions, which the service reads from
     config('healthpass.thresholds') — never hardcoded here.
──────────────────────────────────────────────────────────────────────────────── --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Monthly Clinic Report — {{ $college->code }} — {{ $monthLabel }}</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body {
        font-family: Poppins, 'Segoe UI', Arial, Helvetica, sans-serif;
        font-size: 10pt;
        line-height: 1.45;
        color: #1f2430;
        background: #fff;
        max-width: 7.4in;   /* fits inside A4's 210mm AND Letter's 8.5in */
        margin: 0 auto;
        padding: 0.4in 0.3in;
    }

    /* No `size:` on purpose — the report may run past one page, and it has to
       be clean on BOTH A4 and Letter, so the paper stays the print dialog's
       choice. A4 is the narrower sheet and therefore the width constraint;
       the layout is fluid inside it. */
    @page { margin: 14mm; }

    @media print {
        body { max-width: none; padding: 0; }
        /* Repeat table headers on every sheet and never split a row. */
        thead { display: table-header-group; }
        tr, .block { break-inside: avoid; page-break-inside: avoid; }
    }

    /* ── Header ─────────────────────────────────────────────────────────── */
    .head { border-bottom: 2px solid #FF8C2A; padding-bottom: 10px; }
    .univ {
        font-size: 12pt; font-weight: 700; letter-spacing: 0.04em;
        text-transform: uppercase;
    }
    .clinic { font-size: 9pt; color: #4B5563; }
    .title { margin-top: 10px; font-size: 15pt; font-weight: 700; color: #C2410C; }
    .college { margin-top: 2px; font-size: 11pt; font-weight: 600; }
    .period { margin-top: 1px; font-size: 10pt; color: #4B5563; }
    .filter {
        display: inline-block; margin-top: 5px; padding: 1px 7px;
        border: 1px solid #FF8C2A; border-radius: 9px;
        font-size: 8.5pt; font-weight: 600; color: #C2410C;
    }
    .generated { margin-top: 7px; font-size: 8.5pt; color: #6B7280; }

    /* ── Summary line ───────────────────────────────────────────────────── */
    .summary {
        margin-top: 14px; padding: 9px 12px;
        background: #F6F2ED; border: 1px solid #E6DED4; border-radius: 6px;
        font-size: 10pt;
    }
    .summary strong { font-size: 12pt; }
    .summary .sep { color: #9CA3AF; margin: 0 8px; }

    /* ── Sections + tables ──────────────────────────────────────────────── */
    .block { margin-top: 18px; }
    h2 {
        font-size: 10.5pt; font-weight: 700; color: #1f2430;
        border-bottom: 1px solid #D8D2CA; padding-bottom: 3px;
    }
    .note { margin-top: 3px; font-size: 8.5pt; color: #6B7280; }

    table { width: 100%; border-collapse: collapse; margin-top: 7px; }
    th, td { padding: 4px 8px; text-align: left; vertical-align: top; }
    thead th {
        border-bottom: 1px solid #9CA3AF;
        font-size: 8.5pt; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.03em; color: #4B5563;
    }
    tbody td { border-bottom: 1px solid #EAE6E0; }
    tbody tr:nth-child(even) td { background: #FAF8F5; }
    tfoot td { border-top: 2px solid #9CA3AF; font-weight: 700; background: #fff; }
    .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .sub { display: block; font-size: 8pt; color: #6B7280; font-weight: 400; }
    .empty { padding: 8px; font-size: 9pt; color: #6B7280; font-style: italic; }

    /* ── Footer ─────────────────────────────────────────────────────────── */
    .foot {
        margin-top: 22px; padding-top: 8px; border-top: 1px solid #D8D2CA;
        font-size: 8pt; color: #6B7280;
    }
</style>
    @include('partials.favicon')
</head>
{{-- data-hp-print-doc: partials/print-frame only fires the print dialog when
     the frame holds THIS document — never a redirect or an error page. --}}
<body data-hp-print-doc>

{{-- ── Header (FR-ADM-09) ─────────────────────────────────────────────────
     The printout has to stand alone on a desk: whose report, for which month,
     under which filter, produced by whom and when. --}}
<div class="head">
    <p class="univ">Pampanga State University</p>
    <p class="clinic">University Clinic &middot; HealthPass</p>

    <h1 class="title">Monthly Clinic Report</h1>
    <p class="college">{{ $college->name }} ({{ $college->code }})</p>
    <p class="period">{{ $monthLabel }}</p>

    @if ($selectedProgram !== null)
        {{-- A filtered report must SAY it is filtered, or the reader takes
             one program's figures for the whole college's. --}}
        <p><span class="filter">Filtered to: {{ $selectedProgram }}</span></p>
    @endif

    <p class="generated">
        Generated {{ $generatedAt->format('F j, Y') }} at {{ $generatedAt->format('g:i A') }}
        by {{ $generatedBy }}
    </p>
</div>

{{-- ── Summary line ──────────────────────────────────────────────────────── --}}
<p class="summary">
    <strong>{{ $totalVisits }}</strong> total visits
</p>

{{-- ── Clinic Visits by Program (FR-ADM-08 as printed) ───────────────────── --}}
<div class="block">
    <h2>Clinic Visits by Program</h2>
    <p class="note">
        Kiosk check-ins, counted under the program recorded at capture.
        Programs with no visits this month are listed with zeros.
    </p>
    <table>
        <thead>
            <tr>
                <th>Program</th>
                <th class="num">Visits</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($programRows as $row)
                <tr>
                    <td>{{ $row['program'] }}</td>
                    <td class="num">{{ $row['visits'] }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">No programs are listed for this college.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="num">{{ $totalVisits }}</td>
            </tr>
        </tfoot>
    </table>
</div>

{{-- ── Visits by Purpose ─────────────────────────────────────────────────── --}}
<div class="block">
    <h2>Visits by Purpose</h2>
    <p class="note">
        Why students came, from the linked appointment. A visit with none recorded counts as Not specified.
    </p>
    <table>
        <thead>
            <tr>
                <th>Purpose</th>
                <th class="num">Visits</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($purposeRows as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ $row['count'] }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">No medical visits recorded for this month.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- ── Vital-Sign Flags (FR-ANL-10 as printed) ────────────────────────────
     The threshold captions arrive on $flagTiles['sub'], which the service
     builds from config('healthpass.thresholds') — never restated here. --}}
<div class="block">
    <h2>Vital-Sign Flags</h2>
    <p class="note">
        Rule-based screening signals, not diagnoses.
        Rate = share of the {{ $screenings }} kiosk screenings captured this month.
    </p>
    <table>
        <thead>
            <tr>
                <th>Flag</th>
                <th class="num">Count</th>
                <th class="num">Rate</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($flagTiles as $tile)
                <tr>
                    <td>
                        {{ $tile['label'] }}
                        <span class="sub">{{ $tile['sub'] }}</span>
                    </td>
                    <td class="num">{{ $tile['count'] }}</td>
                    <td class="num">{{ number_format($tile['rate'], 1) }}%</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- ── BMI Distribution (FR-ANL-12 as printed) ───────────────────────────── --}}
<div class="block">
    <h2>BMI Distribution</h2>
    <p class="note">
        Rule-based buckets of the {{ $bmiTotal }} captured screenings — descriptive only.
    </p>
    <table>
        <thead>
            <tr>
                <th>Category</th>
                <th class="num">Students</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($bmiRows as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ $row['count'] }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="num">{{ $bmiTotal }}</td>
            </tr>
        </tfoot>
    </table>
</div>

{{-- ── Students Screened by Sex (FR-ANL-04 as printed) ───────────────────── --}}
<div class="block">
    <h2>Students Screened by Sex</h2>
    <p class="note">Captured kiosk visits, counted once per visit.</p>
    <table>
        <thead>
            <tr>
                <th>Sex</th>
                <th class="num">Screened</th>
                <th class="num">Share</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($bySex as $slice)
                <tr>
                    <td>{{ $slice['label'] }}</td>
                    <td class="num">{{ $slice['count'] }}</td>
                    <td class="num">{{ $slice['percent'] }}%</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="num">{{ $totalScreened }}</td>
                <td class="num">{{ $totalScreened > 0 ? '100%' : '0%' }}</td>
            </tr>
        </tfoot>
    </table>
</div>

{{-- ── Flagged Vitals by Sex (FR-ANL-14 as printed) ──────────────────────── --}}
<div class="block">
    <h2>Flagged Vitals by Sex</h2>
    <p class="note">Flagged readings, split by the student's profile sex.</p>
    <table>
        <thead>
            <tr>
                <th>Flag</th>
                <th class="num">Male</th>
                <th class="num">Female</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($flagsBySexRows as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ $row['male'] }}</td>
                    <td class="num">{{ $row['female'] }}</td>
                    <td class="num">{{ $row['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- ── Footer: the honesty line D-32 was decided on ──────────────────────── --}}
<p class="foot">
    This report covers data captured by HealthPass only — appointments booked in the
    system and vitals captured at the clinic kiosk. Other clinic consultations that never
    passed through HealthPass are not represented.
</p>

<script>
    // Inside the analytics page's hidden print frame, the PARENT fires the
    // dialog once this document has loaded — self-firing here as well would
    // open it twice. Opened directly in a tab instead (middle-click, a
    // bookmarked URL), there is no parent to do it, so print ourselves.
    // `load` rather than DOMContentLoaded: the sheet must be fully laid out
    // before the preview snapshots it.
    if (window.self === window.top) {
        window.addEventListener('load', function () { window.print(); });
    }
</script>

</body>
</html>
