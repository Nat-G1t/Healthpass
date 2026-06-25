{{-- Review (FR-KSK-11): two cards — Vital Signs and Questionnaire — then
     "Submit to Clinic →". Flagged vitals show in orange with a ⚑; those flags
     are DISPLAY-TIME ONLY, computed client-side from the thresholds published
     into the page from config (tempFlagged / bpFlagged / bmiFlagged, the same
     helpers the vitals badges use). The AUTHORITATIVE flag booleans are computed
     SERVER-side at submit (§7.4, FR-KSK-12). Per FR-KSK-14 the kiosk still never
     shows Fit/Unfit — only the neutral per-vital ⚑.

     Compact two-column layout to fit the 800×480 Pi panel. --}}
<section class="kiosk-screen" x-show="state.screen === 'review'" x-cloak>
    <div class="flex h-full w-full flex-col px-6 py-3">

        {{-- Header --}}
        <div class="flex flex-col items-center gap-1 text-center">
            <span class="rounded-full bg-hp-peach/40 px-2.5 py-0.5 text-[0.6rem] font-semibold uppercase tracking-widest text-hp-orange">Review</span>
            <h1 class="text-lg font-semibold text-hp-slate">Please check your answers</h1>
            <p class="text-[0.7rem] text-hp-slate/60" x-text="state.identity ? (state.identity.fullName + ' · ' + (state.identity.studentNumber ?? '')) : ''"></p>
        </div>

        {{-- Two cards side by side --}}
        <div class="mt-2 grid flex-1 grid-cols-2 gap-3 overflow-hidden">

            {{-- ── Vital Signs card (flagged items orange + ⚑) ──────────────── --}}
            <div class="flex flex-col overflow-y-auto rounded-2xl bg-hp-white p-3 shadow-sm">
                <p class="text-[0.62rem] font-semibold uppercase tracking-wider text-hp-slate/50">Vital Signs</p>
                <div class="mt-1.5 flex flex-col divide-y divide-hp-slate/10">
                    {{-- Height — no flag. --}}
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-[0.72rem] text-hp-slate/70">Height</span>
                        <span class="text-[0.8rem] font-semibold text-hp-slate"><span x-text="fieldValue('height')"></span> cm</span>
                    </div>
                    {{-- Weight — no flag (BMI carries it). --}}
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-[0.72rem] text-hp-slate/70">Weight</span>
                        <span class="text-[0.8rem] font-semibold text-hp-slate"><span x-text="fieldValue('weight')"></span> kg</span>
                    </div>
                    {{-- BMI — flagged ≥ 30 (BR-13). --}}
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-[0.72rem] text-hp-slate/70">BMI</span>
                        <span class="text-[0.8rem] font-semibold" :class="bmiFlagged(bmiValue()) ? 'text-hp-orange' : 'text-hp-slate'">
                            <span x-show="bmiFlagged(bmiValue())" x-cloak>⚑ </span><span x-text="bmiValue() !== null ? bmiValue().toFixed(1) : '—'"></span>
                        </span>
                    </div>
                    {{-- Temperature — flagged > 37.2 °C. --}}
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-[0.72rem] text-hp-slate/70">Temperature</span>
                        <span class="text-[0.8rem] font-semibold" :class="tempFlagged(fieldValue('temperature')) ? 'text-hp-orange' : 'text-hp-slate'">
                            <span x-show="tempFlagged(fieldValue('temperature'))" x-cloak>⚑ </span><span x-text="fieldValue('temperature') !== null ? fieldValue('temperature').toFixed(1) : '—'"></span> °C
                        </span>
                    </div>
                    {{-- Blood Pressure — flagged sys ≥ 140 OR dia ≥ 90 (D-10). --}}
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-[0.72rem] text-hp-slate/70">Blood Pressure</span>
                        <span class="text-[0.8rem] font-semibold" :class="bpFlagged(fieldValue('systolic'), fieldValue('diastolic')) ? 'text-hp-orange' : 'text-hp-slate'">
                            <span x-show="bpFlagged(fieldValue('systolic'), fieldValue('diastolic'))" x-cloak>⚑ </span><span x-text="fieldValue('systolic')"></span>/<span x-text="fieldValue('diastolic')"></span> mmHg
                        </span>
                    </div>
                    {{-- Heart Rate — no flag threshold (shown for completeness). --}}
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-[0.72rem] text-hp-slate/70">Heart Rate</span>
                        <span class="text-[0.8rem] font-semibold text-hp-slate"><span x-text="fieldValue('heartRate')"></span> bpm</span>
                    </div>
                </div>
            </div>

            {{-- ── Questionnaire card (Yes/No badges) ───────────────────────── --}}
            <div class="flex flex-col overflow-y-auto rounded-2xl bg-hp-white p-3 shadow-sm">
                <p class="text-[0.62rem] font-semibold uppercase tracking-wider text-hp-slate/50">Questionnaire</p>
                <div class="mt-1.5 flex flex-col divide-y divide-hp-slate/10">
                    <template x-for="sys in systemList" :key="sys.key">
                        <div class="flex items-center justify-between gap-2 py-1">
                            <span class="text-[0.7rem] text-hp-slate/70" x-text="sys.label"></span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[0.62rem] font-semibold"
                                :class="systemAnswer(sys.key) === true
                                    ? 'bg-hp-orange/15 text-hp-orange'
                                    : 'bg-emerald-50 text-emerald-600'"
                                x-text="systemAnswer(sys.key) === true ? 'Yes' : 'No'"
                            ></span>
                        </div>
                    </template>
                    {{-- Pregnancy + LMP. --}}
                    <div class="flex items-center justify-between gap-2 py-1">
                        <span class="text-[0.7rem] text-hp-slate/70">
                            Pregnant
                            <span x-show="state.questionnaire.isPregnant === true" x-cloak class="block text-[0.6rem] text-hp-slate/40">LMP: <span x-text="lmpLabel()"></span></span>
                        </span>
                        <span
                            class="rounded-full px-2 py-0.5 text-[0.62rem] font-semibold"
                            :class="state.questionnaire.isPregnant === true
                                ? 'bg-hp-orange/15 text-hp-orange'
                                : 'bg-emerald-50 text-emerald-600'"
                            x-text="state.questionnaire.isPregnant === true ? 'Yes' : 'No'"
                        ></span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit error (network / server). --}}
        <p x-show="state.submit.status === 'error'" x-cloak class="mt-1.5 text-center text-[0.7rem] font-medium text-red-600" x-text="state.submit.error"></p>

        {{-- ── Footer: back to questionnaire + submit to clinic ─────────────── --}}
        <div class="mt-2 flex items-center justify-between">
            <button
                type="button"
                @click="go('questionnaire')"
                class="text-[0.72rem] font-medium text-hp-slate/60 transition hover:text-hp-orange"
            >← Back to questionnaire</button>
            <button
                type="button"
                @click="submitToClinic()"
                :disabled="state.submit.status === 'sending'"
                class="rounded-xl bg-hp-orange px-6 py-2 text-sm font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60"
                x-text="state.submit.status === 'sending' ? 'Submitting…' : 'Submit to Clinic →'"
            ></button>
        </div>
    </div>
</section>
