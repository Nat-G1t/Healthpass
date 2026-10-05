@props([
    'url',              {{-- where the file loads from — a role-checked route, never public/ --}}
    'name',             {{-- the file's name, shown in the toolbar --}}
    'isImage' => false, {{-- image: zoom buttons; otherwise a PDF in the browser's own viewer --}}
    'label',            {{-- what the dialog is, for screen readers --}}
])

{{--
    A full-screen viewer for one attached file (D-109 — the signed memo; D-113
    — the release request's attachment). The slot is the trigger: anything in
    it that calls show() opens the viewer.
      • dark backdrop, X button top-right; Esc closes it too
      • image: Zoom in / Zoom out / Reset (25 % – 400 %); "100 %" is the whole
        file fitted to the screen, and a zoomed image scrolls inside the viewer
      • PDF: the browser's own PDF viewer in an <iframe> (it has its own zoom)

    The zoom state is this one small Alpine object. `fit` is how much the image
    is scaled to fit the screen at 100 %; it is measured when the image loads
    and again when the window is resized.

    Esc is handled on the viewer itself (it takes focus when it opens, and
    tabindex="-1" keeps focus inside it when the image is clicked) and stops
    there, so a viewer opened from inside a popup closes on its own without
    also closing the popup behind it.
--}}
<div {{ $attributes }}
     x-data="{
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

    {{ $slot }}

    {{-- Teleported to <body> so no card, popup or layout clips it. --}}
    <template x-teleport="body">
        <div x-show="open" x-cloak tabindex="-1" @keydown.escape.stop="close()"
             @resize.window="if (open) measure()"
             class="fixed inset-0 z-[70] flex flex-col bg-black/90 focus:outline-none"
             role="dialog" aria-modal="true" aria-label="{{ $label }}" data-file-viewer>

            {{-- Toolbar: the file's name, the zoom buttons (images), and X at the top-right. --}}
            <div class="flex items-center gap-2 px-3 py-2 text-white sm:px-4">
                <p class="min-w-0 flex-1 truncate text-sm font-semibold">{{ $name }}</p>

                @if ($isImage)
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
                        class="ml-1 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white" data-file-viewer-close>
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="min-h-0 flex-1">
                @if ($isImage)
                    {{-- m-auto (not justify-center) centres the image AND keeps
                         every edge reachable by scrolling once it is bigger
                         than the screen. --}}
                    <div x-ref="stage" class="flex h-full w-full overflow-auto p-4">
                        <img x-ref="img" src="{{ $url }}" alt="{{ $label }}" @load="measure()"
                             class="m-auto max-w-none select-none bg-white"
                             :style="natW > 0 ? { width: `${Math.round(natW * fit * zoom)}px` } : { maxWidth: '100%' }">
                    </div>
                @else
                    {{-- Loaded only while the viewer is open. --}}
                    <template x-if="open">
                        <iframe src="{{ $url }}" title="{{ $label }}" class="h-full w-full border-0 bg-white"></iframe>
                    </template>
                @endif
            </div>
        </div>
    </template>
</div>
