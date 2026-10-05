<x-layout.sidebar title="Batch Tracking">

{{--
    Director Batch Tracking (FR-DIRA-09, D-112).

    A batch leaves Batch Approvals the moment it is decided (D-108); this is
    where the Director finds it again, for every college. Three cards; the
    two tables are each paged at ten under their own page parameter (FR-UI-06):

      1. Batch Results     — APPROVED batches, newest clinic date first, with
                             the D-111 status; View opens the results page
      2. Clinic Calendar   — D-119: read-only — how full each day is, and the
                             picked day's hours and batches
                             (batches/_clinic-calendar)
      3. Rejected and Cancelled batches — D-116's name for the batches the
                             Director rejected and the ones a college
                             cancelled (Q20), newest decision first; View
                             opens the details page with the reason

    D-114: each card has its own College dropdown and Batch ID search
    (batches/_card-filters — keys results_* and rejected_*).

    D-120: a row with news the Director has not opened — a status change, a
    release request, a college's cancellation — ends in a notification dot
    ($dots, from App\Support\BatchNotifications); View clears it.

    Both tables are <x-hp.table>, which re-flows each row into a stacked card
    below `md` instead of sliding the page sideways.
--}}

@php
    // D-62: Form Type immediately before Purpose. Purpose is the batch's
    // `reason` column (D-105). The last column holds the View link, so its
    // header is blank, as on the College Admin's Batch Results card.
    $headers = ['Batch ID', 'College', 'Form Type', 'Purpose', 'Date scheduled', 'Status', ''];

    // The admin Batch Results card's View link, so both pages look the same.
    $viewLink = 'inline-flex items-center justify-center gap-2 rounded-full
                 border-[1.5px] border-hp-slate/30 px-4 py-1 text-xs font-semibold
                 text-hp-slate transition-colors duration-hp-fast hover:bg-hp-slate/10
                 focus-visible:outline-none focus-visible:ring-2
                 focus-visible:ring-hp-slate focus-visible:ring-offset-1';
@endphp

