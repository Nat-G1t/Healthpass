<x-layout.sidebar title="Upcoming Batches">

{{--
    The clinic's Upcoming Batches (FR-NRS-12, D-114) — nurse and physician.

    Every college's APPROVED batches, in two cards like the Director's Batch
    Tracking, each paged at ten under its own page parameter (FR-UI-06):

      1. Upcoming — a clinic day today or later: the batch's first day or a
                    2nd-chance day (D-110), soonest first
      2. Past     — every other approved batch, newest clinic date first

    Each card has its own College dropdown and Batch ID search
    (batches/_card-filters). View opens the batch's results page, where the
    clinic prints or downloads the students' forms.
--}}

{{-- ── Page header ─────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <h2 class="text-xl font-semibold text-hp-slate">Upcoming Batches</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">
        Every college’s approved batches — who is coming next, and each batch’s results and forms.
    </p>
</div>

@include('nurse.batches._card', [
    'batches' => $upcomingBatches,
    'filters' => $upcomingFilters,
    'colleges' => $colleges,
    'title' => 'Upcoming',
    'caption' => 'Batches with a clinic day today or later — including a 2nd-chance day — soonest first.',
    'empty' => 'No upcoming batches.',
])

@include('nurse.batches._card', [
    'batches' => $pastBatches,
    'filters' => $pastFilters,
    'colleges' => $colleges,
    'title' => 'Past',
    'caption' => 'Batches whose clinic days are over, newest first.',
    'empty' => 'No past batches yet.',
])

</x-layout.sidebar>
