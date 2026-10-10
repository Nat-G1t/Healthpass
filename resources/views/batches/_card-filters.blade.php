{{--
    D-114: one card's filter bar — a College dropdown and a Batch ID search —
    for the pages that list batches in two cards (the Director's Batch
    Tracking, the clinic's Upcoming Batches). Sits at the right of the card's
    heading and wraps under it on a phone.

    Takes:
      $filters  — this card's App\Support\BatchCardFilters
      $colleges — every college (id, code), for the dropdown
      $action   — the page's URL
      $months   — (D-130, only for a card that opted into "month") the
                  BatchCardFilters::monthOptions() of that card

    D-130: a card whose BatchCardFilters opted into month / form / status /
    release also gets those dropdowns after College, each submitting on
    change like College. A card that did not (the clinic's) renders as before.

    A plain GET form, so the server filters every page (not just the ten on
    screen) and the filters stay in the URL for the pager's links. The OTHER
    card's filters and page ride along as hidden fields; this card's page is
    dropped, because the old page number may not exist in the new results.
    Picking a college submits straight away, like the Director's Data Summary
    filters; the search waits for Search (or Enter).
--}}
@php
    $prefix = $filters->prefix;
    $kept = collect(request()->query())->except($filters->keys())->filter(fn ($value) => is_string($value));

    // D-130: [key => [screen-reader label, "All" option, options, picked value]],
    // only the ones this card opted into.
    $dropdowns = collect([
        'month' => ['Filter by month', 'All months', $months ?? [], $filters->month],
        'form' => ['Filter by form type', 'All form types', \App\Models\BatchRequest::FORM_TYPES, $filters->formType],
        'status' => ['Filter by status', 'All statuses', \App\Models\BatchRequest::RESULTS_STATUSES, $filters->status],
        'release' => ['Filter by release request', 'All release requests', \App\Support\BatchCardFilters::RELEASE_OPTIONS, $filters->release],
    ])->filter(fn ($dropdown, $extra) => $filters->has($extra));
@endphp

<form method="GET" action="{{ $action }}" role="search" data-card-filters="{{ $prefix }}"
      class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
    @foreach ($kept as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach

    <label for="{{ $prefix }}-college" class="sr-only">Filter by college</label>
    {{-- x-data makes the select a tiny Alpine component so it can submit its own form on change. --}}
    <select id="{{ $prefix }}-college" name="{{ $prefix }}_college" x-data @change="$el.form.submit()"
            class="rounded-lg border-hp-slate/20 py-1.5 pl-3 pr-8 text-xs font-medium text-hp-slate focus:border-hp-orange focus:ring-hp-orange">
        <option value="">All colleges</option>
        @foreach ($colleges as $college)
            <option value="{{ $college->id }}" @selected($college->id === $filters->collegeId)>{{ $college->code }}</option>
        @endforeach
    </select>

    @foreach ($dropdowns as $extra => [$label, $all, $options, $picked])
        <label for="{{ $prefix }}-{{ $extra }}" class="sr-only">{{ $label }}</label>
        <select id="{{ $prefix }}-{{ $extra }}" name="{{ $prefix }}_{{ $extra }}" x-data @change="$el.form.submit()"
                class="rounded-lg border-hp-slate/20 py-1.5 pl-3 pr-8 text-xs font-medium text-hp-slate focus:border-hp-orange focus:ring-hp-orange">
            <option value="">{{ $all }}</option>
            @foreach ($options as $value => $text)
                <option value="{{ $value }}" @selected($value === $picked)>{{ $text }}</option>
            @endforeach
        </select>
    @endforeach

    {{-- D-130: beside the extra dropdowns the search keeps 8rem on a phone (it wraps to
         its own line instead of shrinking to a sliver); a card without them is unchanged. --}}
    <label for="{{ $prefix }}-search" class="sr-only">Search by Batch ID</label>
    <input id="{{ $prefix }}-search" name="{{ $prefix }}_q" type="search" value="{{ $filters->search }}"
           maxlength="30" placeholder="Search Batch ID"
           class="{{ $dropdowns->isEmpty() ? 'min-w-0' : 'min-w-[8rem]' }} flex-1 rounded-lg border-hp-slate/20 bg-hp-white px-3 py-1.5 text-xs text-hp-slate placeholder-hp-slate/40 focus:border-hp-orange focus:ring-hp-orange sm:w-40 sm:flex-none">
    <x-hp.button type="submit" variant="soft" size="sm">Search</x-hp.button>

    @if ($filters->isActive())
        <a href="{{ request()->fullUrlWithoutQuery($filters->keys()) }}"
           class="px-1 py-1.5 text-xs font-medium text-hp-slate/50 hover:text-hp-slate">
            Clear
        </a>
    @endif
</form>
