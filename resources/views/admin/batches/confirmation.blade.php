<x-layout.sidebar title="Batch Request Submitted">

{{-- `cancelTarget` feeds the shared cancel dialog (D-88, FR-ADM-11): null, or
     the { id, ref, students, date } of this batch once Cancel is clicked. --}}
<div x-data="{ cancelTarget: null }">

{{-- ── Page header ──────────────────────────────────────────────────────────── --}}
<div class="mb-7">
    <h2 class="text-xl font-semibold text-hp-slate">Batch Request Submitted</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">
        Your request is now waiting for the Clinic Director's review.
    </p>
</div>

{{-- ── Success banner ───────────────────────────────────────────────────────── --}}
<div class="hp-anim-fade-up mb-6 flex items-center gap-3 rounded-xl border border-hp-peach bg-hp-peach/20 px-4 py-3">
    <svg class="h-5 w-5 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24"
         stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" pathLength="48"
              class="hp-anim-check-draw"
              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <p class="text-sm font-semibold text-hp-orange">
        Batch request submitted successfully!
    </p>
</div>

{{-- ── Batch detail card (FR-ADM-04) ────────────────────────────────────────── --}}
<x-hp.card class="mb-6">
    <p class="mb-5 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
        Batch Details
    </p>

    <dl class="space-y-4">

        {{-- Batch ID --}}
        <div class="flex items-start justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Batch ID</dt>
            <dd class="text-right">
                <span class="font-mono text-sm font-semibold tracking-wider text-hp-orange">
                    {{ $batch->reference_no }}
                </span>
            </dd>
        </div>

        <div class="border-t border-hp-slate/10"></div>

        {{-- Status --}}
        <div class="flex items-center justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Status</dt>
            <dd>
                <x-hp.badge :variant="$batch->status">{{ $batch->statusLabel() }}</x-hp.badge>
            </dd>
        </div>

        {{-- Form type (D-62) — the official form the clinic will use. D-88
             dropped the "Service" row under it, which always read "Medical
             Clearance" even on a Medical Assessment Form batch. --}}
        <div class="flex items-start justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Form</dt>
            <dd class="text-right text-sm font-semibold text-hp-slate">
                {{ $batch->formTypeLabel() }}
            </dd>
        </div>

        {{-- Reason --}}
        <div class="flex items-start justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Reason</dt>
            <dd class="text-right text-sm font-semibold text-hp-slate">
                {{ $batch->reasonText() }}
            </dd>
        </div>

        {{-- Students --}}
        <div class="flex items-center justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Students</dt>
            <dd class="text-sm font-semibold text-hp-slate">
                {{ $batch->batch_request_students_count }}
            </dd>
        </div>

        {{-- Requested clinic date (D-29) --}}
        <div class="flex items-center justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Requested Date</dt>
            <dd class="text-sm font-semibold text-hp-slate">
                {{ $batch->requested_date?->format('l, F j, Y') ?? '—' }}
            </dd>
        </div>

        {{-- Requested clinic hours (D-37) --}}
        <div class="flex items-center justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Requested Time</dt>
            <dd class="text-sm font-semibold text-hp-slate">
                {{ $batch->requestedSpanLabel() }}
            </dd>
        </div>

        {{-- Submitted date --}}
        <div class="flex items-center justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Submitted</dt>
            <dd class="text-sm font-semibold text-hp-slate">
                {{ $batch->created_at->format('l, F j, Y') }}
            </dd>
        </div>

    </dl>
</x-hp.card>

{{-- ── Heads-up: an earlier request wants the same hours (D-90) ─────────────── --}}
{{-- $freeStarts is null when there is nothing to warn about. It never says
     whose request came first — only what that means for this one. --}}
@if ($freeStarts !== null)
    <div class="mb-6 flex items-start gap-3 rounded-xl border border-hp-orange/40 bg-hp-peach/30 px-4 py-3.5">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24"
             stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
        <div class="text-xs leading-relaxed text-hp-slate">
            <p class="text-sm font-semibold">Another request is waiting for these hours</p>
            <p class="mt-1">
                It was submitted before yours, and the clinic can't fit both. If the
                Clinic Director approves it first, this request will be rejected.
            </p>
            <p class="mt-1">
                @if ($freeStarts === [])
                    No other start time is free that day. To be safe, cancel this request
                    and submit it again for another date.
                @else
                    To be safe, cancel this request and submit it again starting at
                    <strong class="font-semibold">{{ Illuminate\Support\Arr::join($freeStarts, ', ', ' or ') }}</strong>,
                    which still have room that day.
                @endif
            </p>
        </div>
    </div>
@endif

{{-- ── Students on this batch (D-88) ────────────────────────────────────────── --}}
<x-hp.card class="mb-6">
    <p class="mb-5 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
        Students in this batch
    </p>

    @include('admin.batches.partials.roster')
</x-hp.card>

{{-- ── What happens next --}}
{{-- D-88: since D-36 the Director confirms the requested date and time as they
     are (or rejects with a reason) — they can no longer move them. --}}
<x-hp.card class="mb-6 bg-hp-bg">
    <div class="flex gap-3">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24"
             stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <div>
            <p class="text-sm font-semibold text-hp-slate">What happens next</p>
            <p class="mt-1 text-xs leading-relaxed text-hp-slate/60">
                The Clinic Director reviews the request and either approves your
                requested date and time or rejects it with a reason you can read in
                Batch Tracking. Once approved, an appointment is created automatically
                for every listed student, and each student is emailed their schedule.
                Until then, you can still cancel this request.
            </p>
        </div>
    </div>
</x-hp.card>

{{-- ── Actions (FR-ADM-04) ──────────────────────────────────────────────────── --}}
<div class="flex flex-col gap-3 sm:flex-row">

    <a href="{{ route('admin.batches.index') }}"
       class="inline-flex w-full items-center justify-center gap-2 rounded-full bg-hp-orange
              px-6 py-2.5 text-sm font-semibold text-white transition-colors
              duration-hp-fast hover:bg-orange-500 sm:w-auto">
        View Tracking
    </a>

    <a href="{{ route('admin.dashboard') }}"
       class="inline-flex w-full items-center justify-center gap-2 rounded-full border border-hp-slate/20
              px-6 py-2.5 text-sm font-semibold text-hp-slate transition-colors
              hover:border-hp-orange/40 hover:text-hp-orange sm:w-auto">
        Back to Dashboard
    </a>

    {{-- D-88: withdraw it right here (FR-ADM-11). isCancellable() is the SAME
         rule the cancel endpoint re-checks under a row lock, so the button never
         offers what the server would refuse. Plain <button> because the payload
         is built with Js::from(), which isn't compiled inside a component tag's
         attribute. `students` is cast to int: withCount() is a string on MySQL,
         and the dialog compares it with === 1 to pluralise. --}}
    @if ($batch->isCancellable())
        <button type="button"
            @click="cancelTarget = {{ Illuminate\Support\Js::from([
                'id' => $batch->id,
                'ref' => $batch->reference_no,
                'students' => (int) $batch->batch_request_students_count,
                'date' => $batch->requested_date?->format('M j, Y'),
            ]) }}"
            class="inline-flex w-full items-center justify-center gap-2 rounded-full
                   border-[1.5px] border-red-300 bg-transparent px-6 py-2.5 text-sm
                   font-semibold text-red-500 hover:bg-red-50 active:scale-[0.97]
                   transition-[color,background-color,border-color,transform]
                   duration-hp-fast ease-hp-out focus-visible:outline-none
                   focus-visible:ring-2 focus-visible:ring-red-500
                   focus-visible:ring-offset-1 sm:ml-auto sm:w-auto">
            Cancel Request
        </button>
    @endif

</div>

<x-batch-cancel-confirm />

</div>

</x-layout.sidebar>
