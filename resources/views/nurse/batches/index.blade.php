<x-layout.sidebar title="Upcoming Batches">

{{--
    The clinic's Upcoming Batches (FR-NRS-12, D-114) — nurse and physician.

    Every college's APPROVED batches with a clinic day today or later — the
    batch's first day or a 2nd-chance day (D-110) — soonest first, paged at
    ten (FR-UI-06), with its own College dropdown and Batch ID search
    (batches/_card-filters).

    D-119: under it, the read-only Clinic Calendar card
    (batches/_clinic-calendar) — how full each day is, and the picked day's
    hours and batches.

    D-125: the old Past card moved to its own page, Batch Results
    ("Completed batches", nurse/batches/completed). View opens the batch's
    results page, where the clinic prints or downloads the students' forms
    once the Director has released them.
--}}

{{-- ── Page header ─────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <h2 class="text-xl font-semibold text-hp-slate">Upcoming Batches</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">
        Every college’s approved batches that are still coming up — who is coming next, and how full each clinic day is.
    </p>
</div>

@include('nurse.batches._card', [
    'batches' => $upcomingBatches,
    'filters' => $upcomingFilters,
    'colleges' => $colleges,
    'action' => route('nurse.batches.index'),
    'title' => 'Upcoming',
    'caption' => 'Batches with a clinic day today or later — including a 2nd-chance day — soonest first.',
    'empty' => 'No upcoming batches.',
])

@include('batches._clinic-calendar', [
    'monthUrl' => route('nurse.calendar.month'),
    'dayUrl' => route('nurse.calendar.day'),
])

</x-layout.sidebar>
