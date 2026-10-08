{{--
    D-113 (FR-DIRA-11): under the Director's results page's main card, full
    width — "Review request of documents".

    Takes:
      $batch          — the approved BatchRequest, with `releaseRequests`
                        (oldest first) and each one's `requester` loaded
      $pendingRelease — the request waiting for a decision, or null
      $approvedRelease — the approved request, or null (D-124)
      $memoSize       — the batch's signed memo's size label ("2.4 MB"), or null

    The button is enabled only while a request is pending; it opens a popup
    with who asked and when, the reason, whether the college wants printed
    copies, the batch's SIGNED MEMO (D-123 — D-109's memo, in place of the old
    attachment: an image as a thumbnail, a PDF as a tile — both open the shared
    full-screen viewer, <x-hp.file-viewer>, like the Review page's memo card),
    and Approve / Reject. Reject reveals a required message (10–500
    characters, live counter) the college reads on its page. Under the button,
    small: every request this batch has had, with its outcome — and, for a
    request made before D-123, a link to the file it carried
    (director.release-requests.attachment, the Director's route only).

    The memo loads from director.batches.memo. Both decisions are re-checked
    under a lock.

    D-124: once a request is APPROVED the button, the line under it and the
    popup are not rendered. In their place is the same highlighted note the
    College Admin sees — "Documents released on {date}." — and the history
    stays under it. Approval is final (D-113), so nothing can be pending then.
--}}
@php
    $messageMin = App\Http\Requests\Director\RejectReleaseRequest::MESSAGE_MIN;
    $messageMax = App\Http\Requests\Director\RejectReleaseRequest::MESSAGE_MAX;
    $hasMessageError = $errors->has('rejection_message');
@endphp

<section class="mt-6" data-release-review
         x-data="{
            reviewOpen: {{ Illuminate\Support\Js::from($hasMessageError && $pendingRelease !== null) }},
            rejecting: {{ Illuminate\Support\Js::from($hasMessageError) }},
            message: {{ Illuminate\Support\Js::from((string) old('rejection_message', '')) }},
            deciding: false,
            get messageLength() { return this.message.trim().length; },
            get messageIsValid() { return this.messageLength >= {{ $messageMin }} && this.messageLength <= {{ $messageMax }}; },
         }">

    @if ($approvedRelease !== null)
        {{-- The College Admin's released note (admin/batches/partials/release-request), worded for the Director. --}}
        <div class="rounded-2xl border border-hp-orange/30 bg-hp-peach/40 px-5 py-4 text-sm text-hp-slate" data-release-state="approved">
            <p class="font-semibold">
                Documents released on {{ $approvedRelease->reviewed_at?->format('M j, Y') }}.
                @if ($approvedRelease->wants_printed_copies)
                    Printed copies requested.
                @endif
            </p>
        </div>
    @else
        <x-hp.button size="xl" class="w-full" :disabled="$pendingRelease === null" @click="reviewOpen = true"
                     data-release-review-open>
            Review request of documents
        </x-hp.button>
        <p class="mt-2 text-center text-sm text-hp-slate/60">
            @if ($pendingRelease === null)
                No release request from the college.
            @else
                {{ $pendingRelease->requester?->name ?? 'The college' }} asked on {{ $pendingRelease->created_at->format('M j, Y · g:i A') }}.
            @endif
        </p>
    @endif

    {{-- The request history, small. --}}
    @if ($batch->releaseRequests->isNotEmpty())
        <div class="mt-4 rounded-lg bg-hp-bg px-4 py-3 text-xs text-hp-slate/70" data-release-history>
            <p class="hp-card-title">Release requests</p>
            <ul class="mt-1.5 space-y-1">
                @foreach ($batch->releaseRequests as $release)
                    @php
                        $outcome = match ($release->status) {
                            'approved' => 'Approved '.$release->reviewed_at?->format('M j, Y'),
                            'rejected' => 'Rejected '.$release->reviewed_at?->format('M j, Y'),
                            default => 'Waiting for your review',
                        };
                    @endphp
                    <li class="flex flex-wrap gap-x-2">
                        <span>{{ $release->created_at->format('M j, Y · g:i A') }}</span>
                        <span>—</span>
                        <span class="font-semibold">{{ $outcome }}</span>
                        @if ($release->wants_printed_copies)
                            <span>· printed copies</span>
                        @endif
                        @if ($release->hasAttachment())
                            {{-- A request from before D-123 still carries its file. --}}
                            <span>·</span>
                            <a href="{{ route('director.release-requests.attachment', $release) }}" target="_blank" rel="noopener"
                               class="font-semibold text-hp-orange hover:underline" data-release-history-attachment>attachment</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($pendingRelease !== null && $approvedRelease === null)
        {{-- ── The review popup ────────────────────────────────────────────
             Teleported to <body> so no ancestor's overflow clips it. Esc closes
             it — unless the memo viewer is open on top, which handles
             (and stops) its own Esc. --}}
        <template x-teleport="body">
            <div x-show="reviewOpen" x-cloak @keydown.escape.window="reviewOpen = false"
                 class="fixed inset-0 z-[60] flex items-center justify-center px-4"
                 role="dialog" aria-modal="true" aria-labelledby="release-review-title" data-release-review-dialog>
                <div x-show="reviewOpen" @click="reviewOpen = false"
                     x-transition:enter="ease-hp-out duration-hp-base" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                     x-transition:leave="ease-hp-in duration-hp-fast" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                     class="absolute inset-0 bg-hp-slate/50" aria-hidden="true"></div>

                <div x-show="reviewOpen"
                     x-transition:enter="ease-hp-spring duration-hp-slow" x-transition:enter-start="opacity-0 translate-y-6" x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="ease-hp-in duration-hp-base" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-6"
                     class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-hp-white p-6 shadow-xl">
                    <h2 id="release-review-title" class="text-lg font-semibold text-hp-slate">
                        Release request for {{ $batch->reference_no }}
                    </h2>
                    <p class="mt-1 text-sm text-hp-slate/70">
                        Approving lets {{ $batch->college->code }}’s College Admin download the official forms of this
                        batch’s encoded students. It never expires.
                    </p>

                    <dl class="mt-5 space-y-3 text-sm">
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Requested by</dt>
                            <dd class="mt-0.5 text-hp-slate">
                                {{ $pendingRelease->requester?->name ?? '—' }}
                                <span class="text-hp-slate/50">({{ $batch->college->code }} College Admin)</span>
                                · {{ $pendingRelease->created_at->format('M j, Y · g:i A') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Reason</dt>
                            {{-- The college's own words: escaped text, line breaks kept. --}}
                            <dd class="mt-0.5 whitespace-pre-line text-hp-slate" data-release-reason>{{ $pendingRelease->reason }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Printed copies</dt>
                            <dd class="mt-0.5 text-hp-slate" data-release-printed-copies>
                                Printed copies: {{ $pendingRelease->wants_printed_copies ? 'Yes' : 'No' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="mb-1.5 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Signed memo</dt>
                            <dd>
                                @if (! $batch->hasMemo())
                                    <p class="rounded-lg bg-hp-bg px-3 py-2 text-sm text-hp-slate/70" data-release-memo-missing>
                                        No memo on file — submitted before memos were required.
                                    </p>
                                @else
                                    @php
                                        $memoUrl = route('director.batches.memo', $batch);
                                        $memoLabel = 'Signed memo for '.$batch->reference_no;
                                    @endphp
                                    <x-hp.file-viewer :url="$memoUrl" :name="$batch->memo_original_name"
                                                      :is-image="$batch->memoIsImage()" :label="$memoLabel">
                                        @if ($batch->memoIsImage())
                                            <button type="button" @click="show()" data-release-memo-thumbnail
                                                    class="group block w-full rounded-lg border border-hp-slate/15 bg-hp-bg p-3 text-left transition-colors hover:border-hp-orange/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-hp-orange">
                                                <img src="{{ $memoUrl }}" alt="{{ $memoLabel }}" class="mx-auto max-h-48 w-auto rounded object-contain shadow-sm">
                                                <span class="mt-2 flex items-center justify-between gap-3 text-xs">
                                                    <span class="min-w-0 truncate font-semibold text-hp-slate">{{ $batch->memo_original_name }}</span>
                                                    <span class="shrink-0 font-semibold text-hp-orange group-hover:underline">View full screen</span>
                                                </span>
                                            </button>
                                        @else
                                            <button type="button" @click="show()" data-release-memo-pdf-tile
                                                    class="group flex w-full items-center gap-4 rounded-lg border border-hp-slate/15 bg-hp-bg p-4 text-left transition-colors hover:border-hp-orange/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-hp-orange">
                                                <svg class="h-10 w-10 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                                </svg>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-sm font-semibold text-hp-slate">{{ $batch->memo_original_name }}</span>
                                                    <span class="block text-xs text-hp-slate/50">PDF{{ $memoSize !== null ? ' · '.$memoSize : '' }}</span>
                                                </span>
                                                <span class="shrink-0 text-xs font-semibold text-hp-orange group-hover:underline">Open</span>
                                            </button>
                                        @endif
                                    </x-hp.file-viewer>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    {{-- Reject: the message box, revealed by the Reject button. --}}
                    <form x-show="rejecting" x-cloak method="POST" action="{{ route('director.release-requests.reject', $pendingRelease) }}"
                          @submit="deciding = true" class="mt-6" data-release-reject-form>
                        @csrf
                        <label for="release-rejection-message" class="block text-xs font-semibold uppercase tracking-widest text-hp-slate/40">
                            Message to the college
                        </label>
                        <textarea id="release-rejection-message" name="rejection_message" x-model="message" rows="4" required
                                  minlength="{{ $messageMin }}" maxlength="{{ $messageMax }}"
                                  class="mt-1.5 w-full rounded-lg border-hp-slate/20 text-sm text-hp-slate focus:border-hp-orange focus:ring-hp-orange"></textarea>
                        {{-- Live counter. Convenience only — RejectReleaseRequest is the real gate. --}}
                        <p class="mt-1.5 text-xs" :class="messageIsValid ? 'text-hp-slate/50' : 'text-red-500'">
                            <span x-text="messageLength"></span>/{{ $messageMax }}
                            <span x-show="! messageIsValid" x-cloak>— at least {{ $messageMin }} characters</span>
                        </p>
                        @error('rejection_message')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror

                        <div class="mt-4 flex flex-wrap justify-end gap-3">
                            <x-hp.button variant="muted" @click="rejecting = false">Back</x-hp.button>
                            <x-hp.button type="submit" variant="danger" x-bind:disabled="! messageIsValid || deciding" data-release-reject-submit>
                                <span x-text="deciding ? 'Rejecting…' : 'Reject request'">Reject request</span>
                            </x-hp.button>
                        </div>
                    </form>

                    {{-- Approve / Reject --}}
                    <form x-show="! rejecting" method="POST" action="{{ route('director.release-requests.approve', $pendingRelease) }}"
                          @submit="deciding = true" class="mt-6 flex flex-wrap justify-end gap-3" data-release-approve-form>
                        @csrf
                        <x-hp.button variant="muted" @click="reviewOpen = false">Close</x-hp.button>
                        <x-hp.button variant="danger" @click="rejecting = true" data-release-reject>Reject</x-hp.button>
                        <x-hp.button type="submit" x-bind:disabled="deciding" data-release-approve>
                            <span x-text="deciding ? 'Approving…' : 'Approve'">Approve</span>
                        </x-hp.button>
                    </form>
                </div>
            </div>
        </template>
    @endif
</section>
