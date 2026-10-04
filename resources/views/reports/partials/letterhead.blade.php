{{-- ── PSU letterhead for the HealthPass reports (D-104) ─────────────────────
     The official letterhead the Medical Assessment form prints (D-71):
     Republic of the Philippines / PAMPANGA STATE UNIVERSITY / (former Don
     Honorio Ventura State University) on the left, the PSU seal and Bagong
     Pilipinas on the right, a thin black rule underneath. It tops page 1 of
     the Monthly Clinic Report (admin + Director) and both Yearly Report PDFs.

     A COPY of the form's markup and CSS, not something the form includes:
     the official forms (resources/views/forms/) must keep matching their PSU
     revisions exactly (D-67, D-71), so they are never re-wired to this file.

     Two renderers read it — Chrome (the monthly report's print view) and
     dompdf (the yearly PDFs) — so it is dompdf-safe: a table for the two
     columns, no flex or grid, Arial for the text, the logos as base64 data:
     URIs. The CSS travels with the markup, scoped under .hp-letterhead, so a
     report only has to @include it; the td/table rules also undo the padding,
     borders and margins the reports' own table styles would give these cells.

     Expects $logos = App\Support\ClearanceDocument::letterheadLogos().
──────────────────────────────────────────────────────────────────────────── --}}
<style>
    .hp-letterhead { margin-bottom: 12pt; color: #000; line-height: 1.22; }
    .hp-letterhead table { width: 100%; margin: 0; border-collapse: collapse; }
    .hp-letterhead td {
        padding: 0; border: 0; background: none;
        vertical-align: top; text-align: left;
    }
    .hp-letterhead .republic { font-family: Arial, Helvetica, sans-serif; font-size: 9.5pt; }
    .hp-letterhead .university {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 18pt;
        letter-spacing: 0.03em;
        line-height: 1.15;
    }
    .hp-letterhead .former { font-family: Arial, Helvetica, sans-serif; font-size: 9pt; }
    .hp-letterhead td.lh-logos { text-align: right; white-space: nowrap; }
    .hp-letterhead img.seal { height: 42pt; }
    .hp-letterhead img.bagong { height: 50pt; }
    .hp-letterhead .rule { border-bottom: 1px solid #000; margin-top: 4pt; }
</style>
<div class="hp-letterhead">
    <table>
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
</div>
