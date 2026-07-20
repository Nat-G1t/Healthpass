<x-layout.sidebar title="Batch Tracking">

{{-- ── Page header ────────────────────────────────────────────────────────── --}}
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="text-xl font-semibold text-hp-slate">Batch Tracking</h2>
        <p class="mt-0.5 text-sm text-hp-slate/50">
            All batch requests submitted for {{ $college->code }}.
        </p>
    </div>

    <a href="{{ route('admin.batches.create') }}"
       class="inline-flex items-center justify-center gap-2 rounded-full bg-hp-orange
              px-5 py-2 text-sm font-semibold text-white transition-colors
              duration-hp-fast hover:bg-orange-500">
        New Batch Request
    </a>
</div>

{{-- ── Batch requests table (FR-ADM-05) ───────────────────────────────────── --}}
<x-hp.card>
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
                        <th class="py-2.5 pr-4 font-semibold">Batch ID</th>
                        <th class="py-2.5 pr-4 font-semibold">Reason</th>
                        <th class="py-2.5 pr-4 font-semibold">Students</th>
                        <th class="py-2.5 pr-4 font-semibold">Submitted</th>
                        <th class="py-2.5 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hp-slate/10">
                    @foreach ($batchRequests as $batch)
                        <tr class="text-hp-slate">
                            <td class="py-3 pr-4 font-medium">{{ $batch->reference_no }}</td>
                            <td class="py-3 pr-4">{{ Str::limit($batch->reasonText(), 60) }}</td>
                            <td class="py-3 pr-4">{{ $batch->batch_request_students_count }}</td>
                            <td class="py-3 pr-4 text-hp-slate/60">{{ $batch->created_at->format('M j, Y') }}</td>
                            <td class="py-3">
                                <x-hp.badge :variant="$batch->status">{{ $batch->statusLabel() }}</x-hp.badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-hp.card>

</x-layout.sidebar>
