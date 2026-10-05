{{--
    Card 3 of the Review page (FR-DIRA-07, D-109): the signed memo the college
    attached. An image shows as a thumbnail, a PDF as a tile; either one opens
    the full-screen viewer (<x-hp.file-viewer> — X / Esc to close, zoom for an
    image, the browser's own PDF viewer for a PDF; shared with D-113's release
    attachment). The file comes from director.batches.memo — private storage,
    never public/. Batches submitted before D-109 have no memo and say so.
--}}
@php
    $memoUrl = $batch->hasMemo() ? route('director.batches.memo', $batch) : null;
    $memoLabel = 'Signed memo for '.$batch->reference_no;
@endphp

<x-hp.card class="mt-6" data-memo-card>
    <p class="mb-5 hp-card-title">Signed memo</p>

    @if ($memoUrl === null)
        <p class="rounded-lg bg-hp-bg px-3 py-2 text-sm text-hp-slate/70" data-memo-missing>
            No memo attached — submitted before signed memos were required.
        </p>
    @else
        <x-hp.file-viewer :url="$memoUrl" :name="$batch->memo_original_name"
                          :is-image="$batch->memoIsImage()" :label="$memoLabel">
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
        </x-hp.file-viewer>
    @endif
</x-hp.card>
