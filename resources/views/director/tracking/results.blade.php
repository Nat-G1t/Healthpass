<x-layout.sidebar title="Batch Results">

{{--
    One approved batch's results, for the Director (FR-DIRA-10, D-112) — what
    the Batch Results card's View opens on the Director's Batch Tracking page.

    The same page body as the College Admin's (FR-ADM-12, D-111): the shared
    partials under resources/views/batches/results/, fed by the same
    BatchResults::page($batch), so both roles always see the same numbers.
    The Director sees every college, so the header also names the college.

    D-113: the Director's select-and-download tools are always on (no release
    request needed), and "Review request of documents" under the card opens
    the college's pending release request.
--}}

@if (session('status'))
    <div data-hp-flash class="mb-6 rounded-lg border border-hp-orange/30 bg-hp-peach/40 px-4 py-3 text-sm text-hp-slate">
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
    'backUrl' => route('director.tracking.index'),
    'backLabel' => 'Batch Tracking',
    'showCollege' => true,
])

@include('batches.results._summary', ['summary' => $results['summary']])

@include('batches.results._table', [
    'rows' => $results['rows'],
    'documents' => [
        'action' => route('director.tracking.documents', $batch),
        'enabled' => true,
        'hint' => null,
    ],
])

{{-- ── Document release review (FR-DIRA-11, D-113) ─────────────────────── --}}
@include('director.tracking.partials.release-review', [
    'batch' => $batch,
    'pendingRelease' => $pendingRelease,
    'attachmentSize' => $attachmentSize,
])

</x-layout.sidebar>
