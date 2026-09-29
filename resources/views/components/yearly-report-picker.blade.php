@props([
    // Where Confirm sends the GET (the admin's or the Director's report route).
    'action',
    // The years both pickers offer, newest first — built from the SERVER clock.
    'years',
    // One line under the pickers saying what the report covers.
    'note',
])

{{--
    Yearly Report (PDF) button + its year-span popup (FR-ADM-13, D-81, D-94),
    shared by the College Admin's and the Director's Analytics pages.

    D-94: two pickers instead of one — the START year (`from`) and the END year
    (`to`). An end year before the start is never offered: those options are
    disabled, and moving the start past the end pulls the end along with it.
    YearlyReportRequest refuses it on the server as well.

    The trigger is type="button": it sits inside each page's filter form, and a
    plain <button> would submit that. The popup is teleported to <body> (the
    batches page's pattern) so no ancestor clips the backdrop — and so its own
    <form> is not nested inside the filter form once it is live. Esc, a
    backdrop click or Cancel close it.

    Confirm is a plain GET to the report. The response is a file download
    (Content-Disposition: attachment), so the browser saves it and the page
    never unloads; the popup closes itself on submit. data-no-progress keeps
    the top progress bar (shared/page-motion.js) from starting a run that no
    page load would ever finish.

    `display: contents` on the wrapper: it only carries the Alpine state, so
    the button still lays out as a direct child of the page's button row.
--}}
<div x-data="{ yearlyOpen: false, from: {{ (int) $years[0] }}, to: {{ (int) $years[0] }} }" class="contents">
    <button type="button" @click="yearlyOpen = true"
            class="inline-flex items-center gap-2 rounded-full bg-hp-peach px-4 py-1.5 text-xs
                   font-semibold text-hp-orange transition-[color,background-color,transform]
                   duration-hp-fast ease-hp-out hover:bg-orange-100
                   active:scale-[0.97] focus-visible:outline-none focus-visible:ring-2
                   focus-visible:ring-hp-orange focus-visible:ring-offset-1">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Yearly Report (PDF)
    </button>

    <template x-teleport="body">
        <div x-show="yearlyOpen" x-cloak
             @keydown.escape.window="yearlyOpen = false"
             class="fixed inset-0 z-[60] flex items-center justify-center px-4"
             role="dialog" aria-modal="true" aria-labelledby="yearly-report-title">
            {{-- Backdrop — click outside to dismiss --}}
            <div x-show="yearlyOpen" @click="yearlyOpen = false"
                 x-transition:enter="ease-hp-out duration-hp-base"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-hp-in duration-hp-fast"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="absolute inset-0 bg-hp-slate/50" aria-hidden="true"></div>

            {{-- Panel --}}
            <form method="GET" action="{{ $action }}"
                  data-no-progress
                  @submit="yearlyOpen = false"
                  x-show="yearlyOpen"
                  x-transition:enter="ease-hp-spring duration-hp-slow"
                  x-transition:enter-start="opacity-0 translate-y-6"
                  x-transition:enter-end="opacity-100 translate-y-0"
                  x-transition:leave="ease-hp-in duration-hp-base"
                  x-transition:leave-start="opacity-100 translate-y-0"
                  x-transition:leave-end="opacity-0 translate-y-6"
                  class="relative w-full max-w-sm rounded-2xl bg-hp-white p-6 shadow-xl">
                <h2 id="yearly-report-title" class="text-lg font-semibold text-hp-slate">
                    Download Yearly Report
                </h2>

                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div>
                        <label for="yearly-report-from" class="block text-xs font-medium text-hp-slate/70">Start year</label>
                        <select id="yearly-report-from" name="from" x-model.number="from"
                                @change="if (to < from) to = from"
                                class="mt-1 w-full rounded-lg border-hp-slate/20 py-2 pl-3 pr-8 text-sm text-hp-slate focus:border-hp-orange focus:ring-hp-orange">
                            @foreach ($years as $reportYear)
                                <option value="{{ $reportYear }}" @selected($loop->first)>{{ $reportYear }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="yearly-report-to" class="block text-xs font-medium text-hp-slate/70">End year</label>
                        <select id="yearly-report-to" name="to" x-model.number="to"
                                class="mt-1 w-full rounded-lg border-hp-slate/20 py-2 pl-3 pr-8 text-sm text-hp-slate focus:border-hp-orange focus:ring-hp-orange">
                            @foreach ($years as $reportYear)
                                <option value="{{ $reportYear }}" @selected($loop->first)
                                        :disabled="{{ (int) $reportYear }} < from">{{ $reportYear }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <p class="mt-2 text-xs text-hp-slate/50">
                    {{ $note }}
                    <span x-text="from === to ? `Jan 1 – Dec 31, ${from}.` : `Jan 1, ${from} – Dec 31, ${to}, one section per year.`"></span>
                </p>

                <div class="mt-6 flex justify-end gap-2">
                    <x-hp.button variant="muted" @click="yearlyOpen = false">Cancel</x-hp.button>
                    <x-hp.button type="submit" x-bind:disabled="to < from">Confirm</x-hp.button>
                </div>
            </form>
        </div>
    </template>
</div>
