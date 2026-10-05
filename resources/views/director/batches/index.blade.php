<x-layout.sidebar title="Batch Approvals">

{{--
    Director Batch Approvals — the queue (FR-DIRA-01, D-108).

    Pending requests only, first come first (`created_at`, then `id`). The
    Director schedules each one from its Review page, so this page carries no
    dates, hours or decision buttons — just who asked, for which form and why,
    and a Review button. Decided batches leave the queue.

    The oldest pending request ($nextId — the oldest in the WHOLE queue, not
    just this page or search) is highlighted as Next, styled like the clinic's
    Live Queue. It is a guide: any row can be reviewed (Q16).
--}}

{{-- ── NEXT-row styling, copied from the Live Queue (nurse/queue.blade.php) ──
     A single `data-next` attribute paints the peach band. It is applied per
     <td> because the table is border-separate, so the rounded end-caps clip. --}}
<style>
    tr[data-next] > td             { background: rgb(var(--hp-peach) / 0.45); }  /* hp-peach/45 */
    tr[data-next] > td:first-child { border-top-left-radius: 1rem; border-bottom-left-radius: 1rem; }
    tr[data-next] > td:last-child  { border-top-right-radius: 1rem; border-bottom-right-radius: 1rem; }
</style>

@php
    // The Review link, styled like <x-hp.button> (which only renders a
    // <button>): primary on the Next request, soft on the rest. Shared by the
    // phone cards and the table so the two never drift apart.
    $reviewLink = 'inline-flex items-center justify-center gap-2 rounded-full font-semibold
                   transition-[color,background-color,transform] duration-hp-fast ease-hp-out
                   active:scale-[0.97] focus-visible:outline-none focus-visible:ring-2
                   focus-visible:ring-hp-orange focus-visible:ring-offset-1';
    $reviewNext = 'bg-hp-orange text-white hover:bg-orange-500';
    $reviewRest = 'bg-hp-peach text-hp-orange hover:bg-orange-100';
    $cardLabel = 'shrink-0 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40';
@endphp

