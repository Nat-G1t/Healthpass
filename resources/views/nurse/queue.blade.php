<x-layout.sidebar title="Live Queue">

@php
    $count = $visits->count();
    // The ghost (just-encoded visit, §6.1b) keeps the table on screen even
    // when it is the only row left — the JS collapses it, then fades the
    // empty state in.
    $hasRows = $count > 0 || $ghost !== null;
@endphp

{{-- ── NEXT-row styling ─────────────────────────────────────────────────────────
     The whole "NEXT-ness" of a row is driven by a single `data-next` attribute so
     the poll (FR-NRS-02) only has to flip ONE attribute when the queue advances —
     CSS does the rest. The peach band is applied per <td> (the table is
     border-separate) so the rounded end-caps clip cleanly; the Next tag, the
     reference line, and the primary/ghost buttons are all rendered in every row
     and shown/hidden by the same attribute, so the poller never juggles classes.
──────────────────────────────────────────────────────────────────────────────── --}}
<style>
    tr[data-next] > td            { background: rgb(var(--hp-peach) / 0.45); }  /* hp-peach/45 */
    tr[data-next] > td:first-child { border-top-left-radius: 1rem; border-bottom-left-radius: 1rem; }
    tr[data-next] > td:last-child  { border-top-right-radius: 1rem; border-bottom-right-radius: 1rem; }

    /* NEXT promotion animates: when the poll moves data-next to a new row,
       the peach band fades in/out instead of snapping (§6.1a). */
    [data-queue-body] tr > td { transition: background-color var(--hp-dur-base) var(--hp-ease-out); }

    /* New arrival (§6.1a): fade-up plus a peach highlight that clears over
       ~1.5 s, so the nurse's eye is drawn to the row that just appeared. */
    @keyframes queue-arrive-highlight {
        from { background-color: rgb(var(--hp-peach) / 0.45); }
        to   { background-color: transparent; }
    }
    tr.queue-row-new         { animation: hp-fade-up var(--hp-dur-base) var(--hp-ease-out); }
    tr.queue-row-new > td    { animation: queue-arrive-highlight 1.5s var(--hp-ease-out); }
    /* The NEXT band supersedes the arrival highlight (a new sole row is both). */
    tr.queue-row-new[data-next] > td { animation: none; }

    /* Show the Next tag + primary button only on the NEXT row; the reference
       line + ghost button only on the rest. */
    tr:not([data-next]) .queue-next-tag,
    tr:not([data-next]) .queue-btn-next { display: none; }
    tr[data-next] .queue-ref,
    tr[data-next] .queue-btn-rest       { display: none; }
</style>

{{-- ── Page header ──────────────────────────────────────────────────────────────
     Blinking LIVE pill + a live count. The subtitle is rewritten by the poll:
     "{n} students waiting · updated Xs ago". It starts as the server value.
──────────────────────────────────────────────────────────────────────────────── --}}
<div class="mb-7 flex flex-wrap items-center justify-between gap-3"
     data-live-queue
     data-feed-url="{{ route('nurse.queue.feed') }}">
    <div>
        <div class="flex items-center gap-2.5">
            <h2 class="text-xl font-semibold text-hp-slate">Live Queue</h2>

            {{-- Blinking LIVE pill: the pulsing dot reads as "receiving updates". --}}
            <span class="inline-flex items-center gap-1.5 rounded-full bg-hp-orange px-2.5 py-1
                         text-[10px] font-bold uppercase tracking-widest text-white">
                {{-- Literal white, not bg-hp-white: this dot sits ON the orange
                     pill, so it must stay white in dark mode too (the surface
                     token would turn it into a dark dot on orange). --}}
                <span class="h-1.5 w-1.5 rounded-full bg-white animate-pulse"></span>
                Live
            </span>
        </div>
        {{-- Count lives in its own span so the poll can countUp() just the
             number while the ticker rewrites the rest of the line. --}}
        {{-- Kept as ONE line: the spans must be separated by exactly one space
             so the rendered text still reads "{n} students waiting …". --}}
        <p class="mt-0.5 text-sm text-hp-slate/50" data-queue-subtitle><span data-queue-count>{{ $count }}</span> <span data-queue-subtitle-rest>{{ \Illuminate\Support\Str::plural('student', $count) }} waiting · updated just now</span></p>
    </div>
</div>

{{-- Save & Close success flash (FR-NRS-04) — one-shot, gone on the next load.
     data-hp-flash: fades up on entry and auto-dismisses after ~5 s (§5.7). It
     occupies its layout slot from the first frame (the fade-up only moves
     transform/opacity), so its entrance never shifts the table mid-collapse. --}}
@if (session('status'))
    <div data-hp-flash class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
        {{ session('status') }}
    </div>
@endif

{{-- D-93: a Remove that found nothing to remove, or an encode link for a visit
     a colleague already removed. --}}
@if (session('error'))
    <div data-hp-flash data-flash-sticky class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ session('error') }}
    </div>
@endif

