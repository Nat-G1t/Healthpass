{{-- Questionnaire (FR-KSK-10, D-63): the new official forms' twelve "Physical
     Signs Disorder of" rows — SKIN … MENTAL DISORDER, reading down the form's
     three columns — as a 2-column grid
     of cards, each with the form's label VERBATIM, one plain-language helper
     line and Yes/No. Plus, for a female student only (D-79), the form's
     pregnancy question, whose "Yes" reveals an inline month calendar for the
     Last Menstrual Period (future dates disabled, LMP required when pregnant).
     The twelve cards render from `systemList`
     (state-machine.js SYSTEMS, mirroring ScreeningResponse::QUESTIONS) — data,
     not twelve copies of markup. The footer shows "{N} of {total} answered"
     (13 for a female student, 12 for a male) and Review & Submit stays
     disabled until all of them are in.

     YES details (the form: "If YES, give details under Remarks"): a Yes card
     offers "Add details", which opens a FULL-WIDTH panel docked at the
     bottom of this screen with the on-screen keyboard — a card in the 2-column
     grid is too narrow to type in on the 1080×1920 portrait panel. ≤ 120
     characters; switching to No clears it. Optional on a Medical Assessment
     (D-56); REQUIRED on a Medical Clearance (D-75) — at least 3 characters
     after trimming, and Review stays locked, naming the question, until then.

     Two columns (was three on the old landscape panel) so each card and its
     Yes/No targets stay big on the portrait screen; the middle area scrolls if
     the LMP calendar is open. --}}
