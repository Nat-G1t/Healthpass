{{-- Personal / Social History (FR-KSK-10a, D-68) — section I of the Medical
     Assessment Form's back page, asked ONLY when today's batch named that form
     (state.formType === 'assessment'). A Medical Clearance batch never reaches
     this screen: goReview() skips straight from the questionnaire to Review.

     Four full-width rows, each with the form's label VERBATIM and the paper's
     own boxes as large touch buttons — Yes / No / Quit for the three habits,
     Yes / No for Sexually Active. All four are required before Continue; Back
     returns to the questionnaire. The rows render from `socialHistoryList`
     (state-machine.js SOCIAL_HISTORY, mirroring
     ScreeningResponse::SOCIAL_HISTORY) — data, not four copies of markup.

     One column, full-bleed rows: at --k-zoom on the 1080×1920 portrait panel
     four rows plus header and footer fit without scrolling, and each button
     stays a comfortable standing-distance target. --}}
<section class="kiosk-screen" x-show="state.screen === 'social-history'" x-cloak
         x-transition:enter="transition ease-hp-out duration-hp-base"
         x-transition:enter-start="opacity-0 translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0">
    <div class="flex h-full w-full flex-col px-8 py-8">

        {{-- Header --}}
        <div class="flex flex-col items-center gap-2 text-center">
            <span class="rounded-full bg-hp-peach/40 px-3.5 py-1 text-xs font-semibold uppercase tracking-widest text-hp-orange">Questionnaire</span>
            <h1 class="text-2xl font-semibold text-hp-slate">Personal / Social History</h1>
            {{-- Nat-approved wording — the kiosk says who sees this, and
                 nothing about what it means (FR-KSK-14). --}}
            <p class="text-base text-hp-slate/60">Your answers are confidential and are seen only by the clinic staff.</p>
        </div>

        {{-- ── The form's four rows ─────────────────────────────────────────── --}}
        <div class="mt-6 flex-1 overflow-y-auto">
            <div class="flex flex-col gap-4">
                <template x-for="row in socialHistoryList" :key="row.key">
                    <div class="flex items-center justify-between gap-6 rounded-xl bg-hp-white p-5 shadow-sm"
                         :data-social-row="row.key">
                        <p class="text-lg font-semibold leading-tight text-hp-slate" x-text="row.label"></p>

                        {{-- The paper's boxes. Yes = a reported habit → orange
                             (the kiosk's "reported" colour); No = green; Quit is
                             neither, so it takes the neutral slate selection. --}}
                        <div class="flex shrink-0 gap-3">
                            <template x-for="option in row.options" :key="option">
                                <button
                                    type="button"
                                    @click="setSocialHistory(row.key, option)"
                                    class="min-w-[6.5rem] rounded-lg px-6 py-3.5 text-base font-semibold transition"
                                    :class="socialHistoryAnswer(row.key, option)
                                        ? (option === 'yes'
                                            ? 'bg-hp-orange text-hp-white shadow-sm hp-anim-pop'
                                            : (option === 'no'
                                                ? 'bg-emerald-500 text-hp-white shadow-sm hp-anim-pop'
                                                : 'bg-hp-slate text-hp-white shadow-sm hp-anim-pop'))
                                        : 'bg-hp-bg text-hp-slate/70 hover:bg-hp-peach/30'"
                                    x-text="socialHistoryLabels[option]"
                                ></button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- ── Footer: back to the questionnaire + gated Continue ───────────── --}}
        <div class="mt-4 flex items-center justify-between">
            <button
                type="button"
                @click="go('questionnaire')"
                class="rounded-lg px-3 py-3 text-base font-medium text-hp-slate/60 transition hover:text-hp-orange"
            >← Back</button>
            <button
                type="button"
                @click="goReviewFromSocialHistory()"
                :disabled="!socialHistoryComplete()"
                class="rounded-2xl bg-hp-orange px-9 py-4 text-lg font-semibold text-hp-white shadow-sm transition hover:brightness-95 active:scale-[0.98] disabled:cursor-not-allowed disabled:bg-hp-slate/20 disabled:text-hp-slate/40 disabled:shadow-none"
            >Review &amp; Submit →</button>
        </div>
    </div>
</section>
