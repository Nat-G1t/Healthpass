{{-- Student "Start over?" confirmation (D-89), behind the top-left Cancel.
     "Start over" runs reset() — the same wipe as the 90 s idle reset — so the
     kiosk goes straight back to the scan screen for the next person. "Keep
     going" or a tap outside just closes it.

     Built like the manual-entry pad, the kiosk's lightest overlay, because the
     Pi 4 lags under heavy effects: a flat scrim, NOT backdrop-blur (a
     full-viewport backdrop-filter re-blurs the whole 1080×1920 panel every
     frame and drops the Pi to ~10 fps), a light shadow-md, and buttons that
     only transition their colours. --}}
<div
    x-show="state.cancel.open"
    x-cloak
    class="absolute inset-0 z-40 flex items-center justify-center bg-hp-slate/55 px-8"
    @click.self="closeCancel()"
>
    <div class="hp-anim-sheet-up w-full max-w-md rounded-2xl bg-hp-white p-6 text-center shadow-md">
        <p class="text-xl font-semibold text-hp-slate">Start over?</p>
        <p class="mt-2 text-base leading-relaxed text-hp-slate/70">
            Everything you entered will be cleared and nothing is sent to the
            clinic. Scan your ID again when you're ready.
        </p>

        <div class="mt-5 flex gap-2.5">
            <button
                type="button"
                @click="closeCancel()"
                class="flex-1 rounded-lg border border-hp-slate/20 py-3.5 text-base font-medium text-hp-slate transition-colors hover:bg-hp-slate/5"
            >Keep going</button>
            <button
                type="button"
                @click="reset()"
                class="flex-1 rounded-lg bg-hp-orange py-3.5 text-base font-semibold text-hp-white transition-colors hover:brightness-95"
            >Start over</button>
        </div>
    </div>
</div>
