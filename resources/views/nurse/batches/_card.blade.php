{{--
    D-114: one card of the clinic's batch pages — Upcoming on Upcoming Batches
    and, since D-125, "Completed batches" on Batch Results. Both are this
    partial, so their columns can never drift apart.

    Takes:
      $batches  — a paginator of approved BatchRequests, loaded with
                  `college` and BatchResults::statusRelations()
      $filters  — this card's App\Support\BatchCardFilters; its prefix
                  ("upcoming", "past") also names the card in
                  `data-batches-card`, for the tests
      $colleges — every college, for the filter bar's dropdown
      $action   — the page the filter bar submits to
      $title    — the card's small heading ("Upcoming", "Completed batches")
      $caption  — one line under it saying what is listed and in what order
      $empty    — what to say when there is nothing to list
      $from     — optional: the `from` value View carries, so the results
                  page's back link returns here ('completed')
      $dots     — optional (D-125): batch id => bool, this user's row dots
                  (App\Support\BatchNotifications). Left out, no dots.
      $pagerDots — optional (D-131): ['previous' => bool, 'next' => bool],
                  the pager's dots (BatchNotifications::pagerDots()). Left
                  out, none.
      $printTags — optional (D-125): show "Print Requested" / "Printed" under
                  the Batch ID. The batches must have `approvedReleaseRequest`
                  loaded.

    Columns as the Director's Batch Results card (D-112): Batch ID · College ·
    Form Type · Purpose · Date scheduled · Status · View. A batch with a
    2nd-chance day (D-110) shows it under its first date — that is why a batch
    first seen last week can sit in Upcoming.

    <x-hp.table> re-flows each row into a stacked card below `md` instead of
    sliding the page sideways.
--}}
@php
    $headers = ['Batch ID', 'College', 'Form Type', 'Purpose', 'Date scheduled', 'Status', ''];
    $from ??= null;
    $dots ??= null;
    $pagerDots ??= ['previous' => false, 'next' => false];
    $printTags ??= false;

    // The Director's and the College Admin's View link, so the pages match.
    $viewLink = 'inline-flex items-center justify-center gap-2 rounded-full
                 border-[1.5px] border-hp-slate/30 px-4 py-1 text-xs font-semibold
                 text-hp-slate transition-colors duration-hp-fast hover:bg-hp-slate/10
                 focus-visible:outline-none focus-visible:ring-2
                 focus-visible:ring-hp-slate focus-visible:ring-offset-1';
@endphp

<x-hp.card class="mb-6" data-batches-card="{{ $filters->prefix }}">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="hp-card-title">{{ $title }}</p>
            <p class="mt-1 text-xs text-hp-slate/50">{{ $caption }}</p>
        </div>

        {{-- Hidden only while the card has nothing to filter. --}}
        @if ($filters->isActive() || $batches->total() > 0)
            @include('batches._card-filters', [
                'filters' => $filters,
                'colleges' => $colleges,
                'action' => $action,
            ])
        @endif
    </div>

    @if ($batches->isEmpty())
        <p class="py-6 text-center text-sm text-hp-slate/50">
            {{ $filters->isActive() ? 'No batch here matches these filters.' : $empty }}
        </p>
    @else
        <x-hp.table :headers="$headers">
            @foreach ($batches as $batch)
                @php
                    $secondChance = $batch->secondChanceStart();
                @endphp
                <x-hp.table-row>
                    <x-hp.table-cell label="Batch ID" class="whitespace-nowrap font-semibold">
                        {{ $batch->reference_no }}
                        {{-- D-125: the college's approved release asked for printed
                             copies. "Print Requested" until the clinic presses Mark
                             as printed on the batch's page, then a muted "Printed"
                             for the record (Q5). --}}
                        @if ($printTags && $batch->approvedReleaseRequest?->asksClinicToPrint())
                            <span class="mt-1 block">
                                @if ($batch->approvedReleaseRequest->copies_printed_at === null)
                                    <x-hp.badge variant="live" data-print-requested>Print Requested</x-hp.badge>
                                @else
                                    <x-hp.badge variant="neutral" data-copies-printed>Printed</x-hp.badge>
                                @endif
                            </span>
                        @endif
                    </x-hp.table-cell>
                    <x-hp.table-cell label="College">{{ $batch->college->code }}</x-hp.table-cell>
                    <x-hp.table-cell label="Form Type">{{ $batch->formTypeLabel() }}</x-hp.table-cell>
                    <x-hp.table-cell label="Purpose">{{ Str::limit($batch->reasonText(), 60) }}</x-hp.table-cell>
                    <x-hp.table-cell label="Date scheduled">
                        <span class="whitespace-nowrap">{{ $batch->scheduled_date?->format('M j, Y') ?? '—' }}</span>
                        <span class="block whitespace-nowrap text-xs text-hp-slate/60">{{ $batch->scheduledSpanLabel() }}</span>
                        @if ($secondChance !== null)
                            <span class="block whitespace-nowrap text-xs font-semibold text-hp-slate" data-second-chance>
                                2nd chance: {{ $secondChance->format('M j, Y, g:i A') }}
                            </span>
                        @endif
                    </x-hp.table-cell>
                    {{-- D-111's status, worded by the same partial as every other role's card. --}}
                    <x-hp.table-cell label="Status">
                        @include('batches.results._status', ['status' => $batch->resultsStatus()])
                    </x-hp.table-cell>
                    <x-hp.table-cell label="">
                        <span class="inline-flex items-center gap-2">
                            <a href="{{ route('nurse.batches.results', ['batch' => $batch, ...($from !== null ? ['from' => $from] : [])]) }}"
                               class="{{ $viewLink }}">View</a>
                            @if ($dots !== null)
                                <x-hp.notify-dot :show="$dots[$batch->id] ?? false" />
                            @endif
                        </span>
                    </x-hp.table-cell>
                </x-hp.table-row>
            @endforeach
        </x-hp.table>

        <x-hp.pager :paginator="$batches" :previous-dot="$pagerDots['previous']" :next-dot="$pagerDots['next']" />
    @endif
</x-hp.card>
