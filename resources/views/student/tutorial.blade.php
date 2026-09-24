<x-layout.sidebar title="Kiosk Tutorial">

{{--
    FR-STU-11 — Kiosk Tutorial. One page, seven steps, driven by a single
    Alpine `state` object (same pattern as the kiosk state machine): `x-show`
    swaps step panels client-side, so there is exactly one route and no reload
    between steps. Each of steps 2–7 shows one of Nat's illustrations
    (public/images/tutorial/, web copies of docs/steps/) above its text.
--}}

@php
    // Steps 2–7 share one layout (illustration + instructions), so their
    // content lives in data and the markup is written once in the loop below.
    $steps = [
        2 => [
            'title' => 'Approach the kiosk & log in',
            'body'  => 'Head to the clinic kiosk on the day your college scheduled for you. '
                     . 'Hold your student ID\'s QR code up to the scanner — the kiosk reads '
                     . 'your linked ID and greets you by name.',
            'tip'   => 'Lost your ID? Tap "Log in with email" on the kiosk and use your '
                     . 'HealthPass email and password instead. You can check that your ID is '
                     . 'linked anytime under My ID & Profile.',
            'image' => 'images/tutorial/step1.jpg',
            'alt'   => 'A student holding their ID card up to the kiosk\'s scanner.',
        ],
        3 => [
            'title' => 'Vital signs — Height',
            'body'  => 'Stand upright on the marked spot under the height sensor, look '
                     . 'straight ahead, and hold still. The kiosk captures your height in '
                     . 'centimeters and shows it on screen before moving on.',
            'tip'   => 'If the sensor can\'t get a reading, tap "Enter manually" and type '
                     . 'your height — manual entry is always available on every step.',
            'image' => 'images/tutorial/step2.jpg',
            'alt'   => 'A student standing straight on the platform under the height sensor.',
        ],
        4 => [
            'title' => 'Weight and BMI',
            'body'  => 'Step onto the weighing platform and place both feet fully inside the '
                     . 'weighing plate — no heel or toes on the edge. Stand still until the '
                     . 'number settles. Your BMI is computed automatically from your height '
                     . 'and weight — you never type it in.',
            'tip'   => 'Both feet inside the plate is what makes the weight accurate — a foot on '
                     . 'the edge throws the sensor off. Keep bags and jackets off the platform too.',
            'image' => 'images/tutorial/step3.jpg',
            'alt'   => 'Top-down view of both feet standing fully inside the black weighing plate.',
        ],
        5 => [
            'title' => 'Temperature',
            'body'  => 'Position your forehead in front of the contactless thermometer and '
                     . 'hold still for a moment. The kiosk records your temperature in °C.',
            'tip'   => 'Just came in from the sun? Rest a minute first so the reading '
                     . 'reflects your actual temperature.',
            'image' => 'images/tutorial/step4.jpg',
            'alt'   => 'A student leaning their forehead toward the contactless thermometer.',
        ],
        6 => [
            'title' => 'Blood pressure and pulse rate',
            'body'  => 'Slip your arm into the blood pressure cuff, rest it on the table at '
                     . 'heart level, and relax. The cuff inflates briefly and the kiosk '
                     . 'records your blood pressure and pulse rate together.',
            'tip'   => 'Sit still and don\'t talk during the measurement — movement can '
                     . 'throw off the reading.',
            'image' => 'images/tutorial/step5.jpg',
            'alt'   => 'A seated student with the blood pressure cuff on their upper arm.',
        ],
        7 => [
            'title' => 'Finishing up',
            'body'  => 'Answer the short health questionnaire, review all your readings on '
                     . 'the summary screen, and tap "Submit to Clinic". Then proceed to the '
                     . 'clinic nurse — submitting places you in the nurse\'s queue, and the '
                     . 'nurse takes it from there.',
            'tip'   => 'The kiosk never shows a result. Your clearance outcome appears in '
                     . 'My Records here on HealthPass once the nurse has encoded it.',
            'image' => 'images/tutorial/step6.jpg',
            'alt'   => 'A student tapping the kiosk\'s touchscreen to finish.',
        ],
    ];

    // On laptop screens and up every card is exactly one viewport tall, so the
    // whole step fits without scrolling and step 1 → 2 doesn't jump in size.
    // 7rem = the 3.5rem top bar + the page's lg:p-7 padding (top and bottom);
    // the min-height stops the image shrinking to nothing in a very short window.
    $cardHeight = 'lg:h-[calc(100dvh-7rem)] lg:min-h-[34rem]';
@endphp

