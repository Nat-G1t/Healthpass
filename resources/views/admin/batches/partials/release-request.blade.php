{{--
    D-113 (FR-ADM-15): under the results page's main card, full width — the
    College Admin asks the Director to release this batch's documents.

    Takes:
      $batch         — the approved BatchRequest
      $status        — its BatchRequest::resultsStatus() key
      $latestRelease — the college's most recent DocumentReleaseRequest, or null
      $isReleased    — has the Director approved a request for this batch?

    What shows, by state:
      released             a note "Documents released by the Director on …";
                           the select / download tools in the card above work
      pending              the button, disabled — "… waiting for the Director."
                           (+ " Printed copies requested." when ticked — D-123)
      latest rejected      the Director's message + "Request again"
      not finished yet     the button, disabled — "Available when the batch
                           is completed." (Completed or Done / Incomplete)
      ready, never asked   the button → the popup

    The popup (D-123): a reason (10–500 characters, live counter) and a
    "printed copies" checkbox, unchecked by default. No attachment — the
    Director reviews the batch's own signed memo (D-109). Send stays disabled
    until the reason is valid; StoreReleaseRequest and the controller's locked
    checks are the real gate.
--}}
@php
    $reasonMin = App\Http\Requests\Admin\StoreReleaseRequest::REASON_MIN;
    $reasonMax = App\Http\Requests\Admin\StoreReleaseRequest::REASON_MAX;
    $isReady = in_array($status, App\Models\BatchRequest::RELEASABLE_STATUSES, true);
    $isPending = $latestRelease?->isPending() ?? false;
    $isRejected = $latestRelease?->status === 'rejected';
    $hasFormErrors = $errors->hasAny(['reason', 'wants_printed_copies']);
@endphp

