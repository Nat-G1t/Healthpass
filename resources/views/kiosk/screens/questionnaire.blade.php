{{-- Questionnaire (FR-KSK-10): a 3-column grid of nine body-system cards, each
     answered Yes/No, plus a full-width pregnancy question whose "Yes" reveals an
     inline month calendar for the Last Menstrual Period (future dates disabled,
     LMP required when pregnant). The nine cards are rendered from `systemList`
     (state-machine.js) — data, not nine copies of markup. The footer shows
     "{N} of 10 answered" and Review & Submit stays disabled until all 10 are in.

     Compact spacing (like the vitals screen) so the grid + pregnancy section fit
     the 800×480 Pi panel; the middle area scrolls if the calendar is open. --}}
<section class="kiosk-screen" x-show="state.screen === 'questionnaire'" x-cloak>
    <div class="flex h-full w-full flex-col px-6 py-3">

        {{-- Header --}}
        <div class="flex flex-col items-center gap-1 text-center">
            <span class="rounded-full bg-hp-peach/40 px-2.5 py-0.5 text-[0.6rem] font-semibold uppercase tracking-widest text-hp-orange">Questionnaire</span>
            <h1 class="text-lg font-semibold text-hp-slate">Have you had problems with any of these?</h1>
            <p class="text-[0.7rem] text-hp-slate/60">Answer Yes or No for each. There are no wrong answers — this just helps the nurse.</p>
        </div>

        {{-- Scrollable answer area (grid + pregnancy) --}}
        <div class="mt-2 flex-1 overflow-y-auto">
            {{-- ── 3-column grid of nine system cards ───────────────────────── --}}
            <div class="grid grid-cols-3 gap-2">
                <template x-for="sys in systemList" :key="sys.key">
                    <div class="flex flex-col gap-1.5 rounded-xl bg-hp-white p-2 shadow-sm">
                        <p class="text-[0.72rem] font-semibold leading-tight text-hp-slate" x-text="sys.label"></p>
                        <div class="grid grid-cols-2 gap-1.5">
                            {{-- Yes = a reported issue → orange (matches the flag colour). --}}
                            <button
                                type="button"
                                @click="setSystem(sys.key, true)"
                                class="rounded-lg py-1 text-[0.7rem] font-semibold transition"
                                :class="systemAnswer(sys.key) === true
                                    ? 'bg-hp-orange text-hp-white shadow-sm'
                                    : 'bg-hp-bg text-hp-slate/70 hover:bg-hp-peach/30'"
                            >Yes</button>
                            {{-- No = all clear → green. --}}
                            <button
                                type="button"
                                @click="setSystem(sys.key, false)"
                                class="rounded-lg py-1 text-[0.7rem] font-semibold transition"
                                :class="systemAnswer(sys.key) === false
                                    ? 'bg-emerald-500 text-hp-white shadow-sm'
                                    : 'bg-hp-bg text-hp-slate/70 hover:bg-emerald-50'"
                            >No</button>
                        </div>
                    </div>
                </template>
            </div>

            {{-- ── Full-width pregnancy question (FR-KSK-10) ─────────────────── --}}
            <div class="mt-2 rounded-xl bg-hp-white p-2.5 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-[0.72rem] font-semibold leading-tight text-hp-slate">
                        Are you currently pregnant?
                        <span class="block text-[0.62rem] font-normal text-hp-slate/50">If yes, please select the first day of your last menstrual period.</span>
                    </p>
                    <div class="grid shrink-0 grid-cols-2 gap-1.5">
                        <button
                            type="button"
                            @click="setPregnant(true)"
                            class="rounded-lg px-4 py-1 text-[0.7rem] font-semibold transition"
                            :class="state.questionnaire.isPregnant === true
                                ? 'bg-hp-orange text-hp-white shadow-sm'
                                : 'bg-hp-bg text-hp-slate/70 hover:bg-hp-peach/30'"
                        >Yes</button>
                        <button
                            type="button"
                            @click="setPregnant(false)"
                            class="rounded-lg px-4 py-1 text-[0.7rem] font-semibold transition"
                            :class="state.questionnaire.isPregnant === false
                                ? 'bg-emerald-500 text-hp-white shadow-sm'
                                : 'bg-hp-bg text-hp-slate/70 hover:bg-emerald-50'"
                        >No</button>
                    </div>
                </div>

                {{-- Inline LMP calendar — only when pregnant = Yes (FR-KSK-10). --}}
                <div x-show="state.questionnaire.isPregnant === true" x-cloak class="mt-2.5 border-t border-hp-slate/10 pt-2.5">
                    <div class="mx-auto w-full max-w-[16rem]">
                        {{-- Month header with prev / next (next blocked at current month). --}}
                        <div class="flex items-center justify-between">
                            <button
                                type="button"
                                @click="calPrevMonth()"
                                class="grid h-6 w-6 place-items-center rounded-md bg-hp-bg text-hp-slate transition hover:bg-hp-peach/30"
                                aria-label="Previous month"
                            >‹</button>
                            <span class="text-[0.72rem] font-semibold text-hp-slate" x-text="calMonthLabel()"></span>
                            <button
                                type="button"
                                @click="calNextMonth()"
                                :disabled="calIsCurrentMonth()"
                                class="grid h-6 w-6 place-items-center rounded-md bg-hp-bg text-hp-slate transition hover:bg-hp-peach/30 disabled:cursor-not-allowed disabled:opacity-30"
                                aria-label="Next month"
                            >›</button>
                        </div>

                        {{-- Weekday header --}}
                        <div class="mt-1.5 grid grid-cols-7 gap-0.5 text-center text-[0.55rem] font-semibold uppercase text-hp-slate/40">
                            <template x-for="d in ['Su','Mo','Tu','We','Th','Fr','Sa']" :key="d">
                                <span x-text="d"></span>
                            </template>
                        </div>

                        {{-- Day grid. Blanks pad the 1st; future days are disabled. --}}
                        <div class="mt-0.5 grid grid-cols-7 gap-0.5">
                            <template x-for="(day, i) in calDays()" :key="i">
                                <div class="aspect-square">
                                    <button
                                        x-show="day !== null"
                                        type="button"
                                        @click="selectLmp(day)"
                                        :disabled="day !== null && calDayIsFuture(day)"
                                        class="grid h-full w-full place-items-center rounded-md text-[0.65rem] font-medium transition"
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
                            class="mt-1.5 text-center text-[0.66rem] font-medium"
                            :class="state.questionnaire.lmp ? 'text-hp-orange' : 'text-hp-slate/40'"
                            x-text="state.questionnaire.lmp ? ('Last period: ' + lmpLabel()) : 'Tap a day to record your last period.'"
                        ></p>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Footer: progress + gated Review & Submit (FR-KSK-10) ─────────── --}}
        <div class="mt-2 flex items-center justify-between">
            <p class="text-[0.72rem] font-medium text-hp-slate/60">
                <span class="font-semibold text-hp-slate" x-text="answeredCount()"></span> of 10 answered
            </p>
            <button
                type="button"
                @click="goReview()"
                :disabled="!questionnaireComplete()"
                class="rounded-xl bg-hp-orange px-6 py-2 text-sm font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98] disabled:cursor-not-allowed disabled:bg-hp-slate/20 disabled:text-hp-slate/40 disabled:shadow-none"
            >Review &amp; Submit →</button>
        </div>
    </div>
</section>
