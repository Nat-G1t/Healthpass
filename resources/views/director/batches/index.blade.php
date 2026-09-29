<x-layout.sidebar title="Batch Approvals">

@php
    // One source of truth for the reason bounds: the Form Request that
    // actually enforces them (D-36).
    $reasonMin = App\Http\Requests\Director\RejectBatchRequest::REASON_MIN;
    $reasonMax = App\Http\Requests\Director\RejectBatchRequest::REASON_MAX;
@endphp

{{--
    Director Batch Approvals (FR-DIRA-01/05/06, D-36).

    One page-level Alpine component owns BOTH decision modals: the approve
    confirmation (which batch, its requested date, its D-37 hour span, and the
    live per-hour capacity check) and the reject dialog (which batch, the typed
    reason). Both are confirmations now — D-36 removed the Director's date
    picker, so approving means confirming the College Admin's requested date
    and span, and pushing back means rejecting with a written reason. Since
    D-37 a full hour inside the span BLOCKS approval outright rather than
    warning about it.

    D-90: when an earlier pending batch can't fit beside this one, the approve
    modal becomes a "Schedule conflict" warning — it recommends first come,
    and can hand off to the reject dialog with a suggested reason.
--}}
<div x-data="batchApprovals()">

    {{-- ── Page header ─────────────────────────────────────────────────── --}}
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-hp-slate">Batch Approvals</h2>
        <p class="mt-0.5 text-sm text-hp-slate/50">
            Batch requests from all colleges, awaiting your review.
        </p>
    </div>

    {{-- Flash from the decision endpoints --}}
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

    {{-- Reject validation failure (D-36). Shown at page level because the
         redirect closes the modal the reason was typed into. --}}
    @error('rejection_reason')
        <div class="mb-6 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ $message }}
        </div>
    @enderror

    {{-- ── Requests table (FR-DIRA-01) ─────────────────────────────────── --}}
    <x-hp.card>
        {{-- D-90: Batch ID search. A plain GET form, so the server filters
             every page (not just the ten on screen) and the term stays in the
             URL for the pager links — the same shape as the College Admin's
             Batch Results search (D-86). Shown whenever there is anything to
             search, or a search to change or clear. --}}
        @if ($search !== '' || $batchRequests->total() > 0)
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                    Batch Requests
                </p>

                <form method="GET" action="{{ route('director.batches.index') }}" role="search"
                      class="flex w-full items-center gap-2 sm:w-auto">
                    <label for="approvals-search" class="sr-only">Search by Batch ID</label>
                    <input id="approvals-search" name="q" type="search" value="{{ $search }}"
                           maxlength="30" placeholder="Search Batch ID"
                           class="w-full rounded-lg border-hp-slate/20 bg-hp-white px-3 py-1.5 text-xs text-hp-slate placeholder-hp-slate/40 focus:border-hp-orange focus:ring-hp-orange sm:w-48">
                    <x-hp.button type="submit" variant="soft" size="sm">Search</x-hp.button>
                    @if ($search !== '')
                        <a href="{{ route('director.batches.index') }}"
                           class="px-1 py-1.5 text-xs font-medium text-hp-slate/50 hover:text-hp-slate">
                            Clear
                        </a>
                    @endif
                </form>
            </div>
        @endif

        @if ($batchRequests->isEmpty() && $search !== '')
            <p class="py-6 text-center text-sm text-hp-slate/50">
                No batch request matches “{{ $search }}”.
            </p>
        @elseif ($batchRequests->isEmpty())
            <div class="flex flex-col items-center py-10 text-center">
                <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                    <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-hp-slate">No batch requests yet</p>
                <p class="mt-0.5 text-xs text-hp-slate/50">
                    College admins' submissions will appear here for review.
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-hp-slate/10 text-[11px] uppercase tracking-widest text-hp-slate/40">
                            <th class="py-2.5 pr-4 font-semibold">Batch ID</th>
                            <th class="py-2.5 pr-4 font-semibold">College</th>
                            {{-- D-62: Form Type immediately before Reason — the reason list is the form's --}}
                            <th class="py-2.5 pr-4 font-semibold">Form Type</th>
                            <th class="py-2.5 pr-4 font-semibold">Reason</th>
                            <th class="py-2.5 pr-4 font-semibold">Students</th>
                            <th class="py-2.5 pr-4 font-semibold">Requested Date</th>
                            <th class="py-2.5 pr-4 font-semibold">Clinic Hours</th>
                            <th class="py-2.5 pr-4 font-semibold">Submitted</th>
                            <th class="py-2.5 font-semibold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hp-slate/10">
                        @foreach ($batchRequests as $batch)
                            <tr class="text-hp-slate">
                                <td class="py-3 pr-4">
                                    <div class="flex items-center gap-2">
                                        <span class="whitespace-nowrap font-medium">{{ $batch->reference_no }}</span>
                                        <x-hp.badge :variant="$batch->status">{{ ucfirst($batch->status) }}</x-hp.badge>
                                    </div>

                                    {{-- D-90: pending batches this one cannot share the
                                         clinic with, on the SAME row as the batch they
                                         affect, so the Director sees them before
                                         clicking Approve. First come is the one to
                                         approve, so each says which came first. --}}
                                    @foreach ($conflicts[$batch->id] ?? [] as $other)
                                        {{-- Two short lines, so the column stays no wider
                                             than the Batch ID and its badge. --}}
                                        <p class="mt-1 whitespace-nowrap text-xs font-medium text-hp-orange">
                                            &#9888; Conflicts with {{ $other->reference_no }}
                                        </p>
                                        <p class="w-0 min-w-full text-xs text-hp-slate/60">
                                            {{ $other->submittedBefore($batch) ? 'which was submitted first' : 'which was submitted after this one' }}
                                        </p>
                                    @endforeach
                                </td>
                                <td class="py-3 pr-4">{{ $batch->college->code }}</td>
                                <td class="py-3 pr-4 whitespace-nowrap">{{ $batch->formTypeLabel() }}</td>
                                <td class="py-3 pr-4">&ldquo;{{ Str::limit($batch->reasonText(), 60) }}&rdquo;</td>
                                <td class="py-3 pr-4">{{ $batch->batch_request_students_count }}</td>
                                {{-- Admin-proposed clinic date (D-29); "—" on pre-D-29 batches --}}
                                <td class="py-3 pr-4">{{ $batch->requested_date?->format('M j, Y') ?? '—' }}</td>
                                {{-- D-37 hour span; "—" on pre-D-37 batches --}}
                                <td class="py-3 pr-4">{{ $batch->requestedSpanLabel() }}</td>
                                <td class="py-3 pr-4 text-hp-slate/60">{{ $batch->created_at->format('M j, Y') }}</td>
                                <td class="py-3">
                                    @if ($batch->status === 'pending')
                                        {{-- Both actions open a modal (D-36). Plain <button> (not
                                             <x-hp.button>) because the payload is built with
                                             Js::from() — Blade output isn't compiled inside a
                                             component tag's attribute value. --}}
                                        @php
                                            // D-36: nothing left to confirm in either case —
                                            // no date was ever requested, or the one that was
                                            // has passed. Mirrors the server's two refusals.
                                            $isStale = $batch->hasStaleRequestedDate();
                                            // D-37 adds a third case with nothing to confirm:
                                            // a batch submitted before the hourly-slot change
                                            // has no hour span. Same reject-and-resubmit path.
                                            $hasNoSpan = $batch->requestedSpan() === [];
                                            // BR-23: requested for today, but some of
                                            // its hours ended while it sat pending.
                                            $elapsedHours = $batch->elapsedSpanHours();
                                            // All four together, as one model rule (D-90
                                            // reads it too). The flags above only pick
                                            // the message.
                                            $canApprove = $batch->isApprovable();
                                        @endphp

                                        <div class="flex items-center gap-2">
                                            @if (! $canApprove)
                                                <button type="button" disabled
                                                    class="inline-flex cursor-not-allowed items-center justify-center gap-2
                                                           rounded-full bg-hp-orange px-4 py-1.5 text-xs font-semibold
                                                           text-white opacity-50">
                                                    Approve
                                                </button>
                                            @else
                                                <button type="button"
                                                    @click="openApprove({{ Illuminate\Support\Js::from([
                                                        'ref' => $batch->reference_no,
                                                        'form' => $batch->formTypeLabel(),
                                                        'students' => $batch->batch_request_students_count,
                                                        'requested' => $batch->requested_date->toDateString(),
                                                        'time' => $batch->requested_time,
                                                        'blocks' => (int) $batch->requested_blocks,
                                                        'spanLabel' => $batch->requestedSpanLabel(),
                                                        'url' => route('director.batches.approve', $batch),
                                                        // D-90: set only when an EARLIER pending batch
                                                        // can't fit alongside this one; the popup then
                                                        // warns, and "Reject this one" posts here.
                                                        'conflict' => $conflictPopups[$batch->id] ?? null,
                                                        'rejectUrl' => route('director.batches.reject', $batch),
                                                    ]) }})"
                                                    class="inline-flex items-center justify-center gap-2 rounded-full
                                                           bg-hp-orange px-4 py-1.5 text-xs font-semibold text-white
                                                           transition-colors duration-hp-fast hover:bg-orange-500
                                                           focus-visible:outline-none focus-visible:ring-2
                                                           focus-visible:ring-hp-orange focus-visible:ring-offset-1">
                                                    Approve
                                                </button>
                                            @endif

                                            <button type="button"
                                                @click="openReject({{ Illuminate\Support\Js::from([
                                                    'ref' => $batch->reference_no,
                                                    'form' => $batch->formTypeLabel(),
                                                    'url' => route('director.batches.reject', $batch),
                                                ]) }})"
                                                class="inline-flex items-center justify-center gap-2 rounded-full
                                                       border-[1.5px] border-red-300 px-4 py-1.5
                                                       text-xs font-semibold text-red-500
                                                       transition-colors duration-hp-fast hover:bg-red-50 focus-visible:outline-none
                                                       focus-visible:ring-2 focus-visible:ring-red-500
                                                       focus-visible:ring-offset-1">
                                                Reject
                                            </button>
                                        </div>

                                        @if (! $canApprove)
                                            <p class="mt-2 max-w-xs text-xs text-hp-slate/60">
                                                @if ($isStale)
                                                    The requested date has already passed — reject it with a reason
                                                    asking the college to resubmit for a new date.
                                                @elseif ($batch->requested_date === null)
                                                    This batch predates the requested-date field — reject it with the reason
                                                    &ldquo;predates the requested-date field — please resubmit&rdquo;.
                                                @elseif ($elapsedHours !== [])
                                                    The {{ app(App\Services\ClinicScheduleService::class)->label($elapsedHours[0]) }}
                                                    slot in this batch&rsquo;s span has already passed today — reject it with a
                                                    reason asking the college to resubmit for a later time.
                                                @else
                                                    This batch has no clinic hour span — reject it with the reason
                                                    &ldquo;predates the hourly-slot change — please resubmit&rdquo;.
                                                @endif
                                            </p>
                                        @endif
                                    @elseif ($batch->status === 'approved')
                                        {{-- Decided rows are static — no re-decision (FR-DIRA-05) --}}
                                        <span class="text-sm font-semibold text-hp-orange">✓ Approved</span>
                                    @elseif ($batch->status === 'cancelled')
                                        {{-- FR-ADM-11 (D-52): the college withdrew it before
                                             this page could rule on it. Called out separately
                                             because it is NOT a Director decision — labelling
                                             it "Rejected" would credit the Director with an
                                             action they never took. --}}
                                        <span class="whitespace-nowrap text-sm font-semibold text-hp-slate/60">↩ Cancelled by college</span>
                                        {{-- D-92: an approved batch can be cancelled too
                                             (until its first hour), and every cancel now
                                             carries the college's written reason. --}}
                                        @if ($batch->wasCancelledAfterApproval())
                                            <p class="mt-0.5 whitespace-nowrap text-xs text-hp-slate/50">after you approved it</p>
                                        @endif
                                        @if ($batch->cancellation_reason !== null)
                                            <button type="button"
                                                @click="cancelDetail = {{ Illuminate\Support\Js::from([
                                                    'ref' => $batch->reference_no,
                                                    'college' => $batch->college->code,
                                                    'reason' => $batch->cancellation_reason,
                                                    'by' => $batch->canceller?->name,
                                                    'at' => $batch->cancelled_at?->format('M j, Y g:i A'),
                                                    'afterApproval' => $batch->wasCancelledAfterApproval(),
                                                ]) }}"
                                                class="mt-1.5 inline-flex items-center justify-center gap-2 rounded-full
                                                       border-[1.5px] border-hp-slate/30 px-4 py-1 text-xs font-semibold
                                                       text-hp-slate transition-colors duration-hp-fast hover:bg-hp-slate/10
                                                       focus-visible:outline-none focus-visible:ring-2
                                                       focus-visible:ring-hp-slate focus-visible:ring-offset-1">
                                                View reason
                                            </button>
                                        @endif
                                    @else
                                        <span class="text-sm font-semibold text-hp-slate/60">✕ Rejected</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-hp.pager :paginator="$batchRequests" />
        @endif
    </x-hp.card>

    {{-- ── Approve modal (FR-DIRA-02 confirm + FR-DIRA-06 warning) ─────── --}}
    {{-- Teleported to <body> so no ancestor's overflow/stacking clips the
         full-screen backdrop (same pattern as <x-logout-confirm>). --}}
    <template x-teleport="body">
        <div
            x-show="batch !== null"
            x-cloak
            @keydown.escape.window="close()"
            class="fixed inset-0 z-[60] flex items-center justify-center px-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="approve-batch-title"
        >
            {{-- Backdrop — click outside to cancel. Motion tokens (§5.7):
                 backdrop fades, panel rises like an iOS sheet, exits faster. --}}
            <div
                x-show="batch !== null"
                @click="close()"
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
                x-show="batch !== null"
                x-transition:enter="ease-hp-spring duration-hp-slow"
                x-transition:enter-start="opacity-0 translate-y-6"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="ease-hp-in duration-hp-base"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-6"
                class="relative w-full rounded-2xl bg-hp-white p-6 shadow-xl"
                {{-- D-90: a little wider for the conflict warning, so its three
                     buttons sit on one row. --}}
                :class="batch?.conflict ? 'max-w-lg' : 'max-w-md'"
            >
                <h2 id="approve-batch-title" class="text-lg font-semibold text-hp-slate">
                    <span x-show="! batch?.conflict">Approve <span x-text="batch?.ref"></span>?</span>
                    <span x-show="batch?.conflict" x-cloak>Schedule conflict</span>
                </h2>

                {{-- D-90: an EARLIER pending batch asked for these hours and the
                     clinic can't fit both. A warning, not a block — the Director
                     may still approve — but it recommends first come, and lists
                     where this batch could go instead. --}}
                <template x-if="batch?.conflict">
                    <div class="mt-3 rounded-lg border border-hp-orange/40 bg-hp-peach/30 px-4 py-3 text-sm text-hp-slate">
                        <template x-for="other in batch.conflict.earlier" :key="other.ref">
                            <p class="mb-2">
                                <strong x-text="other.ref"></strong>
                                (<span x-text="other.college"></span>) asked for
                                <span x-text="other.span"></span> on the same day first, submitted
                                <span x-text="other.submitted"></span>.
                            </p>
                        </template>
                        <p>
                            This batch, <strong x-text="batch.ref"></strong>, was submitted
                            <span x-text="batch.conflict.submitted"></span>. The clinic can't
                            fit both.
                        </p>
                        <p class="mt-2 font-semibold">
                            Recommended: approve
                            <span x-text="batch.conflict.earlier.map((other) => other.ref).join(' and ')"></span>
                            first, and reject this one so the college can resubmit.
                        </p>
                        <p class="mt-2 text-xs text-hp-slate/70">
                            <span x-show="batch.conflict.freeStarts.length > 0">
                                Start times still free that day for this batch:
                                <strong x-text="batch.conflict.freeStarts.join(', ')"></strong>.
                            </span>
                            <span x-show="batch.conflict.freeStarts.length === 0">
                                No other start time is free that day for this batch; the college
                                will need another date.
                            </span>
                        </p>
                    </div>
                </template>

                <p class="mt-1.5 text-sm text-hp-slate/70">
                    One appointment will be created for each of the
                    <strong x-text="batch?.students"></strong> students in this batch,
                    on the date the college requested.
                </p>
                {{-- D-62: the form those students will be examined on --}}
                <p class="mt-1.5 text-sm text-hp-slate/70">
                    Form: <strong x-text="batch?.form"></strong>
                </p>

                {{-- D-36: read-only confirmation, NOT an input. The server takes
                     the date off the locked batch row, so there is nothing here
                     for the Director to change — disliking the date means
                     rejecting with a reason. --}}
                <div class="mt-5 rounded-lg bg-hp-bg px-4 py-3">
                    <p class="text-xs font-semibold uppercase tracking-widest text-hp-slate/40">
                        Requested clinic date
                    </p>
                    <p class="mt-1 text-base font-semibold text-hp-slate" x-text="requestedLabel"></p>

                    {{-- D-37: the hour span, read-only for the same reason the
                         date is — the Director confirms it or rejects. --}}
                    <p class="mt-3 text-xs font-semibold uppercase tracking-widest text-hp-slate/40">
                        Clinic hours
                    </p>
                    <p class="mt-1 text-base font-semibold text-hp-slate" x-text="batch?.spanLabel"></p>
                </div>

                {{-- No stale-date notice here: a batch whose requested date has
                     passed can't open this modal at all (D-36 — its Approve
                     button is disabled, and the endpoint refuses it too). --}}

                {{-- @submit sets `submitting` (without preventing the POST) so a
                     double-click can't fire twice; the server re-check is the
                     real guard, this just avoids the round trip. --}}
                <form method="POST" :action="batch?.url" @submit="submitting = true" class="mt-3">
                    @csrf

                    {{-- Day load, for context. Informational only — the rule
                         that decides anything is the per-hour block below. --}}
                    <p x-show="booked !== null" x-cloak
                       class="mt-3 text-xs text-hp-slate/50">
                        This date currently holds <strong x-text="booked"></strong> of
                        <strong x-text="capacity"></strong> appointments.
                    </p>

                    {{-- Capacity BLOCK (FR-DIRA-06 as amended by D-37).
                         This used to be a warning that still let the approval
                         through. It no longer does: an hour without room for
                         the cohort means the clinic cannot process it, and
                         confirm-only approval (D-36) leaves no way to move the
                         batch — so the only outcome is reject-and-resubmit.
                         D-91: "without room" counts this batch's own students,
                         not just whether the hour is already at the cap. --}}
                    <p x-show="fullSlots.length > 0" x-cloak
                       class="mt-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700">
                        &#9888; The <strong x-text="fullSlots.join(' and the ')"></strong>
                        slot in this batch's span doesn't have room for its students, so this
                        batch cannot be approved. Reject it with a reason so the college can
                        resubmit for another date or start time.
                    </p>

                    {{-- BR-23: requested for today, but those hours have ended. --}}
                    <p x-show="elapsedSlots.length > 0" x-cloak
                       class="mt-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700">
                        &#9888; The <strong x-text="elapsedSlots.join(' and the ')"></strong>
                        slot in this batch's span has already passed today, so this batch
                        cannot be approved. Reject it with a reason so the college can
                        resubmit for a later time.
                    </p>

                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                        <x-hp.button variant="muted" @click="close()">
                            <span x-text="batch?.conflict ? 'Close' : 'Cancel'"></span>
                        </x-hp.button>
                        {{-- D-90: the recommended way out of a conflict, one click
                             away, with the reason already written for the college. --}}
                        <x-hp.button variant="danger" x-show="batch?.conflict" x-cloak @click="rejectInstead()">
                            Reject this one
                        </x-hp.button>
                        <x-hp.button type="submit" variant="primary" x-bind:disabled="submitting || isBlocked">
                            <span x-text="submitting ? 'Approving…' : (batch?.conflict ? 'Approve anyway' : 'Confirm & Approve')"></span>
                        </x-hp.button>
                    </div>
                </form>
            </div>
        </div>
    </template>

    {{-- ── Reject modal (FR-DIRA-04, D-36 — reason required) ────────────── --}}
    {{-- Same teleport/transition conventions as <x-logout-confirm>. --}}
    <template x-teleport="body">
        <div
            x-show="rejectTarget !== null"
            x-cloak
            @keydown.escape.window="closeReject()"
            class="fixed inset-0 z-[60] flex items-center justify-center px-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="reject-batch-title"
        >
            <div
                x-show="rejectTarget !== null"
                @click="closeReject()"
                x-transition:enter="ease-hp-out duration-hp-base"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-hp-in duration-hp-fast"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 bg-hp-slate/50"
                aria-hidden="true"
            ></div>

            <div
                x-show="rejectTarget !== null"
                x-transition:enter="ease-hp-spring duration-hp-slow"
                x-transition:enter-start="opacity-0 translate-y-6"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="ease-hp-in duration-hp-base"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-6"
                class="relative w-full max-w-md rounded-2xl bg-hp-white p-6 shadow-xl"
            >
                <h2 id="reject-batch-title" class="text-lg font-semibold text-hp-slate">
                    Reject <span x-text="rejectTarget?.ref"></span>?
                </h2>
                {{-- D-62: the form the batch asked for --}}
                <p class="mt-1 text-sm text-hp-slate/70">
                    Form: <strong x-text="rejectTarget?.form"></strong>
                </p>
                <p class="mt-1.5 text-sm text-hp-slate/70">
                    No appointments are created. The college admin sees this reason on
                    Batch Tracking, so write what they need to do — e.g.
                    &ldquo;date unavailable, please resubmit for the following week&rdquo;.
                </p>

                <form method="POST" :action="rejectTarget?.url" @submit="rejecting = true" class="mt-5">
                    @csrf

                    <label for="rejection-reason" class="block text-xs font-semibold uppercase tracking-widest text-hp-slate/40">
                        Reason for rejection
                    </label>
                    <textarea
                        id="rejection-reason"
                        name="rejection_reason"
                        x-model="reason"
                        rows="4"
                        required
                        minlength="{{ $reasonMin }}"
                        maxlength="{{ $reasonMax }}"
                        class="mt-1.5 w-full rounded-lg border-hp-slate/20 text-sm text-hp-slate
                               focus:border-hp-orange focus:ring-hp-orange"
                    ></textarea>

                    {{-- Live counter. Convenience only — RejectBatchRequest is
                         the real gate (a client can post anything). --}}
                    <p class="mt-1.5 text-xs" :class="reasonIsValid ? 'text-hp-slate/50' : 'text-red-500'">
                        <span x-text="reason.trim().length"></span>/{{ $reasonMax }}
                        <span x-show="! reasonIsValid" x-cloak>
                            — at least {{ $reasonMin }} characters
                        </span>
                    </p>

                    <div class="mt-6 flex justify-end gap-3">
                        <x-hp.button variant="muted" @click="closeReject()">Cancel</x-hp.button>
                        <x-hp.button type="submit" variant="danger" x-bind:disabled="! reasonIsValid || rejecting">
                            <span x-text="rejecting ? 'Rejecting…' : 'Reject Batch'"></span>
                        </x-hp.button>
                    </div>
                </form>
            </div>
        </div>
    </template>

    {{-- ── Cancellation reason (D-92) — read-only ──────────────────────── --}}
    {{-- Same teleport/transition conventions as the modals above. --}}
    <template x-teleport="body">
        <div
            x-show="cancelDetail !== null"
            x-cloak
            @keydown.escape.window="cancelDetail = null"
            class="fixed inset-0 z-[60] flex items-center justify-center px-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="cancel-reason-title"
        >
            <div
                x-show="cancelDetail !== null"
                @click="cancelDetail = null"
                x-transition:enter="ease-hp-out duration-hp-base"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-hp-in duration-hp-fast"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 bg-hp-slate/50"
                aria-hidden="true"
            ></div>

            <div
                x-show="cancelDetail !== null"
                x-transition:enter="ease-hp-spring duration-hp-slow"
                x-transition:enter-start="opacity-0 translate-y-6"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="ease-hp-in duration-hp-base"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-6"
                class="relative w-full max-w-md rounded-2xl bg-hp-white p-6 shadow-xl"
            >
                <h2 id="cancel-reason-title" class="text-lg font-semibold text-hp-slate">
                    <span x-text="cancelDetail?.ref"></span> was cancelled
                </h2>
                <p class="mt-1 text-sm text-hp-slate/70">
                    By <span x-text="cancelDetail?.by ?? 'the college'"></span>
                    (<span x-text="cancelDetail?.college"></span>)<span x-show="cancelDetail?.at">
                    on <span x-text="cancelDetail?.at"></span></span><span x-show="cancelDetail?.afterApproval">,
                    after you approved it — its appointments were cancelled and each student was emailed this reason</span>.
                </p>

                <p class="mt-4 text-xs font-semibold uppercase tracking-widest text-hp-slate/40">Reason</p>
                <p class="mt-1.5 whitespace-pre-line rounded-lg bg-hp-bg px-4 py-3 text-sm text-hp-slate"
                   x-text="cancelDetail?.reason"></p>

                <div class="mt-6 flex justify-end">
                    <x-hp.button variant="muted" @click="cancelDetail = null">Close</x-hp.button>
                </div>
            </div>
        </div>
    </template>

