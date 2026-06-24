{{-- Vitals (FR-KSK-05): one screen, 4 internal steps via state.vitalStep. Placeholder. --}}
<section class="kiosk-screen" x-show="state.screen === 'vitals'" x-cloak>
    <div class="flex w-full flex-col items-center justify-center gap-4 px-10 text-center">
        <span class="rounded-full bg-hp-peach/40 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-hp-orange">Vitals</span>

        {{-- Progress dots (FR-KSK-05) --}}
        <div class="flex gap-2">
            <template x-for="n in 4" :key="n">
                <span class="h-2.5 w-2.5 rounded-full" :class="n === state.vitalStep ? 'bg-hp-orange' : 'bg-hp-slate/20'"></span>
            </template>
        </div>

        <h1 class="text-2xl font-semibold text-hp-slate">
            Vital Step <span x-text="state.vitalStep"></span> of 4
        </h1>
        <p class="max-w-md text-sm text-hp-slate/70">Placeholder for FR-KSK-05/06/07 (Height → Weight+BMI → Temperature → Blood Pressure; sensor + manual paths).</p>

        <div class="mt-2 flex gap-3">
            <button type="button" @click="state.vitalStep = Math.max(1, state.vitalStep - 1)" class="rounded-lg border border-hp-slate/20 px-4 py-2 text-sm font-medium text-hp-slate hover:bg-hp-slate/5">Prev step</button>
            <button
                type="button"
                x-show="state.vitalStep < 4"
                @click="state.vitalStep = Math.min(4, state.vitalStep + 1)"
                class="rounded-lg bg-hp-orange px-4 py-2 text-sm font-semibold text-hp-white hover:brightness-95"
            >Next step</button>
            <button
                type="button"
                x-show="state.vitalStep === 4"
                @click="go('questionnaire')"
                class="rounded-lg bg-hp-orange px-4 py-2 text-sm font-semibold text-hp-white hover:brightness-95"
            >Continue →</button>
        </div>
    </div>
</section>
