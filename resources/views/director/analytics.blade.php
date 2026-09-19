<x-layout.sidebar title="Analytics">

    {{-- Director Analytics, rebuilt after the D-32 rescope (FR-ANL-09..13).
         Layout follows the approved mockup
         (docs/prototypes/web/director-analytics-rescope.html): filters row,
         Visits by College (purpose inside), Vital-Sign Flags, trend + donut
         side by side, BMI last. No print, no CSV export (FR-ANL-06 retired). --}}

    {{-- ── Filters row (FR-ANL-13) ──────────────────────────────────────────
         Month + college scope every card except the trend. Both selects
         auto-submit the GET form; the overlay shows while reloading. --}}
    <form method="GET" action="{{ route('director.analytics') }}"
          class="mb-4 flex flex-wrap items-center gap-3">
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

        <label for="analytics-college" class="sr-only">College filter</label>
        <select id="analytics-college" name="college" data-filter-select
                class="rounded-lg border-hp-slate/20 py-1.5 pl-3 pr-8 text-xs font-medium text-hp-slate focus:border-hp-orange focus:ring-hp-orange">
            <option value="">All colleges</option>
            @foreach ($colleges as $collegeOption)
                <option value="{{ $collegeOption->id }}" @selected($collegeOption->id === $selectedCollegeId)>
                    {{ $collegeOption->code }}
                </option>
            @endforeach
        </select>

        <p class="text-[11px] text-hp-slate/40">
            Month + college scope every card below · the trend always shows the whole year
        </p>
    </form>

    {{-- ── Clinic Visits by College / by Program (FR-ANL-09, D-46) ────────
         With "All colleges" this is the by-College card, unchanged. Filter to
         ONE college and it becomes that college's PROGRAMS — a single college
         bar has nothing to compare itself against, and the programs are the
         same visits one level down (the College Admin's FR-ADM-08 card, from
         the same ClinicAnalytics builder). Only the rows, the bar and the unit
         label change; the totals are identical either way. --}}
    @php
        $byProgram = $selectedCollegeId !== null;
        $unitLabel = $byProgram ? 'Program' : 'College';
        $unitKey = $byProgram ? 'program' : 'code';
        $unitRows = $byProgram ? $programRows : $collegeRows;
        $unitBar = $byProgram ? $programBar : $collegeBar;
        // Program names wrap onto two or three axis lines; college codes are
        // three letters on one.
        $unitRowHeight = $byProgram ? 56 : 32;
    @endphp
    <x-hp.card class="mb-5">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-hp-slate">Clinic Visits by {{ $unitLabel }}</h3>
                <p class="mt-1 text-xs text-hp-slate/50">
                    Kiosk check-ins per {{ strtolower($unitLabel) }} — sorted by volume.
                </p>
            </div>
            <p class="text-3xl font-bold leading-none text-hp-orange">
                {{ $totalVisits }}
                <span class="ml-1 text-sm font-medium text-hp-slate/50">visits in {{ $selectedMonthLabel }}</span>
            </p>
        </div>

        @if ($totalVisits === 0)
            <div class="flex flex-col items-center py-10 text-center">
                <p class="text-sm font-medium text-hp-slate/60">No visits recorded for this month yet</p>
                <p class="mt-1 text-xs text-hp-slate/40">
                    The chart fills in as students check in at the kiosk.
                </p>
            </div>
        @else
            {{-- Horizontal bar — one row per unit, zeros included. Height
                 scales with the row count so single-college filtering doesn't
                 stretch one bar across the card. One series since D-60, so no
                 legend is needed: the table toggle below repeats the numbers. --}}
            <div data-visits-bar data-chart="{{ json_encode($unitBar) }}"
                 style="height: {{ count($unitRows) * $unitRowHeight + 24 }}px">
                <canvas role="img"
                        aria-label="Bar chart: clinic visits per {{ strtolower($unitLabel) }}. The same numbers are in the table below."></canvas>
            </div>

            {{-- "View as table" (FR-ANL-09) — also the contrast relief for
                 the orange series (dataviz: sub-3:1 fill needs a table). --}}
            <details class="mt-3">
                <summary class="cursor-pointer text-xs font-semibold text-hp-slate/50">View as table</summary>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-[280px] text-xs text-hp-slate">
                        <thead>
                            <tr class="border-b border-hp-slate/10 text-hp-slate/50">
                                <th class="px-3 py-1.5 text-left font-semibold">{{ $unitLabel }}</th>
                                <th class="px-3 py-1.5 text-right font-semibold">Visits</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($unitRows as $row)
                                <tr class="even:bg-hp-slate/[0.04]">
                                    <td class="px-3 py-1.5">{{ $row[$unitKey] }}</td>
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

        {{-- Visits by Purpose (inside the same card, FR-ANL-09): why the
             month's visits happened. Single muted hue + direct value
             labels; a visit with no recorded purpose gets its own
             "Not specified" bucket. --}}
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
                            <div class="h-3 rounded-r"
                                 style="background:#64748B; width: {{ $purposeMax > 0 ? round($row['count'] / $purposeMax * 100, 1) : 0 }}%"></div>
                            <span class="text-xs font-semibold tabular-nums text-hp-slate">{{ $row['count'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </x-hp.card>

    {{-- ── Vital-Sign Flags (FR-ANL-10) ─────────────────────────────────── --}}
    <x-hp.card class="mb-5">
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
            <div class="grid gap-3.5 sm:grid-cols-3">
                @foreach ($flagTiles as $tile)
                    <div class="rounded-xl border border-hp-slate/10 px-4 py-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-hp-slate/50">{{ $tile['label'] }}</p>
                        <p class="mt-1 text-3xl font-bold tabular-nums text-hp-slate">{{ $tile['count'] }}</p>
                        <span class="mt-1.5 inline-block rounded-full bg-hp-orange/15 px-2.5 py-0.5 text-xs font-semibold text-amber-700">
                            {{ number_format($tile['rate'], 1) }}% of screenings
                        </span>
                        <p class="mt-1.5 text-[11px] text-hp-slate/40">{{ $tile['sub'] }}</p>
                    </div>
                @endforeach
            </div>
        @endif

        <p class="mt-3.5 text-xs text-hp-slate/50">
            Row-level detail lives on the <span class="font-semibold">Flagged Anomalies</span> screen.
        </p>
    </x-hp.card>

    {{-- ── Trend + donut, side by side (stacking on narrow) ─────────────── --}}
    <div class="mb-5 grid gap-5 lg:grid-cols-5">

        {{-- Visits per Month (FR-ANL-11) — ignores both filters by design. --}}
        <x-hp.card class="lg:col-span-3">
            <h3 class="text-sm font-semibold text-hp-slate">Visits per Month</h3>
            <p class="mt-1 text-xs text-hp-slate/50">
                Clinic visits across all months with data — the whole-year,
                all-college view (ignores the filters above by design).
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

        {{-- Students Screened by Sex (FR-ANL-04 as amended by D-32). --}}
        <x-hp.card class="lg:col-span-2">
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
                            <p class="text-2xl font-bold leading-none text-hp-slate">{{ $totalScreened }}</p>
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
    </div>

    {{-- ── BMI Distribution (FR-ANL-12) — last card, per the mockup ─────── --}}
    <x-hp.card>
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
                        <div class="h-3 rounded-r"
                             style="background:#FF8C2A; opacity: {{ $row['opacity'] }}; width: {{ $bmiMax > 0 ? round($row['count'] / $bmiMax * 100, 1) : 0 }}%"></div>
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
            // form so the page reloads scoped to the chosen month/college.
            const overlay = document.getElementById('analytics-loading');
            document.querySelectorAll('[data-filter-select]').forEach((select) => {
                select.addEventListener('change', () => {
                    overlay.classList.remove('hidden');
                    overlay.classList.add('flex');
                    select.form.submit();
                });
            });
        })();
    </script>

    @vite('resources/js/analytics.js')

</x-layout.sidebar>