{{--
    D-57: reaching the LAST step ("Step 6 of 6: Finishing up") finishes the
    tutorial — complete() tells the server once, and the sidebar's Kiosk
    Tutorial dot is gone from the next page load on. Opening the page alone
    finishes nothing. A failed request is logged and retried the next time the
    student reaches the last step; the server ignores repeats anyway.
--}}
<div
    x-data="{
        state: { step: 1, completionSent: false },
        first: 1,
        last: {{ count($steps) + 1 }},
        completeUrl: @js(route('student.tutorial.complete')),
        next() {
            if (this.state.step < this.last) this.state.step++
            if (this.state.step === this.last) this.complete()
        },
        prev() { if (this.state.step > this.first) this.state.step-- },
        complete() {
            if (this.state.completionSent) return
            this.state.completionSent = true
            fetch(this.completeUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
            })
                .then((response) => { if (! response.ok) throw new Error('HTTP ' + response.status) })
                .catch((error) => {
                    this.state.completionSent = false
                    console.error('The finished Kiosk Tutorial was not recorded:', error)
                })
        },
    }"
>

    {{-- ── Step 1: landing ─────────────────────────────────────────────────── --}}
    {{-- Enter-only transitions (§6.2): each step fades up as it appears; no
         leave transition, so two steps are never visible stacked. --}}
    <div x-show="state.step === 1"
         x-transition:enter="transition ease-hp-out duration-hp-base"
         x-transition:enter-start="opacity-0 translate-y-3"
         x-transition:enter-end="opacity-100 translate-y-0">
        <x-hp.card class="flex flex-col items-center justify-center py-12 text-center sm:py-16 {{ $cardHeight }}">
            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-hp-peach">
                <svg class="h-8 w-8 text-hp-orange" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h16a2 2 0 012 2v10a2 2 0 01-2 2h-2"/>
                </svg>
            </div>

            <h2 class="text-2xl font-semibold text-hp-slate">Kiosk Tutorial</h2>
            <p class="mx-auto mt-2 max-w-md text-base leading-relaxed text-hp-slate/60">
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

    {{-- ── Steps 2–7: shared layout — illustration on top, then instructions ── --}}
    @foreach ($steps as $n => $step)
        <div x-show="state.step === {{ $n }}" x-cloak
             x-transition:enter="transition ease-hp-out duration-hp-base"
             x-transition:enter-start="opacity-0 translate-y-3"
             x-transition:enter-end="opacity-100 translate-y-0">
            <x-hp.card class="flex flex-col {{ $cardHeight }}">

                {{-- Progress: "Step N of 6" + dots --}}
                <div class="mb-4 flex shrink-0 items-center justify-between">
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                        Step {{ $n - 1 }} of {{ count($steps) }}
                    </p>
                    <div class="flex items-center gap-1.5" aria-hidden="true">
                        @foreach (array_keys($steps) as $dot)
                            <span class="h-1.5 w-1.5 rounded-full {{ $dot <= $n ? 'bg-hp-orange' : 'bg-hp-slate/15' }}"></span>
                        @endforeach
                    </div>
                </div>

                {{-- Illustration: takes whatever height the text leaves over.
                     object-contain, never object-cover — nothing is cropped (the
                     weight picture's point is the whole plate with both feet in
                     it). The bg matches the illustrations' warm off-white. --}}
                <div class="flex min-h-0 flex-1 items-center justify-center overflow-hidden rounded-xl bg-hp-bg">
                    <img src="{{ asset($step['image']) }}" alt="{{ $step['alt'] }}"
                         width="1600" height="1195" decoding="async"
                         @if ($n > 2) loading="lazy" @endif
                         class="h-auto w-full object-contain lg:h-full">
                </div>

                {{-- Instructions --}}
                <div class="mt-5 shrink-0">
                    <h3 class="text-xl font-semibold text-hp-slate">{{ $step['title'] }}</h3>
                    <p class="mt-1.5 text-base leading-relaxed text-hp-slate/70">{{ $step['body'] }}</p>

                    <div class="mt-3 flex gap-2.5 rounded-lg bg-hp-bg px-3.5 py-2.5">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24"
                             stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <p class="text-sm leading-relaxed text-hp-slate/60">{{ $step['tip'] }}</p>
                    </div>
                </div>

                {{-- Navigation --}}
                <div class="mt-5 flex shrink-0 items-center justify-between border-t border-hp-slate/10 pt-4">
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
                                  duration-hp-fast hover:bg-orange-500">
                            Done — Back to Dashboard
                        </a>
                    @endif
                </div>

            </x-hp.card>
        </div>
    @endforeach

</div>

</x-layout.sidebar>
