{{--
    Card 3 of the Review page (FR-DIRA-07, D-109): the signed memo the college
    attached. An image shows as a thumbnail, a PDF as a tile; either one opens
    a full-screen viewer:
      • dark backdrop, X button top-right, Esc closes it too
      • image: Zoom in / Zoom out / Reset (25 % – 400 %); "100 %" is the whole
        memo fitted to the screen, and a zoomed image scrolls inside the viewer
      • PDF: the browser's own PDF viewer in an <iframe> (it has its own zoom)
    The file comes from director.batches.memo — private storage, never public/.
    Batches submitted before D-109 have no memo and say so.

    The zoom state is this one small Alpine object. `fit` is how much the image
    is scaled to fit the screen at 100 %; it is measured when the image loads
    and again when the window is resized.
--}}
@php
    $memoUrl = $batch->hasMemo() ? route('director.batches.memo', $batch) : null;
    $memoLabel = 'Signed memo for '.$batch->reference_no;
@endphp

<x-hp.card class="mt-6" data-memo-card>
    <p class="mb-5 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Signed memo</p>

    @if ($memoUrl === null)
        <p class="rounded-lg bg-hp-bg px-3 py-2 text-sm text-hp-slate/70" data-memo-missing>
            No memo attached — submitted before signed memos were required.
        </p>
    @else
        <div x-data="{
                open: false,
                zoom: 1,
                fit: 1,
                natW: 0,
                show() {
                    this.open = true;
                    this.zoom = 1;
                    document.body.classList.add('overflow-hidden');
                    this.$nextTick(() => { this.measure(); this.$refs.close.focus(); });
                },
                close() {
                    this.open = false;
                    document.body.classList.remove('overflow-hidden');
                },
                measure() {
                    const img = this.$refs.img;
                    if (! img || ! img.naturalWidth) return;
                    this.natW = img.naturalWidth;
                    this.fit = Math.min(this.$refs.stage.clientWidth / img.naturalWidth, this.$refs.stage.clientHeight / img.naturalHeight);
                },
                zoomBy(step) {
                    this.zoom = Math.min(4, Math.max(0.25, Math.round((this.zoom + step) * 100) / 100));
                },
             }">

            @if ($batch->memoIsImage())
                <button type="button" @click="show()" data-memo-thumbnail
                        class="group block w-full rounded-lg border border-hp-slate/15 bg-hp-bg p-3 text-left transition-colors hover:border-hp-orange/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-hp-orange">
                    <img src="{{ $memoUrl }}" alt="{{ $memoLabel }}" class="mx-auto max-h-72 w-auto rounded object-contain shadow-sm">
                    <span class="mt-3 flex items-center justify-between gap-3 text-xs">
                        <span class="min-w-0 truncate font-semibold text-hp-slate">{{ $batch->memo_original_name }}</span>
                        <span class="shrink-0 font-semibold text-hp-orange group-hover:underline">View full screen</span>
                    </span>
                </button>
            @else
                <button type="button" @click="show()" data-memo-pdf-tile
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

            {{-- Teleported to <body> so no card or layout clips the full-screen
                 viewer (the same pattern as the page's other dialogs). --}}
            <template x-teleport="body">
                <div x-show="open" x-cloak @keydown.escape.window="if (open) close()"
                     @resize.window="if (open) measure()"
                     class="fixed inset-0 z-[70] flex flex-col bg-black/90"
                     role="dialog" aria-modal="true" aria-label="{{ $memoLabel }}" data-memo-viewer>

                    {{-- Toolbar: the file's name, the zoom buttons (images), and X at the top-right. --}}
                    <div class="flex items-center gap-2 px-3 py-2 text-white sm:px-4">
                        <p class="min-w-0 flex-1 truncate text-sm font-semibold">{{ $batch->memo_original_name }}</p>

                        @if ($batch->memoIsImage())
                            <button type="button" @click="zoomBy(-0.25)" :disabled="zoom <= 0.25" aria-label="Zoom out"
                                    class="flex h-9 w-9 items-center justify-center rounded-full hover:bg-white/15 disabled:opacity-40" data-zoom-out>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/>
                                </svg>
                            </button>
                            <span class="w-12 text-center text-xs font-semibold tabular-nums" x-text="`${Math.round(zoom * 100)}%`" aria-live="polite" data-zoom-level></span>
                            <button type="button" @click="zoomBy(0.25)" :disabled="zoom >= 4" aria-label="Zoom in"
                                    class="flex h-9 w-9 items-center justify-center rounded-full hover:bg-white/15 disabled:opacity-40" data-zoom-in>
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                </svg>
                            </button>
                            <button type="button" @click="zoom = 1" aria-label="Reset zoom"
                                    class="rounded-full px-3 py-1.5 text-xs font-semibold hover:bg-white/15" data-zoom-reset>
                                Reset
                            </button>
                        @endif

                        <button type="button" x-ref="close" @click="close()" aria-label="Close"
                                class="ml-1 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white" data-memo-close>
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="min-h-0 flex-1">
                        @if ($batch->memoIsImage())
                            {{-- m-auto (not justify-center) centres the image AND
                                 keeps every edge reachable by scrolling once it is
                                 bigger than the screen. --}}
                            <div x-ref="stage" class="flex h-full w-full overflow-auto p-4">
                                <img x-ref="img" src="{{ $memoUrl }}" alt="{{ $memoLabel }}" @load="measure()"
                                     class="m-auto max-w-none select-none bg-white"
                                     :style="natW > 0 ? { width: `${Math.round(natW * fit * zoom)}px` } : { maxWidth: '100%' }">
                            </div>
                        @else
                            {{-- Loaded only while the viewer is open. --}}
                            <template x-if="open">
                                <iframe src="{{ $memoUrl }}" title="{{ $memoLabel }}" class="h-full w-full border-0 bg-white"></iframe>
                            </template>
                        @endif
                    </div>
                </div>
            </template>
        </div>
    @endif
</x-hp.card>
