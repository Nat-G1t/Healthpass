<x-layout.sidebar title="Clinic Dashboard">
    <x-back-guard />


    {{-- FR-NRS-09 (D-44) — stat tiles on top, the clinic-wide encode history
         directly below. This is the landing page for nurses and physicians
         alike (D-64), who read the same rows: once a visit is
         encoded it drops out of the Live Queue, and this is where it lands. --}}

    {{-- ── Stat tiles ───────────────────────────────────────────────────────
         hp-stagger fades the cards up one after another on first paint (§6.2);
         data-hp-countup animates each number from 0. Same treatment as the
         Director dashboard's KPI row, so the two read as one system. --}}
    <div class="hp-stagger mb-6 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">

        <x-hp.card class="border-l-4 border-l-hp-orange">
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Encoded Today
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-orange" data-hp-countup>{{ $stats['encodedToday'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">{{ today()->format('M j, Y') }}</p>
        </x-hp.card>

        <x-hp.card>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Encoded This Month
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['encodedMonth'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">{{ now()->format('F Y') }}</p>
        </x-hp.card>

        <x-hp.card>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Awaiting Encode
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['awaitingEncode'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">
                <a href="{{ route('nurse.queue') }}" class="text-hp-orange hover:underline">in the Live Queue</a>
            </p>
        </x-hp.card>

        <x-hp.card>
            <p class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Flagged Vitals This Month
            </p>
            <p class="mt-3 text-3xl font-bold leading-none text-hp-slate" data-hp-countup>{{ $stats['flaggedMonth'] }}</p>
            <p class="mt-2 text-xs text-hp-slate/50">vitals over a flag threshold</p>
        </x-hp.card>

    </div>

    {{-- ── Encode history ───────────────────────────────────────────────── --}}
    <x-hp.card>

        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-hp-slate">Encode History</h3>
                <p class="mt-1 text-xs text-hp-slate/50">
                    Every encoded result in the clinic, newest first — including your colleagues&rsquo;.
                </p>
            </div>
            <x-hp.badge variant="neutral">{{ $records->total() }} {{ Str::plural('result', $records->total()) }}</x-hp.badge>
        </div>

        {{-- Filters (GET, so the filtered view is shareable and bookmarkable —
             the URL alone says what is on screen). No `page` field: applying a
             filter always starts again at page 1. --}}
        <form method="GET" action="{{ route('nurse.dashboard') }}"
              class="mb-5 flex flex-wrap items-end gap-3">

            <div class="flex flex-col gap-1">
                <label for="history-month" class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Month</label>
                <select id="history-month" name="month"
                        class="rounded-lg border-hp-slate/20 bg-hp-white py-1.5 pl-3 pr-8 text-xs font-medium text-hp-slate focus:border-hp-orange focus:ring-hp-orange">
                    <option value="">All months</option>
                    @foreach ($availableMonths as $month)
                        <option value="{{ $month['value'] }}" @selected($month['value'] === $selectedMonth)>
                            {{ $month['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="history-result" class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Result</label>
                <select id="history-result" name="result"
                        class="rounded-lg border-hp-slate/20 bg-hp-white py-1.5 pl-3 pr-8 text-xs font-medium text-hp-slate focus:border-hp-orange focus:ring-hp-orange">
                    <option value="">All results</option>
                    @foreach (\App\Models\ClearanceRecord::RESULTS as $result)
                        <option value="{{ $result }}" @selected($result === $selectedResult)>{{ $result }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex min-w-[200px] flex-1 flex-col gap-1">
                <label for="history-search" class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Search</label>
                <input id="history-search" name="q" type="search" value="{{ $search }}"
                       placeholder="Student name or reference no."
                       class="w-full rounded-lg border-hp-slate/20 bg-hp-white py-1.5 px-3 text-xs text-hp-slate placeholder-hp-slate/40 focus:border-hp-orange focus:ring-hp-orange">
            </div>

            <x-hp.button type="submit" variant="soft" size="sm">Apply</x-hp.button>

            @if ($selectedMonth !== null || $selectedResult !== null || $search !== '')
                <a href="{{ route('nurse.dashboard') }}"
                   class="px-1 py-1.5 text-xs font-medium text-hp-slate/50 hover:text-hp-slate">
                    Clear
                </a>
            @endif
        </form>

        @if ($records->isEmpty())
            {{-- Empty state, in the Live Queue's voice. --}}
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-hp-bg">
                    <svg class="h-6 w-6 text-hp-slate/30" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-hp-slate">No encoded results yet</p>
                <p class="mt-0.5 text-xs text-hp-slate/50">
                    @if ($selectedMonth !== null || $selectedResult !== null || $search !== '')
                        Nothing matches these filters — try clearing them.
                    @else
                        Results appear here as soon as you encode a visit from the Live Queue.
                    @endif
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <x-hp.table :headers="['Reference No', 'Student', 'College', 'Program', 'Result', 'Encoded by', 'Encoded at', 'Printed', '']">
                    @foreach ($records as $record)
                        @php
                            $visit = $record->clinicVisit;
                        @endphp
                        <x-hp.table-row>
                            <x-hp.table-cell label="Reference No">
                                <span class="font-mono text-xs text-hp-slate/70">{{ $visit?->reference_no ?? '—' }}</span>
                            </x-hp.table-cell>

                            <x-hp.table-cell label="Student" class="font-medium">
                                {{ $visit?->student?->name ?? '—' }}
                            </x-hp.table-cell>

                            <x-hp.table-cell label="College">
                                {{-- Capture-time snapshot (D-17), not the student's current college. --}}
                                <span title="{{ $visit?->college?->name }}">{{ $visit?->college?->code ?? '—' }}</span>
                            </x-hp.table-cell>

                            <x-hp.table-cell label="Program">
                                {{-- clinic_visits.course, the D-43 capture-time snapshot. Visits
                                     captured before D-43 have none and are never backfilled. --}}
                                <span class="text-xs">{{ $visit?->course ?: '—' }}</span>
                            </x-hp.table-cell>

                            <x-hp.table-cell label="Result">
                                <x-hp.badge :variant="$record->result === 'Fit' ? 'fit' : 'unfit'">
                                    {{ $record->result }}
                                </x-hp.badge>
                            </x-hp.table-cell>

                            {{-- D-64: name + a role badge, so a shared history
                                 shows at a glance whether a nurse or the
                                 physician encoded (and so what prints). --}}
                            <x-hp.table-cell label="Encoded by">
                                {{ $record->encoder?->name ?? '—' }}
                                @if ($record->encoder)
                                    <x-hp.badge :variant="$record->encoder->isPhysician() ? 'positive' : 'neutral'" class="ml-1">
                                        {{ $record->encoder->roleLabel() }}
                                    </x-hp.badge>
                                @endif
                            </x-hp.table-cell>

                            <x-hp.table-cell label="Encoded at">
                                <span class="text-xs">{{ $record->encoded_at?->format('M j, Y g:i A') ?? '—' }}</span>
                            </x-hp.table-cell>

                            <x-hp.table-cell label="Printed">
                                @if ($record->printed_at)
                                    <span class="text-xs" title="Last printed {{ $record->printed_at->format('M j, Y g:i A') }}">Yes</span>
                                @else
                                    <span class="text-xs text-hp-slate/50">No</span>
                                @endif
                            </x-hp.table-cell>

                            <x-hp.table-cell label="Actions">
                                <div class="flex items-center justify-end gap-3 md:justify-start">
                                    {{-- The encode screen already renders read-only for an
                                         encoded visit (its $readOnly flag), so there is no
                                         separate detail page to build. --}}
                                    {{-- from=dashboard + the table's filters/page make the
                                         visit page's back link return here (FR-NRS-09). --}}
                                    <a href="{{ route('nurse.visits.encode', ['visit' => $visit, 'from' => 'dashboard'] + $tableState) }}"
                                       class="text-xs font-semibold text-hp-orange hover:underline">View</a>

                                    {{-- Reprint re-stamps printed_at and returns the official
                                         form (FR-NRS-05). It posts into the hidden print frame
                                         at the foot of this page and prints there — the print
                                         dialog is the preview, so the nurse never leaves the
                                         dashboard or loses their filters. It stays a POST, not
                                         a link, because it writes printed_at. --}}
                                    <form method="POST" action="{{ route('nurse.visits.print.reprint', $visit) }}"
                                          target="hp-print-frame">
                                        @csrf
                                        <button type="submit" data-print-trigger
                                                class="text-xs font-semibold text-hp-slate/60 hover:text-hp-slate hover:underline">
                                            Reprint
                                        </button>
                                    </form>
                                </div>
                            </x-hp.table-cell>
                        </x-hp.table-row>
                    @endforeach
                </x-hp.table>
            </div>

            {{-- Pager (FR-UI-06) — the shared <x-hp.pager>; the paginator carries
                 the active filters in its URLs. --}}
            <x-hp.pager :paginator="$records" />
        @endif

    </x-hp.card>

    {{-- Hidden frame every Reprint above posts into (FR-NRS-05). One frame for
         the whole table — the forms name it as their target. --}}
    @include('partials.print-frame')

</x-layout.sidebar>
