{{-- Email login (FR-KSK-02): email + password with an on-screen QWERTY keyboard.
     Typing routes to whichever field is focused; password has an eye toggle;
     "← Cancel" returns to Welcome. The keyboard LAYOUT lives in a small nested
     x-data here (it's pure presentation); all behaviour is in the kiosk
     component (focusField / keyPress / togglePassword / submitLogin).

     Layout note: the 800×480 panel is height-constrained, so the two fields sit
     SIDE BY SIDE (not stacked) to leave room for all five keyboard rows, and the
     whole column is vertically centred + compactly spaced to never clip. --}}
<section class="kiosk-screen" x-show="state.screen === 'email_login'" x-cloak>
    <div
        class="flex h-full w-full flex-col items-center justify-center gap-2 px-6 py-3"
        x-data="{
            rows: [
                ['1','2','3','4','5','6','7','8','9','0'],
                ['q','w','e','r','t','y','u','i','o','p'],
                ['a','s','d','f','g','h','j','k','l','@'],
                ['z','x','c','v','b','n','m','.','_','-'],
            ],
        }"
    >
        {{-- ── Header ──────────────────────────────────────────────────────── --}}
        <div class="flex w-full max-w-2xl items-center justify-between">
            <button
                type="button"
                @click="go('welcome')"
                class="text-sm font-medium text-hp-slate/70 transition hover:text-hp-orange"
            >← Cancel</button>
            <span class="rounded-full bg-hp-peach/40 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-hp-orange">Email Login</span>
            <span class="w-16"></span>{{-- spacer to keep the badge centred --}}
        </div>

        {{-- ── Fields (side by side to save vertical space) ─────────────────── --}}
        <div class="grid w-full max-w-2xl grid-cols-2 gap-3">
            {{-- Email --}}
            <button
                type="button"
                @click="focusField('email')"
                class="flex flex-col items-start rounded-xl border-2 bg-hp-white px-4 py-1.5 text-left transition"
                :class="state.login.field === 'email' ? 'border-hp-orange' : 'border-hp-slate/15'"
            >
                <span class="text-[0.65rem] font-semibold uppercase tracking-wider text-hp-slate/50">Email</span>
                <span class="min-h-[1.5rem] w-full break-all text-base text-hp-slate" x-text="state.login.email || ' '"></span>
            </button>

            {{-- Password (eye toggle) --}}
            <div
                class="flex items-center rounded-xl border-2 bg-hp-white px-4 py-1.5 transition"
                :class="state.login.field === 'password' ? 'border-hp-orange' : 'border-hp-slate/15'"
            >
                <button type="button" @click="focusField('password')" class="flex flex-1 flex-col items-start text-left">
                    <span class="text-[0.65rem] font-semibold uppercase tracking-wider text-hp-slate/50">Password</span>
                    <span class="min-h-[1.5rem] w-full break-all text-base text-hp-slate">
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
                    {{-- eye (open) --}}
                    <svg x-show="!state.login.showPassword" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                    {{-- eye (off) --}}
                    <svg x-show="state.login.showPassword" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a13.2 13.2 0 0 1-2.16 3M6.1 6.1A13.3 13.3 0 0 0 2 11s3.5 7 10 7a9 9 0 0 0 4-.93"></path>
                        <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2M1 1l22 22"></path>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Inline error (spans both fields) --}}
        <p class="min-h-[1rem] w-full max-w-2xl text-center text-sm font-medium text-red-600" x-text="state.login.error"></p>

        {{-- ── On-screen QWERTY keyboard ───────────────────────────────────── --}}
        <div class="flex w-full max-w-2xl flex-col gap-1 select-none">
            <template x-for="(row, i) in rows" :key="i">
                <div class="flex justify-center gap-1">
                    <template x-for="key in row" :key="key">
                        <button
                            type="button"
                            @pointerdown="pressKey($event.currentTarget)"
                            @animationend="$event.currentTarget.classList.remove('k-key-press')"
                            @click="keyPress(key)"
                            class="h-9 flex-1 rounded-lg bg-hp-white text-base font-medium text-hp-slate shadow-sm"
                            x-text="keyLabel(key)"
                        ></button>
                    </template>
                </div>
            </template>

            {{-- Modifier + action row: Caps · Shift · Space · Delete (peach) · Enter (orange).
                 Caps/Shift highlight orange while engaged; press feedback elsewhere
                 is `active:` only, so nothing stays highlighted after a tap. --}}
            <div class="flex justify-center gap-1">
                <button
                    type="button"
                    @click="keyPress('caps')"
                    class="h-9 flex-[2] rounded-lg text-sm font-semibold shadow-sm transition active:scale-95"
                    :class="state.login.caps ? 'bg-hp-orange text-hp-white' : 'bg-hp-white text-hp-slate active:bg-hp-peach/60'"
                >⇪ Caps</button>
                <button
                    type="button"
                    @click="keyPress('shift')"
                    class="h-9 flex-[2] rounded-lg text-sm font-semibold shadow-sm transition active:scale-95"
                    :class="state.login.shift ? 'bg-hp-orange text-hp-white' : 'bg-hp-white text-hp-slate active:bg-hp-peach/60'"
                >⇧ Shift</button>
                <button
                    type="button"
                    @click="keyPress('space')"
                    class="h-9 flex-[4] rounded-lg bg-hp-white text-sm font-medium text-hp-slate/70 shadow-sm transition active:scale-95 active:bg-hp-peach/60"
                >Space</button>
                <button
                    type="button"
                    @click="keyPress('backspace')"
                    class="h-9 flex-[2] rounded-lg bg-hp-peach text-sm font-semibold text-hp-slate shadow-sm transition active:scale-95 active:brightness-90"
                >⌫ Delete</button>
                <button
                    type="button"
                    @click="keyPress('enter')"
                    :disabled="state.login.status === 'sending'"
                    class="h-9 flex-[2] rounded-lg bg-hp-orange text-sm font-semibold text-hp-white shadow-sm transition active:scale-95 active:brightness-90 disabled:opacity-60"
                    x-text="state.login.status === 'sending' ? 'Signing in…' : 'Enter ⏎'"
                ></button>
            </div>
        </div>
    </div>
</section>
