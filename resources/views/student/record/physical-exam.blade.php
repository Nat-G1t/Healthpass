{{-- ── Pertinent Physical Examination (D-70) ───────────────────────────────────
     The back page's eight groups A–H. Only the findings the clinic actually
     ticked are listed; a group with none shows "No findings recorded", which
     is what an empty group on the paper means.

     "Essentially Normal" is a finding like any other here — the paper lets the
     examiner tick it alongside others, and inventing a rule it does not have
     would lose what the clinician meant.
──────────────────────────────────────────────────────────────────────────────── --}}

@php
    $sections = $visit->clearanceRecord?->medicalAssessment;
@endphp

<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">Pertinent Physical Examination</h3>
    <p class="mt-0.5 text-xs text-hp-slate/50">What the clinic found during your examination.</p>

    <div class="mt-3 grid gap-x-8 gap-y-4 sm:grid-cols-2">
        @foreach (\App\Models\MedicalAssessment::PHYSICAL_EXAM as $key => $group)
            @php
                $found = array_filter(
                    $group['findings'],
                    fn (string $label, string $finding): bool => (bool) $sections?->hasFinding($key, $finding),
                    ARRAY_FILTER_USE_BOTH,
                );
                $others = $sections?->findingOthers($key);
            @endphp
            <div class="border-b border-hp-slate/10 pb-3">
                <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">{{ $group['label'] }}</p>
                @if ($found === [] && blank($others))
                    <p class="mt-1 text-sm text-hp-slate/40">No findings recorded.</p>
                @else
                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        @foreach ($found as $label)
                            <x-hp.badge variant="flagged">{{ $label }}</x-hp.badge>
                        @endforeach
                    </div>
                    @if (filled($others))
                        <p class="mt-1.5 text-xs text-hp-slate/60">Others: {{ $others }}</p>
                    @endif
                @endif
            </div>
        @endforeach
    </div>
</x-hp.card>
