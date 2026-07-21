{{-- Email login (FR-KSK-02): email + password with an on-screen QWERTY keyboard.
     Typing routes to whichever field is focused; password has an eye toggle;
     "← Cancel" returns to Welcome. The keyboard LAYOUT lives in a small nested
     x-data here (it's pure presentation); all behaviour is in the kiosk
     component (focusField / keyPress / togglePassword / submitLogin).

     Layout note: the 1080×1920 portrait panel has generous height, so the two
     fields are STACKED full-width above the keyboard, and the whole column is
     vertically centred. --}}
<section class="kiosk-screen" x-show="state.screen === 'email_login'" x-cloak
         x-transition:enter="transition ease-hp-out duration-hp-base"
         x-transition:enter-start="opacity-0 translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0">
    <div class="flex h-full w-full flex-col items-center justify-center gap-4 px-8 py-6">
        {{-- ── Header ──────────────────────────────────────────────────────── --}}
        <div class="flex w-full max-w-2xl items-center justify-between">
            <button
                type="button"
                @click="go('welcome')"
                class="rounded-lg px-3 py-2 text-base font-medium text-hp-slate/70 transition hover:text-hp-orange"
            >← Cancel</button>
            <span class="rounded-full bg-hp-peach/40 px-4 py-1.5 text-sm font-semibold uppercase tracking-widest text-hp-orange">Email Login</span>
            <span class="w-20"></span>{{-- spacer to keep the badge centred --}}
        </div>

        {{-- ── Fields (stacked full-width for portrait) ─────────────────────── --}}
        <div class="grid w-full max-w-2xl grid-cols-1 gap-3">
            {{-- Email --}}
            <button
                type="button"
                @click="focusField('email')"
                class="flex flex-col items-start rounded-xl border-2 bg-hp-white px-5 py-3 text-left transition"
                :class="state.login.field === 'email' ? 'border-hp-orange' : 'border-hp-slate/15'"
            >
                <span class="text-xs font-semibold uppercase tracking-wider text-hp-slate/50">Email</span>
                {{-- Single line that scrolls sideways instead of wrapping/growing the
                     field; auto-scrolls to the end so the latest typed text stays in view. --}}
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
                {{-- min-w-0 lets this flex item shrink below the text's width so the
                     password scrolls inside it instead of pushing the eye icon (which
                     stays fixed via shrink-0 on its button). --}}
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
                    {{-- eye (open) --}}
                    <svg x-show="!state.login.showPassword" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                    {{-- eye (off) --}}
                    <svg x-show="state.login.showPassword" x-cloak class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a13.2 13.2 0 0 1-2.16 3M6.1 6.1A13.3 13.3 0 0 0 2 11s3.5 7 10 7a9 9 0 0 0 4-.93"></path>
                        <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2M1 1l22 22"></path>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Inline error (spans both fields) — shakes once per new error; the
             class re-applies because typing clears the error first (§7). --}}
        <p class="min-h-[1rem] w-full max-w-2xl text-center text-sm font-medium text-red-600"
           :class="state.login.error ? 'hp-anim-shake' : ''"
           x-text="state.login.error"></p>

        {{-- ── On-screen QWERTY keyboard (shared with the staff-exit prompt) ── --}}
        @include('kiosk.partials.credential-keyboard')
    </div>
</section>
