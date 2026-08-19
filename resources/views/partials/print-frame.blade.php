{{-- Hidden print frame — print WITHOUT leaving the page (FR-NRS-05, FR-ADM-09).

     The browser's print dialog IS the preview, so nothing here needs its own
     tab: the document to print is loaded into a 0×0 iframe and that frame is
     printed in place. The page underneath keeps its scroll position, its
     filters and its form state.

     Any element marked `data-print-trigger` arms the frame; whatever loads
     into it next gets printed — but ONLY if that document's <body> carries
     `data-hp-print-doc`. That marker is the safety catch: a validation
     redirect, an error page or a login screen landing in the frame must never
     open a print dialog.

     Two ways to load the frame:
       - a submit button with `formtarget="hp-print-frame"` (or a form with
         `target="hp-print-frame"`) — for POSTs, like the nurse's Reprint,
         which re-stamps `printed_at` and so must not be a plain link; or
       - a LINK carrying `data-print-trigger`, whose href is loaded into the
         frame instead of navigating — for GETs, like the College Admin's
         monthly report. The href stays real, so middle-click / "open in new
         tab" still works and those documents print themselves on arrival.

     0×0 fixed positioning rather than `display: none` — Chrome will not
     reliably print a frame it never rendered.

     Lifted out of nurse/encode.blade.php's inline copy when the second and
     third caller appeared. The encode screen deliberately keeps its own
     version: it also flips a hidden "printed" field that belongs to that
     screen's Save & Close flow, and forking that is what this partial exists
     to avoid, not to cause.
--}}
<iframe name="hp-print-frame" id="hp-print-frame" title="Print preview"
        style="position: fixed; right: 0; bottom: 0; width: 0; height: 0; border: 0;"></iframe>

@push('scripts')
<script>
    (function () {
        const frame = document.getElementById('hp-print-frame');
        if (!frame) return;

        let armed = false;

        document.querySelectorAll('[data-print-trigger]').forEach((el) => {
            el.addEventListener('click', (event) => {
                armed = true;

                // A submit button already targets the frame via formtarget —
                // let the form submit. A link would navigate the whole page,
                // so load its href into the frame instead. location.replace
                // (rather than frame.src) so clicking the SAME link twice
                // still re-loads and re-fires the print.
                if (el.tagName === 'A' && el.getAttribute('href')) {
                    event.preventDefault();
                    frame.contentWindow.location.replace(el.href);
                }
            });
        });

        frame.addEventListener('load', () => {
            if (!armed) return;   // skips the frame's initial about:blank load
            armed = false;

            const doc = frame.contentDocument;
            if (!doc || !doc.body || !doc.body.hasAttribute('data-hp-print-doc')) return;

            frame.contentWindow.focus();
            frame.contentWindow.print();
        });
    })();
</script>
@endpush
