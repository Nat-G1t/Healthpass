{{--
    Cancel-batch confirmation (FR-ADM-11, D-52), shared by Batch Tracking and,
    since D-88, the Batch Request Submitted page.

    Reads `cancelTarget` from the ENCLOSING Alpine component: null (closed) or
    the { id, ref, students, date } of the batch to cancel. The page owns that
    state because Batch Tracking opens this one dialog from many rows.

    Same dialog shape as the rejection-reason modal on Batch Tracking and as
    <x-college-reassign-confirm>: teleported to <body> so no ancestor's
    overflow or stacking context clips the full-screen backdrop, and
    dismissable with Esc, a backdrop click, or "Keep it".

    ONE form serves every row. The panel is rendered once, outside the table,
    and Alpine builds the action from the clicked batch's id — rendering a
    hidden form per row would put N of them in the DOM for no gain. The base
    comes from the NAMED index route, so a URL-prefix change still lands.
--}}
<template x-teleport="body">
    <div
        x-show="cancelTarget !== null"
        x-cloak
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

            <p class="mt-1.5 text-sm text-hp-slate/70">
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

            <p class="mt-3 rounded-lg bg-hp-bg px-4 py-3 text-xs text-hp-slate">
                This cannot be undone. To book the same students again, submit a new
                batch request.
            </p>

            <div class="mt-6 flex justify-end gap-3">
                <x-hp.button variant="muted" @click="cancelTarget = null">Keep it</x-hp.button>

                <form method="POST" :action="`{{ route('admin.batches.index') }}/${cancelTarget?.id}/cancel`">
                    @csrf
                    @method('DELETE')
                    <x-hp.button type="submit" variant="danger" data-pending-label="Cancelling…">
                        Cancel request
                    </x-hp.button>
                </form>
            </div>
        </div>
    </div>
</template>
