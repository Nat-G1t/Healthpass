<x-layout.sidebar title="College Admin Dashboard">

    @if (session('error'))
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
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
    <div class="mb-6 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">

        <x-hp.card>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Registered Students
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate">{{ $stats['students'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">in {{ $college->code }}</p>
        </x-hp.card>

        <x-hp.card>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Total Batches
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate">{{ $stats['batches'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">all time</p>
        </x-hp.card>

        <x-hp.card>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Pending Approval
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate">{{ $stats['pending'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">awaiting the Director</p>
        </x-hp.card>

        <x-hp.card>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Approved
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate">{{ $stats['approved'] }}</p>
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
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-hp-slate/10 text-[11px] uppercase tracking-widest text-hp-slate/40">
                            <th class="py-2.5 pr-4 font-semibold">Reference No.</th>
                            <th class="py-2.5 pr-4 font-semibold">Purpose</th>
                            <th class="py-2.5 pr-4 font-semibold">Service</th>
                            <th class="py-2.5 pr-4 font-semibold">Students</th>
                            <th class="py-2.5 pr-4 font-semibold">Scheduled Date</th>
                            <th class="py-2.5 pr-4 font-semibold">Status</th>
                            <th class="py-2.5 font-semibold">Submitted</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hp-slate/10">
                        @foreach ($batchRequests as $batch)
                            <tr class="text-hp-slate">
                                <td class="py-3 pr-4 font-medium">{{ $batch->reference_no }}</td>
                                <td class="py-3 pr-4">{{ ucfirst($batch->reason) }}</td>
                                <td class="py-3 pr-4">{{ ucfirst($batch->service_type) }}</td>
                                <td class="py-3 pr-4">{{ $batch->batch_request_students_count }}</td>
                                <td class="py-3 pr-4">
                                    {{ $batch->scheduled_date?->format('M j, Y') ?? '—' }}
                                </td>
                                <td class="py-3 pr-4">
                                    <x-hp.badge :variant="$batch->status">
                                        {{ ucfirst($batch->status) }}
                                    </x-hp.badge>
                                </td>
                                <td class="py-3 text-hp-slate/60">{{ $batch->created_at->format('M j, Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-hp.card>

</x-layout.sidebar>
