{{--
    D-129 (FR-DIRA-12): the Director's "Cancel batch" button and its popup,
    drawn beside the status badge in the results page header (the shared
    header's `headerAction`). The page passes it only when
    BatchRequest::isCancellable() is true for this approved batch — the
    College Admin's rule — and the endpoint re-checks that rule under a row
    lock, so this button never offers what the server would refuse.

    Takes: $batch — the approved BatchRequest.

    Same dialog shape as <x-batch-cancel-confirm>: teleported to <body> so no
    ancestor's overflow clips the backdrop, dismissable with Esc, a backdrop
    click or "Keep batch". If the reason bounces back from the server, the
    popup reopens with what was typed and the error under the box.
--}}
@php
    // One source of truth for the bounds: the Form Request that enforces them.
    $reasonMin = App\Http\Requests\Director\CancelBatchRequest::REASON_MIN;
    $reasonMax = App\Http\Requests\Director\CancelBatchRequest::REASON_MAX;
@endphp
<div x-data="{
        open: {{ Illuminate\Support\Js::from($errors->has('cancellation_reason')) }},
        reason: {{ Illuminate\Support\Js::from((string) old('cancellation_reason', '')) }},
        sending: false,
    }" data-director-cancel>
    <x-hp.button variant="danger" size="sm" @click="open = true">Cancel batch</x-hp.button>

    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            @keydown.escape.window="open = false"
            class="fixed inset-0 z-[60] flex items-center justify-center px-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="director-cancel-title"
        >
            {{-- Backdrop — click outside to dismiss --}}
            <div
                x-show="open"
                @click="open = false"
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
                x-show="open"
                x-transition:enter="ease-hp-spring duration-hp-slow"
                x-transition:enter-start="opacity-0 translate-y-6"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="ease-hp-in duration-hp-base"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-6"
                class="relative w-full max-w-md rounded-2xl bg-hp-white p-6 shadow-xl"
            >
                <h2 id="director-cancel-title" class="text-lg font-semibold text-hp-slate">
                    Cancel {{ $batch->reference_no }}?
                </h2>

                <p class="mt-1.5 text-sm text-hp-slate/70">
                    This cancels its schedule on
                    <span class="font-semibold text-hp-slate">{{ $batch->scheduled_date?->format('M j, Y') }}, {{ $batch->scheduledSpanLabel() }}</span>
                    for every student on it, and frees those clinic hours.
                </p>

                <form method="POST" action="{{ route('director.batches.cancel', $batch) }}"
                      @submit="sending = true" class="mt-5">
                    @csrf

                    <label for="director-cancellation-reason" class="block text-xs font-semibold uppercase tracking-widest text-hp-slate/40">
                        Reason for cancelling
                    </label>
                    <textarea
                        id="director-cancellation-reason"
                        name="cancellation_reason"
                        x-model="reason"
                        rows="4"
                        required
                        minlength="{{ $reasonMin }}"
                        maxlength="{{ $reasonMax }}"
                        class="mt-1.5 w-full rounded-lg border-hp-slate/20 text-sm text-hp-slate
                               focus:border-hp-orange focus:ring-hp-orange"
                    ></textarea>

                    @error('cancellation_reason')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror

                    {{-- Live counter. Convenience only — CancelBatchRequest is the
                         real gate (a client can post anything). --}}
                    <p class="mt-1.5 text-xs" :class="reason.trim().length >= {{ $reasonMin }} ? 'text-hp-slate/50' : 'text-red-500'">
                        <span x-text="reason.trim().length"></span>/{{ $reasonMax }}
                        <span x-show="reason.trim().length < {{ $reasonMin }}" x-cloak>
                            — at least {{ $reasonMin }} characters
                        </span>
                    </p>
                    <p class="mt-1 text-xs text-hp-slate/50">Each student is emailed this reason.</p>

                    <div class="mt-6 flex justify-end gap-3">
                        <x-hp.button variant="muted" @click="open = false">Keep batch</x-hp.button>
                        <x-hp.button type="submit" variant="danger"
                                     x-bind:disabled="reason.trim().length < {{ $reasonMin }} || sending">
                            <span x-text="sending ? 'Cancelling…' : 'Cancel batch'"></span>
                        </x-hp.button>
                    </div>
                </form>
            </div>
        </div>
    </template>
</div>