<section class="mt-6" data-release-section
         x-data="{
            requestOpen: {{ Illuminate\Support\Js::from($hasFormErrors && $isReady) }},
            reason: {{ Illuminate\Support\Js::from((string) old('reason', '')) }},
            sending: false,
            get reasonLength() { return this.reason.trim().length; },
            get reasonIsValid() { return this.reasonLength >= {{ $reasonMin }} && this.reasonLength <= {{ $reasonMax }}; },
         }">

    @if ($isReleased)
        <div class="rounded-2xl border border-hp-orange/30 bg-hp-peach/40 px-5 py-4 text-sm text-hp-slate" data-release-state="approved">
            <p class="font-semibold">
                Documents released by the Director on {{ $latestRelease?->reviewed_at?->format('M j, Y') }}.
            </p>
            <p class="mt-0.5 text-hp-slate/70">Use <strong>Select students</strong> above to download their official forms.</p>
        </div>
    @elseif ($isPending)
        <x-hp.button size="xl" class="w-full" disabled data-release-state="pending">Request release of documents</x-hp.button>
        <p class="mt-2 text-center text-sm text-hp-slate/60">
            Release requested on {{ $latestRelease->created_at->format('M j, Y') }} — waiting for the Director.
            @if ($latestRelease->wants_printed_copies)
                Printed copies requested.
            @endif
        </p>
    @else
        @if ($isRejected)
            {{-- The Director's message: escaped text, line breaks kept. --}}
            <div class="mb-3 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700" data-release-rejected>
                <p><span class="font-semibold">Not released:</span> <span class="whitespace-pre-line">{{ $latestRelease->rejection_message }}</span></p>
                <p class="mt-1 text-xs text-red-600/80">
                    Reviewed on {{ $latestRelease->reviewed_at?->format('M j, Y') }}. You can send a new request with a new reason.
                </p>
            </div>
        @endif

        <x-hp.button size="xl" class="w-full" :disabled="! $isReady" @click="requestOpen = true"
                     data-release-state="{{ $isReady ? 'ready' : 'not-ready' }}" data-release-request>
            {{ $isRejected ? 'Request again' : 'Request release of documents' }}
        </x-hp.button>
        @unless ($isReady)
            <p class="mt-2 text-center text-sm text-hp-slate/60">Available when the batch is completed.</p>
        @endunless

        @if ($isReady)
            {{-- ── The request popup ───────────────────────────────────────
                 Teleported to <body> so no ancestor's overflow clips it. --}}
            <template x-teleport="body">
                <div x-show="requestOpen" x-cloak @keydown.escape.window="requestOpen = false"
                     class="fixed inset-0 z-[60] flex items-center justify-center px-4"
                     role="dialog" aria-modal="true" aria-labelledby="release-request-title">
                    <div x-show="requestOpen" @click="requestOpen = false"
                         x-transition:enter="ease-hp-out duration-hp-base" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                         x-transition:leave="ease-hp-in duration-hp-fast" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                         class="absolute inset-0 bg-hp-slate/50" aria-hidden="true"></div>

                    <div x-show="requestOpen"
                         x-transition:enter="ease-hp-spring duration-hp-slow" x-transition:enter-start="opacity-0 translate-y-6" x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="ease-hp-in duration-hp-base" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-6"
                         class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-hp-white p-6 shadow-xl">
                        <h2 id="release-request-title" class="text-lg font-semibold text-hp-slate">Request release of documents</h2>
                        <p class="mt-1.5 text-sm text-hp-slate/70">
                            Tell the Director why the college needs {{ $batch->reference_no }}’s official forms. The Director
                            reviews it with the signed memo you sent for this batch. Once the Director releases them, you
                            can download the forms of the students the clinic has encoded.
                        </p>

                        <form method="POST" action="{{ route('admin.batches.release-requests.store', $batch->id) }}"
                              @submit="sending = true" class="mt-5">
                            @csrf

                            <label for="release-reason" class="block text-xs font-semibold uppercase tracking-widest text-hp-slate/40">
                                Reason
                            </label>
                            <textarea id="release-reason" name="reason" x-model="reason" rows="4" required
                                      minlength="{{ $reasonMin }}" maxlength="{{ $reasonMax }}"
                                      class="mt-1.5 w-full rounded-lg border-hp-slate/20 text-sm text-hp-slate focus:border-hp-orange focus:ring-hp-orange"></textarea>
                            {{-- Live counter. Convenience only — StoreReleaseRequest is the real gate. --}}
                            <p class="mt-1.5 text-xs" :class="reasonIsValid ? 'text-hp-slate/50' : 'text-red-500'">
                                <span x-text="reasonLength"></span>/{{ $reasonMax }}
                                <span x-show="! reasonIsValid" x-cloak>— at least {{ $reasonMin }} characters</span>
                            </p>
                            @error('reason')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror

                            {{-- D-123. text-hp-orange, not accent-*: @tailwindcss/forms paints a checked box with currentColor. --}}
                            <label class="mt-5 flex items-start gap-3 text-sm text-hp-slate">
                                <input type="checkbox" name="wants_printed_copies" value="1" @checked(old('wants_printed_copies'))
                                       class="mt-0.5 rounded border-hp-slate/30 text-hp-orange focus:ring-hp-orange" data-release-printed-copies>
                                <span>
                                    <span class="font-semibold">The college needs printed copies of the forms</span>
                                    <span class="mt-0.5 block text-xs text-hp-slate/60">If the Director approves, the clinic is asked to print them.</span>
                                </span>
                            </label>

                            <div class="mt-6 flex flex-wrap justify-end gap-3">
                                <x-hp.button variant="muted" @click="requestOpen = false">Cancel</x-hp.button>
                                <x-hp.button type="submit" x-bind:disabled="! reasonIsValid || sending" data-release-send>
                                    <span x-text="sending ? 'Sending…' : 'Send request'">Send request</span>
                                </x-hp.button>
                            </div>
                        </form>
                    </div>
                </div>
            </template>
        @endif
    @endif
</section>
