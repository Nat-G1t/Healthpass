<x-layout.sidebar title="Batch Request Submitted">

{{-- ── Page header ──────────────────────────────────────────────────────────── --}}
<div class="mb-7">
    <h2 class="text-xl font-semibold text-hp-slate">Batch Request Submitted</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">
        Your request is now waiting for the Clinic Director's review.
    </p>
</div>

{{-- ── Success banner ───────────────────────────────────────────────────────── --}}
<div class="mb-6 flex items-center gap-3 rounded-xl border border-hp-peach bg-hp-peach/20 px-4 py-3">
    <svg class="h-5 w-5 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24"
         stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round"
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

        {{-- Reason --}}
        <div class="flex items-start justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Reason</dt>
            <dd class="text-right text-sm font-semibold text-hp-slate">
                {{ $batch->reasonText() }}
            </dd>
        </div>

        {{-- Service --}}
        <div class="flex items-center justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Service</dt>
            <dd>
                <x-hp.badge variant="positive">
                    {{ $batch->service_type === 'medical' ? 'Medical Clearance' : 'Dental Check' }}
                </x-hp.badge>
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

        {{-- Submitted date --}}
        <div class="flex items-center justify-between gap-4">
            <dt class="text-sm text-hp-slate/50">Submitted</dt>
            <dd class="text-sm font-semibold text-hp-slate">
                {{ $batch->created_at->format('l, F j, Y') }}
            </dd>
        </div>

    </dl>
</x-hp.card>

{{-- ── What happens next --}}
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
                The Clinic Director reviews the request and confirms your
                requested date (they may adjust it if the clinic is full that
                day). Once approved, an appointment is created automatically for
                every listed student — you can follow the status in Batch Tracking.
            </p>
        </div>
    </div>
</x-hp.card>

{{-- ── Actions (FR-ADM-04) ──────────────────────────────────────────────────── --}}
<div class="flex flex-col gap-3 sm:flex-row">

    <a href="{{ route('admin.batches.index') }}"
       class="inline-flex w-full items-center justify-center gap-2 rounded-full bg-hp-orange
              px-6 py-2.5 text-sm font-semibold text-white transition-colors
              duration-150 hover:bg-orange-500 sm:w-auto">
        View Tracking
    </a>

    <a href="{{ route('admin.dashboard') }}"
       class="inline-flex w-full items-center justify-center gap-2 rounded-full border border-hp-slate/20
              px-6 py-2.5 text-sm font-semibold text-hp-slate transition-colors
              hover:border-hp-orange/40 hover:text-hp-orange sm:w-auto">
        Back to Dashboard
    </a>

</div>

</x-layout.sidebar>
