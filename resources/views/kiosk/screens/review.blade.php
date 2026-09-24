{{-- Review (FR-KSK-11): two cards — Vital Signs and Questionnaire — plus, on a
     Medical Assessment Form visit only, a third for the Personal / Social
     History (D-68) — then "Submit to Clinic →". Flagged vitals show in orange with a ⚑; those flags
     are DISPLAY-TIME ONLY, computed client-side from the thresholds published
     into the page from config (tempFlagged / bpFlagged / bmiFlagged, the same
     helpers the vitals badges use). The AUTHORITATIVE flag booleans are computed
     SERVER-side at submit (§7.4, FR-KSK-12). Per FR-KSK-14 the kiosk still never
     shows Fit/Unfit — only the neutral per-vital ⚑.

     The cards STACK vertically (was side-by-side on the old landscape
     panel) — the 1080×1920 portrait screen has the height for them; the
     middle area scrolls as one column if anything overflows. --}}
<section class="kiosk-screen" x-show="state.screen === 'review'" x-cloak
         x-transition:enter="transition ease-hp-out duration-hp-base"
         x-transition:enter-start="opacity-0 translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0">
    <div class="flex h-full w-full flex-col px-8 py-8">

        {{-- Header --}}
        <div class="flex flex-col items-center gap-2 text-center">
            <span class="rounded-full bg-hp-peach/40 px-3.5 py-1 text-xs font-semibold uppercase tracking-widest text-hp-orange">Review</span>
            <h1 class="text-2xl font-semibold text-hp-slate">Please check your answers</h1>
            <p class="text-base text-hp-slate/60" x-text="state.identity ? (state.identity.fullName + ' · ' + (state.identity.studentNumber ?? '')) : ''"></p>
        </div>

        {{-- Two cards stacked in one scrollable column --}}
        <div class="mt-5 flex flex-1 flex-col gap-4 overflow-y-auto">

            {{-- ── Vital Signs card (flagged items orange + ⚑) ──────────────── --}}
            <div class="rounded-2xl bg-hp-white p-5 shadow-sm">
                <p class="text-sm font-semibold uppercase tracking-wider text-hp-slate/50">Vital Signs</p>
                {{-- hp-stagger (§7): summary rows fade up in sequence each time
                     the Review screen is shown (delays cap after 8 children). --}}
                <div class="hp-stagger mt-2 flex flex-col divide-y divide-hp-slate/10">
                    {{-- Height — no flag. --}}
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-base text-hp-slate/70">Height</span>
                        <span class="text-lg font-semibold text-hp-slate"><span x-text="fieldValue('height')"></span> cm</span>
                    </div>
                    {{-- Weight — no flag (BMI carries it). --}}
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-base text-hp-slate/70">Weight</span>
                        <span class="text-lg font-semibold text-hp-slate"><span x-text="fieldValue('weight')"></span> kg</span>
                    </div>
                    {{-- BMI — flagged ≥ 30 (BR-13). --}}
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-base text-hp-slate/70">BMI</span>
                        <span class="text-lg font-semibold" :class="bmiFlagged(bmiValue()) ? 'text-hp-orange' : 'text-hp-slate'">
                            <span x-show="bmiFlagged(bmiValue())" x-cloak>⚑ </span><span x-text="bmiValue() !== null ? bmiValue().toFixed(1) : '—'"></span>
                        </span>
                    </div>
                    {{-- Temperature — flagged > 37.2 °C. --}}
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-base text-hp-slate/70">Temperature</span>
                        <span class="text-lg font-semibold" :class="tempFlagged(fieldValue('temperature')) ? 'text-hp-orange' : 'text-hp-slate'">
                            <span x-show="tempFlagged(fieldValue('temperature'))" x-cloak>⚑ </span><span x-text="fieldValue('temperature') !== null ? fieldValue('temperature').toFixed(1) : '—'"></span> °C
                        </span>
                    </div>
                    {{-- Blood Pressure — flagged sys ≥ 140 OR dia ≥ 90 (D-10). --}}
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-base text-hp-slate/70">Blood Pressure</span>
                        <span class="text-lg font-semibold" :class="bpFlagged(fieldValue('systolic'), fieldValue('diastolic')) ? 'text-hp-orange' : 'text-hp-slate'">
                            <span x-show="bpFlagged(fieldValue('systolic'), fieldValue('diastolic'))" x-cloak>⚑ </span><span x-text="fieldValue('systolic')"></span>/<span x-text="fieldValue('diastolic')"></span> mmHg
                        </span>
                    </div>
                    {{-- Heart Rate — flagged > 100 bpm (D-66). --}}
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-base text-hp-slate/70">Heart Rate</span>
                        <span class="text-lg font-semibold" :class="hrFlagged(fieldValue('heartRate')) ? 'text-hp-orange' : 'text-hp-slate'">
                            <span x-show="hrFlagged(fieldValue('heartRate'))" x-cloak>⚑ </span><span x-text="fieldValue('heartRate')"></span> bpm
                        </span>
                    </div>
                </div>
            </div>

            {{-- ── Questionnaire card: the form's twelve rows (D-63) as Yes/No badges,
                 each YES detail under its badge (D-56) ─────────────────────── --}}
            <div class="rounded-2xl bg-hp-white p-5 shadow-sm">
                <p class="text-sm font-semibold uppercase tracking-wider text-hp-slate/50">Questionnaire</p>
                <div class="mt-2 flex flex-col divide-y divide-hp-slate/10">
                    <template x-for="sys in systemList" :key="sys.key">
                        <div class="py-2">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-base text-hp-slate/70" x-text="sys.label"></span>
                                <span
                                    class="rounded-full px-3 py-1 text-sm font-semibold"
                                    :class="systemAnswer(sys.key) === true
                                        ? 'bg-hp-orange/15 text-hp-orange'
                                        : 'bg-emerald-50 text-emerald-600'"
                                    x-text="systemAnswer(sys.key) === true ? 'Yes' : 'No'"
                                ></span>
                            </div>
                            <p x-show="systemAnswer(sys.key) === true && detailText(sys.key) !== ''" x-cloak
                               class="mt-1 break-words text-sm text-hp-slate/50"
                               x-text="detailText(sys.key)"></p>
                        </div>
                    </template>
                    {{-- Pregnancy + LMP — female students only (D-79). --}}
                    <div x-show="isFemale()" class="flex items-center justify-between gap-2 py-2">
                        <span class="text-base text-hp-slate/70">
                            Pregnant
                            <span x-show="state.questionnaire.isPregnant === true" x-cloak class="block text-sm text-hp-slate/40">LMP: <span x-text="lmpLabel()"></span></span>
                        </span>
                        <span
                            class="rounded-full px-3 py-1 text-sm font-semibold"
                            :class="state.questionnaire.isPregnant === true
                                ? 'bg-hp-orange/15 text-hp-orange'
                                : 'bg-emerald-50 text-emerald-600'"
                            x-text="state.questionnaire.isPregnant === true ? 'Yes' : 'No'"
                        ></span>
                    </div>
                </div>
            </div>

            {{-- ── Personal / Social History card (D-68) — Medical Assessment
                 Form visits only; a Clearance student was never asked these,
                 so the card is not rendered at all for them ─────────────── --}}
            <div class="rounded-2xl bg-hp-white p-5 shadow-sm" x-show="isAssessment()" x-cloak>
                <p class="text-sm font-semibold uppercase tracking-wider text-hp-slate/50">Personal / Social History</p>
                <div class="mt-2 flex flex-col divide-y divide-hp-slate/10">
                    <template x-for="row in socialHistoryList" :key="row.key">
                        <div class="flex items-center justify-between gap-2 py-2">
                            <span class="text-base text-hp-slate/70" x-text="row.label"></span>
                            <span
                                class="rounded-full px-3 py-1 text-sm font-semibold"
                                :class="socialHistoryAnswer(row.key, 'yes')
                                    ? 'bg-hp-orange/15 text-hp-orange'
                                    : (socialHistoryAnswer(row.key, 'no')
                                        ? 'bg-emerald-50 text-emerald-600'
                                        : 'bg-hp-slate/10 text-hp-slate')"
                                x-text="socialHistoryLabel(row.key)"
                            ></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- D-72: one of temperature / blood pressure / heart rate is flagged,
             so this pass offers a rest instead of a submit. Plain, unalarming
             wording — it says what happens next, not what it might mean
             (FR-KSK-14). The kiosk decides this line from its own display
             flags; the SERVER recomputes them at /kiosk/rest and refuses if it
             disagrees, which puts Submit to Clinic back. --}}
        <p x-show="needsRest()" x-cloak
           class="mt-2 rounded-xl bg-hp-peach/40 px-4 py-3 text-center text-base font-medium text-hp-slate/80">
            One of your readings is a little high. Please rest and re-take it
            before we send your results to the clinic.
        </p>

        {{-- Submit error (network / server) — fades in with one shake (§7). --}}
        <p x-show="state.submit.status === 'error'" x-cloak
           class="hp-anim-shake mt-2 text-center text-base font-medium text-red-600"
           x-text="state.submit.error"></p>

        {{-- Rest error (network / server, or the server's "nothing to re-take"). --}}
        <p x-show="state.recheck.status === 'error'" x-cloak
           class="hp-anim-shake mt-2 text-center text-base font-medium text-red-600"
           x-text="state.recheck.error"></p>

        {{-- ── Footer: back to questionnaire + submit to clinic ─────────────── --}}
        <div class="mt-4 flex items-center justify-between">
            <button
                type="button"
                {{-- D-68: back one screen in the ordered flow — the Personal /
                     Social History for an Assessment student, the questionnaire
                     for a Clearance one. --}}
                @click="backFromReview()"
                class="rounded-lg px-3 py-3 text-base font-medium text-hp-slate/60 transition hover:text-hp-orange"
                x-text="isRecheck() ? '← Back' : (isAssessment() ? '← Back' : '← Back to questionnaire')"
            ></button>
            {{-- D-72: ONE primary button, whose job depends on the flags. A
                 re-check pass never offers a second rest (needsRest() is false
                 for it), and the server refuses one anyway. --}}
            <button
                x-show="needsRest()" x-cloak
                type="button"
                @click="restAndRecheck()"
                :disabled="state.recheck.status === 'sending'"
                class="rounded-2xl bg-hp-orange px-9 py-4 text-lg font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60"
                x-text="state.recheck.status === 'sending' ? 'Saving…' : 'Rest &amp; re-check →'"
            ></button>
            <button
                x-show="!needsRest()"
                type="button"
                @click="submitToClinic()"
                :disabled="state.submit.status === 'sending'"
                class="rounded-2xl bg-hp-orange px-9 py-4 text-lg font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60"
                x-text="state.submit.status === 'sending' ? 'Submitting…' : 'Submit to Clinic →'"
            ></button>
        </div>

        {{-- Submit pending overlay (§7): covers the screen while the visit is
             being written. submitToClinic() holds it ≥400 ms so a fast server
             never makes it strobe. Neutral wording — celebrates nothing. --}}
        <div x-show="state.submit.status === 'sending' || state.recheck.status === 'sending'" x-cloak
             x-transition:enter="transition ease-hp-out duration-hp-fast"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-4 bg-hp-bg/90">
            <svg class="h-10 w-10 animate-spin text-hp-orange" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity="0.25"/>
                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>
            </svg>
            <p class="text-lg font-semibold text-hp-slate">Saving your visit…</p>
        </div>
    </div>
</section>
