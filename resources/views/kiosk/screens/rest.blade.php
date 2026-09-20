{{-- Rest & re-check (FR-KSK-13a, D-72): shown instead of Complete when the
     first pass caught a high temperature, blood pressure or heart rate. The
     student sits down, waits, then scans their ID again to re-take just that
     reading.

     Per FR-KSK-14 there is NO interpretation here — no Fit/Unfit, no "you may
     be hypertensive", not even the number itself. It names WHICH reading is
     being re-taken and WHEN to come back, and nothing more.

     The come-back time is `state.recheck.until`, a ready-made string from the
     SERVER's clinic_visits.resting_until. The kiosk never formats a time of its
     own and never reads the browser clock (same rule as BR-20/BR-23).

     Like Complete, it auto-resets to Welcome on the complete_reset_seconds
     countdown and has a Done button — the next student must never walk up to
     the previous student's screen (FR-KSK-13). --}}
<section class="kiosk-screen" x-show="state.screen === 'rest'" x-cloak
         x-transition:enter="transition ease-hp-out duration-hp-base"
         x-transition:enter-start="opacity-0 translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0">
    <div class="flex w-full flex-col items-center justify-center gap-6 px-10 text-center">

        {{-- A resting figure, not a warning triangle: nothing has gone wrong. --}}
        <div class="hp-anim-scale-in flex h-28 w-28 items-center justify-center rounded-full bg-hp-peach/50">
            <svg class="h-16 w-16 text-hp-orange" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9" />
                <path d="M12 7v5l3 2" />
            </svg>
        </div>

        <div class="flex flex-col gap-3">
            <h1 class="text-4xl font-semibold text-hp-slate">Please rest for a few minutes</h1>
            <p class="max-w-xl text-lg text-hp-slate/70">
                Your <span class="font-semibold text-hp-slate" x-text="restReadingLabel()"></span>
                reading is a little high. Please sit and rest for
                <span class="font-semibold text-hp-slate" x-text="restMinutes()"></span> minutes.
            </p>
        </div>

        {{-- The server's come-back time — the one thing the student must
             remember, so it gets the emphasis. --}}
        <div class="hp-anim-fade-up rounded-2xl bg-hp-white px-10 py-6 shadow-sm" style="animation-delay: 150ms">
            <p class="text-sm font-semibold uppercase tracking-widest text-hp-slate/50">Come back at</p>
            <p class="mt-1 text-5xl font-bold text-hp-orange" x-text="state.recheck.until ?? '—'"></p>
            <p class="mt-2 text-base text-hp-slate/60">then scan your ID again to re-take it.</p>
        </div>

        {{-- Auto-reset countdown pill — same one Complete uses (FR-KSK-13). --}}
        <span class="rounded-full bg-hp-peach/40 px-5 py-2 text-sm font-semibold uppercase tracking-widest text-hp-orange">
            Returning to start in <span x-text="completeCountdown"></span>s
        </span>

        <button
            type="button"
            @click="reset()"
            class="mt-2 rounded-2xl bg-hp-orange px-12 py-4 text-lg font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98]"
        >Done</button>
    </div>
</section>
