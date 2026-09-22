{{-- Flagged Vitals by Sex (FR-ANL-14) — shared by the Director and College
     Admin analytics pages, sitting beside Students Screened by Sex. One
     stacked bar per flag; hovering a segment shows that sex's count. --}}
<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">Flagged Vitals by Sex</h3>
    <p class="mt-1 text-xs text-hp-slate/50">
        This month's flagged readings, split by the student's profile sex. Follows both filters.
    </p>

    @if (collect($flagsBySexRows)->sum('total') === 0)
        <div class="flex flex-col items-center py-10 text-center">
            <p class="text-sm font-medium text-hp-slate/60">No flagged vitals for this month yet</p>
            <p class="mt-1 text-xs text-hp-slate/40">The bars fill in as kiosk screenings are flagged.</p>
        </div>
    @else
        <div class="mt-5 flex items-center gap-5">
            {{-- Legend: server-rendered, the same swatches as the donut's. --}}
            <div class="shrink-0 space-y-3">
                @foreach ($flagsBySex['datasets'] as $dataset)
                    <div class="flex items-center gap-2.5">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-sm"
                              style="background-color: {{ $dataset['backgroundColor'] }}"></span>
                        <p class="text-xs font-semibold text-hp-slate">{{ $dataset['label'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="h-48 min-w-0 flex-1" data-flags-by-sex data-chart="{{ json_encode($flagsBySex) }}">
                <canvas role="img"
                        aria-label="Stacked bar chart: flagged vitals by sex — {{ collect($flagsBySexRows)->map(fn ($row) => "{$row['label']} {$row['male']} male, {$row['female']} female")->implode('; ') }}."></canvas>
            </div>
        </div>
    @endif
</x-hp.card>
