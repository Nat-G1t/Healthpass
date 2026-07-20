<x-layout.sidebar title="Batch Approvals">

{{--
    Director Batch Approvals (FR-DIRA-01/05/06).

    One page-level Alpine component owns the approve modal's state: which
    batch is being approved, the picked date, and the live booked-count for
    the capacity warning. Approve opens the modal; Reject posts directly
    (FR-DIRA-04 — no date to pick, nothing is generated).
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

    {{-- ── Requests table (FR-DIRA-01) ─────────────────────────────────── --}}
    <x-hp.card>
        @if ($batchRequests->isEmpty())
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
                                        {{-- Approve opens the date-picker modal (FR-DIRA-02 shape).
                                             Plain <button> (not <x-hp.button>) because the payload is
                                             built with Js::from() — Blade output isn't compiled inside
                                             a component tag's attribute value. --}}
                                        <div class="flex items-center gap-2">
                                            <button type="button"
                                                @click="openApprove({{ Illuminate\Support\Js::from([
                                                    'ref' => $batch->reference_no,
                                                    'students' => $batch->batch_request_students_count,
                                                    'requested' => $batch->requested_date?->toDateString(),
                                                    'url' => route('director.batches.approve', $batch),
                                                ]) }})"
                                                class="inline-flex items-center justify-center gap-2 rounded-full
                                                       bg-hp-orange px-4 py-1.5 text-xs font-semibold text-white
                                                       transition-colors duration-150 hover:bg-orange-500
                                                       focus-visible:outline-none focus-visible:ring-2
                                                       focus-visible:ring-hp-orange focus-visible:ring-offset-1">
                                                Approve
                                            </button>
                                            <form method="POST" action="{{ route('director.batches.reject', $batch) }}">
                                                @csrf
                                                <x-hp.button type="submit" variant="danger" size="sm" data-pending-label="Rejecting…">Reject</x-hp.button>
                                            </form>
                                        </div>
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

    {{-- ── Approve modal (FR-DIRA-02 date pick + FR-DIRA-06 warning) ────── --}}
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
            {{-- Backdrop — click outside to cancel --}}
            <div
                x-show="batch !== null"
                @click="close()"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 bg-hp-slate/50"
                aria-hidden="true"
            ></div>

            {{-- Panel --}}
            <div
                x-show="batch !== null"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-xl"
            >
                <h2 id="approve-batch-title" class="text-lg font-semibold text-hp-slate">
                    Approve <span x-text="batch?.ref"></span>?
                </h2>
                <p class="mt-1.5 text-sm text-hp-slate/70">
                    One appointment will be created for each of the
                    <strong x-text="batch?.students"></strong> students in this batch.
                    {{-- D-29: the admin's requested date leads; adjust only when needed --}}
                    <span x-show="batch?.requested" x-cloak>
                        The college requested <strong x-text="requestedLabel"></strong> —
                        adjust it only if the clinic can't take them that day.
                    </span>
                </p>

                {{-- The requested date went stale while the batch sat pending --}}
                <p x-show="requestedDatePassed" x-cloak
                   class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                    &#9888; The requested date (<span x-text="requestedLabel"></span>) has
                    already passed — pick a new date, ideally with the college admin.
                </p>

                {{-- @submit sets `submitting` (without preventing the POST) so a
                     double-click can't fire twice; the server re-check is the
                     real guard, this just avoids the round trip. --}}
                <form method="POST" :action="batch?.url" @submit="submitting = true" class="mt-5">
                    @csrf

                    <label for="approve-date" class="block text-xs font-semibold uppercase tracking-widest text-hp-slate/40">
                        Appointment date
                    </label>
                    {{-- Pre-filled with the admin's requested date, or today when
                         it has passed / is absent (D-29). Past dates disabled here
                         and enforced server-side (ApproveBatchRequest). --}}
                    <input
                        id="approve-date"
                        type="date"
                        name="scheduled_date"
                        x-model="date"
                        @change="checkCapacity()"
                        min="{{ now()->toDateString() }}"
                        required
                        class="mt-1.5 w-full rounded-lg border-hp-slate/20 text-sm text-hp-slate
                               focus:border-hp-orange focus:ring-hp-orange"
                    >

                    {{-- Capacity warning (FR-DIRA-06) — warn, never block --}}
                    <p x-show="isAtCapacity" x-cloak
                       class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        &#9888; This date already has <strong x-text="booked"></strong> of
                        <strong x-text="capacity"></strong> appointments booked. Approving will
                        still schedule every student, but the clinic will be over its daily capacity.
                    </p>

                    <div class="mt-6 flex justify-end gap-3">
                        <x-hp.button variant="muted" @click="close()">Cancel</x-hp.button>
                        <x-hp.button type="submit" variant="primary" x-bind:disabled="submitting">
                            <span x-text="submitting ? 'Approving…' : 'Approve Batch'"></span>
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
            batch: null,                              // { ref, students, requested, url } of the row being approved
            date: '',
            submitting: false,                        // disables Approve after first click
            today: '{{ now()->toDateString() }}',
            booked: null,                             // null until the capacity fetch answers
            capacity: {{ (int) config('healthpass.daily_capacity') }},

            // FR-DIRA-06: warn when the date is AT or OVER the daily cap.
            get isAtCapacity() {
                return this.booked !== null && this.booked >= this.capacity;
            },

            // D-29: a batch can sit pending past its requested date.
            get requestedDatePassed() {
                return !!(this.batch?.requested && this.batch.requested < this.today);
            },

            // "Aug 3, 2026" for the modal copy (dates are ISO strings).
            get requestedLabel() {
                if (!this.batch?.requested) return '';
                return new Date(`${this.batch.requested}T00:00:00`)
                    .toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            },

            openApprove(batch) {
                this.batch = batch;
                this.submitting = false;
                // D-29: default to the admin's requested date; fall back to
                // today when it has passed (ISO strings compare correctly) or
                // the batch predates requested_date.
                this.date = (batch.requested && batch.requested >= this.today)
                    ? batch.requested
                    : this.today;
                this.checkCapacity();
            },

            close() {
                this.batch = null;
            },

            async checkCapacity() {
                this.booked = null;
                if (!this.date) return;

                const requestedDate = this.date;
                try {
                    const res = await fetch(
                        `{{ route('director.batches.capacity') }}?date=${requestedDate}`,
                        { headers: { Accept: 'application/json' } },
                    );
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    const data = await res.json();

                    // Ignore stale answers if the Director changed dates again
                    // while this request was in flight.
                    if (requestedDate !== this.date) return;

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
