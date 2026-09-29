<x-layout.sidebar title="Batch Tracking">

@php
    // D-36: the Reason column only exists once something has been rejected —
    // an all-approved list shouldn't carry a column of em dashes.
    $hasRejected = $batchRequests->contains(fn ($batch) => $batch->status === 'rejected');
    // D-62: Form Type sits immediately before Reason — the reason list is the form's.
    $headers = ['Batch ID', 'Form Type', 'Reason', 'Students', 'Submitted', 'Status'];

    // Named "Rejection Reason", not "Reason": the list already has a Reason
    // column (the batch's own reason) and two identical headers would be
    // ambiguous on desktop and in the mobile cards' labels alike.
    if ($hasRejected) {
        $headers[] = 'Rejection Reason';
    }

    // FR-ADM-11 (D-52): the Cancel column follows the same rule as the one
    // above — it exists only when at least one row can actually use it, so a
    // list with nothing pending doesn't carry a column of em dashes either.
    // Its header is deliberately BLANK: the column holds an action, not a
    // value, exactly like the Withdraw column on the batch roster (FR-ADM-07).
    $hasCancellable = $batchRequests->contains(fn ($batch) => $batch->isCancellable());

    if ($hasCancellable) {
        $headers[] = '';
    }
@endphp

{{--
    One page-level Alpine component holds ALL THREE modals' state:
    `results` (D-55) is null (closed) or the Batch Results popup payload of the
    approved batch whose View button was clicked; `detail` is null or the
    { ref, reason, reviewer, reviewedAt } of the rejected row whose View button
    was clicked; `cancelTarget` (D-52) is null or the { id, ref, students, date }
    of the row whose Cancel button was clicked.

    Independent keys rather than one shared `modal` object: the dialogs carry
    different payloads, and only one can be open at a time, so closing one
    never has to reset the others.
--}}
<div x-data="{ results: null, detail: null, cancelTarget: null }">

{{-- ── Flash messages ─────────────────────────────────────────────────────── --}}
{{-- Where the cancel endpoint lands (FR-ADM-11): both its success and its
     refusal redirect back to this page. --}}
@if (session('status'))
    <div data-hp-flash class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
        {{ session('status') }}
    </div>
@endif

@if (session('error'))
    <div data-hp-flash data-flash-sticky class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ session('error') }}
    </div>
@endif

{{-- D-92: the cancel reason failed validation. Shown at page level because the
     redirect closes the dialog it was typed into. --}}
@error('cancellation_reason')
    <div data-hp-flash data-flash-sticky class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ $message }}
    </div>
@enderror

{{-- ── Page header ────────────────────────────────────────────────────────── --}}
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="text-xl font-semibold text-hp-slate">Batch Tracking</h2>
        <p class="mt-0.5 text-sm text-hp-slate/50">
            All batch requests submitted for {{ $college->code }}.
        </p>
    </div>

    <a href="{{ route('admin.batches.create') }}"
       class="inline-flex items-center justify-center gap-2 rounded-full bg-hp-orange
              px-5 py-2 text-sm font-semibold text-white transition-colors
              duration-hp-fast hover:bg-orange-500">
        New Batch Request
    </a>
</div>

{{-- ── Batch Results (FR-ADM-12, D-55, D-86) ─────────────────────────────── --}}
{{-- Every APPROVED batch of this college, newest clinic date first — a batch
     appears here the moment the Director approves it. The card renders when
     the college has at least one ($hasApprovedBatches — decided in the
     controller, so neither a stale page link nor a search with no match can
     hide it), and it is rebuilt on every page load (nothing polls), so a
     reload after the nurse encodes shows the new result. --}}
