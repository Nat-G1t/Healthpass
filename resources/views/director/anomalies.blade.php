<x-layout.sidebar title="Flagged Anomalies">

    {{--
        Stub (FR-ANL-05). The real screen — three stat cards (High Blood
        Pressure / Fever / Abnormal BMI) and the flagged-visits table — comes
        with the analytics build; this page exists so the dashboard's
        "View all →" already lands somewhere real.
    --}}
    <x-hp.card>
        <div class="flex flex-col items-center py-14 text-center">
            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-hp-slate">Flagged Anomalies is under construction</p>
            <p class="mt-0.5 text-xs text-hp-slate/50">
                The full view — stat cards and the flagged-visits table — is coming with Analytics.
            </p>
            <a href="{{ route('director.dashboard') }}"
               class="mt-4 text-xs font-semibold text-hp-orange hover:underline">
                &larr; Back to Dashboard
            </a>
        </div>
    </x-hp.card>

</x-layout.sidebar>
