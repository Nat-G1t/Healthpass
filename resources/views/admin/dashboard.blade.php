<x-layout.sidebar title="College Admin Dashboard">

    @if (session('error'))
        <div data-hp-flash data-flash-sticky class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    {{-- ── College-scope banner (FR-ADM-01) ─────────────────────────────── --}}
    <div class="mb-6 flex items-start gap-3 rounded-xl border border-hp-orange/25 bg-hp-peach/40 px-4 py-3.5">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24"
             stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-sm text-hp-slate">
            <span class="font-semibold">{{ $college->name }}</span>
            — you can only manage students and batch requests for your assigned college.
        </p>
    </div>

    {{-- ── Stat cards (FR-ADM-01) ───────────────────────────────────────── --}}
    {{-- hp-stagger + data-hp-countup: cards fade up in sequence, stats count
         up from 0 on first paint (§6.2). --}}
    <div class="hp-stagger mb-6 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">

        <x-hp.card>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Registered Students
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['students'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">in {{ $college->code }}</p>
        </x-hp.card>

        <x-hp.card>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Total Batches
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['batches'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">all time</p>
        </x-hp.card>

        <x-hp.card>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Pending Approval
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['pending'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">awaiting the Director</p>
        </x-hp.card>

        <x-hp.card>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Approved
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['approved'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">scheduled batches</p>
        </x-hp.card>

    </div>

    {{-- ── Batch requests table (FR-ADM-01) ─────────────────────────────── --}}
    <x-hp.card>
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-hp-slate">Batch Requests</h3>
        </div>

        @if ($batchRequests->isEmpty())
            <div class="flex flex-col items-center py-10 text-center">
                <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                    <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-hp-slate">No batch requests yet</p>
                <p class="mt-0.5 text-xs text-hp-slate/50">
                    Use “New Batch Request” to submit your first batch for {{ $college->code }}.
                </p>
            </div>
        @else
            <x-hp.table :headers="['Reference No.', 'Purpose', 'Service', 'Students', 'Scheduled Date', 'Status', 'Submitted']">
                @foreach ($batchRequests as $batch)
                    <x-hp.table-row>
                        <x-hp.table-cell label="Reference No." class="font-medium">{{ $batch->reference_no }}</x-hp.table-cell>
                        <x-hp.table-cell label="Purpose">{{ ucfirst($batch->reason) }}</x-hp.table-cell>
                        <x-hp.table-cell label="Service">{{ ucfirst($batch->service_type) }}</x-hp.table-cell>
                        <x-hp.table-cell label="Students">{{ $batch->batch_request_students_count }}</x-hp.table-cell>
                        <x-hp.table-cell label="Scheduled Date">
                            {{ $batch->scheduled_date?->format('M j, Y') ?? '—' }}
                        </x-hp.table-cell>
                        <x-hp.table-cell label="Status">
                            <x-hp.badge :variant="$batch->status">
                                {{ ucfirst($batch->status) }}
                            </x-hp.badge>
                        </x-hp.table-cell>
                        <x-hp.table-cell label="Submitted" class="text-hp-slate/60">{{ $batch->created_at->format('M j, Y') }}</x-hp.table-cell>
                    </x-hp.table-row>
                @endforeach
            </x-hp.table>
        @endif
    </x-hp.card>

</x-layout.sidebar>
