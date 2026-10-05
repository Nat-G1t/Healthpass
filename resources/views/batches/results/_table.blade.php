{{--
    D-111: the results page's main card — three filters, then every student on
    the batch in one table. D-113 adds the document tools.

    Takes:
      $rows      — BatchResults::page()['rows']: name, student number, status
                   key and Fit / Unfit per student (OUTCOME ONLY, PRD §6.6),
                   plus an encoded student's visit id, already in name order
      $documents — optional (D-113): the select-and-download tools —
                   ['action' => the download route's URL,
                    'enabled' => may this viewer use them now,
                    'hint' => what to say while they are greyed out]
                   Left out, the card has no tools at all.

    The whole roster is on the page, so filtering happens in the browser: the
    `batchResults` Alpine component (resources/js/shared/batch-results.js)
    holds the rows (embedded with Js::from(), which escapes quotes, so a name
    like O'Brien can't break it), the three filter values and the selection.
    Its `filtered` getter is what the table loops over. Every value is written
    with x-text, which sets textContent — a name is never parsed as markup.

    Q13: the table is ten rows tall and then scrolls INSIDE the card, with its
    header pinned — no pager. That is this table's exception to FR-UI-06.
    Below `md` the shared <x-hp.table> re-flows each student into a small card
    (never a sideways-scrolling table), and the same box scrolls those.

    D-113 select mode: only encoded students (they have a document) stay in
    the table, each with a checkbox at the left end of its row — inside the
    Name cell, so the column headers never shift and the phone cards get it
    too. Before the Director releases a batch to its college, the College
    Admin sees the same tools greyed out with the hint.
--}}
@php
    // The student statuses (Appointment::clearanceProgress() keys) in the
    // order the filter lists them, each with its badge. D-110's colours:
    // orange while still moving, peach once done, slate for waiting or
    // did-not-happen, red for a student on their 2nd chance.
    $progressBadges = [
        'scheduled' => ['neutral', 'Scheduled'],
        'in_clinic' => ['live', 'At the clinic'],
        'rest_retake' => ['live', 'Rest / Retake'],
        'rescheduled' => ['danger', 'Rescheduled'],
        'completed' => ['approved', 'Completed'],
        'unattended' => ['rejected', 'Unattended'],
    ];

    $documents ??= null;
    $toolsEnabled = $documents !== null && $documents['enabled'];
    $maxPerDownload = App\Services\BatchDocumentZip::maxPerDownload();
@endphp

