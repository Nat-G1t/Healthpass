<x-layout.sidebar title="Batch Roster">

{{--
    D-55: the Status and Result columns and the results roll-up D-53 put on
    this page are gone. Each student's outcome now lives on the Batch Results
    popup on Batch Tracking (FR-ADM-12), where every approved batch sits in
    one place; the roster is back to who is booked and when. D-87 removed
    the Withdraw column, and students an earlier withdrawal removed are not
    listed at all. The table itself is shared with the Submitted page (D-88).

    $rows is ONE PAGE of the roster (FR-UI-06). $totalCount comes from the
    controller and counts the WHOLE roster, so the header never shrinks to
    the page size.
--}}

{{-- ── Flash messages ───────────────────────────────────────────────────────── --}}
@if (session('status'))
    <div data-hp-flash class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
        {{ session('status') }}
    </div>
@endif

@if (session('error'))
    <div data-hp-flash data-flash-sticky class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ session('error') }}
    </div>
@endif

{{-- ── Page header ──────────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <a href="{{ route('admin.batches.index') }}"
       class="inline-flex items-center gap-1.5 text-xs font-semibold text-hp-slate/50
              transition-colors hover:text-hp-orange">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
        </svg>
        Back to Batch Tracking
    </a>

    <div class="mt-3 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-hp-slate">
                {{ $batch->reference_no }}
            </h2>
            <p class="mt-0.5 text-sm text-hp-slate/50">
                Medical Clearance
                for {{ $totalCount }} {{ Str::plural('student', $totalCount) }}
            </p>
        </div>
        <x-hp.badge :variant="$batch->status">{{ $batch->statusLabel() }}</x-hp.badge>
    </div>
</div>

{{-- ── Batch summary ────────────────────────────────────────────────────────── --}}
<x-hp.card class="mb-6">
    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Clinic date
            </dt>
            <dd class="mt-1 text-sm font-semibold text-hp-slate">
                {{ $batch->requested_date?->format('l, F j, Y') ?? '—' }}
            </dd>
        </div>
        <div>
            <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Hour span
            </dt>
            {{-- "—" on pre-D-37 batches, which have no span --}}
            <dd class="mt-1 text-sm font-semibold text-hp-slate">{{ $batch->requestedSpanLabel() }}</dd>
        </div>
        <div>
            <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Form
            </dt>
            {{-- D-62: the official form the clinic uses for this batch --}}
            <dd class="mt-1 text-sm font-semibold text-hp-slate">{{ $batch->formTypeLabel() }}</dd>
        </div>
        <div>
            <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Purpose
            </dt>
            {{-- The batch's own reason (BR-06), or the admin's typed text when
                 they picked "Others" — reasonText() already resolves both. --}}
            <dd class="mt-1 text-sm font-semibold text-hp-slate">{{ $batch->reasonText() }}</dd>
        </div>
        <div>
            <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Students
            </dt>
            <dd class="mt-1 text-sm font-semibold text-hp-slate">{{ $totalCount }}</dd>
        </div>
    </dl>
</x-hp.card>

{{-- ── Cancelled notice (FR-ADM-11, D-52) ───────────────────────────────────── --}}
@if ($batch->status === 'cancelled')
    <div class="mb-6 flex items-start gap-3 rounded-xl border border-hp-slate/20 bg-hp-bg px-4 py-3.5">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-hp-slate/50" fill="none" viewBox="0 0 24 24"
             stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
        </svg>
        <p class="text-xs leading-relaxed text-hp-slate">
            This request was cancelled
            @if ($batch->canceller !== null)
                by <strong class="font-semibold">{{ $batch->canceller->name }}</strong>
            @endif
            @if ($batch->cancelled_at !== null)
                on {{ $batch->cancelled_at->format('M j, Y \a\t g:i A') }}
            @endif
            {{-- D-92: an approved batch can be cancelled too, until its first hour. --}}
            @if ($batch->wasCancelledAfterApproval())
                after the Clinic Director approved it. <strong class="font-semibold">Every
                appointment on it was cancelled</strong>, its clinic hours were freed, and each
                student was emailed.
            @else
                before the Clinic Director reviewed it. <strong class="font-semibold">No appointments
                were created</strong>, and the clinic hours it asked for were never held.
            @endif
            To book these students, submit a new batch request.
            @if ($batch->cancellation_reason !== null)
                <span class="mt-1.5 block whitespace-pre-line"><strong class="font-semibold">Reason:</strong> {{ $batch->cancellation_reason }}</span>
            @endif
        </p>
    </div>
@endif

{{-- ── Roster ───────────────────────────────────────────────────────────────── --}}
<x-hp.card>
    <p class="mb-5 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
        Students in this batch
    </p>

    @include('admin.batches.partials.roster')
</x-hp.card>

</x-layout.sidebar>
