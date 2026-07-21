{{-- Questionnaire (FR-KSK-10): a 2-column grid of nine body-system cards, each
     answered Yes/No, plus a full-width pregnancy question whose "Yes" reveals an
     inline month calendar for the Last Menstrual Period (future dates disabled,
     LMP required when pregnant). The nine cards are rendered from `systemList`
     (state-machine.js) — data, not nine copies of markup. The footer shows
     "{N} of 10 answered" and Review & Submit stays disabled until all 10 are in.

     Two columns (was three on the old landscape panel) so each card and its
     Yes/No targets stay big on the 1080×1920 portrait screen; the middle area
     scrolls if the LMP calendar is open. --}}
<section class="kiosk-screen" x-show="state.screen === 'questionnaire'" x-cloak
         x-transition:enter="transition ease-hp-out duration-hp-base"
         x-transition:enter-start="opacity-0 translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0">
    <div class="flex h-full w-full flex-col px-8 py-8">

        {{-- Header --}}
        <div class="flex flex-col items-center gap-2 text-center">
            <span class="rounded-full bg-hp-peach/40 px-3.5 py-1 text-xs font-semibold uppercase tracking-widest text-hp-orange">Questionnaire</span>
            <h1 class="text-2xl font-semibold text-hp-slate">Have you had problems with any of these?</h1>
            <p class="text-base text-hp-slate/60">Answer Yes or No for each. There are no wrong answers — this just helps the nurse.</p>
        </div>

        {{-- Scrollable answer area (grid + pregnancy) --}}
        <div class="mt-5 flex-1 overflow-y-auto">
            {{-- ── 2-column grid of nine system cards ───────────────────────── --}}
            <div class="grid grid-cols-2 gap-3">
                {{-- data-system-card lets setSystem() gently scroll the NEXT
                     unanswered card into view; hp-anim-pop lands on whichever
                     answer was just picked (§7). --}}
                <template x-for="sys in systemList" :key="sys.key">
                    <div class="flex flex-col gap-2.5 rounded-xl bg-hp-white p-4 shadow-sm" :data-system-card="sys.key">
                        <p class="text-base font-semibold leading-tight text-hp-slate" x-text="sys.label"></p>
                        <div class="grid grid-cols-2 gap-2.5">
                            {{-- Yes = a reported issue → orange (matches the flag colour). --}}
                            <button
                                type="button"
                                @click="setSystem(sys.key, true)"
                                class="rounded-lg py-3 text-base font-semibold transition"
                                :class="systemAnswer(sys.key) === true
                                    ? 'bg-hp-orange text-hp-white shadow-sm hp-anim-pop'
                                    : 'bg-hp-bg text-hp-slate/70 hover:bg-hp-peach/30'"
                            >Yes</button>
                            {{-- No = all clear → green. --}}
                            <button
                                type="button"
                                @click="setSystem(sys.key, false)"
                                class="rounded-lg py-3 text-base font-semibold transition"
                                :class="systemAnswer(sys.key) === false
                                    ? 'bg-emerald-500 text-hp-white shadow-sm hp-anim-pop'
                                    : 'bg-hp-bg text-hp-slate/70 hover:bg-emerald-50'"
                            >No</button>
                        </div>
                    </div>
                </template>
            </div>

            {{-- ── Full-width pregnancy question (FR-KSK-10) ─────────────────── --}}
            <div class="mt-3 rounded-xl bg-hp-white p-4 shadow-sm">
                <div class="flex items-center justify-between gap-4">
                    <p class="text-base font-semibold leading-tight text-hp-slate">
                        Are you currently pregnant?
                        <span class="block text-sm font-normal text-hp-slate/50">If yes, please select the first day of your last menstrual period.</span>
                    </p>
                    <div class="grid shrink-0 grid-cols-2 gap-2.5">
                        <button
                            type="button"
                            @click="setPregnant(true)"
                            class="rounded-lg px-8 py-3 text-base font-semibold transition"
                            :class="state.questionnaire.isPregnant === true
                                ? 'bg-hp-orange text-hp-white shadow-sm hp-anim-pop'
                                : 'bg-hp-bg text-hp-slate/70 hover:bg-hp-peach/30'"
                        >Yes</button>
                        <button
                            type="button"
                            @click="setPregnant(false)"
                            class="rounded-lg px-8 py-3 text-base font-semibold transition"
                            :class="state.questionnaire.isPregnant === false
                                ? 'bg-emerald-500 text-hp-white shadow-sm hp-anim-pop'
                                : 'bg-hp-bg text-hp-slate/70 hover:bg-emerald-50'"
                        >No</button>
                    </div>
                </div>

                {{-- Inline LMP calendar — only when pregnant = Yes (FR-KSK-10). --}}
                <div x-show="state.questionnaire.isPregnant === true" x-cloak
                     x-transition:enter="transition ease-hp-out duration-hp-base"
                     x-transition:enter-start="opacity-0 translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="mt-4 border-t border-hp-slate/10 pt-4">
                    <div class="mx-auto w-full max-w-[22rem]">
                        {{-- Month header with prev / next (next blocked at current month). --}}
                        <div class="flex items-center justify-between">
                            <button
                                type="button"
                                @click="calPrevMonth()"
                                class="grid h-11 w-11 place-items-center rounded-lg bg-hp-bg text-lg text-hp-slate transition hover:bg-hp-peach/30"
                                aria-label="Previous month"
                            >‹</button>
                            <span class="text-base font-semibold text-hp-slate" x-text="calMonthLabel()"></span>
                            <button
                                type="button"
                                @click="calNextMonth()"
                                :disabled="calIsCurrentMonth()"
                                class="grid h-11 w-11 place-items-center rounded-lg bg-hp-bg text-lg text-hp-slate transition hover:bg-hp-peach/30 disabled:cursor-not-allowed disabled:opacity-30"
                                aria-label="Next month"
                            >›</button>
                        </div>

                        {{-- Weekday header --}}
                        <div class="mt-2.5 grid grid-cols-7 gap-1 text-center text-xs font-semibold uppercase text-hp-slate/40">
                            <template x-for="d in ['Su','Mo','Tu','We','Th','Fr','Sa']" :key="d">
                                <span x-text="d"></span>
                            </template>
                        </div>

                        {{-- Day grid. Blanks pad the 1st; future days are disabled. --}}
                        <div class="mt-1 grid grid-cols-7 gap-1">
                            <template x-for="(day, i) in calDays()" :key="i">
                                <div class="aspect-square">
                                    <button
                                        x-show="day !== null"
                                        type="button"
                                        @click="selectLmp(day)"
                                        :disabled="day !== null && calDayIsFuture(day)"
                                        class="grid h-full w-full place-items-center rounded-md text-base font-medium transition"
                                        :class="calDayIsSelected(day)
                                            ? 'bg-hp-orange text-hp-white font-semibold shadow-sm'
                                            : (calDayIsFuture(day)
                                                ? 'text-hp-slate/20 cursor-not-allowed'
                                                : 'text-hp-slate hover:bg-hp-peach/30')"
                                        x-text="day"
                                    ></button>
                                </div>
                            </template>
                        </div>

                        {{-- Selected LMP confirmation / prompt. --}}
                        <p
                            class="mt-2.5 text-center text-sm font-medium"
                            :class="state.questionnaire.lmp ? 'text-hp-orange' : 'text-hp-slate/40'"
                            x-text="state.questionnaire.lmp ? ('Last period: ' + lmpLabel()) : 'Tap a day to record your last period.'"
                        ></p>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Footer: progress + gated Review & Submit (FR-KSK-10) ─────────── --}}
        <div class="mt-4 flex items-center justify-between">
            <p class="text-base font-medium text-hp-slate/60">
                <span class="font-semibold text-hp-slate" x-text="answeredCount()"></span> of 10 answered
            </p>
            <button
                type="button"
                @click="goReview()"
                :disabled="!questionnaireComplete()"
                class="rounded-2xl bg-hp-orange px-9 py-4 text-lg font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98] disabled:cursor-not-allowed disabled:bg-hp-slate/20 disabled:text-hp-slate/40 disabled:shadow-none"
            >Review &amp; Submit →</button>
        </div>
    </div>
</section>
