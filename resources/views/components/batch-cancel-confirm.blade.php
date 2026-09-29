{{--
    Cancel-batch confirmation (FR-ADM-11, D-52, D-92), shared by Batch Tracking
    and, since D-88, the Batch Request Submitted page.

    Reads `cancelTarget` from the ENCLOSING Alpine component: null (closed) or
    the { id, ref, students, date, approved } of the batch to cancel. The page
    owns that state because Batch Tracking opens this one dialog from many rows.

    D-92: a written reason is required, pending or approved. `approved` switches
    the copy: an approved batch's appointments are cancelled and every student
    is emailed the reason, so the dialog says so before the admin commits.

    Same dialog shape as the rejection-reason modal on Batch Tracking and as
    <x-college-reassign-confirm>: teleported to <body> so no ancestor's
    overflow or stacking context clips the full-screen backdrop, and
    dismissable with Esc, a backdrop click, or "Keep it".

    ONE form serves every row. The panel is rendered once, outside the table,
    and Alpine builds the action from the clicked batch's id — rendering a
    hidden form per row would put N of them in the DOM for no gain. The base
    comes from the NAMED index route, so a URL-prefix change still lands.
--}}
@php
    // One source of truth for the bounds: the Form Request that enforces them.
    $reasonMin = App\Http\Requests\Admin\CancelBatchRequest::REASON_MIN;
    $reasonMax = App\Http\Requests\Admin\CancelBatchRequest::REASON_MAX;
@endphp
<template x-teleport="body">
    <div
        x-show="cancelTarget !== null"
        x-cloak
        {{-- The typed reason lives here, not on the page: it belongs to this
             dialog alone, and is wiped each time a batch is opened. --}}
        x-data="{ reason: '', sending: false }"
        x-effect="if (cancelTarget !== null) { reason = ''; sending = false }"
        @keydown.escape.window="cancelTarget = null"
        class="fixed inset-0 z-[60] flex items-center justify-center px-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="cancel-batch-title"
    >
        {{-- Backdrop — click outside to dismiss --}}
        <div
            x-show="cancelTarget !== null"
            @click="cancelTarget = null"
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
            x-show="cancelTarget !== null"
            x-transition:enter="ease-hp-spring duration-hp-slow"
            x-transition:enter-start="opacity-0 translate-y-6"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="ease-hp-in duration-hp-base"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-6"
            class="relative w-full max-w-md rounded-2xl bg-hp-white p-6 shadow-xl"
        >
            <h2 id="cancel-batch-title" class="text-lg font-semibold text-hp-slate">
                Cancel <span x-text="cancelTarget?.ref"></span>?
            </h2>

            {{-- Pending: nothing has been created yet (D-52). --}}
            <p x-show="! cancelTarget?.approved" class="mt-1.5 text-sm text-hp-slate/70">
                This withdraws the request before the Clinic Director reviews it.
                <span x-show="cancelTarget?.students" x-cloak>
                    The <span x-text="cancelTarget?.students"></span><span
                        x-text="cancelTarget?.students === 1 ? ' student' : ' students'"></span>
                    on it lose nothing — no appointment has been created yet.
                </span>
                <span x-show="cancelTarget?.date" x-cloak>
                    The clinic hours it asked for on
                    <span class="font-semibold text-hp-slate" x-text="cancelTarget?.date"></span>
                    stay free for other bookings.
                </span>
            </p>

            {{-- Approved (D-92): appointments exist and students were emailed. --}}
            <p x-show="cancelTarget?.approved" x-cloak class="mt-1.5 text-sm text-hp-slate/70">
                The Clinic Director has already approved this batch. Cancelling it cancels
                the approved schedule on
                <span class="font-semibold text-hp-slate" x-text="cancelTarget?.date"></span>
                for all <span class="font-semibold text-hp-slate" x-text="cancelTarget?.students"></span><span
                    x-text="cancelTarget?.students === 1 ? ' student' : ' students'"></span>,
                and <strong class="font-semibold text-hp-slate">each student is emailed</strong>
                the Batch ID and your reason.
            </p>

            <form method="POST" :action="`{{ route('admin.batches.index') }}/${cancelTarget?.id}/cancel`"
                  @submit="sending = true" class="mt-5">
                @csrf
                @method('DELETE')

                <label for="cancellation-reason" class="block text-xs font-semibold uppercase tracking-widest text-hp-slate/40">
                    Reason for cancelling
                </label>
                <textarea
                    id="cancellation-reason"
                    name="cancellation_reason"
                    x-model="reason"
                    rows="4"
                    required
                    minlength="{{ $reasonMin }}"
                    maxlength="{{ $reasonMax }}"
                    class="mt-1.5 w-full rounded-lg border-hp-slate/20 text-sm text-hp-slate
                           focus:border-hp-orange focus:ring-hp-orange"
                ></textarea>

                {{-- Live counter. Convenience only — CancelBatchRequest is the
                     real gate (a client can post anything). --}}
                <p class="mt-1.5 text-xs" :class="reason.trim().length >= {{ $reasonMin }} ? 'text-hp-slate/50' : 'text-red-500'">
                    <span x-text="reason.trim().length"></span>/{{ $reasonMax }}
                    <span x-show="reason.trim().length < {{ $reasonMin }}" x-cloak>
                        — at least {{ $reasonMin }} characters
                    </span>
                </p>
                <p class="mt-1 text-xs text-hp-slate/50">
                    The Clinic Director reads this reason<span x-show="cancelTarget?.approved" x-cloak>, and so does every student on the batch</span>.
                </p>

                <p class="mt-4 rounded-lg bg-hp-bg px-4 py-3 text-xs text-hp-slate">
                    This cannot be undone. To book the same students again, submit a new
                    batch request.
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <x-hp.button variant="muted" @click="cancelTarget = null">Keep it</x-hp.button>
                    <x-hp.button type="submit" variant="danger"
                                 x-bind:disabled="reason.trim().length < {{ $reasonMin }} || sending">
                        <span x-text="sending ? 'Cancelling…' : (cancelTarget?.approved ? 'Cancel schedule' : 'Cancel request')"></span>
                    </x-hp.button>
                </div>
            </form>
        </div>
    </div>
</template>
