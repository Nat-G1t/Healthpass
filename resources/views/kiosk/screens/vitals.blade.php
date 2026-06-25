{{-- Vitals (FR-KSK-05): ONE screen, four internal steps via state.vitalStep.
     Steps 1–2 (Height, Weight+BMI) use a single reusable 3-phase card:
        ready (instructions)  →  scanning (animation)  →  captured (value+badge).
     The sensor path (FR-KSK-07) fills the value; manual entry (FR-KSK-06) is a
     DISGUISED gesture — triple-tap the corner logo — so a student can't simply
     type a fake reading. BMI is computed, never entered (FR-KSK-09). Step/meta
     data lives in state-machine.js (VITALS) so this Blade is identical per step.

     Sizing: the panel renders 1:1 on the 800×480 Pi screen, so this layout is
     kept deliberately compact (small gaps/paddings) to fit that height with the
     1.3× kiosk zoom — it stays readable and reflows on larger dev displays too. --}}
<section class="kiosk-screen" x-show="state.screen === 'vitals'" x-cloak>
    <div class="relative flex h-full w-full flex-col items-center justify-center gap-1.5 px-6 py-1.5 text-center">

        {{-- Disguised manual-entry trigger (FR-KSK-06): looks like branding, but
             three taps within ~1.5 s opens the numeric pad for the current vital.
             Works on every step because openPad() targets the active step. --}}
        <button
            type="button"
            @click="logoTap()"
            class="absolute right-4 top-3 z-10 select-none"
            aria-label="HealthPass"
        >
            <x-hp.logo size="sm" />
        </button>

        {{-- Compact header: context pill + progress dots on one row. --}}
        <div class="flex items-center gap-3">
            <span class="rounded-full bg-hp-peach/40 px-2.5 py-0.5 text-[0.6rem] font-semibold uppercase tracking-widest text-hp-orange">Vitals</span>
            <div class="flex items-center gap-1.5">
                <template x-for="n in 4" :key="n">
                    <span
                        class="rounded-full transition-all"
                        :class="n === state.vitalStep
                            ? 'h-2.5 w-2.5 bg-hp-orange ring-2 ring-hp-orange/30'
                            : (n < state.vitalStep ? 'h-2 w-2 bg-hp-orange' : 'h-2 w-2 bg-hp-slate/20')"
                    ></span>
                </template>
            </div>
        </div>

        {{-- ════════════════ Built steps (1–2): reusable 3-phase card ════════════════ --}}
        <template x-if="vitalMeta(state.vitalStep)">
            <div class="flex w-full max-w-md flex-col items-center gap-1.5">
                <h1 class="text-xl font-semibold text-hp-slate">
                    <span x-text="vitalMeta(state.vitalStep).label"></span>
                    <span class="text-sm font-normal text-hp-slate/50">· Step <span x-text="state.vitalStep"></span> of 4</span>
                </h1>

                {{-- ── Phase: READY (instructions) ──────────────────────────────── --}}
                <div x-show="currentVital().phase === 'ready'" class="flex w-full flex-col items-center gap-2 rounded-2xl bg-hp-white p-4 shadow-sm">
                    {{-- Pulsing sensor icon (generic across steps — reusable). --}}
                    <div class="relative grid h-14 w-14 place-items-center">
                        <span class="k-pulse-ring absolute h-14 w-14 rounded-full bg-hp-peach/50"></span>
                        <span class="relative grid h-12 w-12 place-items-center rounded-full bg-hp-peach/40">
                            <svg class="h-6 w-6 text-hp-orange" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 12a7 7 0 0 1 14 0"></path>
                                <path d="M2 12a10 10 0 0 1 20 0"></path>
                                <circle cx="12" cy="12" r="1.5"></circle>
                            </svg>
                        </span>
                    </div>

                    <p class="max-w-sm text-xs text-hp-slate/80" x-text="vitalMeta(state.vitalStep).instruction"></p>
                    <p class="text-[0.65rem] text-hp-slate/40">Waiting for the sensor…</p>

                    {{-- Graceful-degrade notice from the sensor path (FR-KSK-07). --}}
                    <p
                        x-show="currentVital().notice"
                        x-cloak
                        class="max-w-sm rounded-lg bg-hp-peach/40 px-3 py-1.5 text-[0.7rem] font-medium text-hp-orange"
                        x-text="currentVital().notice"
                    ></p>

                    {{-- Dev-only: injects a plausible reading through receiveReading()
                         — the EXACT path the Web Serial module will call in W5. --}}
                    @if (app()->isLocal())
                        <button
                            type="button"
                            @click="simulateReading()"
                            class="rounded-md bg-hp-slate/10 px-3 py-1 text-[0.65rem] font-medium text-hp-slate/60 transition hover:bg-hp-slate/20"
                        >⚡ Simulate reading (dev)</button>
                    @endif
                </div>

                {{-- ── Phase: SCANNING (animation) ──────────────────────────────── --}}
                <div x-show="currentVital().phase === 'scanning'" x-cloak class="flex w-full flex-col items-center gap-2 rounded-2xl bg-hp-white p-5 shadow-sm">
                    <div class="relative grid h-16 w-16 place-items-center">
                        <span class="k-pulse-ring absolute h-16 w-16 rounded-full bg-hp-orange/40"></span>
                        <span class="k-pulse-ring absolute h-16 w-16 rounded-full bg-hp-orange/30" style="animation-delay: .6s"></span>
                        <span class="relative grid h-12 w-12 animate-pulse place-items-center rounded-full bg-hp-orange text-hp-white">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"></path>
                            </svg>
                        </span>
                    </div>
                    <p class="text-sm font-semibold text-hp-slate">Measuring <span x-text="vitalMeta(state.vitalStep).label.toLowerCase()"></span>…</p>
                    <p class="text-[0.65rem] text-hp-slate/50">Hold still.</p>
                </div>

                {{-- ── Phase: CAPTURED (value + badge) ──────────────────────────── --}}
                <div x-show="currentVital().phase === 'captured'" x-cloak class="flex w-full flex-col items-center gap-1 rounded-2xl bg-hp-white px-3 py-2 shadow-sm">
                    {{-- Large value + unit. --}}
                    <div class="flex items-baseline gap-2">
                        <span class="text-4xl font-bold text-hp-slate" x-text="currentVital().value"></span>
                        <span class="text-lg font-medium text-hp-slate/50" x-text="vitalMeta(state.vitalStep).unit"></span>
                    </div>

                    {{-- Status badge + provenance chip. --}}
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[0.7rem] font-semibold text-emerald-600">✓ Recorded</span>
                        <span
                            class="rounded-full px-2.5 py-0.5 text-[0.7rem] font-medium"
                            :class="currentVital().method === 'sensor' ? 'bg-hp-peach/40 text-hp-orange' : 'bg-hp-slate/10 text-hp-slate/60'"
                            x-text="currentVital().method === 'sensor' ? 'From sensor' : 'Entered manually'"
                        ></span>
                    </div>

                    {{-- ── BMI panel (step 2 only) — computed, never entered (FR-KSK-09). ── --}}
                    <template x-if="state.vitalStep === 2 && bmiValue() !== null">
                        <div class="w-full rounded-xl border border-hp-slate/10 bg-hp-bg/60 p-2">
                            <p class="text-[0.6rem] font-semibold uppercase tracking-wider text-hp-slate/50">Body Mass Index</p>
                            <div class="mt-0.5 flex items-center justify-center gap-2.5">
                                <span class="text-2xl font-bold text-hp-slate" x-text="bmiValue().toFixed(1)"></span>
                                {{-- Colour-coded status (underweight/obese red · normal green ·
                                     overweight orange); ⚑ only at the locked flag threshold (≥30, BR-13). --}}
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="bmiBadgeClass(bmiValue())"
                                >
                                    <span x-show="bmiFlagged(bmiValue())" x-cloak>⚑ </span><span x-text="bmiStatus(bmiValue())"></span>
                                </span>
                            </div>
                            <p class="mt-0.5 text-[0.7rem] text-hp-slate/50">
                                from <span x-text="state.vitals.height.value"></span> cm + <span x-text="state.vitals.weight.value"></span> kg
                            </p>
                        </div>
                    </template>

                    {{-- Retry / Next. --}}
                    <div class="flex gap-2">
                        <button
                            type="button"
                            @click="retryVital()"
                            class="rounded-lg border border-hp-slate/20 px-4 py-1.5 text-xs font-medium text-hp-slate transition hover:bg-hp-slate/5"
                        >↻ Retry</button>
                        <button
                            type="button"
                            @click="nextVital()"
                            class="rounded-lg bg-hp-orange px-5 py-1.5 text-xs font-semibold text-hp-white transition hover:brightness-95"
                        >Next →</button>
                    </div>
                </div>

                {{-- Back to the previous step (not on step 1). --}}
                <button
                    x-show="state.vitalStep > 1"
                    type="button"
                    @click="prevVital()"
                    class="text-[0.7rem] font-medium text-hp-slate/50 transition hover:text-hp-orange"
                >← Previous step</button>
            </div>
        </template>

        {{-- ════════════════ Steps 3–4: not built yet (placeholder) ════════════════ --}}
        <template x-if="!vitalMeta(state.vitalStep)">
            <div class="flex w-full max-w-md flex-col items-center gap-3">
                <h1 class="text-xl font-semibold text-hp-slate">Step <span x-text="state.vitalStep"></span> of 4</h1>
                <p class="text-xs text-hp-slate/60">
                    <span x-text="state.vitalStep === 3 ? 'Temperature' : 'Blood Pressure + Heart Rate'"></span>
                    — coming in a later build.
                </p>
                <div class="flex gap-2">
                    <button type="button" @click="prevVital()" class="rounded-lg border border-hp-slate/20 px-4 py-1.5 text-xs font-medium text-hp-slate hover:bg-hp-slate/5">← Prev step</button>
                    <button type="button" @click="nextVital()" class="rounded-lg bg-hp-orange px-4 py-1.5 text-xs font-semibold text-hp-white hover:brightness-95" x-text="state.vitalStep < 4 ? 'Next step →' : 'Continue →'"></button>
                </div>
            </div>
        </template>

        {{-- ════════════════ Manual-entry numeric pad (FR-KSK-06/08) ════════════════ --}}
        <div
            x-show="state.pad.open"
            x-cloak
            class="absolute inset-0 z-20 flex items-center justify-center bg-hp-slate/40 backdrop-blur-sm"
            @click.self="padCancel()"
        >
            <div class="w-full max-w-[15rem] rounded-2xl bg-hp-white p-2.5 shadow-xl">
                <p class="text-center text-xs font-semibold text-hp-slate">
                    Enter <span x-text="vitalMeta(state.vitalStep)?.label.toLowerCase()"></span>
                    <span class="text-hp-slate/50">(<span x-text="vitalMeta(state.vitalStep)?.unit"></span>)</span>
                </p>

                {{-- Display --}}
                <div class="mt-1.5 flex min-h-[2.25rem] items-center justify-center rounded-xl border-2 border-hp-slate/15 bg-hp-bg/50 px-4">
                    <span class="text-2xl font-bold text-hp-slate" x-text="state.pad.value || '0'"></span>
                    <span class="ml-2 text-sm text-hp-slate/40" x-text="vitalMeta(state.vitalStep)?.unit"></span>
                </div>

                {{-- Re-entry prompt on out-of-range / empty (FR-KSK-08). --}}
                <p class="mt-1 min-h-[0.9rem] text-center text-[0.7rem] font-medium text-red-600" x-text="state.pad.error"></p>

                {{-- Keypad 1–9, ., 0, backspace --}}
                <div class="mt-1 grid grid-cols-3 gap-1">
                    <template x-for="d in ['1','2','3','4','5','6','7','8','9','.','0','backspace']" :key="d">
                        <button
                            type="button"
                            @click="padKey(d)"
                            class="h-8 rounded-lg bg-hp-bg text-lg font-semibold text-hp-slate shadow-sm transition active:scale-95 active:bg-hp-peach/50"
                        >
                            <span x-show="d !== 'backspace'" x-text="d"></span>
                            <span x-show="d === 'backspace'" x-cloak>⌫</span>
                        </button>
                    </template>
                </div>

                {{-- Cancel / Confirm --}}
                <div class="mt-2 flex gap-2">
                    <button type="button" @click="padCancel()" class="flex-1 rounded-lg border border-hp-slate/20 py-2 text-xs font-medium text-hp-slate transition hover:bg-hp-slate/5">Cancel</button>
                    <button type="button" @click="padConfirm()" class="flex-1 rounded-lg bg-hp-orange py-2 text-xs font-semibold text-hp-white transition hover:brightness-95">Confirm</button>
                </div>
            </div>
        </div>
    </div>
</section>