@if ($hasApprovedBatches)
    <x-hp.card class="mb-6">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                    Batch Results
                </p>
                <p class="mt-1 text-xs text-hp-slate/50">
                    Who has finished and who didn't show, for every approved batch.
                </p>
            </div>

            {{-- D-86: Batch ID search. A plain GET form, so the server filters
                 every page (not just the ten on screen) and the term stays in
                 the URL for the pager links. It only filters this card. --}}
            <form method="GET" action="{{ route('admin.batches.index') }}" role="search"
                  class="flex w-full items-center gap-2 sm:w-auto">
                <label for="results-search" class="sr-only">Search by Batch ID</label>
                <input id="results-search" name="q" type="search" value="{{ $search }}"
                       maxlength="30" placeholder="Search Batch ID"
                       class="w-full rounded-lg border-hp-slate/20 bg-hp-white px-3 py-1.5 text-xs text-hp-slate placeholder-hp-slate/40 focus:border-hp-orange focus:ring-hp-orange sm:w-48">
                <x-hp.button type="submit" variant="soft" size="sm">Search</x-hp.button>
                @if ($search !== '')
                    <a href="{{ route('admin.batches.index') }}"
                       class="px-1 py-1.5 text-xs font-medium text-hp-slate/50 hover:text-hp-slate">
                        Clear
                    </a>
                @endif
            </form>
        </div>

        @if ($approvedBatches->isEmpty())
            <p class="py-6 text-center text-sm text-hp-slate/50">
                No approved batch matches “{{ $search }}”.
            </p>
        @else
        <x-hp.table :headers="['Batch ID', 'Time of Completion', 'Status', '']">
            @foreach ($approvedBatches as $batch)
                @php
                    // D-86: the completion rule lives on the model and reads the
                    // same clearanceProgress() as the popup rows, so the columns
                    // and the popup can never disagree. Only the wording lives here.
                    $completedAt = $batch->resultsCompletedAt();
                    $resultsStatus = $batch->resultsStatus();
                @endphp
                <x-hp.table-row>
                    <x-hp.table-cell label="Batch ID" class="font-semibold">
                        {{ $batch->reference_no }}
                    </x-hp.table-cell>

                    <x-hp.table-cell label="Time of Completion">
                        @if ($completedAt !== null)
                            {{ $completedAt->format('M j, Y · g:i A') }}
                        @else
                            <span class="text-hp-slate/50">&mdash;</span>
                        @endif
                    </x-hp.table-cell>

                    <x-hp.table-cell label="Status">
                        @if ($resultsStatus === 'completed')
                            <x-hp.badge variant="approved">Completed</x-hp.badge>
                        @elseif ($resultsStatus === 'no_one_attended')
                            <x-hp.badge variant="rejected">No one attended</x-hp.badge>
                        @else
                            <x-hp.badge variant="live">In progress</x-hp.badge>
                        @endif
                    </x-hp.table-cell>

                    <x-hp.table-cell label="">
                        {{-- Plain <button> (not <x-hp.button>), like the rejection
                             reason's View button below: the payload is built with
                             Js::from(), and Blade output isn't compiled inside a
                             component tag's attribute. Js::from() also escapes
                             quotes, so a name like O'Brien can't break it. --}}
                        <button type="button"
                            @click="results = {{ Illuminate\Support\Js::from($resultPopups[$batch->id]) }}"
                            class="inline-flex items-center justify-center gap-2 rounded-full
                                   border-[1.5px] border-hp-slate/30 px-4 py-1 text-xs font-semibold
                                   text-hp-slate transition-colors duration-hp-fast hover:bg-hp-slate/10
                                   focus-visible:outline-none focus-visible:ring-2
                                   focus-visible:ring-hp-slate focus-visible:ring-offset-1">
                            View
                        </button>
                    </x-hp.table-cell>
                </x-hp.table-row>
            @endforeach
        </x-hp.table>

        <x-hp.pager :paginator="$approvedBatches" />
        @endif
    </x-hp.card>
@endif

