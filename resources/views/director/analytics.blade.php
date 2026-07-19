<x-layout.sidebar title="Analytics">

    {{-- The medical-cases charts, matrix, and print/export were removed by
         D-32; the rescoped captured-data analytics land in the rebuild
         phase. What remains: the month scope + the By-Sex donut. --}}

    {{-- ── Screened Students by Sex (FR-ANL-04) ─────────────────────────
         Counts PEOPLE SCREENED — one per encoded visit. --}}
    <x-hp.card class="max-w-2xl">
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-hp-slate">Screened Students by Sex</h3>
                <p class="mt-1 text-xs text-hp-slate/50">
                    Encoded visits, counted once per visit.
                </p>
            </div>
            @if (!empty($availableMonths))
                {{-- Month filter: changing the month reloads the whole page
                     scoped to it. Auto-submits on change; the overlay below
                     shows while the new page loads. --}}
                <form method="GET" action="{{ route('director.analytics') }}" id="analytics-month-form">
                    <label for="analytics-month" class="sr-only">Analytics month</label>
                    <select id="analytics-month" name="month" data-month-select
                            class="rounded-lg border-hp-slate/20 py-1.5 pl-3 pr-8 text-xs font-medium text-hp-slate focus:border-hp-orange focus:ring-hp-orange">
                        @foreach ($availableMonths as $month)
                            <option value="{{ $month['value'] }}" @selected($month['value'] === $selectedMonth)>
                                {{ $month['label'] }}
                            </option>
                        @endforeach
                    </select>
                </form>
            @endif
        </div>

        @if ($totalScreened === 0)
            <div class="flex flex-col items-center py-10 text-center">
                <p class="text-sm font-medium text-hp-slate/60">No encoded visits yet</p>
                <p class="mt-1 text-xs text-hp-slate/40">
                    The donut fills in as the nurse encodes kiosk visits.
                </p>
            </div>
        @else
            <div class="mt-6 flex flex-wrap items-center justify-center gap-8">
                {{-- 160px donut, total in the center. The overlay ignores
                     pointer events so slice tooltips still work. --}}
                <div class="relative h-40 w-40 shrink-0" data-by-sex data-chart="{{ json_encode($donut) }}">
                    <canvas role="img" aria-label="Donut chart: encoded visits by sex. The same counts are in the legend beside it."></canvas>
                    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                        <p class="text-2xl font-bold leading-none text-hp-slate">{{ $totalScreened }}</p>
                        <p class="mt-1 text-[10px] font-semibold uppercase tracking-widest text-hp-slate/40">total</p>
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

    {{-- Reload overlay: shown the moment the Director changes month, and
         torn down when the fresh page paints — so switching months has a
         visible loading beat before the charts re-animate in. --}}
    <div id="analytics-loading"
         class="fixed inset-0 z-50 hidden items-center justify-center bg-white/70 backdrop-blur-sm">
        <svg class="h-10 w-10 animate-spin text-hp-orange" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-90" fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
    </div>

    <script>
        (function () {
            // Month switch: show the reload overlay, then submit the filter
            // form so the page reloads scoped to the chosen month.
            const monthSelect = document.querySelector('[data-month-select]');
            const overlay = document.getElementById('analytics-loading');
            if (monthSelect && overlay) {
                monthSelect.addEventListener('change', () => {
                    overlay.classList.remove('hidden');
                    overlay.classList.add('flex');
                    monthSelect.form.submit();
                });
            }
        })();
    </script>

    @vite('resources/js/director/analytics.js')

</x-layout.sidebar>
