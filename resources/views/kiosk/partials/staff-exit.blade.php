{{-- Discreet staff-exit prompt (FR-KSK-16). Hidden until the corner gesture
     (5 taps within ~3 s) opens it; overlays whatever screen is active so a nurse
     can end the session at any point. Reuses the login fields + shared keyboard
     (state.login) for the nurse's credentials. A valid nurse is authenticated
     server-side and the page navigates to the queue; Cancel just dismisses it.
     This is the ONLY way out of /kiosk — students have no nav otherwise. --}}
<div
    x-show="state.exit.open"
    x-cloak
    x-transition:enter="transition ease-hp-out duration-hp-fast"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    class="absolute inset-0 z-40 flex items-center justify-center bg-hp-slate/40 backdrop-blur-sm"
    @click.self="closeExit()"
>
    {{-- Panel rises like a sheet each time the prompt opens (§7). --}}
    <div class="hp-anim-sheet-up flex max-h-full w-full max-w-2xl flex-col items-center gap-4 overflow-hidden rounded-2xl bg-hp-bg px-8 py-6 shadow-xl">
        {{-- ── Header ──────────────────────────────────────────────────────── --}}
        <div class="flex w-full items-center justify-between">
            <button
                type="button"
                @click="closeExit()"
                class="rounded-lg px-3 py-2 text-base font-medium text-hp-slate/70 transition hover:text-hp-orange"
            >← Cancel</button>
            <span class="rounded-full bg-hp-peach/40 px-4 py-1.5 text-sm font-semibold uppercase tracking-widest text-hp-orange">Staff Exit</span>
            <span class="w-20"></span>{{-- spacer to keep the badge centred --}}
        </div>

        <p class="text-center text-base text-hp-slate/70">Enter nurse credentials to leave kiosk mode.</p>

        {{-- ── Fields (stacked full-width for portrait) ─────────────────────── --}}
        <div class="grid w-full grid-cols-1 gap-3">
            {{-- Email --}}
            <button
                type="button"
                @click="focusField('email')"
                class="flex flex-col items-start rounded-xl border-2 bg-hp-white px-5 py-3 text-left transition"
                :class="state.login.field === 'email' ? 'border-hp-orange' : 'border-hp-slate/15'"
            >
                <span class="text-xs font-semibold uppercase tracking-wider text-hp-slate/50">Nurse Email</span>
                <span
                    class="block min-h-[1.5rem] w-full overflow-x-auto whitespace-nowrap text-base text-hp-slate [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                    x-text="state.login.email || ' '"
                    x-effect="state.login.email; $nextTick(() => $el.scrollLeft = $el.scrollWidth)"
                ></span>
            </button>

            {{-- Password (eye toggle) --}}
            <div
                class="flex items-center rounded-xl border-2 bg-hp-white px-5 py-3 transition"
                :class="state.login.field === 'password' ? 'border-hp-orange' : 'border-hp-slate/15'"
            >
                <button type="button" @click="focusField('password')" class="flex min-w-0 flex-1 flex-col items-start text-left">
                    <span class="text-xs font-semibold uppercase tracking-wider text-hp-slate/50">Password</span>
                    <span
                        class="block min-h-[1.5rem] w-full overflow-x-auto whitespace-nowrap text-base text-hp-slate [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                        x-effect="state.login.password; $nextTick(() => $el.scrollLeft = $el.scrollWidth)"
                    >
                        <span x-show="!state.login.showPassword" x-text="'•'.repeat(state.login.password.length) || ' '"></span>
                        <span x-show="state.login.showPassword" x-text="state.login.password || ' '"></span>
                    </span>
                </button>
                <button
                    type="button"
                    @click="togglePassword()"
                    class="ml-2 shrink-0 rounded-lg p-3 text-hp-slate/60 transition hover:text-hp-orange"
                    :aria-label="state.login.showPassword ? 'Hide password' : 'Show password'"
                >
                    <svg x-show="!state.login.showPassword" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                    <svg x-show="state.login.showPassword" x-cloak class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a13.2 13.2 0 0 1-2.16 3M6.1 6.1A13.3 13.3 0 0 0 2 11s3.5 7 10 7a9 9 0 0 0 4-.93"></path>
                        <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2M1 1l22 22"></path>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Inline error (spans both fields) — shakes once per new error (§7). --}}
        <p class="min-h-[1.25rem] w-full text-center text-base font-medium text-red-600"
           :class="state.exit.error ? 'hp-anim-shake' : ''"
           x-text="state.exit.error"></p>

        @include('kiosk.partials.credential-keyboard')
    </div>
</div>
