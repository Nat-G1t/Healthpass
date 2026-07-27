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
    confirmation (which batch, its requested date, the live booked-count for
    the capacity warning) and the reject dialog (which batch, the typed
    reason). Both are confirmations now — D-36 removed the Director's date
    picker, so approving means confirming the College Admin's requested date
    and pushing back means rejecting with a written reason.
--}}
<div x-data="batchApprovals()">

    {{-- ── Page header ─────────────────────────────────────────────────── --}}
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-hp-slate">Batch Approvals</h2>
        <p class="mt-0.5 text-sm text-hp-slate/50 dark:text-hp-slate/60">
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
        <div data-hp-flash data-flash-sticky class="mb-6 rounded-lg border border-red-300 dark:border-red-500/40 bg-red-50 dark:bg-red-500/10 px-4 py-3 text-sm text-red-700 dark:text-red-300">
            {{ session('error') }}
        </div>
    @endif

    {{-- Reject validation failure (D-36). Shown at page level because the
         redirect closes the modal the reason was typed into. --}}
    @error('rejection_reason')
        <div class="mb-6 rounded-lg border border-red-300 dark:border-red-500/40 bg-red-50 dark:bg-red-500/10 px-4 py-3 text-sm text-red-700 dark:text-red-300">
            {{ $message }}
        </div>
    @enderror

    {{-- ── Requests table (FR-DIRA-01) ─────────────────────────────────── --}}
    <x-hp.card>
        @if ($batchRequests->isEmpty())
            <div class="flex flex-col items-center py-10 text-center">
                <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                    <svg class="h-6 w-6 text-hp-slate/30 dark:text-hp-slate/55" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-hp-slate">No batch requests yet</p>
                <p class="mt-0.5 text-xs text-hp-slate/50 dark:text-hp-slate/60">
                    College admins' submissions will appear here for review.
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-hp-slate/10 text-[11px] uppercase tracking-widest text-hp-slate/40 dark:text-hp-slate/55">
                            <th class="py-2.5 pr-4 font-semibold">Batch ID</th>
                            <th class="py-2.5 pr-4 font-semibold">College</th>
                            <th class="py-2.5 pr-4 font-semibold">Reason</th>
                            <th class="py-2.5 pr-4 font-semibold">Students</th>
                            <th class="py-2.5 pr-4 font-semibold">Requested Date</th>
                            <th class="py-2.5 pr-4 font-semibold">Submitted</th>
                            <th class="py-2.5 font-semibold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hp-slate/10">
                        @foreach ($batchRequests as $batch)
                            <tr class="text-hp-slate">
                                <td class="py-3 pr-4">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">{{ $batch->reference_no }}</span>
                                        <x-hp.badge :variant="$batch->status">{{ ucfirst($batch->status) }}</x-hp.badge>
                                    </div>
                                </td>
                                <td class="py-3 pr-4">{{ $batch->college->code }}</td>
                                <td class="py-3 pr-4">&ldquo;{{ Str::limit($batch->reasonText(), 60) }}&rdquo;</td>
                                <td class="py-3 pr-4">{{ $batch->batch_request_students_count }}</td>
                                {{-- Admin-proposed clinic date (D-29); "—" on pre-D-29 batches --}}
                                <td class="py-3 pr-4">{{ $batch->requested_date?->format('M j, Y') ?? '—' }}</td>
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
                                            $canApprove = $batch->requested_date !== null && ! $isStale;
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
                                                        'students' => $batch->batch_request_students_count,
                                                        'requested' => $batch->requested_date->toDateString(),
                                                        'url' => route('director.batches.approve', $batch),
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
                                                    'url' => route('director.batches.reject', $batch),
                                                ]) }})"
                                                class="inline-flex items-center justify-center gap-2 rounded-full
                                                       border-[1.5px] border-red-300 dark:border-red-500/40 px-4 py-1.5
                                                       text-xs font-semibold text-red-500 dark:text-red-400
                                                       transition-colors duration-hp-fast hover:bg-red-50
                                                       hover:dark:bg-red-500/10 focus-visible:outline-none
                                                       focus-visible:ring-2 focus-visible:ring-red-500
                                                       focus-visible:ring-offset-1">
                                                Reject
                                            </button>
                                        </div>

                                        @if (! $canApprove)
                                            <p class="mt-2 max-w-xs text-xs text-hp-slate/60 dark:text-hp-slate/65">
                                                @if ($isStale)
                                                    The requested date has already passed — reject it with a reason
                                                    asking the college to resubmit for a new date.
                                                @else
                                                    This batch predates the requested-date field — reject it with the reason
                                                    &ldquo;predates the requested-date field — please resubmit&rdquo;.
                                                @endif
                                            </p>
                                        @endif
                                    @elseif ($batch->status === 'approved')
                                        {{-- Decided rows are static — no re-decision (FR-DIRA-05) --}}
                                        <span class="text-sm font-semibold text-hp-orange">✓ Approved</span>
                                    @else
                                        <span class="text-sm font-semibold text-hp-slate/60">✕ Rejected</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
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
                class="absolute inset-0 bg-hp-slate/50 dark:bg-black/60"
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
                class="relative w-full max-w-md rounded-2xl bg-hp-white p-6 shadow-xl"
            >
                <h2 id="approve-batch-title" class="text-lg font-semibold text-hp-slate">
                    Approve <span x-text="batch?.ref"></span>?
                </h2>
                <p class="mt-1.5 text-sm text-hp-slate/70">
                    One appointment will be created for each of the
                    <strong x-text="batch?.students"></strong> students in this batch,
                    on the date the college requested.
                </p>

                {{-- D-36: read-only confirmation, NOT an input. The server takes
                     the date off the locked batch row, so there is nothing here
                     for the Director to change — disliking the date means
                     rejecting with a reason. --}}
                <div class="mt-5 rounded-lg bg-hp-bg px-4 py-3">
                    <p class="text-xs font-semibold uppercase tracking-widest text-hp-slate/40 dark:text-hp-slate/55">
                        Requested clinic date
                    </p>
                    <p class="mt-1 text-base font-semibold text-hp-slate" x-text="requestedLabel"></p>
                </div>

                {{-- No stale-date notice here: a batch whose requested date has
                     passed can't open this modal at all (D-36 — its Approve
                     button is disabled, and the endpoint refuses it too). --}}

                {{-- @submit sets `submitting` (without preventing the POST) so a
                     double-click can't fire twice; the server re-check is the
                     real guard, this just avoids the round trip. --}}
                <form method="POST" :action="batch?.url" @submit="submitting = true" class="mt-3">
                    @csrf

                    {{-- Capacity warning (FR-DIRA-06) — warn, never block --}}
                    <p x-show="isAtCapacity" x-cloak
                       class="mt-3 rounded-lg border border-amber-300 dark:border-amber-500/40 bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-300">
                        &#9888; This date already has <strong x-text="booked"></strong> of
                        <strong x-text="capacity"></strong> appointments booked. Approving will
                        still schedule every student, but the clinic will be over its daily capacity.
                    </p>

                    <div class="mt-6 flex justify-end gap-3">
                        <x-hp.button variant="muted" @click="close()">Cancel</x-hp.button>
                        <x-hp.button type="submit" variant="primary" x-bind:disabled="submitting">
                            <span x-text="submitting ? 'Approving…' : 'Confirm & Approve'"></span>
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
                class="absolute inset-0 bg-hp-slate/50 dark:bg-black/60"
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
                <p class="mt-1.5 text-sm text-hp-slate/70">
                    No appointments are created. The college admin sees this reason on
                    Batch Tracking, so write what they need to do — e.g.
                    &ldquo;date unavailable, please resubmit for the following week&rdquo;.
                </p>

                <form method="POST" :action="rejectTarget?.url" @submit="rejecting = true" class="mt-5">
                    @csrf

                    <label for="rejection-reason" class="block text-xs font-semibold uppercase tracking-widest text-hp-slate/40 dark:text-hp-slate/55">
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
                    <p class="mt-1.5 text-xs" :class="reasonIsValid ? 'text-hp-slate/50 dark:text-hp-slate/60' : 'text-red-500 dark:text-red-400'">
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

