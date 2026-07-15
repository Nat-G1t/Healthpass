<x-layout.sidebar title="Analytics">

    {{-- ── Medical Cases by College (FR-ANL-02) ─────────────────────────── --}}
    <x-hp.card>
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-hp-slate">Medical Cases by College</h3>
                <p class="mt-1 text-xs text-hp-slate/50">
                    Total cases per college, broken down by medical system &mdash; sorted by volume.
                </p>
                <p class="text-xs text-hp-slate/50">
                    Students only &mdash; Faculty and NASA excluded. Encoded results only.
                </p>
            </div>
            {{-- Total-cases headline (FR-ANL-02) --}}
            <p class="text-3xl font-bold leading-none text-hp-orange">
                {{ $totalCases }}
                <span class="ml-1 text-sm font-medium text-hp-slate/50">total cases</span>
            </p>
        </div>

        @if ($totalCases === 0)
            <div class="flex flex-col items-center py-14 text-center">
                <p class="text-sm font-medium text-hp-slate/60">No encoded cases yet</p>
                <p class="mt-1 text-xs text-hp-slate/40">
                    Cases appear here once the nurse encodes results with a case category.
                </p>
            </div>
        @else
            {{-- The JS entry reads the JSON off data-chart; {{ }} escaping is
                 undone by the browser when the dataset attribute is read. --}}
            <div class="relative h-[560px]" data-cases-by-college data-chart="{{ json_encode($chart) }}">
                <canvas role="img" aria-label="Stacked bar chart: medical cases per college by medical system. The same numbers are in the data table below."></canvas>
            </div>

            {{-- Summary of Medical Cases (FR-ANL-03, D-30): the chart's
                 numbers in matrix form — one dataset, two formats. Doubles
                 as the chart's accessible fallback and the contrast relief
                 for the light series colors. --}}
            <details class="mt-4">
                <summary class="cursor-pointer text-xs font-semibold text-hp-slate/60 hover:text-hp-slate">
                    View as table
                </summary>

                <div class="mt-4">
                    <h4 class="text-sm font-semibold text-hp-slate">Summary of Medical Cases</h4>
                    <p class="mt-1 text-xs text-hp-slate/50">
                        Rows = medical system &middot; Columns = college &middot; Faculty &amp; NASA excluded.
                    </p>
                </div>

                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[880px] text-left text-xs text-hp-slate">
                        <thead>
                            <tr class="border-b border-gray-200 text-[10px] uppercase tracking-wider text-hp-slate/40">
                                <th class="py-2 pr-3 font-semibold">Medical System</th>
                                @foreach ($matrixColleges as $college)
                                    <th class="px-2 py-2 text-right font-semibold">{{ $college->code }}</th>
                                @endforeach
                                <th class="py-2 pl-3 text-right font-semibold">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($categories as $category)
                                <tr class="border-b border-gray-100">
                                    <td class="py-2 pr-3 font-semibold">{{ $category }}</td>
                                    @foreach ($matrixColleges as $college)
                                        <td class="px-2 py-2 text-right {{ ($counts[$college->id][$category] ?? 0) === 0 ? 'text-hp-slate/30' : '' }}">
                                            {{ $counts[$college->id][$category] ?? 0 }}
                                        </td>
                                    @endforeach
                                    <td class="py-2 pl-3 text-right font-bold">{{ $categoryTotals[$category] ?? 0 }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-gray-200 text-[10px] uppercase tracking-wider">
                                <td class="py-2 pr-3 font-semibold text-hp-slate/40">Total</td>
                                @foreach ($matrixColleges as $college)
                                    <td class="px-2 py-2 text-right text-xs font-bold">{{ $totals[$college->id] ?? 0 }}</td>
                                @endforeach
                                <td class="py-2 pl-3 text-right text-xs font-bold text-hp-orange">{{ $totalCases }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </details>
        @endif

        {{-- Card-level footnote: uncategorized encoded records are counted
             in NEITHER view (chart or matrix), so it stays visible even
             when the matrix is collapsed or the card shows the empty state. --}}
        @if ($uncategorizedCount > 0)
            <p class="mt-4 text-xs text-hp-slate/50">
                {{ $uncategorizedCount }} encoded without category &mdash; excluded from case counts.
            </p>
        @endif
    </x-hp.card>

    @vite('resources/js/director/analytics.js')

</x-layout.sidebar>
