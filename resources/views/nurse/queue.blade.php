<x-layout.sidebar title="Live Queue">

@php
    $count = $visits->count();
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
    tr[data-next] > td            { background: rgb(255 202 160 / 0.45); }  /* hp-peach/45 */
    tr[data-next] > td:first-child { border-top-left-radius: 1rem; border-bottom-left-radius: 1rem; }
    tr[data-next] > td:last-child  { border-top-right-radius: 1rem; border-bottom-right-radius: 1rem; }

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
                <span class="h-1.5 w-1.5 rounded-full bg-white animate-pulse"></span>
                Live
            </span>
        </div>
        <p class="mt-0.5 text-sm text-hp-slate/50" data-queue-subtitle>
            {{ $count }} {{ \Illuminate\Support\Str::plural('student', $count) }} waiting · updated just now
        </p>
    </div>
</div>

{{-- Save & Close success flash (FR-NRS-04) — one-shot, gone on the next load. --}}
@if (session('status'))
    <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
        {{ session('status') }}
    </div>
@endif

<x-hp.card>

    {{-- ── Empty state — the queue is clear ─────────────────────────────────────
         Both the empty state and the table are always in the DOM; the poll shows
         whichever fits the current count, so the queue can empty or fill without a
         reload. `hidden` is toggled server-side for the first paint. --}}
    <div data-queue-empty class="{{ $count === 0 ? '' : 'hidden' }}">
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
    <div data-queue-table class="overflow-x-auto {{ $count === 0 ? 'hidden' : '' }}">
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
                @foreach ($visits as $visit)
                    <x-nurse.queue-row :visit="$visit" :is-next="$loop->first" />
                @endforeach
            </tbody>
        </table>
    </div>

</x-hp.card>

@push('scripts')
    @vite('resources/js/nurse/live-queue.js')
@endpush

</x-layout.sidebar>
