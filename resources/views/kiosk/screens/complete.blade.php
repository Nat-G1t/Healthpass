{{-- Complete (FR-KSK-13) — placeholder; 12s auto-reset countdown comes later. --}}
<section class="kiosk-screen" x-show="state.screen === 'complete'" x-cloak>
    <div class="flex w-full flex-col items-center justify-center gap-3 px-10 text-center">
        <span class="rounded-full bg-hp-peach/40 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-hp-orange">Complete</span>
        <h1 class="text-2xl font-semibold text-hp-slate">Submitted!</h1>
        <p class="max-w-md text-sm text-hp-slate/70">Placeholder for FR-KSK-13 (success check, "proceed to the nurse's station", 12s auto-reset).</p>
        <button type="button" @click="reset()" class="mt-3 rounded-xl bg-hp-orange px-6 py-3 text-sm font-semibold text-hp-white hover:brightness-95">Done</button>
    </div>
</section>
