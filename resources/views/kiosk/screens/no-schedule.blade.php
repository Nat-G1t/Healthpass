{{-- No Clinic Schedule Today (FR-KSK-03a, D-61).
     Shown between Identity Confirm and Privacy Consent ONLY when the server
     found no `scheduled` appointment today for this student
     (state.identity.hasAppointmentToday). Students are scheduled only through
     their college, so there is NO way forward from here — the one button
     resets to Welcome. The server refuses the submit on its own too
     (SubmitKioskVisit), so this screen is a courtesy, not the gate. --}}
<section class="kiosk-screen" x-show="state.screen === 'no-schedule'" x-cloak
         x-transition:enter="transition ease-hp-out duration-hp-base"
         x-transition:enter-start="opacity-0 translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0">
    <div class="flex w-full flex-col items-center justify-center px-10 py-8 text-center">
        {{-- Calendar icon in a peach rounded square --}}
        <div class="flex h-24 w-24 items-center justify-center rounded-3xl bg-hp-peach/40 text-hp-orange">
            <svg class="h-12 w-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                <path d="M16 2v4M8 2v4M3 10h18"></path>
            </svg>
        </div>

        <h1 class="mt-6 text-3xl font-semibold text-hp-slate">No Clinic Schedule Today</h1>
        <p class="mt-3 max-w-lg text-base leading-relaxed text-hp-slate/70">
            You don't have a clinic schedule today. Clearances are scheduled through
            your college, so please ask your college office to include you in a
            batch request.
        </p>

        <div class="mt-10 flex flex-col items-center gap-4">
            <button
                type="button"
                @click="reset()"
                class="rounded-2xl bg-hp-orange px-14 py-5 text-lg font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98]"
            >Back to start</button>
        </div>
    </div>
</section>
