<x-layout.sidebar title="My Records">

{{-- ── My Records (FR-STU-07) ───────────────────────────────────────────────────
     The list only. D-73 replaced the old detail modal with a page of its own
     (student.records.show), so there is no embedded record JSON here any more —
     nothing clinical is sent to the browser until the student opens a record.
──────────────────────────────────────────────────────────────────────────────── --}}

{{-- ── Page header ─────────────────────────────────────────────────────────── --}}
<div class="mb-7">
    <h2 class="text-xl font-semibold text-hp-slate">My Records</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">Your clinic visit history and clearance results</p>
</div>

{{-- ── Clearance history card ───────────────────────────────────────────────── --}}
<x-hp.card>

    <h3 class="mb-5 text-sm font-semibold text-hp-slate">Clearance History</h3>

    @if ($visits->isEmpty())

        <div class="flex flex-col items-center justify-center py-10 text-center">
            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586
                             a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-hp-slate">No clinic visits yet</p>
            <p class="mt-0.5 text-xs text-hp-slate/50">
                Your records will appear here after your first kiosk visit
            </p>
        </div>

    @else

        {{-- ── Mobile: stacked cards (hidden on sm+) ──────────────────────── --}}
        {{-- hp-stagger: records fade up in sequence on first paint (§6.2). --}}
        <div class="hp-stagger space-y-3 sm:hidden">
            @foreach ($visits as $visit)
            @php
                $isEncoded = $visit->clearanceRecord !== null;
                // D-62: the official form this visit's batch named — "Medical
                // Clearance" or "Medical Assessment Form". formType() reads it off
                // the batch behind the appointment, and falls back to clearance for a
                // legacy visit with no batch.
                $service   = \App\Models\BatchRequest::FORM_TYPES[$visit->formType()];
                $date      = ($visit->checked_in_at ?? $visit->created_at)->format('M j, Y');
            @endphp
            <div class="rounded-xl border border-hp-slate/10 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs text-hp-slate/50">{{ $date }}</p>
                        <p class="mt-0.5 text-sm font-medium text-hp-orange">{{ $service }}</p>
                        <p class="mt-1 font-mono text-xs text-hp-slate/35">{{ $visit->reference_no }}</p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        @if ($isEncoded)
                            <x-hp.badge :variant="$visit->clearanceRecord->result === 'Fit' ? 'fit' : 'unfit'">
                                {{ $visit->clearanceRecord->result }}
                            </x-hp.badge>
                            <a href="{{ route('student.records.show', $visit) }}"
                               class="text-xs font-semibold text-hp-orange hover:underline
                                      focus:outline-none">
                                View
                            </a>
                        @else
                            <x-hp.badge variant="pending">Pending</x-hp.badge>
                            <span class="text-xs text-hp-slate/30">—</span>
                        @endif
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        {{-- ── Desktop: table (hidden below sm) ───────────────────────────── --}}
        <div class="hidden sm:block overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-hp-slate/15">
                        <th class="pb-3 pr-6 text-[11px] font-semibold uppercase
                                   tracking-widest text-hp-slate/40 font-normal">Date</th>
                        <th class="pb-3 pr-6 text-[11px] font-semibold uppercase
                                   tracking-widest text-hp-slate/40 font-normal">Service</th>
                        <th class="pb-3 pr-6 text-[11px] font-semibold uppercase
                                   tracking-widest text-hp-slate/40 font-normal">Result</th>
                        <th class="pb-3 pr-6 text-[11px] font-semibold uppercase
                                   tracking-widest text-hp-slate/40 font-normal">Reference No.</th>
                        <th class="pb-3 text-[11px] font-semibold uppercase
                                   tracking-widest text-hp-slate/40 font-normal text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="hp-stagger divide-y divide-hp-slate/10">
                    @foreach ($visits as $visit)
                    @php
                        $isEncoded = $visit->clearanceRecord !== null;
                        // D-62: the official form this visit's batch named — "Medical
                        // Clearance" or "Medical Assessment Form". formType() reads it off
                        // the batch behind the appointment, and falls back to clearance for a
                        // legacy visit with no batch.
                        $service   = \App\Models\BatchRequest::FORM_TYPES[$visit->formType()];
                        $date      = ($visit->checked_in_at ?? $visit->created_at)->format('M j, Y');
                    @endphp
                    <tr>
                        <td class="py-4 pr-6 text-sm text-hp-slate/60 whitespace-nowrap">
                            {{ $date }}
                        </td>
                        <td class="py-4 pr-6 text-sm font-medium text-hp-orange whitespace-nowrap">
                            {{ $service }}
                        </td>
                        <td class="py-4 pr-6">
                            @if ($isEncoded)
                                <x-hp.badge :variant="$visit->clearanceRecord->result === 'Fit' ? 'fit' : 'unfit'">
                                    {{ $visit->clearanceRecord->result }}
                                </x-hp.badge>
                            @else
                                <x-hp.badge variant="pending">Pending</x-hp.badge>
                            @endif
                        </td>
                        <td class="py-4 pr-6 font-mono text-xs text-hp-slate/40 whitespace-nowrap">
                            {{ $visit->reference_no }}
                        </td>
                        <td class="py-4 text-right whitespace-nowrap">
                            @if ($isEncoded)
                                <a href="{{ route('student.records.show', $visit) }}"
                                   class="text-sm font-semibold text-hp-orange
                                          hover:underline focus:outline-none">
                                    View
                                </a>
                            @else
                                <span class="text-xs text-hp-slate/30">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- One pager for both layouts above — they render the same page. --}}
        <x-hp.pager :paginator="$visits" />

    @endif

</x-hp.card>

</x-layout.sidebar>
