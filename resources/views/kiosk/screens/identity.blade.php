{{-- Identity confirm (FR-KSK-03) — placeholder. --}}
<section class="kiosk-screen" x-show="state.screen === 'identity'" x-cloak>
    <div class="flex w-full flex-col items-center justify-center gap-3 px-10 text-center">
        <span class="rounded-full bg-hp-peach/40 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-hp-orange">Identity</span>
        <h1 class="text-2xl font-semibold text-hp-slate">Identity Confirm</h1>
        <p class="max-w-md text-sm text-hp-slate/70">Placeholder for FR-KSK-03 (initials avatar, greeting, college/course/year/student number).</p>
        <div class="mt-3 flex gap-3">
            <button type="button" @click="go('welcome')" class="text-sm font-medium text-hp-slate/70 hover:underline">Not you?</button>
            <button type="button" @click="go('consent')" class="rounded-xl bg-hp-orange px-6 py-3 text-sm font-semibold text-hp-white hover:brightness-95">That's me — Continue</button>
        </div>
    </div>
</section>