<div x-data="batchResults({{ Illuminate\Support\Js::from([
        'rows' => $rows,
        'enabled' => $toolsEnabled,
        'maxPerDownload' => $maxPerDownload,
    ]) }})">
    <x-hp.card>
        <p class="mb-4 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
            Students <span class="ml-1 normal-case tracking-normal text-hp-slate/50">({{ count($rows) }})</span>
        </p>

        {{-- ── Filters (left) and, with D-113, the selection tools (right).
             Everything wraps onto its own lines on a phone. ───────────────── --}}
        <div class="mb-4 flex flex-wrap items-end gap-3" data-results-filters>
            <div class="w-full sm:w-40">
                <x-hp.select id="results-filter-result" label="Result" x-model="result">
                    <option value="">All results</option>
                    <option value="Fit">Fit</option>
                    <option value="Unfit">Unfit</option>
                </x-hp.select>
            </div>

            <div class="w-full sm:w-48">
                <x-hp.select id="results-filter-status" label="Completion status" x-model="progress">
                    <option value="">All</option>
                    @foreach ($progressBadges as $key => [$variant, $label])
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </x-hp.select>
            </div>

            <div class="w-full sm:w-auto sm:flex-1">
                <label for="results-search" class="mb-1 block text-sm font-semibold text-hp-slate">Search</label>
                <input id="results-search" type="search" x-model="search" maxlength="60"
                       placeholder="Name or student ID"
                       class="w-full rounded-lg border border-hp-slate/25 bg-hp-white px-3 py-2 text-sm text-hp-slate
                              placeholder-hp-slate/40 focus:border-hp-orange focus:outline-none focus:ring-1 focus:ring-hp-orange">
            </div>

            @if ($documents !== null)
                {{-- Select students · Clear (once something is ticked) · Select all at the right end. --}}
                <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto"
                     data-document-tools="{{ $toolsEnabled ? 'enabled' : 'disabled' }}">
                    <x-hp.button size="sm" variant="ghost" @click="toggleSelecting()" :disabled="! $toolsEnabled"
                                 x-bind:aria-pressed="selecting.toString()"
                                 x-bind:class="selecting ? '!border-hp-orange !bg-hp-peach !text-hp-orange' : ''"
                                 data-select-students>
                        <span x-text="selecting ? 'Done selecting' : 'Select students'">Select students</span>
                    </x-hp.button>
                    <button type="button" x-show="selected.length > 0" x-cloak @click="clear()"
                            class="px-1 py-1.5 text-xs font-semibold text-hp-slate/60 hover:text-hp-slate hover:underline" data-select-clear>
                        Clear
                    </button>
                    <x-hp.button size="sm" variant="soft" class="sm:ml-auto" @click="selectAll()" :disabled="! $toolsEnabled" data-select-all>
                        Select all
                    </x-hp.button>
                </div>
            @endif
        </div>

        @if ($documents !== null && ! $toolsEnabled && ($documents['hint'] ?? null) !== null)
            <p class="-mt-1 mb-4 text-xs text-hp-slate/50" data-document-tools-hint>{{ $documents['hint'] }}</p>
        @endif

        {{-- ── The table: ten rows tall, then it scrolls (Q13) ──────────────
             Measured in Chrome: the header row is 40.5px and a student row
             (it always holds a badge) 45.75px, so ten rows plus the header
             is 498px = 31.125rem. --}}
        <div class="max-h-[31.125rem] overflow-y-auto" data-results-scroll>
            <x-hp.table :headers="['Name', 'Student ID', 'Completion status', 'Result']" sticky-header>
                <template x-for="(row, index) in filtered" :key="index">
                    <x-hp.table-row>
                        <x-hp.table-cell label="Name" class="font-medium">
                            {{-- Select mode: the checkbox at the row's left end. A
                                 <label> so the name ticks it too. text-hp-orange,
                                 not accent-*: @tailwindcss/forms paints it. --}}
                            <label x-show="selecting" class="inline-flex cursor-pointer items-center gap-3">
                                <input type="checkbox" class="rounded border-hp-slate/30 text-hp-orange focus:ring-hp-orange"
                                       :checked="isSelected(row.visit_id)" @change="toggle(row.visit_id)"
                                       :aria-label="'Select ' + row.name" data-select-row>
                                <span x-text="row.name"></span>
                            </label>
                            <span x-show="! selecting" x-text="row.name"></span>
                        </x-hp.table-cell>

                        <x-hp.table-cell label="Student ID" class="text-hp-slate/60">
                            <span x-text="row.number"></span>
                        </x-hp.table-cell>

                        <x-hp.table-cell label="Completion status">
                            @foreach ($progressBadges as $key => [$variant, $label])
                                <x-hp.badge :variant="$variant" x-show="row.status === '{{ $key }}'">{{ $label }}</x-hp.badge>
                            @endforeach
                        </x-hp.table-cell>

                        <x-hp.table-cell label="Result">
                            <x-hp.badge variant="fit" x-show="row.result === 'Fit'">Fit</x-hp.badge>
                            <x-hp.badge variant="unfit" x-show="row.result === 'Unfit'">Unfit</x-hp.badge>
                            <span x-show="row.result === null" class="text-hp-slate/50">&mdash;</span>
                        </x-hp.table-cell>
                    </x-hp.table-row>
                </template>

                <x-hp.table-row x-show="filtered.length === 0" x-cloak>
                    <td colspan="4" class="block py-6 text-center text-sm text-hp-slate/50 md:table-cell">
                        No students match these filters.
                    </td>
                </x-hp.table-row>
            </x-hp.table>
        </div>

        @if ($documents !== null)
            {{-- ── Download documents, lower right (D-113) ─────────────────
                 A normal POST form, so the browser downloads the ZIP the
                 server sends back and the page stays put. data-no-progress:
                 nothing navigates, so the top progress bar must not start. --}}
            <form method="POST" action="{{ $documents['action'] }}" data-no-progress
                  @submit="startDownload()"
                  class="mt-4 flex flex-wrap items-center justify-end gap-x-4 gap-y-2" data-document-download>
                @csrf
                <template x-for="visitId in selected" :key="visitId">
                    <input type="hidden" name="visits[]" :value="visitId">
                </template>

                <p x-show="overCap" x-cloak class="text-xs text-red-600" data-download-cap>
                    Select up to {{ $maxPerDownload }} students per download.
                </p>
                <p x-show="preparing" x-cloak class="text-xs text-hp-slate/60">
                    Preparing the documents — the download starts by itself.
                </p>

                <x-hp.button type="submit" :disabled="! $toolsEnabled" x-bind:disabled="! canDownload" data-download-documents>
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    <span x-text="preparing ? 'Preparing…' : 'Download documents (' + selected.length + ')'">Download documents (0)</span>
                </x-hp.button>
            </form>
        @endif
    </x-hp.card>
</div>
