<x-layout.sidebar title="New Batch Request">

{{-- ── Page header ────────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <h2 class="text-xl font-semibold text-hp-slate">New Batch Request</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">
        Request medical clearances for a group of {{ $college->code }} students.
    </p>
</div>

@if (session('status'))
    <div data-hp-flash class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
        {{ session('status') }}
    </div>
@endif

{{--
    Alpine component: batchForm()
    Inline <script> so server-side values (roster, old() input) embed directly;
    inline scripts run before app.js (a deferred module), so window.batchForm
    is defined when Alpine.start() walks the DOM — same pattern as book.blade.php.

    Search strategy (FR-ADM-03, "smooth with 100+ rows"): HYBRID.
    The server ships the full — already college-scoped — roster once, and the
    browser filters it per keystroke (zero network latency, and the selection
    survives searches for free). To keep the DOM light on big rosters, at most
    MAX_RENDER_ROWS matching rows are actually rendered; "Select all" operates
    on the DATA (all filtered matches), not on the rendered rows.
--}}
@php
    // D-54: a batch that would double-book students comes back with ONE
    // validation error per clashing student, keyed `clashes.<student_profile_id>`.
    // $errors is the bag Laravel shares with every view after a failed
    // validation; pick those keys out and pair each with its roster row.
    $roster = $students->keyBy('id');

    $clashes = collect($errors->getMessages())
        ->filter(fn (array $messages, string $key): bool => str_starts_with($key, 'clashes.'))
        ->map(function (array $messages, string $key) use ($roster): array {
            $id = (int) substr($key, strlen('clashes.'));

            return [
                'id' => $id,
                'name' => $roster[$id]['name'] ?? 'Student',
                'number' => $roster[$id]['number'] ?? '—',
                'with' => $messages[0],
            ];
        })
        ->values();
@endphp

<script>
function batchForm() {
    const MAX_RENDER_ROWS = 150;

    return {
        // ── Form fields (seeded from old() after a failed validation) ──────
        // D-62: the clinic form comes first; it decides the reason list.
        formType:      @js(old('form_type', '')),
        reasonsByForm: @js(\App\Models\BatchRequest::REASONS_BY_FORM),
        reason:        @js(old('reason', '')),
        reasonDetail:  @js(old('reason_detail', '') ?? ''),
        // D-29: the admin proposes the clinic date (defaults to today).
        requestedDate: @js(old('requested_date', now()->toDateString())),
        // D-37: …and the START hour. The span is computed from the roster size
        // (ceil(students / hourlyCapacity)) and shown back; the SERVER computes
        // it again at submission, so this preview is a convenience only.
        requestedTime: @js(old('requested_time', '')),
        slots:         @js($slots),
        // BR-23: hours that have already ended TODAY, computed on the SERVER
        // clock and shipped with the page. The browser only compares the
        // picked date against `today` — it never decides what "now" is.
        today:              @js($today),
        elapsedSlotsToday:  @js($elapsedSlotsToday),
        hourlyCapacity: {{ $hourlyCapacity }},
        maxBatchSize:   {{ $maxBatchSize }},
        reasonOthers:  @js(\App\Models\BatchRequest::REASON_OTHERS),

        // ── D-54 mini calendar ──────────────────────────────────────────────
        // Same month grid and rules as the student booking calendar. FULL and
        // cutoff days come from the server, and "today" is the SERVER's date
        // shipped above — this browser's clock is never asked (BR-20/BR-23).
        calYear:         {{ $year }},
        calMonth:        {{ $month }},
        fullDays:        @js($fullDays),
        cutoffDays:      @js($cutoffDays),
        bookingDays:     @js($bookingDays),
        calLoading:      false,
        availabilityUrl: @js(route('admin.batches.availability')),

        // ── D-54 clash popup ────────────────────────────────────────────────
        // Students the server refused because they are already scheduled
        // during this span. Non-empty only straight after that refusal, which
        // is exactly when the popup should open.
        clashes:    @js($clashes),
        clashModal: @js($clashes->isNotEmpty()),

        // ── Student picker ──────────────────────────────────────────────────
        students: @js($students),
        selected: @js(array_values(array_map('intval', old('students', [])))),
        query:    '',
        maxRenderRows: MAX_RENDER_ROWS,

        /**
         * After a failed validation the selection is re-seeded from old()
         * input; keep only ids that exist in the roster so a rejected
         * (tampered) id can never linger as an invisible phantom selection.
         */
        init() {
            this.selected = this.selected.filter(id => this.students.some(s => s.id === id));

            // D-54: a date restored after a failed submission may sit in
            // another month — open the calendar there.
            if (/^\d{4}-\d{2}-\d{2}$/.test(this.requestedDate)) {
                const [y, m] = this.requestedDate.split('-').map(Number);

                if (y !== this.calYear || m !== this.calMonth) {
                    this.calYear  = y;
                    this.calMonth = m;
                    this.fetchAvailability();
                }
            }

            // The default pick is today, but today may already be unselectable
            // (full, or past closing) — don't start on a greyed-out day.
            if (this.requestedDate === this.today
                && this.calendarCells.some(c => c.dateStr === this.today && c.isDisabled)) {
                this.requestedDate = '';
            }
        },

        get filtered() {
            const q = this.query.trim().toLowerCase();
            if (!q) return this.students;
            return this.students.filter(s => s.search.includes(q));
        },

        /** The rows actually put in the DOM — capped so the table stays smooth. */
        get shown() {
            return this.filtered.slice(0, this.maxRenderRows);
        },

        get hiddenMatchCount() {
            return Math.max(0, this.filtered.length - this.maxRenderRows);
        },

        isSelected(id) {
            return this.selected.includes(id);
        },

        toggle(id) {
            this.selected = this.isSelected(id)
                ? this.selected.filter(x => x !== id)
                : [...this.selected, id];
        },

        /** Selects every student matching the current search (all M when blank). */
        selectAllFiltered() {
            this.selected = [...new Set([...this.selected, ...this.filtered.map(s => s.id)])];
        },

        clearSelection() {
            this.selected = [];
        },

        // ── D-62 form chooser ───────────────────────────────────────────────
        /** The chosen form's reasons (key → printed label), or none yet. */
        get reasonsForForm() {
            return this.reasonsByForm[this.formType] ?? {};
        },

        /** A reason from the other form is never valid — start the pick over. */
        onFormChange() {
            this.reason = '';
            this.reasonDetail = '';
        },

        // ── Submit gating (BR-06/07 — the server re-checks all of this) ────
        get reasonReady() {
            if (!this.formType || !this.reason) return false;
            return this.reason !== this.reasonOthers || this.reasonDetail.trim() !== '';
        },

        // ── D-37 span preview ───────────────────────────────────────────────
        /** BR-23: an hour that has already ended, and only ever on today. */
        isSlotElapsed(slot) {
            return this.requestedDate === this.today
                && this.elapsedSlotsToday.includes(slot);
        },

        /**
         * Switching TO today can strand a start hour that has already gone by —
         * clear it rather than leaving an invalid pick in the select.
         */
        onDateChange() {
            if (this.requestedTime && this.isSlotElapsed(this.requestedTime)) {
                this.requestedTime = '';
            }
        },

        // ── D-54 mini calendar ──────────────────────────────────────────────
        /** "September 2026" */
        get monthLabel() {
            return new Date(this.calYear, this.calMonth - 1, 1)
                .toLocaleString('en-US', { month: 'long', year: 'numeric' });
        },

        /** "Thu, Sep 10, 2026" — built from the date's parts, so no timezone shift. */
        get requestedDateLabel() {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(this.requestedDate)) return '';
            const [y, m, d] = this.requestedDate.split('-').map(Number);
            return new Date(y, m - 1, d).toLocaleDateString('en-US', {
                weekday: 'short', month: 'short', day: 'numeric', year: 'numeric',
            });
        },

        /** The server's current month is the earliest one worth showing. */
        get canGoBack() {
            const [y, m] = this.today.split('-').map(Number);
            return this.calYear > y || (this.calYear === y && this.calMonth > m);
        },

        /**
         * One cell per day, padded to the right weekday column. A day is
         * disabled when it is past, FULL, today after closing (cutoff), or not
         * a booking weekday — the student calendar's list, exactly.
         */
        get calendarCells() {
            const firstDow    = new Date(this.calYear, this.calMonth - 1, 1).getDay();
            const daysInMonth = new Date(this.calYear, this.calMonth, 0).getDate();
            const mm          = String(this.calMonth).padStart(2, '0');
            const cells       = [];

            for (let i = 0; i < firstDow; i++) {
                cells.push({ blank: true, key: 'b' + i });
            }

            for (let d = 1; d <= daysInMonth; d++) {
                const dateStr  = `${this.calYear}-${mm}-${String(d).padStart(2, '0')}`;
                const weekday  = new Date(this.calYear, this.calMonth - 1, d).getDay();
                const isCutoff = this.cutoffDays.includes(d);
                const isFull   = this.fullDays.includes(d) && !isCutoff;

                cells.push({
                    blank: false, key: dateStr, d, dateStr, isFull,
                    isToday: dateStr === this.today,
                    // 'YYYY-MM-DD' strings sort as dates, so < means "before".
                    isDisabled: dateStr < this.today || !this.bookingDays.includes(weekday)
                        || isFull || isCutoff,
                });
            }

            return cells;
        },

        prevMonth() {
            if (!this.canGoBack) return;
            if (this.calMonth === 1) { this.calYear--; this.calMonth = 12; } else { this.calMonth--; }
            this.fetchAvailability();
        },

        nextMonth() {
            if (this.calMonth === 12) { this.calYear++; this.calMonth = 1; } else { this.calMonth++; }
            this.fetchAvailability();
        },

        /** A picked day stays picked while the admin browses other months. */
        pickDate(cell) {
            if (cell.isDisabled) return;
            this.requestedDate = cell.dateStr;
            this.onDateChange();
        },

        /** The month on screen's full + cutoff days (GET /admin/batches/availability). */
        async fetchAvailability() {
            const year = this.calYear;
            const month = this.calMonth;
            this.calLoading = true;

            try {
                const response = await fetch(`${this.availabilityUrl}?year=${year}&month=${month}`, {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await response.json();

                // Ignore a stale answer if the admin has already moved on.
                if (year !== this.calYear || month !== this.calMonth) return;

                this.fullDays   = data.full_days;
                this.cutoffDays = data.cutoff_days;
            } catch {
                // Don't leave the previous month's days greyed out on this one —
                // the server still refuses an unavailable date on submit.
                this.fullDays   = [];
                this.cutoffDays = [];
            } finally {
                if (year === this.calYear && month === this.calMonth) {
                    this.calLoading = false;
                }
            }
        },

        // ── D-54 clash popup ────────────────────────────────────────────────
        /** "Remove these students from the batch": deselect exactly them, then close. */
        removeClashingStudents() {
            const clashing = this.clashes.map(c => c.id);
            this.selected = this.selected.filter(id => !clashing.includes(id));
            this.clashModal = false;
        },

        /** How many contiguous hours this cohort needs. */
        get blocksNeeded() {
            return Math.ceil(this.selected.length / this.hourlyCapacity);
        },

        /** The span's slots, or [] when it doesn't fit the clinic day. */
        get spanSlots() {
            const start = this.slots.findIndex(s => s.value === this.requestedTime);
            if (start === -1 || this.blocksNeeded < 1) return [];
            if (start + this.blocksNeeded > this.slots.length) return [];
            return this.slots.slice(start, start + this.blocksNeeded);
        },

        get tooManyStudents() {
            return this.selected.length > this.maxBatchSize;
        },

        get spanOverflows() {
            return !this.tooManyStudents && this.requestedTime !== ''
                && this.selected.length > 0 && this.spanSlots.length === 0;
        },

        /** "30 students → 7:00 AM – 10:00 AM (3 slots)" */
        get spanSummary() {
            if (this.spanSlots.length === 0) return '';
            const first = this.spanSlots[0].label.split(' – ')[0];
            const last  = this.spanSlots[this.spanSlots.length - 1].label.split(' – ')[1];
            const count = this.spanSlots.length;
            return `${this.selected.length} student${this.selected.length === 1 ? '' : 's'} → `
                 + `${first} – ${last} (${count} slot${count === 1 ? '' : 's'})`;
        },

        get canSubmit() {
            return this.reasonReady
                && this.requestedDate !== ''
                && this.requestedTime !== ''
                && !this.isSlotElapsed(this.requestedTime)
                && this.selected.length > 0
                && !this.tooManyStudents
                && !this.spanOverflows;
        },
    };
}
</script>

<form method="POST" action="{{ route('admin.batches.store') }}" x-data="batchForm()">
    @csrf

    {{-- ── D-62: the form the clinic will use ───────────────────────────────
         Two radio buttons styled as tiles (the input is visually hidden but
         still focusable, so arrow keys / Space pick a tile). The choice drives
         the Reason list below and, later, the kiosk questions, the encode
         fields and the printed document. --}}
    @php
        // Each tile shows the REAL form's front page, blank, in an iframe
        // (admin.batches.form-preview). The iframe is laid out at the paper's
        // natural width — both papers are 8.5in = 816px at 96dpi — and scaled
        // down to the tile, so the fixed table layout keeps its shape and its
        // type stays crisp instead of reflowing. pageHeight is the paper's
        // length at 96dpi: Legal 14in, Letter 11in.
        $paperWidthPx = 816;
        $formTiles = [
            'assessment' => [
                'pageHeight' => 1344,
                'copy' => "A full health assessment: medical and family history, immunizations and a physician's physical examination. Two pages, printed back-to-back on long bond paper. Best for On-the-job Training, Related Learning Experience, Sports Activities and Off-campus Procedures.",
            ],
            'clearance' => [
                'pageHeight' => 1056,
                'copy' => "A one-page fitness clearance from the student's vital signs and a short health check. Best for Field Trips / Educational Tours, Outbound Activities and other short events.",
            ],
        ];
    @endphp
    <x-hp.card class="mb-6">
        <fieldset>
            <legend class="text-sm font-semibold text-hp-slate">Choose the form the clinic will use</legend>
            <p class="mt-0.5 text-xs text-hp-slate/50">Every student in this batch will be examined on this form.</p>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach ($formTiles as $value => $tile)
                    <label class="block cursor-pointer" data-form-tile="{{ $value }}">
                        <input type="radio" name="form_type" value="{{ $value }}"
                               x-model="formType" @change="onFormChange()" class="peer sr-only">
                        <div class="relative h-full rounded-xl border-2 border-hp-slate/15 p-4 transition-colors
                                    hover:border-hp-orange/50
                                    peer-checked:border-hp-orange peer-checked:bg-hp-peach/20
                                    peer-focus-visible:ring-2 peer-focus-visible:ring-hp-orange peer-focus-visible:ring-offset-2">
                            {{-- The chosen tile's tick sits ON the tile's corner,
                                 clear of the paper, so it never covers the form. --}}
                            <span x-show="formType === @js($value)" x-cloak
                                  class="absolute -right-2.5 -top-2.5 z-10 inline-flex ring-2 ring-white h-6 w-6 items-center justify-center rounded-full bg-hp-orange text-white">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </span>
                            {{-- The paper: the top of the form, cropped to the frame.
                                 `fit` is the iframe's scale-to-tile, measured
                                 by Alpine whenever the tile resizes. --}}
                            <div class="h-80 rounded-lg bg-hp-bg p-3 sm:h-[26rem]">
                                <div x-data="{ fit: 0.4 }"
                                     x-init="new ResizeObserver(() => fit = $el.clientWidth / {{ $paperWidthPx }}).observe($el)"
                                     class="relative h-full w-full overflow-hidden bg-white shadow-sm">
                                    <span class="sr-only">Preview of the blank {{ \App\Models\BatchRequest::FORM_TYPES[$value] }}, the official form the clinic prints.</span>
                                    {{-- pointer-events: none — a click on the paper
                                         must fall through to the <label> and pick
                                         the radio. Not focusable, hidden from
                                         screen readers: it is a picture. --}}
                                    <iframe src="{{ route('admin.batches.form-preview', $value) }}"
                                            title="{{ \App\Models\BatchRequest::FORM_TYPES[$value] }} preview"
                                            loading="lazy" tabindex="-1" aria-hidden="true" scrolling="no"
                                            class="absolute left-1/2 top-0 max-w-none border-0"
                                            style="pointer-events: none; width: {{ $paperWidthPx }}px; height: {{ $tile['pageHeight'] }}px;
                                                   margin-left: -{{ $paperWidthPx / 2 }}px; transform-origin: top center;"
                                            :style="{ transform: `scale(${fit})` }"></iframe>
                                </div>
                            </div>
                            <p class="mt-3 text-sm font-semibold text-hp-slate">{{ \App\Models\BatchRequest::FORM_TYPES[$value] }}</p>
                            <p class="mt-1 text-xs leading-relaxed text-hp-slate/60">{{ $tile['copy'] }}</p>
                        </div>
                    </label>
                @endforeach
            </div>
            @error('form_type')
                <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </fieldset>
    </x-hp.card>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">

        {{-- ── Left column: request details ─────────────────────────────── --}}
        <div class="space-y-6 xl:col-span-1">

            <x-hp.card>
                <h3 class="mb-4 text-sm font-semibold text-hp-slate">Request Details</h3>

                <div class="space-y-4">
                    {{-- Reason (FR-ADM-02 / BR-06) — D-62: the chosen form's
                         purposes, so it stays disabled until a form is picked.
                         `disabled` is rendered server-side too, so the page
                         never flashes an enabled, empty select. :selected keeps
                         an old() reason picked once x-for builds the options. --}}
                    <div>
                        <x-hp.select label="Reason" name="reason" x-model="reason"
                                     :disabled="! old('form_type')" x-bind:disabled="!formType">
                            <option value="" x-text="formType ? '— Select a reason —' : '— Choose a form first —'">— Choose a form first —</option>
                            <template x-for="(label, key) in reasonsForForm" :key="key">
                                <option :value="key" x-text="label" :selected="key === reason"></option>
                            </template>
                        </x-hp.select>
                        @error('reason')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- "Please specify" — required iff reason = others (BR-06) --}}
                    <div x-show="reason === reasonOthers" x-cloak>
                        <x-hp.textarea label="Please specify" name="reason_detail"
                                       x-model="reasonDetail" rows="3" maxlength="120"
                                       placeholder="Describe the reason for this batch request" />
                        @error('reason_detail')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Requested clinic date (D-29) — a mini calendar since
                         D-54, with the student booking calendar's rules: past
                         days, FULL days, non-booking weekdays and an unavailable
                         today (after closing, or once no hour is left) cannot be
                         picked. It still submits as `requested_date` and the
                         server re-checks it. --}}
                    <div>
                        <span class="text-sm font-semibold text-hp-slate">Requested clinic date</span>
                        <input type="hidden" name="requested_date" :value="requestedDate">

                        <div class="mt-1.5 rounded-lg border border-hp-slate/15 p-3">
                            {{-- Month navigation --}}
                            <div class="mb-2 flex items-center justify-between">
                                <button type="button" @click="prevMonth()" :disabled="!canGoBack"
                                        aria-label="Previous month"
                                        class="flex h-7 w-7 items-center justify-center rounded-lg transition-colors"
                                        :class="canGoBack
                                            ? 'text-hp-slate hover:bg-hp-bg hover:text-hp-orange'
                                            : 'cursor-not-allowed text-hp-slate/20'">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                                    </svg>
                                </button>

                                <span class="text-xs font-semibold text-hp-slate" x-text="monthLabel"></span>

                                <button type="button" @click="nextMonth()" aria-label="Next month"
                                        class="flex h-7 w-7 items-center justify-center rounded-lg text-hp-slate transition-colors hover:bg-hp-bg hover:text-hp-orange">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </button>
                            </div>

                            {{-- Grid (relative, so the loading overlay can cover it) --}}
                            <div class="relative">
                                <div x-show="calLoading" x-cloak
                                     class="absolute inset-0 z-10 flex items-center justify-center rounded-lg bg-hp-white/70 backdrop-blur-[1px]">
                                    <svg class="h-4 w-4 animate-spin text-hp-orange" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                    </svg>
                                </div>

                                <div class="grid grid-cols-7">
                                    @foreach (['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'] as $weekday)
                                        <div class="flex h-6 items-center justify-center text-[10px] font-semibold uppercase tracking-wider text-hp-slate/35">
                                            {{ $weekday }}
                                        </div>
                                    @endforeach
                                </div>

                                <div class="grid grid-cols-7 gap-y-0.5">
                                    <template x-for="cell in calendarCells" :key="cell.key">
                                        <div class="flex items-center justify-center">
                                            <button x-show="!cell.blank" type="button"
                                                :disabled="cell.isDisabled"
                                                @click="pickDate(cell)"
                                                class="flex h-8 w-8 flex-col items-center justify-center rounded-full text-xs
                                                       transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-hp-orange"
                                                :class="{
                                                    'bg-hp-orange text-white font-semibold shadow-sm ring-2 ring-hp-orange/25 hp-anim-pop':
                                                        !cell.blank && requestedDate === cell.dateStr,
                                                    'ring-2 ring-hp-orange font-semibold':
                                                        !cell.blank && cell.isToday && !cell.isDisabled && requestedDate !== cell.dateStr,
                                                    'bg-hp-bg text-hp-slate/40 cursor-default':
                                                        !cell.blank && cell.isFull,
                                                    'text-hp-slate/25 cursor-not-allowed':
                                                        !cell.blank && cell.isDisabled && !cell.isFull,
                                                    'text-hp-slate hover:bg-hp-peach/50 hover:text-hp-orange cursor-pointer':
                                                        !cell.blank && !cell.isDisabled && requestedDate !== cell.dateStr,
                                                }">
                                                <span x-text="cell.d" class="leading-none"></span>
                                                <span x-show="cell.isFull"
                                                      class="mt-px block text-[6px] font-bold uppercase leading-none tracking-wide">
                                                    Full
                                                </span>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            {{-- Legend — the student calendar's, compacted --}}
                            <div class="mt-3 flex flex-wrap gap-x-3 gap-y-1.5 border-t border-hp-slate/10 pt-2.5">
                                <span class="flex items-center gap-1 text-[11px] text-hp-slate/50">
                                    <span class="h-2.5 w-2.5 rounded-full bg-hp-orange"></span> Selected
                                </span>
                                <span class="flex items-center gap-1 text-[11px] text-hp-slate/50">
                                    <span class="h-2.5 w-2.5 rounded-full border-2 border-hp-orange bg-hp-white"></span> Today
                                </span>
                                <span class="flex items-center gap-1 text-[11px] text-hp-slate/50">
                                    <span class="h-2.5 w-2.5 rounded-full border border-hp-slate/20 bg-hp-bg"></span> Full
                                </span>
                                <span class="flex items-center gap-1 text-[11px] text-hp-slate/50">
                                    <span class="h-2.5 w-2.5 rounded-full border border-hp-slate/20 bg-hp-white"></span> Available
                                </span>
                                <span class="flex items-center gap-1 text-[11px] text-hp-slate/50">
                                    <span class="h-2.5 w-2.5 rounded-full border border-hp-slate/10 bg-transparent opacity-40"></span> Unavailable
                                </span>
                            </div>
                        </div>

                        <p x-show="requestedDate" x-cloak class="mt-1.5 text-xs text-hp-slate/70">
                            Selected: <span class="font-semibold text-hp-slate" x-text="requestedDateLabel"></span>
                        </p>
                        <p class="mt-1 text-xs text-hp-slate/50">
                            When should these students visit the clinic? The
                            Director confirms this date or rejects with a reason
                            — they cannot change it (D-36).
                        </p>
                        @error('requested_date')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Start hour (D-37). The clinic day is ten one-hour slots
                         of {{ $hourlyCapacity }} students; the batch occupies as
                         many contiguous hours as its roster needs. --}}
                    <div>
                        {{-- BR-23: an hour that has already ended today is
                             disabled here and refused server-side. --}}
                        <x-hp.select label="Start time" name="requested_time" x-model="requestedTime">
                            <option value="">— Select a start hour —</option>
                            @foreach ($slots as $slot)
                                <option value="{{ $slot['value'] }}"
                                        :disabled="isSlotElapsed(@js($slot['value']))"
                                        x-text="isSlotElapsed(@js($slot['value']))
                                            ? '{{ $slot['label'] }} — already passed'
                                            : '{{ $slot['label'] }}'">{{ $slot['label'] }}</option>
                            @endforeach
                        </x-hp.select>

                        <p x-show="requestedTime !== '' && isSlotElapsed(requestedTime)" x-cloak
                           class="mt-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">
                            That hour has already passed today. Please choose a later start hour.
                        </p>

                        {{-- Live span preview --}}
                        <p x-show="spanSlots.length > 0 && !isSlotElapsed(requestedTime)" x-cloak
                           class="mt-2 rounded-lg bg-hp-peach/40 px-3 py-2 text-xs font-semibold text-hp-orange"
                           x-text="spanSummary"></p>

                        <p x-show="tooManyStudents" x-cloak
                           class="mt-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">
                            <span x-text="selected.length"></span> students cannot fit in one clinic day
                            (max {{ $maxBatchSize }}). Please split this batch across two dates.
                        </p>

                        <p x-show="spanOverflows" x-cloak
                           class="mt-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">
                            <span x-text="selected.length"></span> students need
                            <span x-text="blocksNeeded"></span> clinic hour(s) — starting then would run
                            past closing time. Please choose an earlier start hour.
                        </p>

                        @error('requested_time')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </x-hp.card>

            {{-- Submit — disabled until reason valid + ≥1 student (FR-ADM-03) --}}
            <x-hp.card>
                <x-hp.button type="submit" class="w-full" x-bind:disabled="!canSubmit" data-pending-label="Submitting…">
                    Submit Batch Request
                </x-hp.button>
                <p class="mt-2 text-center text-xs text-hp-slate/50"
                   x-show="!canSubmit" x-cloak>
                    Choose a reason, a date, a start hour and at least one student to submit.
                </p>
            </x-hp.card>

        </div>

        {{-- ── Right column: student multi-select (FR-ADM-03 / BR-07) ───── --}}
        <div class="xl:col-span-2">
            <x-hp.card>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-hp-slate">
                        Select Students
                        <span class="ml-1 font-normal text-hp-slate/50">
                            (<span x-text="selected.length"></span> of {{ $students->count() }} selected)
                        </span>
                    </h3>
                    <div class="flex gap-2">
                        <x-hp.button variant="soft" size="sm" @click="selectAllFiltered()">
                            Select All
                        </x-hp.button>
                        <x-hp.button variant="ghost" size="sm" @click="clearSelection()">
                            Clear
                        </x-hp.button>
                    </div>
                </div>

                @error('students')
                    <p class="mb-3 text-xs text-red-600">{{ $message }}</p>
                @enderror
                @error('students.*')
                    <p class="mb-3 text-xs text-red-600">{{ $message }}</p>
                @enderror

                @if ($students->isEmpty())
                    <div class="flex flex-col items-center py-10 text-center">
                        <p class="text-sm font-medium text-hp-slate">No students registered in {{ $college->code }} yet</p>
                        <p class="mt-0.5 text-xs text-hp-slate/50">
                            Students appear here once they register and are assigned to your college.
                        </p>
                    </div>
                @else
                    {{-- Live search by name or student number --}}
                    <div class="relative mb-3">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-hp-slate/40"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input type="search" x-model="query"
                               placeholder="Search by name or student number…"
                               class="w-full rounded-lg border border-hp-slate/25 py-2 pl-9 pr-3 text-sm text-hp-slate
                                      placeholder-hp-slate/40 transition-colors duration-hp-fast
                                      focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none">
                    </div>

                    <div class="max-h-[30rem] overflow-y-auto rounded-lg border border-hp-slate/10">
                        <table class="w-full text-left text-sm">
                            <thead class="sticky top-0 bg-hp-white">
                                <tr class="border-b border-hp-slate/10 text-[11px] uppercase tracking-widest text-hp-slate/40">
                                    <th class="w-10 py-2.5 pl-4"></th>
                                    <th class="py-2.5 pr-4 font-semibold">Student No.</th>
                                    <th class="py-2.5 pr-4 font-semibold">Name</th>
                                    <th class="py-2.5 pr-4 font-semibold">Course</th>
                                    <th class="py-2.5 pr-4 font-semibold">Year</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-hp-slate/10">
                                <template x-for="s in shown" :key="s.id">
                                    {{-- Whole row toggles; selected rows peach-highlighted --}}
                                    <tr class="cursor-pointer text-hp-slate transition-colors"
                                        :class="isSelected(s.id) ? 'bg-hp-peach/50' : 'hover:bg-hp-slate/5'"
                                        @click="toggle(s.id)">
                                        <td class="py-2.5 pl-4">
                                            {{-- text-hp-orange, not accent-*: the @tailwindcss/forms
                                                 plugin repaints checked boxes with currentColor --}}
                                            <input type="checkbox" tabindex="-1"
                                                   class="pointer-events-none h-4 w-4 rounded text-hp-orange"
                                                   :checked="isSelected(s.id)">
                                        </td>
                                        <td class="py-2.5 pr-4 font-medium" x-text="s.number"></td>
                                        <td class="py-2.5 pr-4" x-text="s.name"></td>
                                        <td class="py-2.5 pr-4 text-hp-slate/70" x-text="s.course"></td>
                                        <td class="py-2.5 pr-4 text-hp-slate/70" x-text="s.year"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>

                        <p class="px-4 py-3 text-center text-xs text-hp-slate/50"
                           x-show="filtered.length === 0" x-cloak>
                            No students match "<span x-text="query"></span>".
                        </p>
                        {{-- Render cap note — Select All still covers ALL matches --}}
                        <p class="border-t border-hp-slate/10 px-4 py-2.5 text-center text-xs text-hp-slate/50"
                           x-show="hiddenMatchCount > 0" x-cloak>
                            Showing the first <span x-text="maxRenderRows"></span> of
                            <span x-text="filtered.length"></span> matches — refine your search.
                            "Select All" still selects every match.
                        </p>
                    </div>
                @endif

                {{-- The actual submitted value: one hidden input per selected id --}}
                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="students[]" :value="id">
                </template>
            </x-hp.card>
        </div>

    </div>

    {{-- ── Clash popup (FR-ADM-04, D-54) ────────────────────────────────────
         Opens on page load when the server refused this batch because some
         students are already scheduled during its span. Same dialog shape as
         the modals on Batch Tracking: teleported to <body> so no ancestor's
         overflow or transform clips it, and dismissable with Esc, a backdrop
         click or Close — all of which KEEP the selection, so the admin can
         change the date or start hour instead. The list is rendered by Blade
         from the validation errors, escaped like any other output. --}}
    <template x-teleport="body">
        <div
            x-show="clashModal"
            x-cloak
            @keydown.escape.window="clashModal = false"
            class="fixed inset-0 z-[60] flex items-center justify-center px-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="batch-clash-title"
        >
            {{-- Backdrop — click outside to dismiss --}}
            <div
                x-show="clashModal"
                @click="clashModal = false"
                x-transition:enter="ease-hp-out duration-hp-base"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-hp-in duration-hp-fast"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 bg-hp-slate/50"
                aria-hidden="true"
            ></div>

            {{-- Panel --}}
            <div
                x-show="clashModal"
                x-transition:enter="ease-hp-spring duration-hp-slow"
                x-transition:enter-start="opacity-0 translate-y-6"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="ease-hp-in duration-hp-base"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-6"
                class="relative w-full max-w-lg rounded-2xl bg-hp-white p-6 shadow-xl"
            >
                <h2 id="batch-clash-title" class="text-lg font-semibold text-hp-slate">
                    Some students are already scheduled at this time
                </h2>

                <p class="mt-1.5 text-sm text-hp-slate/70">
                    A student can't be booked twice in the same hour. Remove them from
                    this batch, or close this and choose a different date or start hour.
                </p>

                <ul class="mt-4 max-h-72 divide-y divide-hp-slate/10 overflow-y-auto rounded-lg border border-hp-slate/10">
                    @foreach ($clashes as $clash)
                        <li class="px-4 py-2.5">
                            <p class="text-sm font-medium text-hp-slate">
                                {{ $clash['name'] }}
                                <span class="font-normal text-hp-slate/50">· {{ $clash['number'] }}</span>
                            </p>
                            <p class="mt-0.5 text-xs text-hp-slate/70">{{ $clash['with'] }}</p>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <x-hp.button variant="muted" @click="clashModal = false">Close</x-hp.button>
                    <x-hp.button @click="removeClashingStudents()">Remove these students from the batch</x-hp.button>
                </div>
            </div>
        </div>
    </template>
</form>

</x-layout.sidebar>
