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
        // D-108: no clinic date and no start hour here — the Director picks
        // both. The largest batch one clinic day can hold, from config.
        maxBatchSize:   {{ $maxBatchSize }},
        // BR-07 (D-105): the Form Request's minimum, shipped from the server.
        minStudents:    {{ $minStudents }},
        reasonOthers:  @js(\App\Models\BatchRequest::REASON_OTHERS),
        // D-109: set by the memo card's file-capture-change event. Always
        // false on load — a browser never refills a file input, even after a
        // failed submit.
        memoAttached:  false,

        // ── Student picker ──────────────────────────────────────────────────
        students: @js($students),
        selected: @js(array_values(array_map('intval', old('students', [])))),
        query:    '',
        // FR-ADM-03: browser-only Program filter ('' = All programs). Never
        // posted — it only narrows what is shown, never what is selected.
        program:  '',
        maxRenderRows: MAX_RENDER_ROWS,

        /**
         * After a failed validation the selection is re-seeded from old()
         * input; keep only ids that exist in the roster so a rejected
         * (tampered) id can never linger as an invisible phantom selection.
         */
        init() {
            this.selected = this.selected.filter(id => this.students.some(s => s.id === id));
        },

        /** Students matching BOTH the chosen program (if any) and the search. */
        get filtered() {
            const q = this.query.trim().toLowerCase();
            return this.students.filter(s =>
                (this.program === '' || s.course === this.program)
                && (q === '' || s.search.includes(q)));
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

        /** Selects every student matching the current program + search (all M when both blank). */
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

        get tooManyStudents() {
            return this.selected.length > this.maxBatchSize;
        },

        /** Some students picked, but fewer than a batch needs (D-105). */
        get tooFewStudents() {
            return this.selected.length > 0 && this.selected.length < this.minStudents;
        },

        get canSubmit() {
            return this.reasonReady
                && this.memoAttached
                && this.selected.length >= this.minStudents
                && !this.tooManyStudents;
        },
    };
}
</script>

