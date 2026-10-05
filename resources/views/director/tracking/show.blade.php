<x-layout.sidebar title="Batch {{ $batch->reference_no }}">

{{--
    A rejected or college-cancelled batch, for the Director (FR-DIRA-10,
    D-112) — what the Rejected batches card's View opens.

    Cards, stacked in this order (single column on phones; details and
    students side by side on wide screens, as on the Review page):
      1. Batch details  — what the college sent, and the signed memo if it
                          attached one (D-109)
      2. Students       — ten rows tall, then it scrolls inside the card
      3. The reason     — the Director's rejection reason, or the college's
                          cancellation reason (D-92), with who and when

    Free text (the reasons) is printed with {{ }}, which escapes it.
--}}

@php
    $isRejected = $batch->status === 'rejected';
    $afterApproval = $batch->wasCancelledAfterApproval();
    $stamp = 'M j, Y · g:i A';
@endphp

{{-- ── Page header ─────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <a href="{{ route('director.tracking.index') }}"
       class="inline-flex items-center gap-1.5 text-xs font-semibold text-hp-slate/50 transition-colors hover:text-hp-orange">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
        </svg>
        Batch Tracking
    </a>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
        <h2 class="text-xl font-semibold text-hp-slate">Batch {{ $batch->reference_no }}</h2>
        <x-hp.badge :variant="$isRejected ? 'rejected' : 'neutral'">
            {{ $isRejected ? 'Rejected' : 'Cancelled by college' }}
        </x-hp.badge>
    </div>
</div>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

    {{-- ── Card 1: batch details (as on the Review page) ─────────────────── --}}
    <x-hp.card>
        <p class="mb-5 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Batch Details</p>

        <dl class="space-y-4">
            <div class="flex items-start justify-between gap-4">
                <dt class="text-sm text-hp-slate/50">Batch ID</dt>
                <dd class="font-mono text-sm font-semibold tracking-wider text-hp-orange">{{ $batch->reference_no }}</dd>
            </div>

            <div class="border-t border-hp-slate/10"></div>

            <div class="flex items-start justify-between gap-4">
                <dt class="text-sm text-hp-slate/50">College</dt>
                <dd class="text-right text-sm font-semibold text-hp-slate">
                    {{ $batch->college->name }} <span class="font-normal text-hp-slate/50">({{ $batch->college->code }})</span>
                </dd>
            </div>
            <div class="flex items-start justify-between gap-4">
                <dt class="text-sm text-hp-slate/50">Form</dt>
                <dd class="text-right text-sm font-semibold text-hp-slate">{{ $batch->formTypeLabel() }}</dd>
            </div>
            <div class="flex items-start justify-between gap-4">
                <dt class="text-sm text-hp-slate/50">Purpose</dt>
                <dd class="text-right text-sm font-semibold text-hp-slate">{{ $batch->reasonText() }}</dd>
            </div>
            <div class="flex items-start justify-between gap-4">
                <dt class="text-sm text-hp-slate/50">Students</dt>
                <dd class="text-sm font-semibold text-hp-slate">{{ $students->count() }}</dd>
            </div>
            <div class="flex items-start justify-between gap-4">
                <dt class="text-sm text-hp-slate/50">Submitted</dt>
                <dd class="text-right text-sm font-semibold text-hp-slate">{{ $batch->created_at->format($stamp) }}</dd>
            </div>
            <div class="flex items-start justify-between gap-4">
                <dt class="text-sm text-hp-slate/50">Submitted by</dt>
                <dd class="text-right text-sm font-semibold text-hp-slate">{{ $batch->requester?->name ?? '—' }}</dd>
            </div>
            {{-- D-92: only a batch cancelled after approval was ever scheduled. --}}
            @if ($batch->scheduled_date !== null)
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-sm text-hp-slate/50">Date scheduled</dt>
                    <dd class="text-right text-sm font-semibold text-hp-slate">
                        {{ $batch->scheduled_date->format('M j, Y') }}
                        <span class="block text-xs font-normal text-hp-slate/50">{{ $batch->scheduledSpanLabel() }}</span>
                    </dd>
                </div>
            @endif
            {{-- D-109: opened in a new tab, like the College Admin's memo link. --}}
            @if ($batch->hasMemo())
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-sm text-hp-slate/50">Signed memo</dt>
                    <dd class="text-right text-sm font-semibold">
                        <a href="{{ route('director.batches.memo', $batch) }}" target="_blank" rel="noopener"
                           class="text-hp-orange hover:underline" data-memo-link>View</a>
                    </dd>
                </div>
            @endif
        </dl>

        {{-- Legacy rows only: what the college asked for before D-108. --}}
        @if ($batch->requestedLabel() !== null)
            <p class="mt-5 rounded-lg bg-hp-bg px-3 py-2 text-xs text-hp-slate/70">
                Originally requested: <span class="font-semibold text-hp-slate">{{ $batch->requestedLabel() }}</span> (before D-108)
            </p>
        @endif
    </x-hp.card>

    {{-- ── Card 2: students — ten rows tall, then scrolls (Q13) ──────────── --}}
    @include('director.batches.partials.students-card')
</div>

{{-- ── Card 3: why it ended ─────────────────────────────────────────────── --}}
<x-hp.card class="mt-6" data-closed-reason>
    <p class="mb-4 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
        {{ $isRejected ? 'Rejection reason' : 'Cancelled by college' }}
    </p>

    @php
        $reason = $isRejected ? $batch->rejection_reason : $batch->cancellation_reason;
    @endphp

    @if ($reason !== null)
        <p class="whitespace-pre-line rounded-lg bg-hp-bg px-4 py-3 text-sm text-hp-slate">{{ $reason }}</p>
    @else
        {{-- Rejections before D-36 and cancellations before D-92 carry no reason. --}}
        <p class="rounded-lg bg-hp-bg px-4 py-3 text-sm text-hp-slate/70">
            No reason was recorded — {{ $isRejected ? 'rejected' : 'cancelled' }} before written reasons were required.
        </p>
    @endif

    <p class="mt-3 text-xs text-hp-slate/60">
        @if ($isRejected)
            Rejected by <span class="font-semibold text-hp-slate">{{ $batch->reviewer?->name ?? 'the Clinic Director' }}</span>
            @if ($batch->reviewed_at !== null)
                on {{ $batch->reviewed_at->format($stamp) }}
            @endif
        @else
            Cancelled by <span class="font-semibold text-hp-slate">{{ $batch->canceller?->name ?? 'the College Admin' }}</span>
            ({{ $batch->college->code }} College Admin)
            @if ($batch->cancelled_at !== null)
                on {{ $batch->cancelled_at->format($stamp) }}
            @endif
        @endif
    </p>

    {{-- D-92: an approved batch the college then cancelled — its students
         were emailed that their schedule was cancelled. --}}
    @if ($afterApproval)
        <p class="mt-1 text-xs text-hp-slate/60" data-cancelled-after-approval>
            This was after approval —
            <span class="font-semibold text-hp-slate">{{ $batch->reviewer?->name ?? 'the Clinic Director' }}</span>
            approved it on {{ $batch->reviewed_at->format($stamp) }}.
        </p>
    @endif
</x-hp.card>

</x-layout.sidebar>
