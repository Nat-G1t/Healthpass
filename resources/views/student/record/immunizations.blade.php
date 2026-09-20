{{-- ── II. Immunization Profile + III. Family Planning Access (D-69) ───────────
     The back page's four vaccine groups, each vaccine marked when it was
     given, plus the group's free-text "Others" line; then the one Yes / No
     family planning question. Neither answered means the box was left blank,
     which is not the same as a No.
──────────────────────────────────────────────────────────────────────────────── --}}

@php
    $sections = $visit->clearanceRecord?->medicalAssessment;
    $others = $sections?->immunizations['others'] ?? null;
    $familyPlanning = $sections?->family_planning_access;
@endphp

<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">II. Immunization Profile</h3>
    <p class="mt-0.5 text-xs text-hp-slate/50">The vaccines the clinic recorded as given.</p>

    <div class="mt-3 space-y-4">
        @foreach (\App\Models\MedicalAssessment::IMMUNIZATIONS as $heading => $vaccines)
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">{{ $heading }}</p>
                <div class="mt-1.5 flex flex-wrap gap-1.5">
                    @foreach ($vaccines as $key => $label)
                        @if ($sections?->hasImmunization($key))
                            <x-hp.badge variant="flagged">{{ $label }}</x-hp.badge>
                        @else
                            <span class="inline-flex rounded-full bg-hp-slate/5 px-2.5 py-0.5
                                         text-[11px] font-semibold leading-tight text-hp-slate/35">
                                {{ $label }}
                            </span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach

        @if (filled($others))
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Others</p>
                <p class="mt-0.5 text-sm text-hp-slate/70">{{ $others }}</p>
            </div>
        @endif
    </div>
</x-hp.card>

<x-hp.card>
    <h3 class="text-sm font-semibold text-hp-slate">III. Family Planning Access</h3>

    <div class="mt-3 flex items-center justify-between gap-3">
        <span class="text-sm text-hp-slate/70">With access to family planning counseling?</span>
        <x-hp.answer :answer="$familyPlanning" />
    </div>
</x-hp.card>
