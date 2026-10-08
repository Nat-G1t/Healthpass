<x-layout.sidebar title="Batch Results">

{{--
    One approved batch's results, for the clinic (FR-NRS-12 / FR-NRS-13,
    D-114, D-125) — what View opens on Upcoming Batches and on Batch Results.
    The back link returns to whichever opened it (`from=completed`, built by
    the controller from route names only).

    The same page body as the College Admin's (D-111) and the Director's
    (D-112): the shared partials under resources/views/batches/results/, fed
    by the same BatchResults::page($batch), so every role sees the same
    numbers. The clinic sees every college, so the header names the college.

    No release button here — the clinic neither asks for documents nor
    reviews requests. Instead:
      - D-125: when the college's approved release asked for printed copies,
        a highlighted banner at the top says so, with Mark as printed (and a
        confirm step); once pressed, it says who marked it done and when;
      - every encoded row has the encode page's print buttons — Print, or
        Print front / Print back on a Medical Assessment Form — printing in
        the hidden frame below, and PDF (`rowDocuments`, D-114);
      - the D-113 select & download tools.
    D-125 (O2): the row buttons and the download work only once the Director
    has released this batch ($release is its approved request); before that
    they are drawn disabled with the hint, and the server refuses them too.
--}}

@php
    $isReleased = $release !== null;
@endphp

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
    'backUrl' => $back['url'],
    'backLabel' => $back['label'],
    'showCollege' => true,
])

@if ($release?->asksClinicToPrint())
    {{-- D-125: the D-124 note style (the Director's "Documents released on …"). --}}
    <div class="mb-6 rounded-2xl border border-hp-orange/30 bg-hp-peach/40 px-5 py-4 text-sm text-hp-slate"
         data-copies-banner="{{ $release->copies_printed_at === null ? 'requested' : 'printed' }}">
        @if ($release->copies_printed_at === null)
            <div class="flex flex-wrap items-center justify-between gap-3" x-data="{ confirming: false }">
                <p class="font-semibold">
                    The college asked for printed copies — approved by the Director on {{ $release->reviewed_at?->format('M j, Y') }}.
                </p>

                {{-- Two steps: the first press only asks; Yes posts. --}}
                <form method="POST" action="{{ $markPrintedUrl }}"
                      class="flex flex-wrap items-center gap-2" data-mark-printed-form>
                    @csrf
                    <x-hp.button size="sm" x-show="! confirming" @click="confirming = true" data-mark-printed>
                        Mark as printed
                    </x-hp.button>
                    <span x-show="confirming" x-cloak class="flex flex-wrap items-center gap-2">
                        <span class="text-xs">Are all the copies printed?</span>
                        <x-hp.button type="submit" size="sm" data-pending-label="Saving…" data-mark-printed-confirm>
                            Yes, mark as printed
                        </x-hp.button>
                        <x-hp.button size="sm" variant="ghost" @click="confirming = false">Cancel</x-hp.button>
                    </span>
                </form>
            </div>
        @else
            @php
                $printedBy = $release->copiesPrinter?->name ?? 'a former staff member';
            @endphp
            <p class="font-semibold">Printed copies marked done by {{ $printedBy }} on {{ $release->copies_printed_at->format('M j, Y') }}.</p>
        @endif
    </div>
@endif

@include('batches.results._summary', ['summary' => $results['summary']])

@include('batches.results._table', [
    'rows' => $results['rows'],
    'documents' => [
        'action' => route('nurse.batches.documents', $batch),
        'enabled' => $isReleased,
        'hint' => App\Models\ClinicVisit::NOT_RELEASED_HINT,
    ],
    'rowDocuments' => [
        'twoSided' => $batch->form_type === 'assessment',
        'enabled' => $isReleased,
    ],
])

{{-- The hidden frame the row Print buttons post into (FR-NRS-05). --}}
@include('partials.print-frame')

</x-layout.sidebar>
