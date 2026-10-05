<x-layout.sidebar title="Batch Results">

{{--
    One approved batch's results, for the Director (FR-DIRA-10, D-112) — what
    the Batch Results card's View opens on the Director's Batch Tracking page.

    The same page body as the College Admin's (FR-ADM-12, D-111): the shared
    partials under resources/views/batches/results/, fed by the same
    BatchResults::page($batch), so both roles always see the same numbers.
    The Director sees every college, so the header also names the college.
--}}

@include('batches.results._header', [
    'batch' => $batch,
    'status' => $results['status'],
    'backUrl' => route('director.tracking.index'),
    'backLabel' => 'Batch Tracking',
    'showCollege' => true,
])

@include('batches.results._summary', ['summary' => $results['summary']])

@include('batches.results._table', ['rows' => $results['rows']])

{{-- ── Document tools (prompt 11, D-113) ────────────────────────────────────
     The Director's document download and "Review request of documents" go
     HERE, under the main card, full width. Nothing renders here until then. --}}

</x-layout.sidebar>
