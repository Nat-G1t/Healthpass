{{-- Privacy consent (FR-KSK-04) — placeholder. --}}
<section class="kiosk-screen" x-show="state.screen === 'consent'" x-cloak>
    <div class="flex w-full flex-col items-center justify-center gap-3 px-10 text-center">
        <span class="rounded-full bg-hp-peach/40 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-hp-orange">Consent</span>
        <h1 class="text-2xl font-semibold text-hp-slate">Privacy Consent</h1>
        <p class="max-w-md text-sm text-hp-slate/70">Placeholder for FR-KSK-04 (RA 10173 text; Decline resets and stores nothing).</p>
        <div class="mt-3 flex gap-3">
            <button type="button" @click="go('welcome')" class="text-sm font-medium text-hp-slate/70 hover:underline">Decline</button>
            <button type="button" @click="go('vitals')" class="rounded-xl bg-hp-orange px-6 py-3 text-sm font-semibold text-hp-white hover:brightness-95">I Agree — Proceed</button>
        </div>
    </div>
</section>