{{-- ── Batch requests table (FR-ADM-05) ───────────────────────────────────── --}}
<x-hp.card>
    @if ($batchRequests->isEmpty())
        <div class="flex flex-col items-center py-10 text-center">
            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-hp-slate">No batch requests yet</p>
            <p class="mt-0.5 text-xs text-hp-slate/50">
                Use “New Batch Request” to submit your first batch for {{ $college->code }}.
            </p>
        </div>
    @else
        <x-hp.table :headers="$headers">
            @foreach ($batchRequests as $batch)
                <x-hp.table-row>
                    {{-- D-40: the reference is now the way into the batch roster,
                         which is where a student's appointment gets withdrawn. --}}
                    <x-hp.table-cell label="Batch ID" class="font-medium">
                        <a href="{{ route('admin.batches.show', $batch->id) }}"
                           class="font-semibold text-hp-orange transition-colors hover:underline">
                            {{ $batch->reference_no }}
                        </a>
                    </x-hp.table-cell>
                    <x-hp.table-cell label="Form Type">{{ $batch->formTypeLabel() }}</x-hp.table-cell>
                    <x-hp.table-cell label="Reason">{{ Str::limit($batch->reasonText(), 60) }}</x-hp.table-cell>
                    <x-hp.table-cell label="Students">{{ $batch->batch_request_students_count }}</x-hp.table-cell>
                    <x-hp.table-cell label="Submitted" class="text-hp-slate/60">{{ $batch->created_at->format('M j, Y') }}</x-hp.table-cell>
                    <x-hp.table-cell label="Status">
                        <x-hp.badge :variant="$batch->status">{{ $batch->statusLabel() }}</x-hp.badge>
                    </x-hp.table-cell>

                    {{-- D-36: why the Director turned this batch down. The cell
                         exists on every row so the desktop table stays aligned;
                         non-rejected rows just carry an em dash. --}}
                    @if ($hasRejected)
                        <x-hp.table-cell label="Rejection Reason">
                            @if ($batch->status === 'rejected' && $batch->rejection_reason !== null)
                                {{-- Plain <button> (not <x-hp.button>): the payload is
                                     built with Js::from(), and Blade output isn't
                                     compiled inside a component tag's attribute. --}}
                                <button type="button"
                                    @click="detail = {{ Illuminate\Support\Js::from([
                                        'ref' => $batch->reference_no,
                                        'reason' => $batch->rejection_reason,
                                        'reviewer' => $batch->reviewer?->name,
                                        'reviewedAt' => $batch->reviewed_at?->format('M j, Y g:i A'),
                                    ]) }}"
                                    class="inline-flex items-center justify-center gap-2 rounded-full
                                           border-[1.5px] border-hp-slate/30 px-4 py-1 text-xs font-semibold
                                           text-hp-slate transition-colors duration-hp-fast hover:bg-hp-slate/10
                                           focus-visible:outline-none focus-visible:ring-2
                                           focus-visible:ring-hp-slate focus-visible:ring-offset-1">
                                    View
                                </button>
                            @else
                                <span class="text-hp-slate/50">&mdash;</span>
                            @endif
                        </x-hp.table-cell>
                    @endif

                    {{-- FR-ADM-11 (D-52): withdraw a request the Director has not
                         ruled on yet. isCancellable() is the SAME rule the endpoint
                         re-checks under a row lock, so this button can never offer
                         something the server would refuse. --}}
                    @if ($hasCancellable)
                        <x-hp.table-cell label="">
                            @if ($batch->isCancellable())
                                {{-- Plain <button> carrying the danger variant's classes,
                                     for the same reason as the View button above: the
                                     payload is built with Js::from(), and Blade output
                                     isn't compiled inside a component tag's attribute.

                                     `students` is CAST to int: withCount() comes back an
                                     int on SQLite but a STRING from MySQL's PDO driver,
                                     and the dialog compares it with === 1 to pluralise. --}}
                                <button type="button"
                                    @click="cancelTarget = {{ Illuminate\Support\Js::from([
                                        'id' => $batch->id,
                                        'ref' => $batch->reference_no,
                                        'students' => (int) $batch->batch_request_students_count,
                                        'date' => $batch->requested_date?->format('M j, Y'),
                                        // D-92: approved batches are cancellable too, and the dialog warns that students are emailed.
                                        'approved' => $batch->status === 'approved',
                                    ]) }}"
                                    class="inline-flex items-center justify-center gap-2 rounded-full
                                           border-[1.5px] border-red-300 bg-transparent px-4 py-1.5 text-xs
                                           font-semibold text-red-500 hover:bg-red-50 active:scale-[0.97]
                                           transition-[color,background-color,border-color,transform]
                                           duration-hp-fast ease-hp-out focus-visible:outline-none
                                           focus-visible:ring-2 focus-visible:ring-red-500
                                           focus-visible:ring-offset-1">
                                    Cancel
                                </button>
                            @else
                                <span class="text-hp-slate/50">&mdash;</span>
                            @endif
                        </x-hp.table-cell>
                    @endif
                </x-hp.table-row>
            @endforeach
        </x-hp.table>

        <x-hp.pager :paginator="$batchRequests" />
    @endif
