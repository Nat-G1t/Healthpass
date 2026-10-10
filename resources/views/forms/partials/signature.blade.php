{{-- ── D-134: a signer's uploaded signature, sitting on the line below it ───
     Used by both official forms (medical-clearance / medical-assessment),
     which each style `.sig-box` — so dompdf-safe like them: a one-cell TABLE
     bottom-aligned at a fixed height, no flex, no grid.

     $signature is App\Support\Signature::forPrint(): the base64 data URI plus
     a width and height already fitted to the box, so the browser print and
     the PDF draw it at the same size. Null (no signature on file) leaves the
     cell empty — the blank line for a wet signature, as before D-134. --}}
<table class="sig-box">
    <tr>
        <td>
            @if ($signature)
                <img src="{{ $signature['src'] }}" alt="Signature"
                     style="width: {{ $signature['width'] }}pt; height: {{ $signature['height'] }}pt;">
            @endif
        </td>
    </tr>
</table>
