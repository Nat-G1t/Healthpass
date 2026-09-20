{{-- ── Past Medical History & Family History (D-69) ────────────────────────────
     The front page's eighteen-row table, exactly as the clinic recorded it:
     one tick column for you (Patient) and one for your lineal family. A row
     with neither ticked is simply not recorded.

     Labels come from MedicalAssessment::CONDITIONS — the SAME constant the
     encode screen and the printed form read, so the three cannot drift apart.
──────────────────────────────────────────────────────────────────────────────── --}}

@php
    $sections = $visit->clearanceRecord?->medicalAssessment;
@endphp

<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">Past Medical History &amp; Family History</h3>
    <p class="mt-0.5 text-xs text-hp-slate/50">As recorded by the clinic.</p>

    <div class="mt-3 overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="border-b border-hp-slate/15">
                    <th class="pb-2 pr-4 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                        Condition
                    </th>
                    @foreach (\App\Models\MedicalAssessment::HISTORY_COLUMNS as $column => $heading)
                        <th class="pb-2 pl-3 text-right text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                            {{ $heading }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-hp-slate/10">
                @foreach (\App\Models\MedicalAssessment::CONDITIONS as $key => $condition)
                    @php
                        $specify = $sections?->specifyFor($key);
                    @endphp
                    <tr>
                        <td class="py-2 pr-4 text-sm text-hp-slate/70">
                            {{ $condition['label'] }}
                            @if ($condition['specify'] !== null && filled($specify))
                                <span class="block text-xs text-hp-slate/50">{{ $condition['specify'] }}: {{ $specify }}</span>
                            @endif
                        </td>
                        @foreach (array_keys(\App\Models\MedicalAssessment::HISTORY_COLUMNS) as $column)
                            <td class="py-2 pl-3 text-right">
                                @if ($sections?->hasCondition($column, $key))
                                    <x-hp.badge variant="flagged">Yes</x-hp.badge>
                                @else
                                    <span class="text-xs text-hp-slate/30">&mdash;</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-hp.card>