<section class="kiosk-screen" x-show="state.screen === 'questionnaire'" x-cloak
         x-transition:enter="transition ease-hp-out duration-hp-base"
         x-transition:enter-start="opacity-0 translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0">
    <div class="flex h-full w-full flex-col px-8 py-8">

        {{-- Header --}}
        <div class="flex flex-col items-center gap-2 text-center">
            <span class="rounded-full bg-hp-peach/40 px-3.5 py-1 text-xs font-semibold uppercase tracking-widest text-hp-orange">Questionnaire</span>
            {{-- D-68: an Assessment student's form labels this table
                 "(Self Assessment)"; a Clearance student's does not. --}}
            <h1 class="text-2xl font-semibold text-hp-slate" x-text="questionnaireHeading()"></h1>
            <p class="text-base text-hp-slate/60">Answer YES or NO for each.</p>
        </div>

        {{-- Scrollable answer area (grid + pregnancy) --}}
        <div class="mt-5 flex-1 overflow-y-auto">
            {{-- ── 2-column grid of the form's twelve rows ────────────────────── --}}
            <div class="grid grid-cols-2 gap-3">
                {{-- data-system-card lets setSystem() gently scroll the NEXT
                     unanswered card into view; hp-anim-pop lands on whichever
                     answer was just picked (§7). --}}
                <template x-for="sys in systemList" :key="sys.key">
                    <div class="flex flex-col gap-2.5 rounded-xl bg-hp-white p-4 shadow-sm" :data-system-card="sys.key">
                        <div>
                            <p class="text-base font-semibold leading-tight text-hp-slate" x-text="sys.label"></p>
                            <p class="mt-1 text-sm leading-snug text-hp-slate/55" x-text="sys.helper"></p>
                        </div>
                        <div class="mt-auto grid grid-cols-2 gap-2.5">
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

                        {{-- Yes → detail (D-56). Once something is typed the card
                             shows it (truncated) and tapping edits it. On a Medical
                             Clearance it is required (D-75): until it is long enough
                             the trigger wears the solid orange border the kiosk uses
                             for the field that needs input, not the dashed "extra". --}}
                        <button
                            type="button"
                            x-show="systemAnswer(sys.key) === true"
                            x-cloak
                            @click="openDetail(sys.key)"
                            class="flex min-w-0 items-center gap-2 rounded-lg px-3 py-2.5 text-left text-sm font-medium text-hp-orange transition hover:bg-hp-peach/20"
                            :class="detailMissing(sys.key)
                                ? 'border-2 border-hp-orange bg-hp-peach/20'
                                : 'border border-dashed border-hp-orange/50'"
                        >
                            <span x-show="detailText(sys.key) === ''" x-text="detailsRequired() ? '+ Add details (required)' : '+ Add details (optional)'"></span>
                            <span x-show="detailText(sys.key) !== ''" class="min-w-0 flex-1 truncate text-hp-slate/80" x-text="detailText(sys.key)"></span>
                            <span x-show="detailText(sys.key) !== ''" class="shrink-0">Edit</span>
                        </button>
                    </div>
                </template>
            </div>

            {{-- ── Full-width pregnancy question — the form's wording (FR-KSK-10) ──
                 Female students only (D-79); the server stores NO for a male. --}}
            <div x-show="isFemale()" class="mt-3 rounded-xl bg-hp-white p-4 shadow-sm">
                <div class="flex items-center justify-between gap-4">
                    <p class="text-base font-semibold leading-tight text-hp-slate">
                        Are you Pregnant?
                        <span class="block text-sm font-normal text-hp-slate/50">If YES, when is the last menstrual period?</span>
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

        {{-- D-75: say WHICH Yes still needs its details — a disabled button with
             no reason is a dead end at a kiosk. Same inline red line the email
             login and the number pad use for a field that needs fixing. --}}
        <p x-show="firstMissingDetail() !== null" x-cloak
           class="mt-3 text-center text-base font-medium text-red-600"
           x-text="'You answered Yes to ' + (firstMissingDetail()?.label ?? '') + '. Tap “Add details” to say more.'"></p>

        {{-- ── Footer: progress + gated Review & Submit (FR-KSK-10) ─────────── --}}
        <div class="mt-4 flex items-center justify-between">
            <p class="text-base font-medium text-hp-slate/60">
                <span class="font-semibold text-hp-slate" x-text="answeredCount()"></span> of <span x-text="questionCount()"></span> answered
            </p>
            <button
                type="button"
                @click="goReview()"
                :disabled="!questionnaireComplete()"
                class="rounded-2xl bg-hp-orange px-9 py-4 text-lg font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98] disabled:cursor-not-allowed disabled:bg-hp-slate/20 disabled:text-hp-slate/40 disabled:shadow-none"
            >Review &amp; Submit →</button>
        </div>

        {{-- ── YES-details panel (D-56) ─────────────────────────────────────── --}}
        {{-- Dim backdrop over the grid — tapping it is the same as Done. --}}
        <div x-show="state.detailPanel.question !== null" x-cloak
             x-transition:enter="transition ease-hp-out duration-hp-fast"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             class="absolute inset-0 z-20 bg-hp-slate/30"
             @click="closeDetail()"></div>

        {{-- The docked panel: question label, the text, a counter, Done, and the
             shared on-screen keyboard typing into this detail. --}}
        <div x-show="state.detailPanel.question !== null" x-cloak
             class="absolute inset-x-0 bottom-0 z-20 flex flex-col items-center gap-4 rounded-t-3xl bg-hp-bg px-8 pb-8 pt-6 shadow-2xl">
            <div class="flex w-full max-w-2xl items-center justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-widest text-hp-orange" x-text="detailsRequired() ? 'Add details (required)' : 'Add details (optional)'"></p>
                    <p class="truncate text-xl font-semibold text-hp-slate" x-text="detailLabel()"></p>
                </div>
                <button
                    type="button"
                    @click="closeDetail()"
                    class="shrink-0 rounded-2xl bg-hp-orange px-9 py-3 text-lg font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98]"
                >Done</button>
            </div>

            {{-- Display-only text field, like the email field: the keyboard below is
                 the only input. It wraps, so the whole detail stays readable. --}}
            <div class="w-full max-w-2xl rounded-xl border-2 border-hp-orange bg-hp-white px-5 py-3">
                <p class="min-h-[3rem] break-words text-base"
                   :class="detailText(state.detailPanel.question) === '' ? 'text-hp-slate/40' : 'text-hp-slate'"
                   x-text="detailText(state.detailPanel.question) || 'Type a few words for the nurse…'"></p>
                <div class="mt-1 flex items-center justify-between gap-4 text-xs font-medium text-hp-slate/50">
                    {{-- D-75: the minimum, shown only while it isn't met yet. --}}
                    <span x-text="detailMissing(state.detailPanel.question) ? ('At least ' + detailMin + ' characters') : ''"></span>
                    <span><span x-text="detailText(state.detailPanel.question).length"></span> / <span x-text="detailMax"></span></span>
                </div>
            </div>

            @include('kiosk.partials.credential-keyboard', ['target' => 'detail'])
        </div>
    </div>
</section>
