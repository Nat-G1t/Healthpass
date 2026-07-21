<x-layout.sidebar title="Flagged Anomalies">

    {{-- ── Stat cards (FR-ANL-05): one per flag type ─────────────────────
         Subtitles quote the thresholds from config/healthpass.php (BR-13:
         one source — kiosk badges, queue flags, and this screen agree). --}}
    <div class="hp-stagger mb-6 grid grid-cols-1 gap-5 sm:grid-cols-3">
        <x-hp.card class="border-l-4 border-l-hp-orange">
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                High Blood Pressure
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['bp'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">
                {{ config('healthpass.thresholds.bp_systolic') }}/{{ config('healthpass.thresholds.bp_diastolic') }} mmHg or higher
            </p>
        </x-hp.card>

        <x-hp.card class="border-l-4 border-l-hp-orange">
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Fever
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['temp'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">
                above {{ config('healthpass.thresholds.temperature_max') }}&deg;C
            </p>
        </x-hp.card>

        <x-hp.card class="border-l-4 border-l-hp-orange">
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Abnormal BMI
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['bmi'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">
                BMI of {{ config('healthpass.thresholds.bmi_obese') }} or higher
            </p>
        </x-hp.card>
    </div>

    {{-- ── Flagged visits table (FR-ANL-05) ─────────────────────────────── --}}
    <x-hp.card>
        <div class="mb-2 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-hp-slate">Flagged Visits</h3>
                <p class="mt-1 text-xs text-hp-slate/50">
                    Vitals over a flag threshold &mdash; screening signals, not diagnoses.
                    Includes visits still awaiting the nurse (flags surface from capture).
                </p>
            </div>
            <div class="flex items-center gap-3">
                <x-hp.badge :variant="$visits->isNotEmpty() ? 'flagged' : 'neutral'">
                    {{ $visits->count() }} flagged
                </x-hp.badge>
            </div>
        </div>

        @if ($visits->isEmpty())
            <div class="flex flex-col items-center py-14 text-center">
                <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                    <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-hp-slate">No flagged visits</p>
                <p class="mt-0.5 text-xs text-hp-slate/50">
                    Kiosk vitals that trip a flag threshold will appear here.
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm text-hp-slate">
                    <thead>
                        <tr class="border-b border-gray-200 text-[10px] uppercase tracking-wider text-hp-slate/40">
                            <th class="py-2 pr-3 font-semibold">Student</th>
                            <th class="px-3 py-2 font-semibold">College</th>
                            <th class="px-3 py-2 font-semibold">Flag</th>
                            <th class="px-3 py-2 font-semibold">Value</th>
                            <th class="py-2 pl-3 font-semibold"><span class="sr-only">View</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($visits as $visit)
                            @php $flags = $visit->vitalSigns?->flagDetails() ?? []; @endphp
                            <tr>
                                <td class="py-3 pr-3 font-semibold">{{ $visit->student->name ?? '—' }}</td>
                                {{-- Capture-time college snapshot (FR-STU-09, D-17) --}}
                                <td class="px-3 py-3 text-xs text-hp-slate/60">{{ $visit->college->code ?? '—' }}</td>
                                <td class="px-3 py-3">
                                    <div class="flex flex-col items-start gap-1.5">
                                        @foreach ($flags as $flag)
                                            <x-hp.badge variant="flagged">{{ $flag['label'] }}</x-hp.badge>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    {{-- Same order as the badges beside them --}}
                                    <div class="flex flex-col gap-1.5 text-xs font-bold leading-[18px] text-hp-orange">
                                        @foreach ($flags as $flag)
                                            <span>{{ $flag['value'] }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="py-3 pl-3 text-right">
                                    <a href="{{ route('director.anomalies.show', $visit) }}"
                                       class="text-xs font-semibold text-hp-orange hover:underline">
                                        View
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-hp.card>

</x-layout.sidebar>
