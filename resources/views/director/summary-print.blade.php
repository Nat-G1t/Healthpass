{{-- ── Printable Summary of Medical Cases (FR-ANL-06 / FR-ANL-03) ──────────────
     One-month reproduction of the clinic's official monthly report. Standalone
     HTML document — no app layout, no Vite bundle — because the Analytics page
     loads it in a hidden iframe and calls window.print() on it (same mechanism
     as the nurse clearance print, FR-NRS-05), so it must print clean alone.

     Landscape: 12 college columns + CATEGORY + TOTAL is far too wide for
     portrait. Counts come month-scoped from MedicalCaseSummary — the SAME
     aggregation the on-screen matrix uses. FACULTY and NASA columns from the
     paper form are omitted: HealthPass tracks students only (PRD FR-ANL-02).
──────────────────────────────────────────────────────────────────────────────── --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Summary of Medical Cases — {{ $monthLabel }}</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body {
        font-family: Cambria, 'Book Antiqua', Georgia, 'Times New Roman', serif;
        font-size: 10pt;
        color: #1a1a1a;
        background: #fff;
        max-width: 10.5in;
        margin: 0 auto;
        padding: 0.35in 0.3in;
    }
    @page { size: letter landscape; margin: 10mm; }
    @media print {
        html, body { height: auto; }
        body { padding: 0; max-width: none; }
    }

    b, strong { font-weight: bold; }

    /* ── Letterhead — same seals as the medical clearance form ── */
    .letterhead {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 18px;
        text-align: center;
    }
    .lh-seals { display: flex; align-items: center; gap: 8px; }
    .lh-seals img { height: 58px; }
    .lh-text .republic { font-size: 10pt; }
    .lh-text h1 { font-size: 15pt; font-weight: bold; letter-spacing: 0.01em; }
    .lh-text .former { font-size: 9.5pt; font-style: italic; }
    .lh-text .place { font-size: 9.5pt; }
    .office { text-align: center; margin-top: 6px; line-height: 1.3; }
    .office .unit { font-weight: bold; }
    .header-rule { border-bottom: 1.5px solid #1a1a1a; margin-top: 6px; }

    /* ── Title ── */
    .report-title { text-align: center; margin-top: 12px; }
    .report-title .name { font-size: 12.5pt; font-weight: bold; letter-spacing: 0.06em; }
    .report-title .month { font-size: 11pt; font-weight: bold; margin-top: 2px; }

    /* ── Matrix ── */
    table.summary {
        width: 100%;
        margin-top: 12px;
        border-collapse: collapse;
        table-layout: fixed;
    }
    table.summary th, table.summary td {
        border: 1px solid #1a1a1a;
        padding: 3px 4px;
        vertical-align: middle;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    table.summary thead th {
        font-size: 7pt;
        font-weight: bold;
        text-align: center;
        line-height: 1.15;
    }
    /* CATEGORY column: wide and left-aligned; the number columns are narrow
       and centered. TOTAL slightly wider than a college column. */
    th.cat, td.cat { width: 20%; text-align: left; }
    th.total-col, td.total-col { width: 5.5%; }
    td.num { text-align: center; font-size: 9.5pt; }
    td.cat .cat-name { display: block; font-weight: bold; font-size: 8.5pt; }
    td.cat .cat-sym { display: block; font-size: 7pt; font-style: italic; line-height: 1.2; }

    /* Totals: the TOTAL column, and the bottom TOTAL row. */
    td.total, tfoot td { font-weight: bold; }
    tfoot td { background: #f0f0f0; }
    tfoot td.cat { text-align: center; letter-spacing: 0.08em; }
</style>
    @include('partials.favicon')
</head>
{{-- data-hp-print-doc: the Analytics page's print script only fires
     window.print() when the iframe holds THIS document. --}}
<body data-hp-print-doc>

    {{-- ── Letterhead ─────────────────────────────────────────────────────── --}}
    <div class="letterhead">
        <div class="lh-seals">
            <img src="{{ asset('images/form/pamsu-seal.png') }}" alt="Pampanga State University Seal">
            <img src="{{ asset('images/form/oswf.png') }}" alt="Office of Student Welfare and Formation">
        </div>
        <div class="lh-text">
            <p class="republic">Republic of the Philippines</p>
            <h1>PAMPANGA STATE UNIVERSITY</h1>
            <p class="former">(formerly Don Honorio Ventura State University)</p>
            <p class="place">Bacolor, Pampanga</p>
        </div>
        <div class="lh-seals">
            <img src="{{ asset('images/form/bagong-pilipinas.png') }}" alt="Bagong Pilipinas">
        </div>
    </div>
    <div class="office">
        <p>Office of Student Welfare and Formation</p>
        <p class="unit">HEALTH SERVICES UNIT</p>
    </div>
    <div class="header-rule"></div>

    {{-- ── Title + month ──────────────────────────────────────────────────── --}}
    <div class="report-title">
        <p class="name">SUMMARY OF MEDICAL CASES</p>
        <p class="month">{{ $monthLabel }}</p>
    </div>

    {{-- ── Matrix (FR-ANL-03): 8 systems × 12 colleges + TOTAL ────────────────
         Rows lettered A–H in CASE_CATEGORIES order (NOT sorted by volume — the
         printed form's rows are fixed); each cell is the month's count. --}}
    <table class="summary">
        <thead>
            <tr>
                <th class="cat">CATEGORY</th>
                @foreach ($colleges as $college)
                    <th>{{ $college->name }}<br>({{ $college->code }})</th>
                @endforeach
                <th class="total-col">TOTAL</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($categories as $i => $category)
                <tr>
                    <td class="cat">
                        <span class="cat-name">{{ chr(65 + $i) }}. {{ strtoupper($category) }}</span>
                        <span class="cat-sym">({{ $symptoms[$category] ?? '' }})</span>
                    </td>
                    @foreach ($colleges as $college)
                        <td class="num">{{ $counts[$college->id][$category] ?? 0 }}</td>
                    @endforeach
                    <td class="num total total-col">{{ $categoryTotals[$category] ?? 0 }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="cat">TOTAL</td>
                @foreach ($colleges as $college)
                    <td class="num">{{ $totals[$college->id] ?? 0 }}</td>
                @endforeach
                <td class="num total-col">{{ $grandTotal }}</td>
            </tr>
        </tfoot>
    </table>

</body>
</html>