</div>

@push('scripts')
<script>
    function batchApprovals() {
        return {
            // ── Approve (confirm-only since D-36) ────────────────────────
            // { ref, form, students, requested, time, blocks, spanLabel, url,
            //   conflict, rejectUrl } of the row being approved. `conflict` is
            // null unless an earlier pending batch can't fit beside it (D-90).
            batch: null,
            submitting: false,                        // disables Approve after first click
            booked: null,                             // null until the capacity fetch answers
            capacity: {{ (int) config('healthpass.daily_capacity') }},
            fullSlots: [],                            // D-37: hours in the span already at the cap
            elapsedSlots: [],                         // BR-23: hours in the span that have ended

            // ── Reject (reason required since D-36) ─────────────────────
            rejectTarget: null,                       // { ref, form, url } of the row being rejected
            reason: '',
            rejecting: false,
            reasonMin: {{ $reasonMin }},

            // ── Cancellation reason (D-92) ──────────────────────────────
            cancelDetail: null,                       // { ref, college, reason, by, at, afterApproval } or null

            // FR-DIRA-06 (D-37): a full hour anywhere in the span BLOCKS
            // approval. The server refuses the POST too — this only saves the
            // Director a round trip.
            get isBlocked() {
                return this.fullSlots.length > 0 || this.elapsedSlots.length > 0;
            },

            // "Aug 3, 2026" for the modal copy (dates are ISO strings).
            get requestedLabel() {
                if (!this.batch?.requested) return '';
                return new Date(`${this.batch.requested}T00:00:00`)
                    .toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            },

            // Mirrors RejectBatchRequest's min rule; the server still decides.
            get reasonIsValid() {
                return this.reason.trim().length >= this.reasonMin;
            },

            openApprove(batch) {
                this.batch = batch;
                this.submitting = false;
                this.checkCapacity();
            },

            close() {
                this.batch = null;
            },

            // `reason` pre-fills the box — D-90's conflict popup passes the
            // suggested one; the Director can still edit it before sending.
            openReject(target, reason = '') {
                this.rejectTarget = target;
                this.reason = reason;
                this.rejecting = false;
            },

            // D-90: "Reject this one" in the conflict popup swaps straight to
            // the reject box for the same batch, reason already written.
            rejectInstead() {
                const batch = this.batch;
                this.close();
                this.openReject({ ref: batch.ref, form: batch.form, url: batch.rejectUrl }, batch.conflict.reason);
            },

            closeReject() {
                this.rejectTarget = null;
            },

            async checkCapacity() {
                this.booked = null;
                this.fullSlots = [];
                this.elapsedSlots = [];

                // D-36: capacity is checked against the admin's requested date,
                // the only date approval can use — and D-37, against the hours
                // of that batch's span rather than the whole day.
                const requestedDate = this.batch?.requested;
                if (!requestedDate) return;

                // D-91: `students` lets the server check each hour has room for
                // the students this batch would put there, not just that the
                // hour isn't already at the cap.
                const params = new URLSearchParams({
                    date: requestedDate,
                    time: this.batch?.time ?? '',
                    blocks: this.batch?.blocks ?? '',
                    students: this.batch?.students ?? '',
                });

                try {
                    const res = await fetch(
                        `{{ route('director.batches.capacity') }}?${params}`,
                        { headers: { Accept: 'application/json' } },
                    );
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    const data = await res.json();

                    // Ignore stale answers if the Director closed this modal and
                    // opened another row while the request was in flight.
                    if (requestedDate !== this.batch?.requested) return;

                    this.booked = data.booked;
                    this.capacity = data.capacity;
                    this.fullSlots = data.full_slots ?? [];
                    this.elapsedSlots = data.elapsed_slots ?? [];
                } catch (error) {
                    // The block simply stays hidden; approve() re-checks under
                    // lock and refuses the POST, so nothing slips through.
                    console.error('Capacity check failed', error);
                }
            },
        };
    }
</script>
@endpush

</x-layout.sidebar>
