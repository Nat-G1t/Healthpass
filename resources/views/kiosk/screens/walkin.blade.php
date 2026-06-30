{{-- Walk-in Check / No Scheduled Clearance Today (FR-KSK-03a).
     Shown between Identity Confirm and Privacy Consent ONLY when the server
     found NO non-cancelled appointment dated today for this student — medical
     OR dental. The check is server-side (state.identity.hasAppointmentToday);
     this screen is a UI gate. "Proceed as Walk-in" → consent; "Not now" →
     full reset to Welcome. The appointment_id linkage is still resolved
     server-side (medical-only) at submit, so a dental-only student records
     as a walk-in. --}}
<section class="kiosk-screen" x-show="state.screen === 'walkin'" x-cloak>
    <div class="flex w-full flex-col items-center justify-center px-8 py-6 text-center">
        {{-- Calendar icon in a peach rounded square --}}
        <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-hp-peach/40 text-hp-orange">
            <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                <path d="M16 2v4M8 2v4M3 10h18"></path>
                <path d="m9 16 2 2 4-4"></path>
            </svg>
        </div>

        <h1 class="mt-4 text-2xl font-semibold text-hp-slate">No Scheduled Clearance Today</h1>
        <p class="mt-2 max-w-md text-sm leading-relaxed text-hp-slate/70">
            We couldn't find an appointment booked for you today. You can still
            continue as a <strong>walk-in</strong> — your visit will be added to the
            clinic queue just the same.
        </p>

        {{-- Actions: primary "Proceed as Walk-in", ghost "Not now" beneath. --}}
        <div class="mt-6 flex flex-col items-center gap-2">
            <button
                type="button"
                @click="proceedAsWalkin()"
                class="rounded-xl bg-hp-orange px-10 py-3 text-base font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98]"
            >Proceed as Walk-in</button>
            <button
                type="button"
                @click="reset()"
                class="rounded-lg px-4 py-1 text-sm font-medium text-hp-slate/70 transition hover:text-hp-orange"
            >Not now</button>
        </div>
    </div>
</section>
