<x-layout.sidebar title="Batch Tracking">

@php
    // D-36: the Reason column only exists once something has been rejected —
    // an all-approved list shouldn't carry a column of em dashes.
    $hasRejected = $batchRequests->contains(fn ($batch) => $batch->status === 'rejected');
    $headers = ['Batch ID', 'Reason', 'Students', 'Submitted', 'Status'];

    // Named "Rejection Reason", not "Reason": the list already has a Reason
    // column (the batch's own reason) and two identical headers would be
    // ambiguous on desktop and in the mobile cards' labels alike.
    if ($hasRejected) {
        $headers[] = 'Rejection Reason';
    }
@endphp

{{--
    One page-level Alpine component holds the rejection-reason modal's state:
    `detail` is null (closed) or the { ref, reason, reviewer, reviewedAt } of
    the row whose View button was clicked.
--}}
<div x-data="{ detail: null }">

{{-- ── Page header ────────────────────────────────────────────────────────── --}}
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="text-xl font-semibold text-hp-slate">Batch Tracking</h2>
        <p class="mt-0.5 text-sm text-hp-slate/50 dark:text-hp-slate/60">
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
                <svg class="h-6 w-6 text-hp-slate/30 dark:text-hp-slate/55" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-hp-slate">No batch requests yet</p>
            <p class="mt-0.5 text-xs text-hp-slate/50 dark:text-hp-slate/60">
                Use “New Batch Request” to submit your first batch for {{ $college->code }}.
            </p>
        </div>
    @else
        <x-hp.table :headers="$headers">
            @foreach ($batchRequests as $batch)
                <x-hp.table-row>
                    {{-- D-40: the reference is now the way into the batch roster,
                         which is where a student's appointment gets withdrawn. --}}
                    <x-hp.table-cell label="Batch ID" class="font-medium">
                        <a href="{{ route('admin.batches.show', $batch->id) }}"
                           class="font-semibold text-hp-orange transition-colors hover:underline">
                            {{ $batch->reference_no }}
                        </a>
                    </x-hp.table-cell>
                    <x-hp.table-cell label="Reason">{{ Str::limit($batch->reasonText(), 60) }}</x-hp.table-cell>
                    <x-hp.table-cell label="Students">{{ $batch->batch_request_students_count }}</x-hp.table-cell>
                    <x-hp.table-cell label="Submitted" class="text-hp-slate/60">{{ $batch->created_at->format('M j, Y') }}</x-hp.table-cell>
                    <x-hp.table-cell label="Status">
                        <x-hp.badge :variant="$batch->status">{{ $batch->statusLabel() }}</x-hp.badge>
                    </x-hp.table-cell>

                    {{-- D-36: why the Director turned this batch down. The cell
                         exists on every row so the desktop table stays aligned;
                         non-rejected rows just carry an em dash. --}}
                    @if ($hasRejected)
                        <x-hp.table-cell label="Rejection Reason">
                            @if ($batch->status === 'rejected' && $batch->rejection_reason !== null)
                                {{-- Plain <button> (not <x-hp.button>): the payload is
                                     built with Js::from(), and Blade output isn't
                                     compiled inside a component tag's attribute. --}}
                                <button type="button"
                                    @click="detail = {{ Illuminate\Support\Js::from([
                                        'ref' => $batch->reference_no,
                                        'reason' => $batch->rejection_reason,
                                        'reviewer' => $batch->reviewer?->name,
                                        'reviewedAt' => $batch->reviewed_at?->format('M j, Y g:i A'),
                                    ]) }}"
                                    class="inline-flex items-center justify-center gap-2 rounded-full
                                           border-[1.5px] border-hp-slate/30 px-4 py-1 text-xs font-semibold
                                           text-hp-slate transition-colors duration-hp-fast hover:bg-hp-slate/10
                                           focus-visible:outline-none focus-visible:ring-2
                                           focus-visible:ring-hp-slate focus-visible:ring-offset-1">
                                    View
                                </button>
                            @else
                                <span class="text-hp-slate/50 dark:text-hp-slate/60">&mdash;</span>
                            @endif
                        </x-hp.table-cell>
                    @endif
                </x-hp.table-row>
            @endforeach
        </x-hp.table>
    @endif
</x-hp.card>

{{-- ── Rejection reason modal (FR-ADM-05, D-36) ───────────────────────────── --}}
{{-- Teleported to <body> so no ancestor's overflow/stacking clips the
     full-screen backdrop (same pattern as <x-logout-confirm>). --}}
<template x-teleport="body">
    <div
        x-show="detail !== null"
        x-cloak
        @keydown.escape.window="detail = null"
        class="fixed inset-0 z-[60] flex items-center justify-center px-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rejection-reason-title"
    >
        {{-- Backdrop — click outside to dismiss --}}
        <div
            x-show="detail !== null"
            @click="detail = null"
            x-transition:enter="ease-hp-out duration-hp-base"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-hp-in duration-hp-fast"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="absolute inset-0 bg-hp-slate/50 dark:bg-black/60"
            aria-hidden="true"
        ></div>

        {{-- Panel --}}
        <div
            x-show="detail !== null"
            x-transition:enter="ease-hp-spring duration-hp-slow"
            x-transition:enter-start="opacity-0 translate-y-6"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="ease-hp-in duration-hp-base"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-6"
            class="relative w-full max-w-md rounded-2xl bg-hp-white p-6 shadow-xl"
        >
            <h2 id="rejection-reason-title" class="text-lg font-semibold text-hp-slate">
                Why <span x-text="detail?.ref"></span> was rejected
            </h2>

            {{-- x-text sets textContent, so Director-written free text is never
                 parsed as markup (the server side of this is Js::from above). --}}
            <p class="mt-4 whitespace-pre-line rounded-lg bg-hp-bg px-4 py-3 text-sm text-hp-slate"
               x-text="detail?.reason"></p>

            <p class="mt-3 text-xs text-hp-slate/50 dark:text-hp-slate/60">
                Reviewed by <span class="font-semibold" x-text="detail?.reviewer ?? 'the Clinic Director'"></span>
                <span x-show="detail?.reviewedAt" x-cloak>
                    on <span x-text="detail?.reviewedAt"></span>
                </span>
            </p>

            <div class="mt-6 flex justify-end">
                <x-hp.button variant="muted" @click="detail = null">Close</x-hp.button>
            </div>
        </div>
    </div>
</template>

</div>

</x-layout.sidebar>
