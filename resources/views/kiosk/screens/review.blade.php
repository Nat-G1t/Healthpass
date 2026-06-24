{{-- Review (FR-KSK-11) — placeholder. --}}
<section class="kiosk-screen" x-show="state.screen === 'review'" x-cloak>
    <div class="flex w-full flex-col items-center justify-center gap-3 px-10 text-center">
        <span class="rounded-full bg-hp-peach/40 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-hp-orange">Review</span>
        <h1 class="text-2xl font-semibold text-hp-slate">Review &amp; Submit</h1>
        <p class="max-w-md text-sm text-hp-slate/70">Placeholder for FR-KSK-11 (vitals card with flagged items + questionnaire badges).</p>
        <button type="button" @click="go('complete')" class="mt-3 rounded-xl bg-hp-orange px-6 py-3 text-sm font-semibold text-hp-white hover:brightness-95">Submit to Clinic →</button>
    </div>
</section>
