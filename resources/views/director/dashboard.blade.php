<x-layout.sidebar title="Director Dashboard">

    @if (session('error'))
        <div data-hp-flash data-flash-sticky class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    {{-- ── KPI cards (FR-ANL-01) ────────────────────────────────────────── --}}
    {{-- hp-stagger: KPI cards fade up one after another on first paint (§6.2);
         data-hp-countup animates each stat from 0 to its value. --}}
    <div class="hp-stagger mb-6 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">

        {{-- Accent card — matches the prototype's orange-edged lead KPI --}}
        <x-hp.card class="border-l-4 border-l-hp-orange">
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Total Encoded Clearances
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-orange" data-hp-countup>{{ $stats['clearances'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">all encoded results</p>
        </x-hp.card>

        <x-hp.card>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Pending Batches
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['pendingBatches'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">awaiting your decision</p>
        </x-hp.card>

        <x-hp.card>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Today&rsquo;s Appointments
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['todaysAppointments'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">{{ today()->format('M j, Y') }}</p>
        </x-hp.card>

        <x-hp.card>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Flagged Visits
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['flaggedVisits'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">vitals over a flag threshold</p>
        </x-hp.card>

    </div>

    {{-- ── Preview panels (FR-ANL-01) ───────────────────────────────────── --}}
    <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">

        {{-- Pending Batch Approvals → Batch Approvals --}}
        <x-hp.card>
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-hp-slate">Pending Batch Approvals</h3>
                <x-hp.badge :variant="$stats['pendingBatches'] > 0 ? 'flagged' : 'neutral'">
                    {{ $stats['pendingBatches'] }} pending
                </x-hp.badge>
            </div>

            @if ($pendingBatches->isEmpty())
                <div class="flex flex-col items-center py-10 text-center">
                    <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                        <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                             stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <p class="text-sm font-medium text-hp-slate">No pending batch approvals</p>
                    <p class="mt-0.5 text-xs text-hp-slate/50">
                        New requests from College Admins will appear here.
                    </p>
                </div>
            @else
                <div class="divide-y divide-hp-slate/10">
                    @foreach ($pendingBatches as $batch)
                        <div class="flex items-center justify-between gap-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-hp-slate">
                                    {{ $batch->college->name }}
                                </p>
                                <p class="text-xs text-hp-slate/50">
                                    {{ $batch->batch_request_students_count }}
                                    {{ Str::plural('student', $batch->batch_request_students_count) }}
                                    · submitted {{ $batch->created_at->format('M j, Y') }}
                                </p>
                            </div>
                            <x-hp.badge variant="pending">Pending</x-hp.badge>
                        </div>
                    @endforeach
                </div>
            @endif

            <a href="{{ route('director.batches.index') }}"
               class="mt-4 inline-block text-xs font-semibold text-hp-orange hover:underline">
                View all &rarr;
            </a>
        </x-hp.card>

        {{-- Flagged Anomalies → /director/anomalies --}}
        <x-hp.card>
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-hp-slate">Flagged Anomalies</h3>
                <x-hp.badge :variant="$stats['flaggedVisits'] > 0 ? 'flagged' : 'neutral'">
                    {{ $stats['flaggedVisits'] }} flagged
                </x-hp.badge>
            </div>

            @if ($flaggedVisits->isEmpty())
                <div class="flex flex-col items-center py-10 text-center">
                    <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                        <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                             stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                    </div>
                    <p class="text-sm font-medium text-hp-slate">No flagged visits</p>
                    <p class="mt-0.5 text-xs text-hp-slate/50">
                        Kiosk vitals that trip a flag threshold will appear here.
                    </p>
                </div>
            @else
                <div class="divide-y divide-hp-slate/10">
                    @foreach ($flaggedVisits as $visit)
                        <div class="flex items-center justify-between gap-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-hp-slate">
                                    {{ $visit->student->name ?? '—' }}
                                </p>
                                <p class="truncate text-xs text-hp-orange">
                                    {{ implode(' · ', $visit->vitalSigns?->flagDescriptions() ?? []) }}
                                </p>
                            </div>
                            {{-- Capture-time college snapshot (FR-STU-09) --}}
                            <span class="shrink-0 text-xs text-hp-slate/50">
                                {{ $visit->college->code ?? '—' }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif

            <a href="{{ route('director.anomalies') }}"
               class="mt-4 inline-block text-xs font-semibold text-hp-orange hover:underline">
                View all &rarr;
            </a>
        </x-hp.card>

    </div>

</x-layout.sidebar>
