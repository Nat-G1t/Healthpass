<x-layout.sidebar title="Batch Results">

{{--
    The clinic's Batch Results page (FR-NRS-13, D-125) — nurse and physician.

    One card, "Completed batches": D-114's old Past card from Upcoming
    Batches, unchanged — every approved batch whose clinic days are over,
    newest first, paged at ten (FR-UI-06), with its own College dropdown and
    Batch ID search (keys past_q / past_college / past_page).

    D-125 adds, per row:
      - "Print Requested" under the Batch ID when the Director approved a
        release that asked for printed copies, until the clinic presses Mark
        as printed on the batch's page — then a muted "Printed";
      - a notification dot (this user's own, App\Support\BatchNotifications)
        when that approval is newer than their last View of the batch.
        View clears it.

    View opens the batch's results page with `from=completed`, so its back
    link returns here.
--}}

{{-- ── Page header ─────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <h2 class="text-xl font-semibold text-hp-slate">Batch Results</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">
        Every college’s batches whose clinic days are over — their results, and the forms to print once the Director releases them.
    </p>
</div>

@include('nurse.batches._card', [
    'batches' => $completedBatches,
    'filters' => $completedFilters,
    'colleges' => $colleges,
    'action' => route('nurse.batch-results'),
    'title' => 'Completed batches',
    'caption' => 'Batches whose clinic days are over, newest first.',
    'empty' => 'No completed batches yet.',
    'from' => 'completed',
    'dots' => $dots,
    'pagerDots' => $pagerDots,
    'printTags' => true,
])

</x-layout.sidebar>
