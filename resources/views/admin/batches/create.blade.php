<x-layout.sidebar title="New Batch Request">

{{-- ── Page header ────────────────────────────────────────────────────────── --}}
<div class="mb-6">
    <h2 class="text-xl font-semibold text-hp-slate">New Batch Request</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">
        Request medical or dental clearances for a group of {{ $college->code }} students.
    </p>
</div>

@if (session('status'))
    <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
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
        reason:        @js(old('reason', '')),
        reasonDetail:  @js(old('reason_detail', '') ?? ''),
        serviceType:   @js(old('service_type', 'medical')),
        reasonOthers:  @js(\App\Models\BatchRequest::REASON_OTHERS),

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

        // ── Submit gating (BR-06/07 — the server re-checks all of this) ────
        get reasonReady() {
            if (!this.reason) return false;
            return this.reason !== this.reasonOthers || this.reasonDetail.trim() !== '';
        },

        get canSubmit() {
            return this.reasonReady && this.selected.length > 0;
        },
    };
}
</script>

<form method="POST" action="{{ route('admin.batches.store') }}" x-data="batchForm()">
    @csrf

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">

        {{-- ── Left column: request details ─────────────────────────────── --}}
        <div class="space-y-6 xl:col-span-1">

            <x-hp.card>
                <h3 class="mb-4 text-sm font-semibold text-hp-slate">Request Details</h3>

                <div class="space-y-4">
                    {{-- Reason (FR-ADM-02 / BR-06) --}}
                    <div>
                        <x-hp.select label="Reason" name="reason" x-model="reason">
                            <option value="">— Select a reason —</option>
                            @foreach (\App\Models\BatchRequest::REASONS as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-hp.select>
                        @error('reason')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- "Please specify" — required iff reason = others (BR-06) --}}
                    <div x-show="reason === reasonOthers" x-cloak>
                        <x-hp.textarea label="Please specify" name="reason_detail"
                                       x-model="reasonDetail" rows="3" maxlength="500"
                                       placeholder="Describe the reason for this batch request" />
                        @error('reason_detail')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Service Type (FR-ADM-02) --}}
                    <div>
                        <span class="text-sm font-semibold text-hp-slate">Service Type</span>
                        <div class="mt-1.5 grid grid-cols-2 gap-2">
                            @foreach (['medical' => 'Medical', 'dental' => 'Dental'] as $value => $label)
                                <label class="flex cursor-pointer items-center justify-center gap-2 rounded-lg border-[1.5px] px-3 py-2.5 text-sm font-medium transition-colors"
                                       :class="serviceType === '{{ $value }}'
                                           ? 'border-hp-orange bg-hp-peach/40 text-hp-orange'
                                           : 'border-hp-slate/25 text-hp-slate/70 hover:border-hp-slate/40'">
                                    <input type="radio" name="service_type" value="{{ $value }}"
                                           x-model="serviceType" class="sr-only">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @error('service_type')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </x-hp.card>

            {{-- Submit — disabled until reason valid + ≥1 student (FR-ADM-03) --}}
            <x-hp.card>
                <x-hp.button type="submit" class="w-full" x-bind:disabled="!canSubmit">
                    Submit Batch Request
                </x-hp.button>
                <p class="mt-2 text-center text-xs text-hp-slate/50"
                   x-show="!canSubmit" x-cloak>
                    Choose a reason and select at least one student to submit.
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
                                      placeholder-hp-slate/40 transition-colors duration-150
                                      focus:border-hp-orange focus:ring-1 focus:ring-hp-orange focus:outline-none">
                    </div>

                    <div class="max-h-[30rem] overflow-y-auto rounded-lg border border-hp-slate/10">
                        <table class="w-full text-left text-sm">
                            <thead class="sticky top-0 bg-white">
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
</form>

</x-layout.sidebar>
