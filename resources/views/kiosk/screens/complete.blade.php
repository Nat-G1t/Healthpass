{{-- Complete (FR-KSK-13): success check, "proceed to the nurse's station", a
     countdown pill that auto-resets to Welcome after 12s, and an instant Done
     tap. All session state is cleared on reset (handled by reset() → freshState).
     Per FR-KSK-14/D-? the kiosk shows NO Fit/Unfit here — only that it was sent. --}}
<section class="kiosk-screen" x-show="state.screen === 'complete'" x-cloak>
    <div class="flex w-full flex-col items-center justify-center gap-6 px-10 text-center">

        {{-- Success check --}}
        <div class="flex h-28 w-28 items-center justify-center rounded-full bg-emerald-50">
            <svg class="h-16 w-16 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20 6 9 17l-5-5" />
            </svg>
        </div>

        <div class="flex flex-col gap-2">
            <h1 class="text-4xl font-semibold text-hp-slate">Submitted!</h1>
            <p class="max-w-lg text-lg text-hp-slate/70">
                Your vitals and screening have been sent. Please proceed to the
                <span class="font-semibold text-hp-slate">nurse's station</span>.
            </p>
        </div>

        {{-- Server-minted reference (HP-YYYY-####). --}}
        <p x-show="state.submit.reference" x-cloak class="text-base text-hp-slate/50">
            Reference <span class="font-semibold text-hp-slate/70" x-text="state.submit.reference"></span>
        </p>

        {{-- Auto-reset countdown pill (FR-KSK-13). --}}
        <span class="rounded-full bg-hp-peach/40 px-5 py-2 text-sm font-semibold uppercase tracking-widest text-hp-orange">
            Returning to start in <span x-text="completeCountdown"></span>s
        </span>

        {{-- Instant reset. --}}
        <button
            type="button"
            @click="reset()"
            class="mt-2 rounded-2xl bg-hp-orange px-12 py-4 text-lg font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98]"
        >Done</button>
    </div>
</section>