</x-hp.card>

{{-- ── Batch Results popup (FR-ADM-12, D-55) ──────────────────────────────── --}}
{{-- Same dialog shape as the rejection-reason modal below: teleported to <body>
     so no ancestor's overflow or stacking context clips the full-screen
     backdrop, and dismissable with Esc, a backdrop click or Close.

     OUTCOME ONLY (PRD §6.6): the payload holds each student's name, number,
     hour, a status key and Fit/Unfit, and nothing else of the clinical record
     is ever sent. Every value is written with x-text, which sets textContent,
     so a name is never parsed as markup. The status WORDING lives here — one
     badge per key, shown with x-show. D-86: from `md` up the student list is
     capped at six rows plus half of the seventh (the half row is the cue that
     it scrolls), so a big batch can't stretch the popup. Below `md` the table
     re-flows into stacked cards like everywhere else; a card is several rows
     tall, so there the list scrolls within the panel's own 90vh instead. --}}
@if ($approvedBatches->isNotEmpty())
    <template x-teleport="body">
        <div
            x-show="results !== null"
            x-cloak
            @keydown.escape.window="results = null"
            class="fixed inset-0 z-[60] flex items-center justify-center px-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="batch-results-title"
        >
            {{-- Backdrop — click outside to dismiss --}}
            <div
                x-show="results !== null"
                @click="results = null"
                x-transition:enter="ease-hp-out duration-hp-base"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-hp-in duration-hp-fast"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 bg-hp-slate/50"
                aria-hidden="true"
            ></div>

            {{-- Panel --}}
            <div
                x-show="results !== null"
                x-transition:enter="ease-hp-spring duration-hp-slow"
                x-transition:enter-start="opacity-0 translate-y-6"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="ease-hp-in duration-hp-base"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-6"
                class="relative flex max-h-[90vh] w-full max-w-3xl flex-col rounded-2xl bg-hp-white p-6 shadow-xl"
            >
                <h2 id="batch-results-title" class="text-lg font-semibold text-hp-slate">
                    Results for <span x-text="results?.ref"></span>
                </h2>
                <p class="mt-1 text-sm text-hp-slate/60">
                    <span x-text="results?.form"></span> ·
                    <span x-text="results?.date"></span> ·
                    <span x-text="results?.span"></span>
                </p>

                <div class="mt-5 min-h-0 overflow-y-auto md:max-h-[21rem]">
                    <x-hp.table :headers="['Student', 'Student No.', 'Hour', 'Status', 'Result']">
                        <template x-for="(row, index) in results?.students ?? []" :key="index">
                            <x-hp.table-row>
                                <x-hp.table-cell label="Student" class="font-medium">
                                    <span x-text="row.name"></span>
                                </x-hp.table-cell>

                                <x-hp.table-cell label="Student No." class="text-hp-slate/60">
                                    <span x-text="row.number"></span>
                                </x-hp.table-cell>

                                <x-hp.table-cell label="Hour">
                                    <span x-text="row.hour"></span>
                                </x-hp.table-cell>

                                <x-hp.table-cell label="Status">
                                    {{-- Same badges the roster used under D-53: orange for
                                         the one state still moving, peach once done, slate
                                         for waiting or did-not-happen. --}}
                                    <x-hp.badge variant="pending" x-show="row.status === 'awaiting'">Not yet attended</x-hp.badge>
                                    {{-- D-72: the kiosk captured a high reading and the
                                         student is resting before re-taking it. Still
                                         moving, so the same orange "pending" badge as
                                         Not yet attended would be wrong — this one is
                                         at the clinic, just not in the queue yet. --}}
                                    <x-hp.badge variant="live" x-show="row.status === 'rechecking'">Re-check</x-hp.badge>
                                    <x-hp.badge variant="live" x-show="row.status === 'in_clinic'">At the clinic</x-hp.badge>
                                    <x-hp.badge variant="approved" x-show="row.status === 'completed'">Completed</x-hp.badge>
                                    <x-hp.badge variant="rejected" x-show="row.status === 'absent'">Absent</x-hp.badge>
                                    {{-- D-86: reached the kiosk, but by the 8 PM cutoff was
                                         still resting or not yet encoded. --}}
                                    <x-hp.badge variant="rejected" x-show="row.status === 'did_not_finish'">Did not finish</x-hp.badge>
                                    <span x-show="row.status === null" class="text-hp-slate/50">&mdash;</span>
                                </x-hp.table-cell>

                                <x-hp.table-cell label="Result">
                                    <x-hp.badge variant="fit" x-show="row.result === 'Fit'">Fit</x-hp.badge>
                                    <x-hp.badge variant="unfit" x-show="row.result === 'Unfit'">Unfit</x-hp.badge>
                                    <span x-show="row.result === null" class="text-hp-slate/50">&mdash;</span>
                                </x-hp.table-cell>
                            </x-hp.table-row>
                        </template>
                    </x-hp.table>
                </div>

                <div class="mt-6 flex justify-end">
                    <x-hp.button variant="muted" @click="results = null">Close</x-hp.button>
                </div>
            </div>
        </div>
    </template>
@endif

{{-- ── Rejection reason modal (FR-ADM-05, D-36) ───────────────────────────── --}}
{{-- Teleported to <body> so no ancestor's overflow/stacking clips the
     full-screen backdrop (same pattern as <x-logout-confirm>). --}}
<template x-teleport="body">
    <div
        x-show="detail !== null"
        x-cloak
        @keydown.escape.window="detail = null"
        class="fixed inset-0 z-[60] flex items-center justify-center px-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rejection-reason-title"
    >
        {{-- Backdrop — click outside to dismiss --}}
        <div
            x-show="detail !== null"
            @click="detail = null"
            x-transition:enter="ease-hp-out duration-hp-base"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-hp-in duration-hp-fast"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="absolute inset-0 bg-hp-slate/50"
            aria-hidden="true"
        ></div>

        {{-- Panel --}}
        <div
            x-show="detail !== null"
            x-transition:enter="ease-hp-spring duration-hp-slow"
            x-transition:enter-start="opacity-0 translate-y-6"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="ease-hp-in duration-hp-base"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-6"
            class="relative w-full max-w-md rounded-2xl bg-hp-white p-6 shadow-xl"
        >
            <h2 id="rejection-reason-title" class="text-lg font-semibold text-hp-slate">
                Why <span x-text="detail?.ref"></span> was rejected
            </h2>

            {{-- x-text sets textContent, so Director-written free text is never
                 parsed as markup (the server side of this is Js::from above). --}}
            <p class="mt-4 whitespace-pre-line rounded-lg bg-hp-bg px-4 py-3 text-sm text-hp-slate"
               x-text="detail?.reason"></p>

            <p class="mt-3 text-xs text-hp-slate/50">
                Reviewed by <span class="font-semibold" x-text="detail?.reviewer ?? 'the Clinic Director'"></span>
                <span x-show="detail?.reviewedAt" x-cloak>
                    on <span x-text="detail?.reviewedAt"></span>
                </span>
            </p>

            <div class="mt-6 flex justify-end">
                <x-hp.button variant="muted" @click="detail = null">Close</x-hp.button>
            </div>
        </div>
    </div>
</template>

{{-- ── Cancel confirmation (FR-ADM-11, D-52) ──────────────────────────────── --}}
{{-- Shared with the Batch Request Submitted page since D-88; it reads this
     page's `cancelTarget`. --}}
<x-batch-cancel-confirm />

</div>

</x-layout.sidebar>