</div>

@push('scripts')
<script>
    function batchApprovals() {
        return {
            // ── Approve (confirm-only since D-36) ────────────────────────
            batch: null,                              // { ref, students, requested, url } of the row being approved
            submitting: false,                        // disables Approve after first click
            booked: null,                             // null until the capacity fetch answers
            capacity: {{ (int) config('healthpass.daily_capacity') }},

            // ── Reject (reason required since D-36) ─────────────────────
            rejectTarget: null,                       // { ref, url } of the row being rejected
            reason: '',
            rejecting: false,
            reasonMin: {{ $reasonMin }},

            // FR-DIRA-06: warn when the date is AT or OVER the daily cap.
            get isAtCapacity() {
                return this.booked !== null && this.booked >= this.capacity;
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

            openReject(target) {
                this.rejectTarget = target;
                this.reason = '';
                this.rejecting = false;
            },

            closeReject() {
                this.rejectTarget = null;
            },

            async checkCapacity() {
                this.booked = null;

                // D-36: capacity is checked against the admin's requested date,
                // the only date approval can use.
                const requestedDate = this.batch?.requested;
                if (!requestedDate) return;

                try {
                    const res = await fetch(
                        `{{ route('director.batches.capacity') }}?date=${requestedDate}`,
                        { headers: { Accept: 'application/json' } },
                    );
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    const data = await res.json();

                    // Ignore stale answers if the Director closed this modal and
                    // opened another row while the request was in flight.
                    if (requestedDate !== this.batch?.requested) return;

                    this.booked = data.booked;
                    this.capacity = data.capacity;
                } catch (error) {
                    // Warning simply stays hidden — never block approval on it.
                    console.error('Capacity check failed', error);
                }
            },
        };
    }
</script>
@endpush

</x-layout.sidebar>