{{-- ── Page header ─────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <h2 class="text-xl font-semibold text-hp-slate">Batch Approvals</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">
        Batch requests waiting for you to schedule them, first come first.
    </p>
</div>

{{-- Flash from the Review page's decision endpoints --}}
@if (session('status'))
    <div data-hp-flash class="mb-6 rounded-lg border border-hp-orange/30 bg-hp-peach/40 px-4 py-3 text-sm text-hp-slate">
        {{ session('status') }}
    </div>
@endif
@if (session('error'))
    <div data-hp-flash data-flash-sticky class="mb-6 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ session('error') }}
    </div>
@endif

<x-hp.card>
    {{-- D-90: Batch ID search. A plain GET form, so the server filters every
         page (not just the ten on screen) and the term stays in the URL for
         the pager links. Shown whenever there is anything to search, or a
         search to change or clear. --}}
    @if ($search !== '' || $batchRequests->total() > 0)
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <p class="hp-card-title">
                Batch Requests
            </p>

            <form method="GET" action="{{ route('director.batches.index') }}" role="search"
                  class="flex w-full items-center gap-2 sm:w-auto">
                <label for="approvals-search" class="sr-only">Search by Batch ID</label>
                <input id="approvals-search" name="q" type="search" value="{{ $search }}"
                       maxlength="30" placeholder="Search Batch ID"
                       class="w-full rounded-lg border-hp-slate/20 bg-hp-white px-3 py-1.5 text-xs text-hp-slate placeholder-hp-slate/40 focus:border-hp-orange focus:ring-hp-orange sm:w-48">
                <x-hp.button type="submit" variant="soft" size="sm">Search</x-hp.button>
                @if ($search !== '')
                    <a href="{{ route('director.batches.index') }}"
                       class="px-1 py-1.5 text-xs font-medium text-hp-slate/50 hover:text-hp-slate">
                        Clear
                    </a>
                @endif
            </form>
        </div>
    @endif

    @if ($batchRequests->isEmpty() && $search !== '')
        <p class="py-6 text-center text-sm text-hp-slate/50">
            No waiting batch request matches “{{ $search }}”.
        </p>
    @elseif ($batchRequests->isEmpty())
        <div class="flex flex-col items-center py-10 text-center">
            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-hp-slate">No batch requests are waiting.</p>
            <p class="mt-0.5 text-xs text-hp-slate/50">
                New requests from the colleges will appear here, oldest first.
            </p>
        </div>
    @else
        {{-- ── Phones and small tablets (below md): one card per request ───────
             The five columns don't fit a phone, and a sideways-scrolling table
             hid Purpose and Review. Each card shows everything its table row
             does — Batch ID (+ Next), College, Form Type, Purpose, Review —
             stacked, with the Next request painted peach like its table row. --}}
        <ul class="space-y-3 md:hidden" data-queue-cards>
            @foreach ($batchRequests as $batch)
                <li class="rounded-xl border p-4 text-hp-slate {{ $batch->id === $nextId ? 'border-hp-orange/30 bg-hp-peach/40' : 'border-hp-slate/15' }}"
                    @if ($batch->id === $nextId) data-next-card @endif>
                    <p class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ $batch->reference_no }}</span>
                        @if ($batch->id === $nextId)
                            <span class="inline-flex items-center rounded-full bg-hp-orange px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest text-white">
                                Next
                            </span>
                        @endif
                    </p>

                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="{{ $cardLabel }}">College</dt>
                            <dd class="min-w-0 text-right">{{ $batch->college->code }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="{{ $cardLabel }}">Form Type</dt>
                            <dd class="min-w-0 text-right">{{ $batch->formTypeLabel() }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="{{ $cardLabel }}">Purpose</dt>
                            <dd class="min-w-0 break-words text-right">&ldquo;{{ Str::limit($batch->reasonText(), 60) }}&rdquo;</dd>
                        </div>
                    </dl>

                    <a href="{{ route('director.batches.review', $batch) }}"
                       class="{{ $reviewLink }} mt-4 w-full px-4 py-2.5 text-sm {{ $batch->id === $nextId ? $reviewNext : $reviewRest }}">
                        Review
                    </a>
                </li>
            @endforeach
        </ul>

        {{-- ── md and up: the table ────────────────────────────────────────────
             `relative` keeps the header's screen-reader-only "Action" label
             (absolutely positioned) inside this scroll box. Without it, that
             label escaped the box whenever the table was wider than the screen
             and stretched the whole page sideways. --}}
        <div class="relative hidden overflow-x-auto md:block">
            {{-- border-separate so the Next row's rounded band clips, like the Live Queue. --}}
            <table class="w-full border-separate border-spacing-x-0 border-spacing-y-1 text-left text-sm">
                <thead>
                    <tr class="text-[11px] uppercase tracking-widest text-hp-slate/40">
                        <th class="border-b border-hp-slate/10 py-2.5 pl-3 pr-4 font-semibold">Batch ID</th>
                        <th class="border-b border-hp-slate/10 py-2.5 pr-4 font-semibold">College</th>
                        {{-- D-62: Form Type immediately before Purpose (the batch's `reason`, D-105) --}}
                        <th class="border-b border-hp-slate/10 py-2.5 pr-4 font-semibold">Form Type</th>
                        <th class="border-b border-hp-slate/10 py-2.5 pr-4 font-semibold">Purpose</th>
                        <th class="border-b border-hp-slate/10 py-2.5 pr-3"><span class="sr-only">Action</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($batchRequests as $batch)
                        <tr class="text-hp-slate" @if ($batch->id === $nextId) data-next @endif>
                            <td class="py-3 pl-3 pr-4">
                                <span class="whitespace-nowrap font-medium">{{ $batch->reference_no }}</span>
                                @if ($batch->id === $nextId)
                                    <span class="ml-1.5 inline-flex items-center rounded-full bg-hp-orange px-2 py-0.5
                                                 align-middle text-[10px] font-bold uppercase tracking-widest text-white">
                                        Next
                                    </span>
                                @endif
                            </td>
                            <td class="py-3 pr-4">{{ $batch->college->code }}</td>
                            <td class="whitespace-nowrap py-3 pr-4">{{ $batch->formTypeLabel() }}</td>
                            <td class="py-3 pr-4">&ldquo;{{ Str::limit($batch->reasonText(), 60) }}&rdquo;</td>
                            <td class="py-3 pr-3 text-right">
                                <a href="{{ route('director.batches.review', $batch) }}"
                                   class="{{ $reviewLink }} px-4 py-1.5 text-xs {{ $batch->id === $nextId ? $reviewNext : $reviewRest }}">
                                    Review
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-hp.pager :paginator="$batchRequests" />
    @endif
</x-hp.card>

</x-layout.sidebar>
