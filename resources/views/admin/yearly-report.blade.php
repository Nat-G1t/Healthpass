{{-- ── Yearly Clearance Report (FR-ADM-13, D-81) ────────────────────────────────
     Rendered by dompdf (Admin\YearlyReportController) and DOWNLOADED as a PDF
     — the college files it as a record. The Monthly Report next to it stays a
     print view (D-46); only this report is a PDF.

     dompdf lays the page out in PHP, not in a browser, so this document is
     written in dompdf-safe CSS: tables for layout, no flex or grid, no
     JavaScript, no Tailwind (the Vite build never runs here). Poppins is not
     available to dompdf, so it uses the same sans stack as the letterheads in
     resources/views/forms/.

     Every figure comes from App\Services\YearlyClearanceReport; the five
     counts are taken from the same rows as the table.
──────────────────────────────────────────────────────────────────────────────── --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Yearly Clearance Report — {{ $college->code }} — {{ $year }}</title>
<style>
    /* No `* { margin: 0 }` reset: dompdf applies it to the page box as well,
       wiping the @page margin and printing flush against the paper edge. */
    @page { margin: 14mm; }
    body, p { margin: 0; padding: 0; }
    body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 10pt;
        line-height: 1.4;
        color: #1f2430;
    }

    /* ── Header (same wording as the Monthly Report) ────────────────────── */
    .head { border-bottom: 2px solid #FF8C2A; padding-bottom: 8px; }
    .univ { font-size: 12pt; font-weight: bold; letter-spacing: 0.04em; text-transform: uppercase; }
    .clinic { font-size: 9pt; color: #4B5563; }
    .title { margin-top: 8px; font-size: 15pt; font-weight: bold; color: #C2410C; }
    .college { margin-top: 2px; font-size: 11pt; font-weight: bold; }
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

    /* ── Table ──────────────────────────────────────────────────────────── */
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
    .rows .status, .rows .date { white-space: nowrap; }
    .rows .empty { padding: 10px 6px; color: #6B7280; font-style: italic; }

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
    <p class="college">{{ $college->name }} ({{ $college->code }})</p>
    <p class="period">January 1 – December 31, {{ $year }}</p>

    <p class="generated">
        Generated {{ $generatedAt->format('F j, Y') }} at {{ $generatedAt->format('g:i A') }}
        by {{ $generatedBy }}
    </p>
</div>

<div class="summary">
    <p class="total">Total clearances: <strong>{{ $summary['total'] }}</strong></p>
    <p>
        Fit <strong>{{ $summary['fit'] }}</strong> <span class="sep">&middot;</span>
        Unfit <strong>{{ $summary['unfit'] }}</strong>
    </p>
    <p>
        Male <strong>{{ $summary['male'] }}</strong> <span class="sep">&middot;</span>
        Female <strong>{{ $summary['female'] }}</strong>
    </p>
</div>

<table class="rows">
    <thead>
        <tr>
            <th>Student name</th>
            <th>Status</th>
            <th>Date</th>
            <th>Department</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td class="status">{{ $row['result'] }}</td>
                <td class="date">{{ $row['checkedInAt']->format('M j, Y') }}</td>
                <td>{{ $row['program'] }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="4" class="empty">
                    No clearances were encoded for this college in {{ $year }}.
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

<p class="foot">
    This report covers data captured by HealthPass only — appointments booked in the
    system and vitals captured at the clinic kiosk. Other clinic consultations that never
    passed through HealthPass are not represented.
</p>

</body>
</html>
