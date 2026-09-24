<x-layout.sidebar title="Analytics">

    {{-- College Admin Analytics (FR-ADM-08, D-45).
         Deliberately the Director's page one college down: same cards, same
         order, same components — only the first card changes, from Clinic
         Visits by College to Clinic Visits by Program. There is no college
         dropdown because there is no college choice to make: the scope banner
         states it and the server derives it from managedCollege(). --}}

    {{-- ── College-scope banner (FR-ADM-01 / FR-ADM-06) ─────────────────── --}}
    <div class="hp-load-card mb-6 flex items-start gap-3 rounded-xl border border-hp-orange/25 bg-hp-peach/40 px-4 py-3.5" style="--i: 0">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24"
             stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-sm text-hp-slate">
            <span class="font-semibold">{{ $college->name }}</span>
            — these figures cover your college only.
        </p>
    </div>

    {{-- ── Filters row ───────────────────────────────────────────────────────
         Month + program scope every card below. Both selects auto-submit the
         GET form; the overlay shows while reloading. --}}
    <form method="GET" action="{{ route('admin.analytics') }}"
          class="hp-load-card mb-4 flex flex-wrap items-center gap-3" style="--i: 1">
        @if (!empty($availableMonths))
            <label for="analytics-month" class="sr-only">Analytics month</label>
            <select id="analytics-month" name="month" data-filter-select
                    class="rounded-lg border-hp-slate/20 py-1.5 pl-3 pr-8 text-xs font-medium text-hp-slate focus:border-hp-orange focus:ring-hp-orange">
                @foreach ($availableMonths as $month)
                    <option value="{{ $month['value'] }}" @selected($month['value'] === $selectedMonth)>
                        {{ $month['label'] }}
                    </option>
                @endforeach
            </select>
        @endif

        <label for="analytics-program" class="sr-only">Program filter</label>
        <select id="analytics-program" name="program" data-filter-select
                class="max-w-[22rem] rounded-lg border-hp-slate/20 py-1.5 pl-3 pr-8 text-xs font-medium text-hp-slate focus:border-hp-orange focus:ring-hp-orange">
            <option value="">All programs</option>
            @foreach ($programs as $programOption)
                <option value="{{ $programOption }}" @selected($programOption === $selectedProgram)>
                    {{ $programOption }}
                </option>
            @endforeach
        </select>

        <p class="text-[11px] text-hp-slate/40">
            Month + program scope every card below · the trend always shows the whole year
        </p>

        {{-- ── Print Monthly Report (FR-ADM-09, D-46) ───────────────────────
             Carries the CURRENT filters into the print URL, so the printout is
             the page the admin is looking at. Nulls are dropped: an empty
             ?program= would read as a filter on "" rather than "all programs".

             data-print-trigger loads the report into the hidden print frame
             below and prints it in place — the print dialog IS the preview,
             so there is no reason to leave Analytics for one. The href stays
             real so middle-click / "open in new tab" still works; opened that
             way the report prints itself instead.

             Not a submit button: this form's GET action is the analytics
             page, and the trend/donut are Chart.js canvases that do not
             print, which is exactly why the report is a separate view. --}}
        @php
            $printQuery = array_filter(
                ['month' => $selectedMonth, 'program' => $selectedProgram],
                fn ($value) => $value !== null,
            );
        @endphp
        {{-- The two report buttons share one wrapper so they sit on one line
             and wrap to the next row together on a narrow screen. x-data here
             is the Yearly Report popup's only state: open or closed. --}}
        <div class="ml-auto flex flex-wrap items-center justify-end gap-2" x-data="{ yearlyOpen: false }">
            <a href="{{ route('admin.analytics.print', $printQuery) }}"
               data-print-trigger
               class="inline-flex items-center gap-2 rounded-full bg-hp-peach px-4 py-1.5 text-xs
                      font-semibold text-hp-orange transition-[color,background-color,transform]
                      duration-hp-fast ease-hp-out hover:bg-orange-100
                      active:scale-[0.97] focus-visible:outline-none focus-visible:ring-2
                      focus-visible:ring-hp-orange focus-visible:ring-offset-1">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Print Monthly Report
            </a>

            {{-- ── Yearly Report (PDF) (FR-ADM-13, D-81) ─────────────────────────
                 type="button": this sits inside the filter form, and a plain
                 <button> would submit it. It only opens the popup below. --}}
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

            {{-- The year picker. Teleported to <body> (the batches page's
                 pattern) so no ancestor clips the backdrop — and so its own
                 <form> is not nested inside the filter form once it is live.
                 Esc, a backdrop click or Cancel close it.

                 Confirm is a plain GET to the report. The response is a file
                 download (Content-Disposition: attachment), so the browser saves
                 it and this page never unloads; the popup closes itself on
                 submit. data-no-progress keeps the top progress bar
                 (shared/page-motion.js) from starting a run that no page load
                 would ever finish. The years come from the server
                 (AnalyticsController) — never the browser clock. --}}
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
                    <form method="GET" action="{{ route('admin.analytics.yearly-report') }}"
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

                        <label for="yearly-report-year" class="mt-4 block text-xs font-medium text-hp-slate/70">Year</label>
                        <select id="yearly-report-year" name="year"
                                class="mt-1 w-full rounded-lg border-hp-slate/20 py-2 pl-3 pr-8 text-sm text-hp-slate focus:border-hp-orange focus:ring-hp-orange">
                            @foreach ($reportYears as $reportYear)
                                <option value="{{ $reportYear }}" @selected($loop->first)>{{ $reportYear }}</option>
                            @endforeach
                        </select>

                        <p class="mt-2 text-xs text-hp-slate/50">
                            Covers every program in {{ $college->code }}, Jan 1 – Dec 31.
                        </p>

                        <div class="mt-6 flex justify-end gap-2">
                            <x-hp.button variant="muted" @click="yearlyOpen = false">Cancel</x-hp.button>
                            <x-hp.button type="submit">Confirm</x-hp.button>
                        </div>
                    </form>
                </div>
            </template>
        </div>
    </form>

    {{-- ── Clinic Visits by Program (FR-ADM-08) ─────────────────────────── --}}
    <x-hp.card class="hp-load-card mb-5" style="--i: 2">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-hp-slate">Clinic Visits by Program</h3>
                <p class="mt-1 text-xs text-hp-slate/50">
                    Kiosk check-ins per program — sorted by volume.
                </p>
            </div>
            <p class="text-3xl font-bold leading-none text-hp-orange">
                <span data-count-up>{{ $totalVisits }}</span>
                <span class="ml-1 text-sm font-medium text-hp-slate/50">visits in {{ $selectedMonthLabel }}</span>
            </p>
        </div>

        @if ($totalVisits === 0)
            <div class="flex flex-col items-center py-10 text-center">
                <p class="text-sm font-medium text-hp-slate/60">No visits recorded for this month yet</p>
                <p class="mt-1 text-xs text-hp-slate/40">
                    The chart fills in as your students check in at the kiosk.
                </p>
            </div>
        @else
            {{-- Horizontal bar — one row per program, zeros included. 56px a
                 row (against the Director's 32) because program names are
                 wrapped onto two or three axis lines, not 3-letter codes. One
                 series since D-60, so no legend: the table repeats the numbers. --}}
            <div data-visits-bar data-chart="{{ json_encode($programBar) }}"
                 style="height: {{ count($programRows) * 56 + 24 }}px">
                <canvas role="img"
                        aria-label="Bar chart: clinic visits per program. The same numbers are in the table below."></canvas>
            </div>

            {{-- "View as table" — also the contrast relief for the orange
                 series (dataviz: sub-3:1 fill needs a table). --}}
            <details class="mt-3">
                <summary class="cursor-pointer text-xs font-semibold text-hp-slate/50">View as table</summary>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-[280px] text-xs text-hp-slate">
                        <thead>
                            <tr class="border-b border-hp-slate/10 text-hp-slate/50">
                                <th class="px-3 py-1.5 text-left font-semibold">Program</th>
                                <th class="px-3 py-1.5 text-right font-semibold">Visits</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($programRows as $row)
                                <tr class="even:bg-hp-slate/[0.04]">
                                    <td class="px-3 py-1.5">{{ $row['program'] }}</td>
                                    <td class="px-3 py-1.5 text-right font-semibold tabular-nums">{{ $row['visits'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-hp-slate/10 font-bold">
                                <td class="px-3 py-1.5">Total</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ $totalVisits }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </details>
        @endif

        {{-- Visits by Purpose (inside the same card): why the month's visits
             happened. Single muted hue + direct value labels; a visit with no
             recorded purpose gets its own "Not specified" bucket. --}}
        <div class="mt-6 border-t border-hp-slate/10 pt-4">
            <h4 class="text-xs font-semibold text-hp-slate">Visits by Purpose</h4>
            <p class="mb-3 mt-0.5 text-xs text-hp-slate/50">
                Why students came — from the linked appointment's purpose. A visit with none recorded counts as Not specified.
            </p>
            @if (empty($purposeRows))
                <p class="py-3 text-center text-xs text-hp-slate/40">No visits recorded for this month yet.</p>
            @else
                <div class="space-y-1.5">
                    @foreach ($purposeRows as $row)
                        <div class="grid grid-cols-[12rem_1fr_2.6rem] items-center gap-2.5">
                            <span class="text-right text-xs text-hp-slate/50">{{ $row['label'] }}</span>
                            <div class="hp-load-bar h-3 rounded-r"
                                 style="--j: {{ $loop->index }}; background:#64748B; width: {{ $purposeMax > 0 ? round($row['count'] / $purposeMax * 100, 1) : 0 }}%"></div>
                            <span class="text-xs font-semibold tabular-nums text-hp-slate">{{ $row['count'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </x-hp.card>

    {{-- ── Vital-Sign Flags (FR-ANL-10) ─────────────────────────────────── --}}
    <x-hp.card class="hp-load-card mb-5" style="--i: 3">
        <div class="mb-4">
            <h3 class="text-sm font-semibold text-hp-slate">Vital-Sign Flags</h3>
            <p class="mt-1 text-xs text-hp-slate/50">
                Which vitals get flagged most, from all captured kiosk screenings in scope.
            </p>
            <p class="text-xs text-hp-slate/50">
                Rate = share of the {{ $screenings }} screenings this month.
            </p>
        </div>

        @if ($screenings === 0)
            <div class="flex flex-col items-center py-10 text-center">
                <p class="text-sm font-medium text-hp-slate/60">No visits recorded for this month yet</p>
                <p class="mt-1 text-xs text-hp-slate/40">Flag tiles fill in as kiosk screenings are captured.</p>
            </div>
        @else
            {{-- Five tiles since D-66 — two per row on narrow, then three, then all five. --}}
            <div class="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
                @foreach ($flagTiles as $tile)
                    <div class="rounded-xl border border-hp-slate/10 px-4 py-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-hp-slate/50">{{ $tile['label'] }}</p>
                        <p class="mt-1 text-3xl font-bold tabular-nums text-hp-slate" data-count-up>{{ $tile['count'] }}</p>
                        <span class="mt-1.5 inline-block rounded-full bg-hp-orange/15 px-2.5 py-0.5 text-xs font-semibold text-amber-700">
                            {{ number_format($tile['rate'], 1) }}% of screenings
                        </span>
                        <p class="mt-1.5 text-[11px] text-hp-slate/40">{{ $tile['sub'] }}</p>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- No Flagged Anomalies screen for this role: row-level vitals are
             clinical data the Nurse and Director act on (FR-ANL-05), so this
             card is counts only. --}}
        <p class="mt-3.5 text-xs text-hp-slate/50">
            Counts only — the clinic follows up flagged students directly.
        </p>
    </x-hp.card>

    {{-- ── Trend: its own row, so the two by-sex cards below sit as a pair ── --}}

        {{-- Visits per Month (FR-ANL-11) — ignores the month filter by design.
             Unlike the Director's, it stays inside this college: an admin is
             never shown another college's numbers, aggregated or not. --}}
        <x-hp.card class="hp-load-card mb-5" style="--i: 4">
            <h3 class="text-sm font-semibold text-hp-slate">Visits per Month</h3>
            <p class="mt-1 text-xs text-hp-slate/50">
                Clinic visits across all months with data — your college's
                whole-year view (ignores the month filter above by design).
            </p>

            @if ($trendMonthCount === 0)
                <div class="flex flex-col items-center py-10 text-center">
                    <p class="text-sm font-medium text-hp-slate/60">No visits recorded yet</p>
                    <p class="mt-1 text-xs text-hp-slate/40">The trend appears once visits span a month.</p>
                </div>
            @else
                {{-- One series since D-60, so no legend: the latest point is
                     direct-labeled by the chart itself. --}}
                <div class="mt-3 h-56" data-trend data-chart="{{ json_encode($trend) }}">
                    <canvas role="img"
                            aria-label="Line chart: clinic visits per month, all months with data."></canvas>
                </div>
            @endif
        </x-hp.card>

    {{-- ── The two by-sex cards, side by side (stacking on narrow) ──────── --}}
    <div class="mb-5 grid gap-5 lg:grid-cols-2">

        {{-- Students Screened by Sex (FR-ANL-04 as amended by D-32). --}}
        <x-hp.card class="hp-load-card" style="--i: 5">
            <h3 class="text-sm font-semibold text-hp-slate">Students Screened by Sex</h3>
            <p class="mt-1 text-xs text-hp-slate/50">
                Captured kiosk visits, counted once per visit. Follows both filters.
            </p>

            @if ($totalScreened === 0)
                <div class="flex flex-col items-center py-10 text-center">
                    <p class="text-sm font-medium text-hp-slate/60">No visits recorded for this month yet</p>
                    <p class="mt-1 text-xs text-hp-slate/40">The donut fills in as students are screened at the kiosk.</p>
                </div>
            @else
                <div class="mt-5 flex flex-wrap items-center justify-center gap-7">
                    {{-- 160px donut, total in the center. The overlay ignores
                         pointer events so slice tooltips still work. --}}
                    <div class="relative h-40 w-40 shrink-0" data-by-sex data-chart="{{ json_encode($donut) }}">
                        <canvas role="img" aria-label="Donut chart: students screened by sex. The same counts are in the legend beside it."></canvas>
                        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                            <p class="text-2xl font-bold leading-none text-hp-slate" data-count-up>{{ $totalScreened }}</p>
                            <p class="mt-1 text-[10px] font-semibold uppercase tracking-widest text-hp-slate/40">screened</p>
                        </div>
                    </div>

                    {{-- Legend: count + % per slice (FR-ANL-04) --}}
                    <div class="space-y-3">
                        @foreach ($bySex as $slice)
                            <div class="flex items-center gap-2.5">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-sm"
                                      style="background-color: {{ $slice['color'] }}"></span>
                                <p class="text-xs text-hp-slate">
                                    <span class="font-semibold">{{ $slice['label'] }}</span>
                                    <span class="ml-1 text-hp-slate/60">
                                        {{ $slice['count'] }} &middot; {{ $slice['percent'] }}%
                                    </span>
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-hp.card>

        @include('partials.analytics-flags-by-sex', ['loadOrder' => 6])
    </div>

    {{-- ── BMI Distribution (FR-ANL-12) — last card, per the mockup ─────── --}}
    <x-hp.card class="hp-load-card" style="--i: 7">
        <h3 class="text-sm font-semibold text-hp-slate">BMI Distribution</h3>
        <p class="mb-3.5 mt-1 text-xs text-hp-slate/50">
            Where screened students fall across BMI categories — rule-based buckets of captured
            vitals, descriptive only. Follows both filters.
        </p>

        @if ($bmiTotal === 0)
            <div class="flex flex-col items-center py-10 text-center">
                <p class="text-sm font-medium text-hp-slate/60">No visits recorded for this month yet</p>
                <p class="mt-1 text-xs text-hp-slate/40">Buckets fill in as kiosk screenings are captured.</p>
            </div>
        @else
            <div class="space-y-1.5">
                @foreach ($bmiRows as $row)
                    <div class="grid grid-cols-[12rem_1fr_2.6rem] items-center gap-2.5">
                        <span class="text-right text-xs text-hp-slate/50">{{ $row['label'] }}</span>
                        <div class="hp-load-bar h-3 rounded-r"
                             style="--j: {{ $loop->index }}; background:#FF8C2A; opacity: {{ $row['opacity'] }}; width: {{ $bmiMax > 0 ? round($row['count'] / $bmiMax * 100, 1) : 0 }}%"></div>
                        <span class="text-xs font-semibold tabular-nums text-hp-slate">{{ $row['count'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-hp.card>

    {{-- Reload overlay: shown the moment a filter changes, torn down when
         the fresh page paints — a visible loading beat between filters. --}}
    <div id="analytics-loading"
         class="fixed inset-0 z-50 hidden items-center justify-center bg-hp-white/70 backdrop-blur-sm">
        <svg class="h-10 w-10 animate-spin text-hp-orange" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-90" fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
    </div>

    <script>
        (function () {
            // Filter switch: show the reload overlay, then submit the GET
            // form so the page reloads scoped to the chosen month/program.
            const overlay = document.getElementById('analytics-loading');
            document.querySelectorAll('[data-filter-select]').forEach((select) => {
                select.addEventListener('change', () => {
                    overlay.classList.remove('hidden');
                    overlay.classList.add('flex');
                    select.form.submit();
                });
            });

            // Back / Forward can bring this page back exactly as it was left:
            // spinner still up, and the select showing the choice that
            // navigated away. Hide the spinner and put the selects back to
            // what this page actually shows (their server-rendered values).
            window.addEventListener('pageshow', () => {
                overlay.classList.add('hidden');
                overlay.classList.remove('flex');
                document.querySelectorAll('[data-filter-select]').forEach((select) => select.form.reset());
            });
        })();
    </script>

    {{-- Hidden frame the Print Monthly Report link loads into (FR-ADM-09). --}}
    @include('partials.print-frame')

    @vite('resources/js/analytics.js')

</x-layout.sidebar>
