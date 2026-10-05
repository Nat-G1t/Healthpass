{{--
    D-111: the results page header — a back link, the Batch ID, one line of
    form · purpose · clinic date and hours (plus the 2nd-chance day when the
    batch has one, D-110), and the batch status badge.

    Takes:
      $batch     — the approved BatchRequest, loaded with
                   BatchResults::pageRelations()
      $status    — its BatchRequest::resultsStatus() key
      $backUrl   — where the back link goes (each role has its own list page)
      $backLabel — that page's name, e.g. "Batch Tracking"
      $showCollege — optional, default false: name the batch's college under
                   the Batch ID, for the roles that see every college (D-112).
                   The batch must then have `college` loaded.
--}}
@php
    $secondChance = $batch->secondChanceStart();
@endphp

<div class="mb-6">
    <a href="{{ $backUrl }}"
       class="inline-flex items-center gap-1.5 text-xs font-semibold text-hp-slate/50
              transition-colors hover:text-hp-orange">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
        </svg>
        {{ $backLabel }}
    </a>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-xl font-semibold text-hp-slate">Batch {{ $batch->reference_no }}</h2>
            @if ($showCollege ?? false)
                <p class="mt-0.5 text-sm font-semibold text-hp-slate/80" data-results-college>
                    {{ $batch->college->name }} <span class="font-normal text-hp-slate/50">({{ $batch->college->code }})</span>
                </p>
            @endif
            <p class="mt-1 text-sm text-hp-slate/60">
                {{ $batch->formTypeLabel() }}
                · {{ $batch->reasonText() }}
                · {{ $batch->scheduled_date?->format('M j, Y') ?? '—' }}, {{ $batch->scheduledSpanLabel() }}
                @if ($secondChance !== null)
                    · <span class="whitespace-nowrap font-semibold text-hp-slate">2nd chance: {{ $secondChance->format('M j, Y, g:i A') }}</span>
                @endif
            </p>
        </div>

        @include('batches.results._status', ['status' => $status])
    </div>
</div>
