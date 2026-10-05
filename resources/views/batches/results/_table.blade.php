{{--
    D-111: the results page's main card — three filters, then every student on
    the batch in one table.

    $rows is BatchResults::page()['rows']: name, student number, status key and
    Fit / Unfit per student (OUTCOME ONLY, PRD §6.6), already in name order.

    The whole roster is on the page, so filtering happens in the browser: one
    small Alpine component holds the rows (embedded with Js::from(), which
    escapes quotes, so a name like O'Brien can't break it) and the three filter
    values, and its `filtered` getter is what the table loops over. Every value
    is written with x-text, which sets textContent — a name is never parsed as
    markup.

    Q13: the table is ten rows tall and then scrolls INSIDE the card, with its
    header pinned — no pager. That is this table's exception to FR-UI-06.
    Below `md` the shared <x-hp.table> re-flows each student into a small card
    (never a sideways-scrolling table), and the same box scrolls those.
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
@endphp

<div x-data="{
        rows: {{ Illuminate\Support\Js::from($rows) }},
        result: '',
        progress: '',
        search: '',
        get filtered() {
            const term = this.search.trim().toLowerCase();

            return this.rows.filter((row) =>
                (this.result === '' || row.result === this.result)
                && (this.progress === '' || row.status === this.progress)
                && (term === '' || (row.name + ' ' + row.number).toLowerCase().includes(term)));
        },
    }">
    <x-hp.card>
        <p class="mb-4 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
            Students <span class="ml-1 normal-case tracking-normal text-hp-slate/50">({{ count($rows) }})</span>
        </p>

        {{-- ── Filters: they wrap onto their own lines on a phone ─────────── --}}
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
        </div>

        {{-- ── The table: ten rows tall, then it scrolls (Q13) ──────────────
             Measured in Chrome: the header row is 40.5px and a student row
             (it always holds a badge) 45.75px, so ten rows plus the header
             is 498px = 31.125rem. --}}
        <div class="max-h-[31.125rem] overflow-y-auto" data-results-scroll>
            <x-hp.table :headers="['Name', 'Student ID', 'Completion status', 'Result']" sticky-header>
                <template x-for="(row, index) in filtered" :key="index">
                    <x-hp.table-row>
                        <x-hp.table-cell label="Name" class="font-medium">
                            <span x-text="row.name"></span>
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
    </x-hp.card>
</div>
