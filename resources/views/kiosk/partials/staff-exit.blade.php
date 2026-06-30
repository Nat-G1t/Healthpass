{{-- Discreet staff-exit prompt (FR-KSK-16). Hidden until the corner gesture
     (5 taps within ~3 s) opens it; overlays whatever screen is active so a nurse
     can end the session at any point. Reuses the login fields + shared keyboard
     (state.login) for the nurse's credentials. A valid nurse is authenticated
     server-side and the page navigates to the queue; Cancel just dismisses it.
     This is the ONLY way out of /kiosk — students have no nav otherwise. --}}
<div
    x-show="state.exit.open"
    x-cloak
    class="absolute inset-0 z-40 flex items-center justify-center bg-hp-slate/40 backdrop-blur-sm"
    @click.self="closeExit()"
>
    <div class="flex max-h-full w-full max-w-2xl flex-col items-center gap-2 overflow-hidden rounded-2xl bg-hp-bg px-6 py-3 shadow-xl">
        {{-- ── Header ──────────────────────────────────────────────────────── --}}
        <div class="flex w-full items-center justify-between">
            <button
                type="button"
                @click="closeExit()"
                class="text-sm font-medium text-hp-slate/70 transition hover:text-hp-orange"
            >← Cancel</button>
            <span class="rounded-full bg-hp-peach/40 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-hp-orange">Staff Exit</span>
            <span class="w-16"></span>{{-- spacer to keep the badge centred --}}
        </div>

        <p class="text-center text-sm text-hp-slate/70">Enter nurse credentials to leave kiosk mode.</p>

        {{-- ── Fields (side by side to save vertical space) ─────────────────── --}}
        <div class="grid w-full grid-cols-2 gap-3">
            {{-- Email --}}
            <button
                type="button"
                @click="focusField('email')"
                class="flex flex-col items-start rounded-xl border-2 bg-hp-white px-4 py-1.5 text-left transition"
                :class="state.login.field === 'email' ? 'border-hp-orange' : 'border-hp-slate/15'"
            >
                <span class="text-[0.65rem] font-semibold uppercase tracking-wider text-hp-slate/50">Nurse Email</span>
                <span
                    class="block min-h-[1.5rem] w-full overflow-x-auto whitespace-nowrap text-base text-hp-slate [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                    x-text="state.login.email || ' '"
                    x-effect="state.login.email; $nextTick(() => $el.scrollLeft = $el.scrollWidth)"
                ></span>
            </button>

            {{-- Password (eye toggle) --}}
            <div
                class="flex items-center rounded-xl border-2 bg-hp-white px-4 py-1.5 transition"
                :class="state.login.field === 'password' ? 'border-hp-orange' : 'border-hp-slate/15'"
            >
                <button type="button" @click="focusField('password')" class="flex min-w-0 flex-1 flex-col items-start text-left">
                    <span class="text-[0.65rem] font-semibold uppercase tracking-wider text-hp-slate/50">Password</span>
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
                    class="ml-2 shrink-0 rounded-lg p-1.5 text-hp-slate/60 transition hover:text-hp-orange"
                    :aria-label="state.login.showPassword ? 'Hide password' : 'Show password'"
                >
                    <svg x-show="!state.login.showPassword" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                    <svg x-show="state.login.showPassword" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a13.2 13.2 0 0 1-2.16 3M6.1 6.1A13.3 13.3 0 0 0 2 11s3.5 7 10 7a9 9 0 0 0 4-.93"></path>
                        <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2M1 1l22 22"></path>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Inline error (spans both fields) --}}
        <p class="min-h-[1rem] w-full text-center text-sm font-medium text-red-600" x-text="state.exit.error"></p>

        @include('kiosk.partials.credential-keyboard')
    </div>
</div>
