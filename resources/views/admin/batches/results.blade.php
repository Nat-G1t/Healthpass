<x-layout.sidebar title="Batch Results">

{{--
    One approved batch's results (FR-ADM-12, D-111) — what the Batch Results
    card's View opens. It replaced D-55's popup.

    The body is the shared partials under resources/views/batches/results/,
    which the Director's and the clinic's results pages reuse (D-112, D-114).
    $results is BatchResults::page($batch): status, summary and rows, built
    once in the controller so the badge, the cards and the table read the same
    students.
--}}

@include('batches.results._header', [
    'batch' => $batch,
    'status' => $results['status'],
    'backUrl' => route('admin.batches.index'),
    'backLabel' => 'Batch Tracking',
])

@include('batches.results._summary', ['summary' => $results['summary']])

@include('batches.results._table', ['rows' => $results['rows']])

{{-- ── Document release (prompt 11, D-113) ──────────────────────────────────
     The College Admin's "Request release" button and its states go HERE,
     under the main card, full width. Nothing renders here until then. --}}

</x-layout.sidebar>
