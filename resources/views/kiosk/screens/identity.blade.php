{{-- Identity Confirm (FR-KSK-03): large initials avatar, "Identity Verified ✓",
     greeting with first name, and college/course/year/student number. The
     identity object is set by either QR scan or email login (arriveAtIdentity).
     "That's me" → consent; "Not you?" → full reset to Welcome (FR-KSK-13). --}}
<section class="kiosk-screen" x-show="state.screen === 'identity'" x-cloak>
    <div class="flex w-full flex-col items-center justify-center px-8 pb-6 text-center" x-show="state.identity">
        <p class="inline-flex items-center gap-1.5 text-sm font-semibold text-green-600">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 6 9 17l-5-5"></path>
            </svg>
            Identity Verified
        </p>

        <h1 class="mt-1 text-2xl font-semibold text-hp-slate">
            Hi, <span x-text="state.identity?.firstName"></span>!
        </h1>

        {{-- Profile detail card --}}
        <dl class="mt-3 grid w-full max-w-md grid-cols-1 gap-2 rounded-2xl bg-hp-white p-4 text-left shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <dt class="text-xs font-semibold uppercase tracking-wider text-hp-slate/50">College</dt>
                <dd class="text-right text-sm font-medium text-hp-slate" x-text="state.identity?.college"></dd>
            </div>
            <div class="flex items-start justify-between gap-4 border-t border-hp-slate/10 pt-2">
                <dt class="text-xs font-semibold uppercase tracking-wider text-hp-slate/50">Course</dt>
                <dd class="text-right text-sm font-medium text-hp-slate" x-text="state.identity?.course"></dd>
            </div>
            <div class="flex items-start justify-between gap-4 border-t border-hp-slate/10 pt-2">
                <dt class="text-xs font-semibold uppercase tracking-wider text-hp-slate/50">Year Level</dt>
                <dd class="text-right text-sm font-medium text-hp-slate" x-text="state.identity?.yearLevel"></dd>
            </div>
            <div class="flex items-start justify-between gap-4 border-t border-hp-slate/10 pt-2">
                <dt class="text-xs font-semibold uppercase tracking-wider text-hp-slate/50">Student No.</dt>
                <dd class="text-right text-sm font-medium text-hp-slate" x-text="state.identity?.studentNumber"></dd>
            </div>
        </dl>

        {{-- Actions: primary CTA centred, "Not you?" as a link beneath it. --}}
        <div class="mt-4 flex flex-col items-center gap-2">
            <button
                type="button"
                @click="confirmIdentity()"
                class="rounded-xl bg-hp-orange px-10 py-3 text-base font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98]"
            >That's me — Continue</button>
            <button
                type="button"
                @click="reset()"
                class="rounded-lg px-4 py-1 text-sm font-medium text-hp-slate/70 transition hover:text-hp-orange"
            >Not you?</button>
        </div>
    </div>
</section>
