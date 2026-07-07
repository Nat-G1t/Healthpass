<x-layout.sidebar title="Kiosk Tutorial">

{{--
    FR-STU-11 — Kiosk Tutorial. One page, seven steps, driven by a single
    Alpine `state` object (same pattern as the kiosk state machine): `x-show`
    swaps step panels client-side, so there is exactly one route and no reload
    between steps. GIF slots are styled placeholders until Baldo records the
    real hardware footage.
--}}

@php
    // Steps 2–7 share one layout (media placeholder + instructions), so their
    // content lives in data and the markup is written once in the loop below.
    $steps = [
        2 => [
            'title' => 'Approach the kiosk & log in',
            'body'  => 'Head to the clinic kiosk on your appointment day (or as a walk-in). '
                     . 'Hold your student ID\'s QR code up to the scanner — the kiosk reads '
                     . 'your linked ID and greets you by name.',
            'tip'   => 'Lost your ID? Tap "Log in with email" on the kiosk and use your '
                     . 'HealthPass email and password instead. You can check that your ID is '
                     . 'linked anytime under My ID & Profile.',
        ],
        3 => [
            'title' => 'Vital signs — Height',
            'body'  => 'Stand upright on the marked spot under the height sensor, look '
                     . 'straight ahead, and hold still. The kiosk captures your height in '
                     . 'centimeters and shows it on screen before moving on.',
            'tip'   => 'If the sensor can\'t get a reading, tap "Enter manually" and type '
                     . 'your height — manual entry is always available on every step.',
        ],
        4 => [
            'title' => 'Weight and BMI',
            'body'  => 'Step onto the weighing platform and stand still until the number '
                     . 'settles. Your BMI is computed automatically from your height and '
                     . 'weight — you never type it in.',
            'tip'   => 'Keep heavy items (bags, jackets) off the platform for an accurate reading.',
        ],
        5 => [
            'title' => 'Temperature',
            'body'  => 'Position your forehead in front of the contactless thermometer and '
                     . 'hold still for a moment. The kiosk records your temperature in °C.',
            'tip'   => 'Just came in from the sun? Rest a minute first so the reading '
                     . 'reflects your actual temperature.',
        ],
        6 => [
            'title' => 'Blood pressure and pulse rate',
            'body'  => 'Slip your arm into the blood pressure cuff, rest it on the table at '
                     . 'heart level, and relax. The cuff inflates briefly and the kiosk '
                     . 'records your blood pressure and pulse rate together.',
            'tip'   => 'Sit still and don\'t talk during the measurement — movement can '
                     . 'throw off the reading.',
        ],
        7 => [
            'title' => 'Finishing up',
            'body'  => 'Answer the short health questionnaire, review all your readings on '
                     . 'the summary screen, and tap "Submit to Clinic". Then proceed to the '
                     . 'clinic nurse — submitting places you in the nurse\'s queue, and the '
                     . 'nurse takes it from there.',
            'tip'   => 'The kiosk never shows a result. Your clearance outcome appears in '
                     . 'My Records here on HealthPass once the nurse has encoded it.',
        ],
    ];
@endphp

<div
    class="mx-auto max-w-4xl"
    x-data="{
        state: { step: 1 },
        first: 1,
        last: {{ count($steps) + 1 }},
        next() { if (this.state.step < this.last) this.state.step++ },
        prev() { if (this.state.step > this.first) this.state.step-- },
    }"
>

    {{-- ── Step 1: landing ─────────────────────────────────────────────────── --}}
    <div x-show="state.step === 1">
        <x-hp.card class="py-12 text-center sm:py-16">
            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-hp-peach">
                <svg class="h-8 w-8 text-hp-orange" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h16a2 2 0 012 2v10a2 2 0 01-2 2h-2"/>
                </svg>
            </div>

            <h2 class="text-2xl font-semibold text-hp-slate">Kiosk Tutorial</h2>
            <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-hp-slate/60">
                Learn how to use the clinic's self-service vitals kiosk — from logging
                in with your student ID to handing over to the nurse — in six quick steps.
            </p>

            <x-hp.button size="lg" class="mt-7" @click="next()">
                Get Started
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                </svg>
            </x-hp.button>
        </x-hp.card>
    </div>

    {{-- ── Steps 2–7: shared layout — media placeholder + instructions ────── --}}
    @foreach ($steps as $n => $step)
        <div x-show="state.step === {{ $n }}" x-cloak>
            <x-hp.card>

                {{-- Progress: "Step N of 6" + dots --}}
                <div class="mb-5 flex items-center justify-between">
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                        Step {{ $n - 1 }} of {{ count($steps) }}
                    </p>
                    <div class="flex items-center gap-1.5" aria-hidden="true">
                        @foreach (array_keys($steps) as $dot)
                            <span class="h-1.5 w-1.5 rounded-full {{ $dot <= $n ? 'bg-hp-orange' : 'bg-hp-slate/15' }}"></span>
                        @endforeach
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2 lg:items-center">

                    {{-- GIF placeholder (real footage recorded on hardware later) --}}
                    <div class="flex aspect-video w-full flex-col items-center justify-center gap-3
                                rounded-xl border-2 border-dashed border-hp-peach bg-hp-peach/20">
                        <div class="flex h-14 w-14 items-center justify-center rounded-full bg-white shadow-sm">
                            <svg class="ml-1 h-7 w-7 text-hp-orange" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M8 5v14l11-7z"/>
                            </svg>
                        </div>
                        <p class="text-xs font-semibold text-hp-orange/70">GIF walkthrough coming soon</p>
                    </div>

                    {{-- Instructions --}}
                    <div>
                        <h3 class="text-lg font-semibold text-hp-slate">{{ $step['title'] }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-hp-slate/70">{{ $step['body'] }}</p>

                        <div class="mt-4 flex gap-2.5 rounded-lg bg-hp-bg px-3.5 py-3">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24"
                                 stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <p class="text-xs leading-relaxed text-hp-slate/60">{{ $step['tip'] }}</p>
                        </div>
                    </div>

                </div>

                {{-- Navigation --}}
                <div class="mt-7 flex items-center justify-between border-t border-hp-slate/10 pt-5">
                    <x-hp.button variant="ghost" @click="prev()">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                             stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 17l-5-5m0 0l5-5m-5 5h12"/>
                        </svg>
                        Previous
                    </x-hp.button>

                    @if ($n < count($steps) + 1)
                        <x-hp.button @click="next()">
                            Next
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                                 stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                            </svg>
                        </x-hp.button>
                    @else
                        <a href="{{ route('student.dashboard') }}"
                           class="inline-flex items-center justify-center gap-2 rounded-full bg-hp-orange
                                  px-6 py-2.5 text-sm font-semibold text-white transition-colors
                                  duration-150 hover:bg-orange-500">
                            Done — Back to Dashboard
                        </a>
                    @endif
                </div>

            </x-hp.card>
        </div>
    @endforeach

</div>

</x-layout.sidebar>