{{-- multipart: the form carries a file, the signed memo (D-109). --}}
<form method="POST" action="{{ route('admin.batches.store') }}" enctype="multipart/form-data"
      x-data="batchForm()" @file-capture-change="memoAttached = $event.detail.attached">
    @csrf

    {{-- ── D-62: the form the clinic will use ───────────────────────────────
         Two radio buttons styled as tiles (the input is visually hidden but
         still focusable, so arrow keys / Space pick a tile). The choice drives
         the Purpose list below and, later, the kiosk questions, the encode
         fields and the printed document. --}}
    @php
        // Each tile shows the REAL form's front page, blank, in an iframe
        // (admin.batches.form-preview). The iframe is laid out at the paper's
        // natural width — both papers are 8.5in = 816px at 96dpi — and scaled
        // down to the tile, so the fixed table layout keeps its shape and its
        // type stays crisp instead of reflowing. pageHeight is the paper's
        // length at 96dpi: Letter 11in for both forms since D-83.
        $paperWidthPx = 816;
        $formTiles = [
            'assessment' => [
                'pageHeight' => 1056,
                'copy' => "A full health assessment: medical and family history, immunizations and a physician's physical examination. Two pages, printed back-to-back on short bond (Letter) paper. Best for On-the-job Training, Related Learning Experience, Sports Activities and Off-campus Procedures.",
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
                    {{-- Purpose (FR-ADM-02 / BR-06; the `reason` field, labelled
                         "Purpose" since D-105) — D-62: the chosen form's
                         purposes, so it stays disabled until a form is picked.
                         `disabled` is rendered server-side too, so the page
                         never flashes an enabled, empty select. :selected keeps
                         an old() reason picked once x-for builds the options. --}}
                    <div>
                        <x-hp.select label="Purpose" name="reason" x-model="reason"
                                     :disabled="! old('form_type')" x-bind:disabled="!formType">
                            <option value="" x-text="formType ? '— Select a purpose —' : '— Choose a form first —'">— Choose a form first —</option>
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
                                       placeholder="Describe the purpose of this batch request" />
                        @error('reason_detail')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </x-hp.card>

            {{-- D-109: the signed request memo, where the date card used to be
                 (D-108). Required; the Director reads it before scheduling. --}}
            <x-hp.card>
                <h3 class="text-sm font-semibold text-hp-slate">Attach signed memo</h3>
                <p class="mb-4 mt-0.5 text-xs text-hp-slate/50">
                    A photo or PDF of the signed request memo. The Director reads it before scheduling.
                </p>
                <x-hp.file-capture name="memo" noun="memo" />
            </x-hp.card>

            {{-- Submit — disabled until purpose valid + memo + ≥ minStudents students (FR-ADM-03, D-105, D-109) --}}
            <x-hp.card>
                <x-hp.button type="submit" class="w-full" x-bind:disabled="!canSubmit" data-pending-label="Submitting…">
                    Submit Batch Request
                </x-hp.button>
                {{-- D-108: the college no longer picks a date or an hour. --}}
                <p class="mt-2 text-center text-xs text-hp-slate/50" x-show="canSubmit" x-cloak>
                    The Clinic Director picks the clinic date and hours, and each student
                    is emailed their schedule once the batch is approved.
                </p>
                <p class="mt-2 text-center text-xs text-hp-slate/50"
                   x-show="!canSubmit" x-cloak>
                    Choose a purpose, attach the signed memo and pick at least {{ $minStudents }} students to submit.
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

                {{-- BR-07 (D-105): one student is not a batch — say so beside
                     the counter instead of leaving Submit greyed out unexplained. --}}
                <p x-show="tooFewStudents" x-cloak class="-mt-2 mb-3 text-xs text-hp-slate/50">
                    Select at least {{ $minStudents }} students.
                </p>

                {{-- One clinic day holds at most this many (hourly cap × hours). --}}
                <p x-show="tooManyStudents" x-cloak
                   class="mb-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">
                    <span x-text="selected.length"></span> students cannot fit in one clinic day
                    (max {{ $maxBatchSize }}). Please split them into two batches.
                </p>

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
                    {{-- Filter bar, styled like the Clinic Dashboard's (FR-ADM-03):
                         Program on the left, live search taking the rest. Both
                         filter in the browser only — no name, so nothing posts. --}}
                    <div class="mb-3 flex flex-wrap items-end gap-3">
                        <div class="flex min-w-0 max-w-full flex-col gap-1">
                            <label for="batch-program" class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Program</label>
                            <select id="batch-program" x-model="program"
                                    class="max-w-full truncate rounded-lg border-hp-slate/20 bg-hp-white py-1.5 pl-3 pr-8 text-xs font-medium text-hp-slate focus:border-hp-orange focus:ring-hp-orange">
                                <option value="">All programs</option>
                                @foreach ($programs as $program)
                                    <option value="{{ $program }}">{{ $program }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="flex min-w-[200px] flex-1 flex-col gap-1">
                            <label for="batch-search" class="text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">Search</label>
                            <div class="relative">
                                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-hp-slate/40"
                                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                                <input id="batch-search" type="search" x-model="query"
                                       placeholder="Search by name or student number…"
                                       class="w-full rounded-lg border border-hp-slate/25 py-2 pl-9 pr-3 text-sm text-hp-slate
                                              placeholder-hp-slate/40 transition-colors duration-hp-fast
                                              focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none">
                            </div>
                        </div>
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
                                        <td class="py-2.5 pr-4">
                                            <span x-text="s.name"></span>
                                        </td>
                                        <td class="py-2.5 pr-4 text-hp-slate/70" x-text="s.course"></td>
                                        <td class="py-2.5 pr-4 text-hp-slate/70" x-text="s.year"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>

                        {{-- A catalog program can have no students yet; a search that
                             finds nobody (with or without a program) keeps the old line. --}}
                        <p class="px-4 py-3 text-center text-xs text-hp-slate/50"
                           x-show="filtered.length === 0 && program !== '' && query.trim() === ''" x-cloak>
                            No students registered in <span x-text="program"></span> yet.
                        </p>
                        <p class="px-4 py-3 text-center text-xs text-hp-slate/50"
                           x-show="filtered.length === 0 && query.trim() !== ''" x-cloak>
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

</form>

</x-layout.sidebar>
