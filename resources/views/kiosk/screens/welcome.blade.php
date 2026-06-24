{{-- Welcome (FR-KSK-01): scan-your-ID on the left, email fallback on the right. --}}
<section class="kiosk-screen" x-show="state.screen === 'welcome'" x-cloak>
    <div class="flex w-full">
        {{-- ── Left: pulsing QR target ──────────────────────────────────────── --}}
        <div class="flex flex-1 flex-col items-center justify-center px-10 text-center">
            <div class="relative mb-4 flex h-32 w-32 items-center justify-center">
                {{-- pulse ring --}}
                <span class="k-pulse-ring absolute inset-0 rounded-3xl border-4 border-hp-orange"></span>
                {{-- target frame --}}
                <div class="relative flex h-32 w-32 items-center justify-center rounded-3xl border-4 border-hp-orange bg-hp-white">
                    <svg class="h-14 w-14 text-hp-orange" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="7" rx="1"></rect>
                        <rect x="14" y="3" width="7" height="7" rx="1"></rect>
                        <rect x="3" y="14" width="7" height="7" rx="1"></rect>
                        <path d="M14 14h3v3M21 14v7h-7v-3"></path>
                    </svg>
                </div>
            </div>

            <h1 class="text-2xl font-semibold text-hp-slate">Tap to Scan Your ID</h1>
            <p class="mt-2 max-w-xs text-sm text-hp-slate/70">
                Hold your student ID's QR code up to the scanner to begin.
            </p>

            {{-- Inline scan feedback (sending / error) --}}
            <div class="mt-3 h-6">
                <p x-show="state.scan.status === 'sending'" x-cloak class="text-sm font-medium text-hp-orange">
                    Reading your ID…
                </p>
                <p x-show="state.scan.status === 'error'" x-cloak class="text-sm font-medium text-red-600" x-text="state.scan.error"></p>
            </div>
        </div>

        {{-- ── Divider ──────────────────────────────────────────────────────── --}}
        <div class="flex flex-col items-center py-12">
            <span class="w-px flex-1 bg-hp-slate/15"></span>
            <span class="my-3 text-xs font-medium uppercase tracking-widest text-hp-slate/40">or</span>
            <span class="w-px flex-1 bg-hp-slate/15"></span>
        </div>

        {{-- ── Right: email login fallback ──────────────────────────────────── --}}
        <div class="flex flex-1 flex-col items-center justify-center px-10 text-center">
            <h2 class="text-xl font-semibold text-hp-slate">Lost ID?</h2>
            <p class="mt-2 mb-6 max-w-xs text-sm text-hp-slate/70">
                No problem — sign in with your HealthPass email and password instead.
            </p>
            <button
                type="button"
                @click="go('email_login')"
                class="rounded-xl bg-hp-orange px-8 py-4 text-base font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98]"
            >
                Log in with email
            </button>
        </div>
    </div>
</section>