<x-hp.card>

    {{-- ── Empty state — the queue is clear ─────────────────────────────────────
         Both the empty state and the table are always in the DOM; the poll shows
         whichever fits the current count, so the queue can empty or fill without a
         reload. `hidden` is toggled server-side for the first paint. --}}
    <div data-queue-empty class="{{ $hasRows ? 'hidden' : '' }}">
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-hp-slate">Queue is clear</p>
            <p class="mt-0.5 text-xs text-hp-slate/50">
                Students appear here the moment they finish at the kiosk
            </p>
        </div>
    </div>

    {{-- Horizontal scroll keeps the full table intact on narrow screens;
         the nurse terminal is a desktop, so the table is the primary view. --}}
    <div data-queue-table class="overflow-x-auto {{ $hasRows ? '' : 'hidden' }}">
        {{-- border-separate (not the default collapse) so the NEXT row's rounded
             corners actually clip; border-spacing-y gives every row breathing room
             instead of butting flush. --}}
        <table class="w-full text-left border-separate border-spacing-x-0 border-spacing-y-1.5">
            <thead>
                <tr class="[&>th]:border-b [&>th]:border-hp-slate/15">
                    <th class="pb-3 pl-4 pr-6 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Student</th>
                    <th class="pb-3 pr-6 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">College</th>
                    <th class="pb-3 pr-6 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Vitals Summary</th>
                    <th class="pb-3 pr-6 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Flags</th>
                    <th class="pb-3 pr-6 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Time</th>
                    <th class="pb-3 pr-4 text-right text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Action</th>
                </tr>
            </thead>
            <tbody data-queue-body>
                {{-- The ghost (just-encoded visit, §6.1b) renders ONCE in its
                     original FCFS slot; live-queue.js collapses it on load.
                     NEXT stays on the first REAL row — the ghost is scenery. --}}
                @foreach ($visits as $visit)
                    @if ($ghost !== null && $ghostIndex === $loop->index)
                        <x-nurse.queue-row :visit="$ghost" leaving />
                    @endif
                    <x-nurse.queue-row :visit="$visit" :is-next="$loop->first" />
                @endforeach
                @if ($ghost !== null && $ghostIndex >= $visits->count())
                    <x-nurse.queue-row :visit="$ghost" leaving />
                @endif
            </tbody>
        </table>
    </div>

</x-hp.card>

{{-- ── Remove-from-queue confirmation (D-93) ────────────────────────────────────
     Opened by the `queue-remove` window event that live-queue.js fires when a
     row's Remove button is clicked — one listener for server-rendered and
     poll-added rows alike, so this popup never depends on how a row was built.
     `target` is null (closed) or the { url, name, ref } of that row.

     Same dialog shape as the app's other confirms: teleported to <body> so no
     ancestor clips the backdrop, and dismissable with Esc, a backdrop click or
     "Keep in queue". The DELETE only happens from the red button in here.
──────────────────────────────────────────────────────────────────────────────── --}}
<div x-data="{ target: null, sending: false }"
     @queue-remove.window="target = $event.detail; sending = false">
    <template x-teleport="body">
        <div
            x-show="target !== null"
            x-cloak
            @keydown.escape.window="target = null"
            class="fixed inset-0 z-[60] flex items-center justify-center px-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="queue-remove-title"
        >
            <div
                x-show="target !== null"
                @click="target = null"
                x-transition:enter="ease-hp-out duration-hp-base"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-hp-in duration-hp-fast"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 bg-hp-slate/50"
                aria-hidden="true"
            ></div>

            <div
                x-show="target !== null"
                x-transition:enter="ease-hp-spring duration-hp-slow"
                x-transition:enter-start="opacity-0 translate-y-6"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="ease-hp-in duration-hp-base"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-6"
                class="relative w-full max-w-md rounded-2xl bg-hp-white p-6 shadow-xl"
            >
                <h2 id="queue-remove-title" class="text-lg font-semibold text-hp-slate">
                    Remove <span x-text="target?.name"></span> from the queue?
                </h2>
                <p class="mt-1 font-mono text-xs text-hp-slate/40" x-text="target?.ref"></p>

                <p class="mt-3 text-sm text-hp-slate/70">
                    Use this when a student left the clinic without being seen.
                    Their kiosk readings and answers for this visit are
                    <strong class="font-semibold text-hp-slate">deleted</strong>, and nothing is encoded.
                </p>
                <p class="mt-3 rounded-lg bg-hp-bg px-4 py-3 text-xs text-hp-slate">
                    This cannot be undone. If the student comes back today, they can go
                    through the kiosk again.
                </p>

                <form method="POST" :action="target?.url" @submit="sending = true" class="mt-6 flex justify-end gap-3">
                    @csrf
                    @method('DELETE')
                    <x-hp.button variant="muted" @click="target = null">Keep in queue</x-hp.button>
                    <x-hp.button type="submit" variant="danger" x-bind:disabled="sending">
                        <span x-text="sending ? 'Removing…' : 'Remove'"></span>
                    </x-hp.button>
                </form>
            </div>
        </div>
    </template>
</div>

@push('scripts')
    @vite('resources/js/nurse/live-queue.js')
@endpush

</x-layout.sidebar>
