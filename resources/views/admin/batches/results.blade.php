<x-layout.sidebar title="Batch Results">

{{--
    One approved batch's results (FR-ADM-12, D-111) — what the Batch Results
    card's View opens. It replaced D-55's popup.

    The body is the shared partials under resources/views/batches/results/,
    which the Director's and the clinic's results pages reuse (D-112, D-114).
    $results is BatchResults::page($batch): status, summary and rows, built
    once in the controller so the badge, the cards and the table read the same
    students.

    D-113: the main card's select-and-download tools work only once the
    Director has released this batch's documents ($isReleased); until then
    they are drawn greyed out. The button under the card asks for the release.
--}}

@if (session('status'))
    <div data-hp-flash class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
        {{ session('status') }}
    </div>
@endif
@if (session('error'))
    <div data-hp-flash data-flash-sticky class="mb-6 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ session('error') }}
    </div>
@endif

@include('batches.results._header', [
    'batch' => $batch,
    'status' => $results['status'],
    'backUrl' => route('admin.batches.index'),
    'backLabel' => 'Batch Tracking',
])

@include('batches.results._summary', ['summary' => $results['summary']])

@include('batches.results._table', [
    'rows' => $results['rows'],
    'documents' => [
        'action' => route('admin.batches.documents', $batch->id),
        'enabled' => $isReleased,
        'hint' => 'Available after the Director releases the documents.',
    ],
])

{{-- ── Document release (FR-ADM-15, D-113) ─────────────────────────────── --}}
@include('admin.batches.partials.release-request', [
    'batch' => $batch,
    'status' => $results['status'],
    'latestRelease' => $latestRelease,
    'isReleased' => $isReleased,
])

</x-layout.sidebar>
