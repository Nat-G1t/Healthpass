<x-layout.sidebar title="Batch Results">

{{--
    One approved batch's results, for the clinic (FR-NRS-12, D-114) — what
    View opens on the Upcoming Batches page.

    The same page body as the College Admin's (D-111) and the Director's
    (D-112): the shared partials under resources/views/batches/results/, fed
    by the same BatchResults::page($batch), so every role sees the same
    numbers. The clinic sees every college, so the header names the college.

    No release button here — the clinic neither asks for documents nor
    reviews requests. Instead:
      - every encoded row has the encode page's print buttons — Print, or
        Print front / Print back on a Medical Assessment Form — printing in
        the hidden frame below, and PDF (`rowDocuments`, D-114);
      - the D-113 select & download tools are always on (Q22: a college may
        ask the clinic to print the copies).
--}}

@include('batches.results._header', [
    'batch' => $batch,
    'status' => $results['status'],
    'backUrl' => route('nurse.batches.index'),
    'backLabel' => 'Upcoming Batches',
    'showCollege' => true,
])

@include('batches.results._summary', ['summary' => $results['summary']])

@include('batches.results._table', [
    'rows' => $results['rows'],
    'documents' => [
        'action' => route('nurse.batches.documents', $batch),
        'enabled' => true,
        'hint' => null,
    ],
    'rowDocuments' => ['twoSided' => $batch->form_type === 'assessment'],
])

{{-- The hidden frame the row Print buttons post into (FR-NRS-05). --}}
@include('partials.print-frame')

</x-layout.sidebar>
