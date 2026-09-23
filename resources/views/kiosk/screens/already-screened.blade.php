{{-- You're all done for today (FR-KSK-03b).
     Shown between Identity Confirm and Privacy Consent when the student's
     appointment today already has a submitted visit — they used their slot
     (state.identity.alreadyScreenedToday). Shows the visit reference and
     whether the clinic has seen them yet (state.identity.screenedStatus),
     NEVER Fit/Unfit. The one button resets to Welcome. The server refuses a
     second submit on its own (SubmitKioskVisit), so this screen is a
     courtesy, not the gate. --}}
<section class="kiosk-screen" x-show="state.screen === 'already-screened'" x-cloak
         x-transition:enter="transition ease-hp-out duration-hp-base"
         x-transition:enter-start="opacity-0 translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0">
    <div class="flex w-full flex-col items-center justify-center px-10 py-8 text-center">
        {{-- Check-mark icon in a peach rounded square --}}
        <div class="flex h-24 w-24 items-center justify-center rounded-3xl bg-hp-peach/40 text-hp-orange">
            <svg class="h-12 w-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="9"></circle>
                <path d="M8 12.5l2.5 2.5L16 9.5"></path>
            </svg>
        </div>

        <h1 class="mt-6 text-3xl font-semibold text-hp-slate">You're all done for today</h1>
        <p class="mt-3 max-w-lg text-base leading-relaxed text-hp-slate/70">
            You've already completed today's clinic screening.
        </p>

        {{-- Same style as the Complete screen's reference line. --}}
        <p x-show="state.identity?.screenedReference" class="mt-4 text-base text-hp-slate/50">
            Reference <span class="font-semibold text-hp-slate/70" x-text="state.identity?.screenedReference"></span>
        </p>

        <p class="mt-4 max-w-lg text-base leading-relaxed text-hp-slate/70"
           x-text="state.identity?.screenedStatus === 'encoded'
               ? 'The clinic has already seen you today.'
               : 'Please proceed to the clinic and wait to be called.'"></p>

        <div class="mt-10 flex flex-col items-center gap-4">
            <button
                type="button"
                @click="reset()"
                class="rounded-2xl bg-hp-orange px-14 py-5 text-lg font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98]"
            >Back to start</button>
        </div>
    </div>
</section>
