<x-layout.sidebar title="Batch Request Submitted">

{{-- `cancelTarget` feeds the shared cancel dialog (D-88, FR-ADM-11): null, or
     the { id, ref, students, date, approved } of this batch once Cancel is clicked. --}}
<div x-data="{ cancelTarget: null }">

{{-- D-92: the cancel reason failed validation; the redirect closed the dialog. --}}
@error('cancellation_reason')
    <div data-hp-flash data-flash-sticky class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ $message }}
    </div>
@enderror

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

        {{-- Purpose — the batch's `reason` (D-105) --}}
        <div class="flex items-start justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Purpose</dt>
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

        {{-- D-108: the clinic date and hours are the Director's to pick. Shown
             once the batch is scheduled; until then, who will decide them. --}}
        <div class="flex items-center justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Clinic date</dt>
            <dd class="text-right text-sm font-semibold text-hp-slate">
                @if ($batch->scheduled_date !== null)
                    {{ $batch->scheduled_date->format('l, F j, Y') }}
                @elseif ($batch->status === 'pending')
                    To be scheduled by the Director
                @else
                    &mdash;
                @endif
            </dd>
        </div>

        @if ($batch->scheduled_date !== null)
            <div class="flex items-center justify-between gap-4">
                <dt class="text-sm text-hp-slate/50">Hours</dt>
                <dd class="text-sm font-semibold text-hp-slate">
                    {{ $batch->scheduledSpanLabel() }}
                </dd>
            </div>
        @endif

        {{-- D-109: the signed memo, opened in a new tab (private file, served
             through this college's own route). --}}
        <div class="flex items-center justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Signed memo</dt>
            <dd class="text-right text-sm font-semibold">
                @if ($batch->hasMemo())
                    <a href="{{ route('admin.batches.memo', $batch) }}" target="_blank" rel="noopener"
                       class="text-hp-orange hover:underline" data-memo-link>View</a>
                @else
                    <span class="font-normal text-hp-slate/50">None — submitted before signed memos were required</span>
                @endif
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

{{-- ── Students on this batch (D-88) ────────────────────────────────────────── --}}
<x-hp.card class="mb-6">
    <p class="mb-5 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
        Students in this batch
    </p>

    @include('admin.batches.partials.roster')
</x-hp.card>

{{-- ── What happens next --}}
{{-- D-108: the Director picks the clinic date and hours (or rejects with a
     reason) — the college no longer proposes them. --}}
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
                The Clinic Director reviews the request, picks the clinic date and
                hours, and approves it — or rejects it with a reason you can read in
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
                'date' => $batch->scheduled_date?->format('M j, Y'),
                // D-92: this page can be reopened after the Director approved it.
                'approved' => $batch->status === 'approved',
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
