{{-- Vitals (FR-KSK-05): ONE screen, four internal steps via state.vitalStep.
     Every step renders from the SAME 3-phase card — ready (instructions) →
     scanning (animation) → captured (value + status badge) — driven entirely by
     the VITALS metadata in state-machine.js, so the four steps are data, not
     four copies of markup. Steps 1–3 carry a single reading; step 4 (Blood
     Pressure) groups systolic/diastolic + heart rate captured together.

     The sensor path (FR-KSK-07) fills values; manual entry (FR-KSK-06) is a
     DISGUISED gesture — triple-tap the corner logo — so a student can't simply
     type a fake reading. BMI is computed, never entered (FR-KSK-09). Status
     badges use NEUTRAL wording only ("Normal" / "Slightly Elevated") and never
     a diagnosis or Fit/Unfit (FR-KSK-14).

     Sizing: the panel renders 1:1 on the 1080×1920 portrait Pi screen, so this
     layout centres one generously spaced column — the old 800×480 compactness
     is gone; --k-zoom-vitals scales the type on top of that. --}}
<section class="kiosk-screen" x-show="state.screen === 'vitals'" x-cloak>
    <div class="relative flex h-full w-full flex-col items-center justify-center gap-5 px-8 py-8 text-center">

        {{-- Disguised manual-entry trigger (FR-KSK-06): looks like branding, but
             three taps within ~1.5 s opens the numeric pad for the current step.
             Works on every step because openPad() targets the active step. --}}
        <button
            type="button"
            @click="logoTap()"
            class="absolute right-6 top-6 z-10 select-none p-3"
            aria-label="HealthPass"
        >
            <x-hp.logo size="sm" />
        </button>

        {{-- Header: context pill + progress dots on one row. --}}
        <div class="flex items-center gap-4">
            <span class="rounded-full bg-hp-peach/40 px-3.5 py-1 text-xs font-semibold uppercase tracking-widest text-hp-orange">Vitals</span>
            <div class="flex items-center gap-2">
                <template x-for="n in 4" :key="n">
                    <span
                        class="rounded-full transition-all"
                        :class="n === state.vitalStep
                            ? 'h-3.5 w-3.5 bg-hp-orange ring-2 ring-hp-orange/30'
                            : (n < state.vitalStep ? 'h-3 w-3 bg-hp-orange' : 'h-3 w-3 bg-hp-slate/20')"
                    ></span>
                </template>
            </div>
        </div>

        {{-- ════════════════ The reusable 3-phase card (all 4 steps) ════════════════ --}}
        <div class="flex w-full max-w-md flex-col items-center gap-4">
            <h1 class="text-2xl font-semibold text-hp-slate">
                <span x-text="vitalMeta(state.vitalStep).label"></span>
                <span class="text-base font-normal text-hp-slate/50">· Step <span x-text="state.vitalStep"></span> of 4</span>
            </h1>

            {{-- ── Phase: READY (instructions) ──────────────────────────────── --}}
            <div x-show="stepPhase() === 'ready'" class="flex w-full flex-col items-center gap-4 rounded-2xl bg-hp-white p-8 shadow-sm">
                {{-- Pulsing sensor icon (generic across steps — reusable). --}}
                <div class="relative grid h-20 w-20 place-items-center">
                    <span class="k-pulse-ring absolute h-20 w-20 rounded-full bg-hp-peach/50"></span>
                    <span class="relative grid h-16 w-16 place-items-center rounded-full bg-hp-peach/40">
                        <svg class="h-8 w-8 text-hp-orange" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12a7 7 0 0 1 14 0"></path>
                            <path d="M2 12a10 10 0 0 1 20 0"></path>
                            <circle cx="12" cy="12" r="1.5"></circle>
                        </svg>
                    </span>
                </div>

                <p class="max-w-sm text-base text-hp-slate/80" x-text="vitalMeta(state.vitalStep).instruction"></p>
                <p class="text-sm text-hp-slate/40" x-text="serial.status === 'connected' ? 'Waiting for the sensor…' : 'Ready for entry.'"></p>

                {{-- Web Serial link (FR-KSK-07/FR-HW-05). On Chromium the first
                     "Connect sensor" tap is the user gesture requestPort needs;
                     once connected a quiet green pill confirms it and readings
                     stream in automatically. Everything here is non-blocking —
                     manual entry (the disguised corner triple-tap) always works,
                     so the sensor is never a dead end. --}}
                <template x-if="serial.supported && serial.status !== 'connected'">
                    <button
                        type="button"
                        @click="connectSensors()"
                        class="rounded-lg bg-hp-peach/50 px-5 py-2.5 text-sm font-semibold text-hp-orange transition hover:bg-hp-peach"
                        x-text="serial.status === 'connecting' ? 'Connecting…' : '🔌 Connect sensor'"
                    ></button>
                </template>
                <template x-if="serial.status === 'connected'">
                    <span class="inline-flex items-center gap-1 text-sm font-medium text-emerald-600">● Sensors connected</span>
                </template>

                {{-- Non-blocking serial degrade nudge (unsupported / quiet /
                     unplugged / error) — see onSerialStatus(). --}}
                <p
                    x-show="serial.notice"
                    x-cloak
                    class="max-w-sm rounded-lg bg-hp-peach/40 px-4 py-2 text-sm font-medium text-hp-orange"
                    x-text="serial.notice"
                ></p>

                {{-- Graceful-degrade notice from the sensor path (FR-KSK-07). --}}
                <p
                    x-show="currentStep().notice"
                    x-cloak
                    class="max-w-sm rounded-lg bg-hp-peach/40 px-4 py-2 text-sm font-medium text-hp-orange"
                    x-text="currentStep().notice"
                ></p>

                {{-- Dev-only: injects a plausible reading through receiveReading()
                     — the EXACT path the Web Serial module will call in W5. --}}
                @if (app()->isLocal())
                    <button
                        type="button"
                        @click="simulateReading()"
                        class="rounded-lg bg-hp-slate/10 px-5 py-2.5 text-sm font-medium text-hp-slate/60 transition hover:bg-hp-slate/20"
                    >⚡ Simulate reading (dev)</button>
                @endif
            </div>

            {{-- ── Phase: SCANNING (animation) ──────────────────────────────── --}}
            <div x-show="stepPhase() === 'scanning'" x-cloak class="flex w-full flex-col items-center gap-4 rounded-2xl bg-hp-white p-8 shadow-sm">
                <div class="relative grid h-20 w-20 place-items-center">
                    <span class="k-pulse-ring absolute h-20 w-20 rounded-full bg-hp-orange/40"></span>
                    <span class="k-pulse-ring absolute h-20 w-20 rounded-full bg-hp-orange/30" style="animation-delay: .6s"></span>
                    <span class="relative grid h-16 w-16 animate-pulse place-items-center rounded-full bg-hp-orange text-hp-white">
                        <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"></path>
                        </svg>
                    </span>
                </div>
                <p class="text-lg font-semibold text-hp-slate">Measuring <span x-text="vitalMeta(state.vitalStep).label.toLowerCase()"></span>…</p>
                <p class="text-sm text-hp-slate/50">Hold still.</p>
            </div>

            {{-- ── Phase: CAPTURED (value + badge) ──────────────────────────── --}}
            <div x-show="stepPhase() === 'captured'" x-cloak class="flex w-full flex-col items-center gap-3 rounded-2xl bg-hp-white px-6 py-6 shadow-sm">
                {{-- Primary value. Single-field steps show value + unit; Blood
                     Pressure shows systolic/diastolic together. --}}
                <template x-if="state.vitalStep !== 4">
                    <div class="flex items-baseline gap-2">
                        <span class="text-5xl font-bold text-hp-slate" x-text="formatField(primaryField(), fieldValue(primaryField().key))"></span>
                        <span class="text-xl font-medium text-hp-slate/50" x-text="primaryField().unit"></span>
                    </div>
                </template>
                <template x-if="state.vitalStep === 4">
                    <div class="flex items-baseline gap-2">
                        <span class="text-5xl font-bold text-hp-slate">
                            <span x-text="fieldValue('systolic')"></span><span class="text-hp-slate/40">/</span><span x-text="fieldValue('diastolic')"></span>
                        </span>
                        <span class="text-xl font-medium text-hp-slate/50">mmHg</span>
                    </div>
                </template>

                {{-- Recorded chip + status badge + provenance chip. --}}
                <div class="flex flex-wrap items-center justify-center gap-2.5">
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-600">✓ Recorded</span>

                    {{-- Temperature status badge — neutral wording only (FR-KSK-14). --}}
                    <template x-if="vitalMeta(state.vitalStep).badge === 'temperature'">
                        <span class="rounded-full px-3 py-1 text-sm font-semibold" :class="tempBadgeClass(fieldValue('temperature'))">
                            <span x-show="tempFlagged(fieldValue('temperature'))" x-cloak>⚑ </span><span x-text="tempStatus(fieldValue('temperature'))"></span>
                        </span>
                    </template>

                    {{-- Blood-pressure status badge — neutral wording only (FR-KSK-14). --}}
                    <template x-if="vitalMeta(state.vitalStep).badge === 'bp'">
                        <span class="rounded-full px-3 py-1 text-sm font-semibold" :class="bpBadgeClass(fieldValue('systolic'), fieldValue('diastolic'))">
                            <span x-show="bpFlagged(fieldValue('systolic'), fieldValue('diastolic'))" x-cloak>⚑ </span><span x-text="bpStatus(fieldValue('systolic'), fieldValue('diastolic'))"></span>
                        </span>
                    </template>

                    <span
                        class="rounded-full px-3 py-1 text-sm font-medium"
                        :class="currentStep().method === 'sensor' ? 'bg-hp-peach/40 text-hp-orange' : 'bg-hp-slate/10 text-hp-slate/60'"
                        x-text="currentStep().method === 'sensor' ? 'From sensor' : 'Entered manually'"
                    ></span>
                </div>

                {{-- ── BMI panel (step 2 only) — computed, never entered (FR-KSK-09). ── --}}
                <template x-if="vitalMeta(state.vitalStep).showsBmi && bmiValue() !== null">
                    <div class="w-full rounded-xl border border-hp-slate/10 bg-hp-bg/60 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-hp-slate/50">Body Mass Index</p>
                        <div class="mt-1.5 flex items-center justify-center gap-3">
                            <span class="text-3xl font-bold text-hp-slate" x-text="bmiValue().toFixed(1)"></span>
                            {{-- Colour-coded status (underweight/obese red · normal green ·
                                 overweight orange); ⚑ only at the locked flag threshold (≥30, BR-13). --}}
                            <span
                                class="rounded-full px-3 py-1 text-sm font-semibold"
                                :class="bmiBadgeClass(bmiValue())"
                            >
                                <span x-show="bmiFlagged(bmiValue())" x-cloak>⚑ </span><span x-text="bmiStatus(bmiValue())"></span>
                            </span>
                        </div>
                        <p class="mt-1.5 text-sm text-hp-slate/50">
                            from <span x-text="fieldValue('height')"></span> cm + <span x-text="fieldValue('weight')"></span> kg
                        </p>
                    </div>
                </template>

                {{-- ── Heart-rate sub-panel (step 4 only) — peach (FR-KSK-05). ── --}}
                <template x-if="state.vitalStep === 4 && fieldValue('heartRate') !== null">
                    <div class="w-full rounded-xl bg-hp-peach/30 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-hp-orange/80">Heart Rate</p>
                        <div class="mt-1.5 flex items-baseline justify-center gap-2">
                            <span class="text-3xl font-bold text-hp-slate" x-text="fieldValue('heartRate')"></span>
                            <span class="text-base font-medium text-hp-slate/50">bpm</span>
                        </div>
                    </div>
                </template>

                {{-- Retry / Next. --}}
                <div class="mt-1 flex gap-3">
                    <button
                        type="button"
                        @click="retryVital()"
                        class="rounded-xl border border-hp-slate/20 px-7 py-3 text-base font-medium text-hp-slate transition hover:bg-hp-slate/5"
                    >↻ Retry</button>
                    <button
                        type="button"
                        @click="nextVital()"
                        class="rounded-xl bg-hp-orange px-9 py-3 text-base font-semibold text-hp-white transition hover:brightness-95"
                        x-text="state.vitalStep < 4 ? 'Next →' : 'Continue →'"
                    ></button>
                </div>
            </div>

            {{-- Back to the previous step (not on step 1). --}}
            <button
                x-show="state.vitalStep > 1"
                type="button"
                @click="prevVital()"
                class="rounded-lg px-5 py-2.5 text-base font-medium text-hp-slate/50 transition hover:text-hp-orange"
            >← Previous step</button>
        </div>

        {{-- ════════════════ Manual-entry numeric pad (FR-KSK-06/08) ════════════════ --}}
        {{-- One pad walks the step's fields in turn — a single prompt for most
             steps, three for Blood Pressure (systolic → diastolic → heart rate). --}}
        <div
            x-show="state.pad.open"
            x-cloak
            class="absolute inset-0 z-20 flex items-center justify-center bg-hp-slate/40 backdrop-blur-sm"
            @click.self="padCancel()"
        >
            <div class="w-full max-w-[18rem] rounded-2xl bg-hp-white p-4 shadow-xl">
                <p class="text-center text-base font-semibold text-hp-slate">
                    Enter <span x-text="(padField()?.label ?? vitalMeta(state.pad.step)?.label)?.toLowerCase()"></span>
                    <span class="text-hp-slate/50">(<span x-text="padField()?.unit"></span>)</span>
                </p>

                {{-- Multi-field progress hint (Blood Pressure only). --}}
                <p
                    x-show="vitalMeta(state.pad.step)?.fields.length > 1"
                    x-cloak
                    class="mt-1 text-center text-sm text-hp-slate/40"
                >Field <span x-text="state.pad.fieldIndex + 1"></span> of <span x-text="vitalMeta(state.pad.step)?.fields.length"></span></p>

                {{-- Display --}}
                <div class="mt-2.5 flex min-h-[3.25rem] items-center justify-center rounded-xl border-2 border-hp-slate/15 bg-hp-bg/50 px-4">
                    <span class="text-3xl font-bold text-hp-slate" x-text="state.pad.value || '0'"></span>
                    <span class="ml-2 text-base text-hp-slate/40" x-text="padField()?.unit"></span>
                </div>

                {{-- Re-entry prompt on out-of-range / empty (FR-KSK-08). --}}
                <p class="mt-1.5 min-h-[1.1rem] text-center text-sm font-medium text-red-600" x-text="state.pad.error"></p>

                {{-- Keypad 1–9, ., 0, backspace --}}
                <div class="mt-1.5 grid grid-cols-3 gap-1.5">
                    <template x-for="d in ['1','2','3','4','5','6','7','8','9','.','0','backspace']" :key="d">
                        <button
                            type="button"
                            @pointerdown="pressKey($event.currentTarget)"
                            @animationend="$event.currentTarget.classList.remove('k-key-press')"
                            @click="padKey(d)"
                            class="h-12 rounded-lg bg-hp-bg text-xl font-semibold text-hp-slate shadow-sm transition"
                        >
                            <span x-show="d !== 'backspace'" x-text="d"></span>
                            <span x-show="d === 'backspace'" x-cloak>⌫</span>
                        </button>
                    </template>
                </div>

                {{-- Cancel / Confirm (Confirm reads "Next →" until the last field) --}}
                <div class="mt-3 flex gap-2.5">
                    <button type="button" @click="padCancel()" class="flex-1 rounded-lg border border-hp-slate/20 py-3.5 text-base font-medium text-hp-slate transition hover:bg-hp-slate/5">Cancel</button>
                    <button type="button" @click="padConfirm()" class="flex-1 rounded-lg bg-hp-orange py-3.5 text-base font-semibold text-hp-white transition hover:brightness-95" x-text="padIsLastField() ? 'Confirm' : 'Next →'"></button>
                </div>
            </div>
        </div>
    </div>
</section>
