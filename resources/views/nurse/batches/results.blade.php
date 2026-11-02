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
      - D-125: when the college's approved release asked for printed copies
        (or, D-136, the Director approved them with the batch), a highlighted
        banner at the top says so, with Mark as printed (and a confirm step);
        once pressed, it says who marked it done and when;
      - every encoded row has the encode page's print buttons — Print, or
        Print front / Print back on a Medical Assessment Form — printing in
        the hidden frame below, and PDF (`rowDocuments`, D-114);
      - the D-113 select & download tools.
    D-125 (O2): the row buttons and the download work only once the Director
    has released this batch ($release is its approved request); before that
    they are drawn disabled with the hint, and the server refuses them too.
    D-136: when the Director approved printed copies WITH the batch, the
    banner shows from approval and the row Print buttons work at once; the
    row PDF and the download still wait for the release.
--}}

@php
    $isReleased = $release !== null;
    $canPrint = $isReleased || $batch->printed_copies_approved;
    // Early copies (D-136) are marked on the batch, a release's on the release.
    $copiesPrintedAt = $batch->copiesPrintedAt();
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

@if ($batch->asksClinicToPrint())
    {{-- D-125: the D-124 note style (the Director's "Documents released on …"). --}}
    <div class="mb-6 rounded-2xl border border-hp-orange/30 bg-hp-peach/40 px-5 py-4 text-sm text-hp-slate"
         data-copies-banner="{{ $copiesPrintedAt === null ? 'requested' : 'printed' }}">
        @if ($copiesPrintedAt === null)
            <div class="flex flex-wrap items-center justify-between gap-3" x-data="{ confirming: false }">
                @if ($batch->printed_copies_approved)
                    <p class="font-semibold" data-copies-source="batch">
                        The college asked for printed copies with this batch — approved by the Director on {{ $batch->reviewed_at?->format('M j, Y') }}.
                        <span class="block text-xs font-normal text-hp-slate/70">Print each student’s form as soon as it is encoded.</span>
                    </p>
                @else
                    <p class="font-semibold" data-copies-source="release">
                        The college asked for printed copies — approved by the Director on {{ $release->reviewed_at?->format('M j, Y') }}.
                    </p>
                @endif

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
                $printer = $batch->printed_copies_approved ? $batch->copiesPrinter : $release->copiesPrinter;
                $printedBy = $printer?->name ?? 'a former staff member';
            @endphp
            <p class="font-semibold">Printed copies marked done by {{ $printedBy }} on {{ $copiesPrintedAt->format('M j, Y') }}.</p>
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
        'enabled' => $canPrint,
        'pdfEnabled' => $isReleased,
    ],
])

{{-- The hidden frame the row Print buttons post into (FR-NRS-05). --}}
@include('partials.print-frame')

</x-layout.sidebar>