{{-- ── Page header ─────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <h2 class="text-xl font-semibold text-hp-slate">Batch Tracking</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">
        Every college's batches once they are decided — approved ones with their results, rejected and cancelled ones with the reason.
    </p>
</div>

{{-- ── Card 1: Batch Results (approved) ────────────────────────────────── --}}
<x-hp.card class="mb-6">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="hp-card-title">Batch Results</p>
            <p class="mt-1 text-xs text-hp-slate/50">Approved batches from every college, newest clinic date first.</p>
        </div>

        {{-- This card's own College dropdown + Batch ID search (D-114). --}}
        @if ($resultsFilters->isActive() || $approvedBatches->total() > 0)
            @include('batches._card-filters', [
                'filters' => $resultsFilters,
                'colleges' => $colleges,
                'action' => route('director.tracking.index'),
            ])
        @endif
    </div>

    @if ($approvedBatches->isEmpty() && $resultsFilters->isActive())
        <p class="py-6 text-center text-sm text-hp-slate/50">
            No approved batch matches these filters.
        </p>
    @elseif ($approvedBatches->isEmpty())
        <p class="py-6 text-center text-sm text-hp-slate/50">No approved batches yet.</p>
    @else
        <x-hp.table :headers="$headers" data-tracking-results>
            @foreach ($approvedBatches as $batch)
                <x-hp.table-row>
                    <x-hp.table-cell label="Batch ID" class="whitespace-nowrap font-semibold">
                        {{ $batch->reference_no }}
                        {{-- D-113 (Q18): the college asked for this batch's documents — review it from View. --}}
                        @if ($batch->release_requested)
                            <span class="mt-1 block"><x-hp.badge variant="flagged" data-release-requested>Release requested</x-hp.badge></span>
                        @endif
                    </x-hp.table-cell>
                    <x-hp.table-cell label="College">{{ $batch->college->code }}</x-hp.table-cell>
                    <x-hp.table-cell label="Form Type">{{ $batch->formTypeLabel() }}</x-hp.table-cell>
                    <x-hp.table-cell label="Purpose">{{ Str::limit($batch->reasonText(), 60) }}</x-hp.table-cell>
                    <x-hp.table-cell label="Date scheduled">
                        <span class="whitespace-nowrap">{{ $batch->scheduled_date?->format('M j, Y') ?? '—' }}</span>
                        <span class="block whitespace-nowrap text-xs text-hp-slate/60">{{ $batch->scheduledSpanLabel() }}</span>
                    </x-hp.table-cell>
                    {{-- D-111's status, worded by the same partial as the college's card. --}}
                    <x-hp.table-cell label="Status">
                        @include('batches.results._status', ['status' => $batch->resultsStatus()])
                    </x-hp.table-cell>
                    <x-hp.table-cell label="">
                        <span class="inline-flex items-center gap-2">
                            <a href="{{ route('director.tracking.results', $batch) }}" class="{{ $viewLink }}">View</a>
                            <x-hp.notify-dot :show="$dots[$batch->id] ?? false" />
                        </span>
                    </x-hp.table-cell>
                </x-hp.table-row>
            @endforeach
        </x-hp.table>

        <x-hp.pager :paginator="$approvedBatches" />
    @endif
</x-hp.card>

{{-- ── Card 2: Clinic Calendar (D-119) ──────────────────────────────────── --}}
@include('batches._clinic-calendar', [
    'monthUrl' => route('director.calendar.month'),
    'dayUrl' => route('director.calendar.day'),
])

{{-- ── Card 3: Rejected and Cancelled batches (D-116) ─────────────────── --}}
<x-hp.card>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="hp-card-title">Rejected and Cancelled batches</p>
            <p class="mt-1 text-xs text-hp-slate/50">Batches you rejected and batches a college cancelled, newest first.</p>
        </div>

        {{-- This card's own College dropdown + Batch ID search (D-114). --}}
        @if ($rejectedFilters->isActive() || $closedBatches->total() > 0)
            @include('batches._card-filters', [
                'filters' => $rejectedFilters,
                'colleges' => $colleges,
                'action' => route('director.tracking.index'),
            ])
        @endif
    </div>

    @if ($closedBatches->isEmpty() && $rejectedFilters->isActive())
        <p class="py-6 text-center text-sm text-hp-slate/50">No rejected or cancelled batch matches these filters.</p>
    @elseif ($closedBatches->isEmpty())
        <p class="py-6 text-center text-sm text-hp-slate/50">No rejected or cancelled batches.</p>
    @else
        <x-hp.table :headers="$headers" data-tracking-rejected>
            @foreach ($closedBatches as $batch)
                <x-hp.table-row>
                    <x-hp.table-cell label="Batch ID" class="whitespace-nowrap font-semibold">{{ $batch->reference_no }}</x-hp.table-cell>
                    <x-hp.table-cell label="College">{{ $batch->college->code }}</x-hp.table-cell>
                    <x-hp.table-cell label="Form Type">{{ $batch->formTypeLabel() }}</x-hp.table-cell>
                    <x-hp.table-cell label="Purpose">{{ Str::limit($batch->reasonText(), 60) }}</x-hp.table-cell>
                    {{-- Only a batch cancelled after approval was ever scheduled. --}}
                    <x-hp.table-cell label="Date scheduled">
                        @if ($batch->scheduled_date !== null)
                            <span class="whitespace-nowrap">{{ $batch->scheduled_date->format('M j, Y') }}</span>
                            <span class="block whitespace-nowrap text-xs text-hp-slate/60">{{ $batch->scheduledSpanLabel() }}</span>
                        @else
                            <span class="text-hp-slate/50">&mdash;</span>
                        @endif
                    </x-hp.table-cell>
                    {{-- FR-ADM-11: a cancellation is never worded as a Director's rejection. --}}
                    <x-hp.table-cell label="Status">
                        @if ($batch->status === 'rejected')
                            <x-hp.badge variant="rejected">Rejected</x-hp.badge>
                        @else
                            <x-hp.badge variant="neutral">Cancelled by college</x-hp.badge>
                            @if ($batch->wasCancelledAfterApproval())
                                <span class="mt-0.5 block text-xs text-hp-slate/60">after approval</span>
                            @endif
                        @endif
                    </x-hp.table-cell>
                    <x-hp.table-cell label="">
                        <span class="inline-flex items-center gap-2">
                            <a href="{{ route('director.tracking.show', $batch) }}" class="{{ $viewLink }}">View</a>
                            <x-hp.notify-dot :show="$dots[$batch->id] ?? false" />
                        </span>
                    </x-hp.table-cell>
                </x-hp.table-row>
            @endforeach
        </x-hp.table>

        <x-hp.pager :paginator="$closedBatches" />
    @endif
</x-hp.card>

</x-layout.sidebar>
