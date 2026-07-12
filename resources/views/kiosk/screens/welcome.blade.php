{{-- Welcome (FR-KSK-01): scan-your-ID on top, email fallback below. Stacked
     vertically for the 1080×1920 portrait panel (was side-by-side columns on
     the old landscape screen). --}}
<section class="kiosk-screen" x-show="state.screen === 'welcome'" x-cloak>
    <div class="flex w-full flex-col items-center justify-center px-10 text-center">
        {{-- ── Top: pulsing QR target ───────────────────────────────────────── --}}
        <div class="relative mb-6 flex h-44 w-44 items-center justify-center">
            {{-- pulse ring --}}
            <span class="k-pulse-ring absolute inset-0 rounded-3xl border-4 border-hp-orange"></span>
            {{-- target frame --}}
            <div class="relative flex h-44 w-44 items-center justify-center rounded-3xl border-4 border-hp-orange bg-hp-white">
                <svg class="h-20 w-20 text-hp-orange" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7" rx="1"></rect>
                    <rect x="14" y="3" width="7" height="7" rx="1"></rect>
                    <rect x="3" y="14" width="7" height="7" rx="1"></rect>
                    <path d="M14 14h3v3M21 14v7h-7v-3"></path>
                </svg>
            </div>
        </div>

        <h1 class="text-3xl font-semibold text-hp-slate">Tap to Scan Your ID</h1>
        <p class="mt-3 max-w-md text-base text-hp-slate/70">
            Hold your student ID's QR code up to the scanner to begin.
        </p>

        {{-- Inline scan feedback (sending / error) --}}
        <div class="mt-4 h-8">
            <p x-show="state.scan.status === 'sending'" x-cloak class="text-base font-medium text-hp-orange">
                Reading your ID…
            </p>
            <p x-show="state.scan.status === 'error'" x-cloak class="text-base font-medium text-red-600" x-text="state.scan.error"></p>
        </div>

        {{-- ── Divider ──────────────────────────────────────────────────────── --}}
        <div class="my-8 flex w-full max-w-md items-center px-6">
            <span class="h-px flex-1 bg-hp-slate/15"></span>
            <span class="mx-4 text-sm font-medium uppercase tracking-widest text-hp-slate/40">or</span>
            <span class="h-px flex-1 bg-hp-slate/15"></span>
        </div>

        {{-- ── Bottom: email login fallback ─────────────────────────────────── --}}
        <h2 class="text-2xl font-semibold text-hp-slate">Lost ID?</h2>
        <p class="mt-2 mb-6 max-w-md text-base text-hp-slate/70">
            No problem — sign in with your HealthPass email and password instead.
        </p>
        <button
            type="button"
            @click="goEmailLogin()"
            class="rounded-2xl bg-hp-orange px-12 py-5 text-lg font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98]"
        >
            Log in with email
        </button>
    </div>
</section>
