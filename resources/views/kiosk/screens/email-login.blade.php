{{-- Email login (FR-KSK-02) — placeholder; QWERTY virtual keyboard comes later. --}}
<section class="kiosk-screen" x-show="state.screen === 'email_login'" x-cloak>
    <div class="flex w-full flex-col items-center justify-center gap-3 px-10 text-center">
        <span class="rounded-full bg-hp-peach/40 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-hp-orange">Email Login</span>
        <h1 class="text-2xl font-semibold text-hp-slate">Email + Password</h1>
        <p class="max-w-md text-sm text-hp-slate/70">Placeholder for FR-KSK-02 (on-screen QWERTY keyboard, password eye toggle).</p>
        <button type="button" @click="go('welcome')" class="mt-3 text-sm font-medium text-hp-orange hover:underline">← Cancel</button>
    </div>
</section>
