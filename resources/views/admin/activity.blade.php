<x-layout.sidebar title="Activity Log">

{{-- ── Page header ────────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <h2 class="text-xl font-semibold text-hp-slate">Activity Log</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">
        Everything that has happened to {{ $college->code }}'s batch requests — submissions by
        any of this college's admins, and the Clinic Director's decisions on them.
        Newest first.
    </p>
</div>

<x-hp.card>
    @if ($entries->isEmpty())
        <div class="flex flex-col items-center py-10 text-center">
            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-hp-slate">Nothing has happened yet</p>
            <p class="mt-0.5 text-xs text-hp-slate/50">
                Submitting a batch request for {{ $college->code }} will show up here.
            </p>
        </div>
    @else
        <x-hp.table :headers="['When', 'Who', 'What', 'Details']">
            @foreach ($entries as $entry)
                @php
                    // Peach chip for the things that went through, slate for a
                    // rejection — the same variant pairing the batch tables use,
                    // so a status reads identically wherever it appears.
                    [$label, $variant] = match ($entry['type']) {
                        'submitted' => ['Submitted', 'neutral'],
                        'approved'  => ['Approved', 'approved'],
                        'rejected'  => ['Rejected', 'rejected'],
                    };
                @endphp
                <x-hp.table-row>
                    <x-hp.table-cell label="When">
                        <span class="whitespace-nowrap">{{ $entry['at']?->format('M j, Y') }}</span>
                        <span class="block text-[12px] text-hp-slate/50">
                            {{ $entry['at']?->format('g:i A') }}
                        </span>
                    </x-hp.table-cell>

                    <x-hp.table-cell label="Who">
                        {{-- A deleted actor is impossible (restrictOnDelete), but
                             reviewed_by is nullable, so the fallback stays. --}}
                        <span class="font-medium">{{ $entry['actor'] ?? '—' }}</span>
                        <span class="block text-[12px] text-hp-slate/50">
                            {{ $entry['actorRole'] }}
                        </span>
                    </x-hp.table-cell>

                    <x-hp.table-cell label="What">
                        <x-hp.badge :variant="$variant">{{ $label }}</x-hp.badge>
                        <a href="{{ route('admin.batches.show', $entry['batch']) }}"
                           class="mt-1 block font-mono text-[12px] font-medium text-hp-orange hover:underline">
                            {{ $entry['batch']->reference_no }}
                        </a>
                    </x-hp.table-cell>

                    <x-hp.table-cell label="Details">
                        <span class="text-[13px] text-hp-slate/70">{{ $entry['detail'] ?? '—' }}</span>
                    </x-hp.table-cell>
                </x-hp.table-row>
            @endforeach
        </x-hp.table>

        @if ($entries->hasPages())
            <div class="mt-5">
                {{ $entries->links() }}
            </div>
        @endif
    @endif
</x-hp.card>

</x-layout.sidebar>
