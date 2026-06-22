<x-layout.sidebar title="Book Appointment">

{{-- ── Page header ────────────────────────────────────────────────────────── --}}
<div class="mb-7">
    <h2 class="text-xl font-semibold text-hp-slate">Book an Appointment</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">Choose a service and a date to get started.</p>
</div>

{{-- ── Stub success flash ──────────────────────────────────────────────────── --}}
@if (session('status') === 'booking-submitted')
<div class="mb-5 flex items-center gap-3 rounded-xl border border-hp-peach bg-hp-peach/20 px-4 py-3">
    <svg class="h-4 w-4 shrink-0 text-hp-orange" fill="none" viewBox="0 0 24 24"
         stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <p class="text-sm font-medium text-hp-orange">
        Booking submitted — full save (FR-STU-04) coming next.
    </p>
</div>
@endif

{{--
    Alpine component: bookCalendar()
    Defined in an inline <script> so server-side PHP values ($year, $month, $fullDays)
    can be embedded directly. Inline scripts run before app.js (a deferred module),
    so window.bookCalendar is available when Alpine.start() walks the DOM.
--}}
<script>
function bookCalendar() {
    return {
        selectedService: null,
        selectedDate:    null,
        currentYear:     {{ $year }},
        currentMonth:    {{ $month }},
        fullDays:        @json($fullDays),
        bookingDays:     @json($bookingDays),
        loading:         false,

        /** "June 2026" — re-evaluates when currentYear / currentMonth change. */
        get monthLabel() {
            return new Date(this.currentYear, this.currentMonth - 1, 1)
                .toLocaleString('en-US', { month: 'long', year: 'numeric' });
        },

        /** Prev-arrow is disabled when already on the current month. */
        get canGoBack() {
            const now = new Date();
            return !(this.currentYear === now.getFullYear() &&
                     this.currentMonth === now.getMonth() + 1);
        },

        /**
         * Array of cell descriptors for the calendar grid.
         * Leading blanks pad the first row to the correct day-of-week column.
         */
        get calendarCells() {
            const firstDow    = new Date(this.currentYear, this.currentMonth - 1, 1).getDay();
            const daysInMonth = new Date(this.currentYear, this.currentMonth, 0).getDate();
            const today       = new Date();
            today.setHours(0, 0, 0, 0);
            const cells = [];

            for (let i = 0; i < firstDow; i++) {
                cells.push({ blank: true, key: 'b' + i });
            }

            for (let d = 1; d <= daysInMonth; d++) {
                const date       = new Date(this.currentYear, this.currentMonth - 1, d);
                const dow           = date.getDay();
                const isPast        = date < today;
                const isBlockedDay  = !this.bookingDays.includes(dow);
                const isFull        = this.fullDays.includes(d);
                const isDisabled    = isPast || isBlockedDay || isFull;
                const mm            = String(this.currentMonth).padStart(2, '0');
                const dd            = String(d).padStart(2, '0');
                const dateStr       = `${this.currentYear}-${mm}-${dd}`;
                const isToday      = date.getTime() === today.getTime();
                cells.push({ blank: false, d, isDisabled, isFull, isPast, isToday, dateStr, key: dateStr });
            }
            const trailingCount = (6 - new Date(this.currentYear, this.currentMonth - 1, daysInMonth).getDay()) % 7;
            for (let i = 0; i < trailingCount; i++) {
                cells.push({ blank: true, key: 'te' + i });
            }
            return cells;
        },

        prevMonth() {
            if (!this.canGoBack) return;
            if (this.currentMonth === 1) { this.currentYear--; this.currentMonth = 12; }
            else { this.currentMonth--; }
            this.selectedDate = null;
            this.fetchAvailability();
        },

        nextMonth() {
            if (this.currentMonth === 12) { this.currentYear++; this.currentMonth = 1; }
            else { this.currentMonth++; }
            this.selectedDate = null;
            this.fetchAvailability();
        },

        async fetchAvailability() {
            this.loading = true;
            try {
                const r    = await fetch(
                    `/student/appointments/availability?year=${this.currentYear}&month=${this.currentMonth}`
                );
                const data = await r.json();
                this.fullDays = data.full_days;
            } finally {
                this.loading = false;
            }
        },
    };
}
</script>

<form method="POST" action="{{ route('student.appointments.store') }}" x-data="bookCalendar()">
    @csrf
    <input type="hidden" name="service" :value="selectedService">
    <input type="hidden" name="date"    :value="selectedDate">

    {{-- ── Step 1: Service Picker ──────────────────────────────────────────── --}}
    <x-hp.card class="mb-5">
        <p class="mb-4 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
            Step 1 — Select a Service
        </p>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">

            {{-- Medical Clearance --}}
            <button type="button"
                @click="selectedService = 'medical'"
                class="rounded-xl border-2 p-5 text-left transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-hp-orange"
                :class="selectedService === 'medical'
                    ? 'border-hp-orange bg-orange-50'
                    : 'border-transparent bg-hp-bg hover:border-hp-orange/30'">

                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl transition-colors"
                     :class="selectedService === 'medical' ? 'bg-hp-orange' : 'bg-white'">
                    <svg class="h-5 w-5 transition-colors"
                         :class="selectedService === 'medical' ? 'text-white' : 'text-hp-orange'"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 12h6m-3-3v6m-7 2a9 9 0 1118 0 9 9 0 01-18 0z"/>
                    </svg>
                </div>

                <p class="font-semibold text-hp-slate">Medical Clearance</p>
                <p class="mt-0.5 text-xs text-hp-slate/50">Full clearance — vitals + screening</p>

                <div class="mt-3 flex items-center gap-1"
                     :class="selectedService === 'medical' ? 'text-hp-orange' : 'invisible'">
                    <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd"
                              d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                              clip-rule="evenodd"/>
                    </svg>
                    <span class="text-xs font-semibold">Selected</span>
                </div>
            </button>

            {{-- Dental Check --}}
            <button type="button"
                @click="selectedService = 'dental'"
                class="rounded-xl border-2 p-5 text-left transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-hp-orange"
                :class="selectedService === 'dental'
                    ? 'border-hp-orange bg-orange-50'
                    : 'border-transparent bg-hp-bg hover:border-hp-orange/30'">

                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl transition-colors"
                     :class="selectedService === 'dental' ? 'bg-hp-orange' : 'bg-white'">
                    {{-- Tooth outline icon --}}
                    <svg class="h-5 w-5 transition-colors"
                         :class="selectedService === 'dental' ? 'text-white' : 'text-hp-orange'"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 3c-2.2 0-4 1.8-4 4 0 1.3.4 2.4 1 3.3.6 1 .9 2.1.9 3.2 0 2.5-.9 5-1.4 7.5h7c-.5-2.5-1.4-5-1.4-7.5 0-1.1.3-2.2.9-3.2.6-.9 1-2 1-3.3 0-2.2-1.8-4-4-4z"/>
                    </svg>
                </div>

                <p class="font-semibold text-hp-slate">Dental Check</p>
                <p class="mt-0.5 text-xs text-hp-slate/50">Scheduling only — no vitals required</p>

                <div class="mt-3 flex items-center gap-1"
                     :class="selectedService === 'dental' ? 'text-hp-orange' : 'invisible'">
                    <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd"
                              d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                              clip-rule="evenodd"/>
                    </svg>
                    <span class="text-xs font-semibold">Selected</span>
                </div>
            </button>

        </div>
    </x-hp.card>

    {{-- ── Step 2: Calendar ─────────────────────────────────────────────────── --}}
    <x-hp.card class="mb-6">
        <p class="mb-5 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
            Step 2 — Pick a Date
        </p>

        {{-- Month navigation --}}
        <div class="mb-4 flex items-center justify-between">
            <button type="button" @click="prevMonth()"
                :disabled="!canGoBack"
                :class="!canGoBack
                    ? 'text-hp-slate/20 cursor-not-allowed'
                    : 'text-hp-slate hover:bg-hp-bg hover:text-hp-orange'"
                class="flex h-8 w-8 items-center justify-center rounded-lg transition-colors">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>

            <span class="text-sm font-semibold text-hp-slate" x-text="monthLabel"></span>

            <button type="button" @click="nextMonth()"
                class="flex h-8 w-8 items-center justify-center rounded-lg text-hp-slate transition-colors hover:bg-hp-bg hover:text-hp-orange">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </button>
        </div>

        {{-- Calendar grid (relative so the loading overlay can be absolute) --}}
        <div class="relative">

            {{-- Loading overlay shown while fetching next-month availability --}}
            <div x-show="loading" x-cloak
                 class="absolute inset-0 z-10 flex items-center justify-center rounded-lg bg-white/70 backdrop-blur-[1px]">
                <svg class="h-5 w-5 animate-spin text-hp-orange" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10"
                            stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor"
                          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
            </div>

            {{-- Day-of-week header --}}
            <div class="grid grid-cols-7">
                @foreach(['Su','Mo','Tu','We','Th','Fr','Sa'] as $lbl)
                <div class="flex h-8 items-center justify-center text-[11px] font-semibold uppercase tracking-wider text-hp-slate/35">
                    {{ $lbl }}
                </div>
                @endforeach
            </div>

            {{-- Day cells --}}
            <div class="grid grid-cols-7 gap-y-0.5">
                <template x-for="cell in calendarCells" :key="cell.key">
                    <div class="flex items-center justify-center py-0.5">
                        {{--
                            Hidden for blank filler cells; shown for actual days.
                            :disabled prevents click events on disabled days even if @click guard fires.
                        --}}
                        <button
                            x-show="!cell.blank"
                            type="button"
                            :disabled="cell.isDisabled"
                            @click="!cell.isDisabled && (selectedDate = cell.dateStr)"
                            class="relative flex h-10 w-10 flex-col items-center justify-center rounded-full
                                   text-[13px] transition-colors focus:outline-none"
                            :class="{
                                'bg-hp-orange text-white font-semibold shadow-sm ring-2 ring-hp-orange/25':
                                    !cell.blank && selectedDate === cell.dateStr,
                                'ring-2 ring-hp-orange font-semibold':
                                    !cell.blank && cell.isToday && !cell.isDisabled && selectedDate !== cell.dateStr,
                                'bg-hp-bg text-hp-slate/40 cursor-default':
                                    !cell.blank && cell.isFull,
                                'text-hp-slate/25 cursor-not-allowed':
                                    !cell.blank && cell.isDisabled && !cell.isFull,
                                'text-hp-slate hover:bg-hp-peach/50 hover:text-hp-orange cursor-pointer':
                                    !cell.blank && !cell.isDisabled && selectedDate !== cell.dateStr,
                            }">
                            <span x-text="cell.d" class="leading-none"></span>
                            {{-- "FULL" sub-label appears only for at-capacity days --}}
                            <span x-show="cell.isFull"
                                  class="mt-0.5 block text-[8px] font-bold uppercase leading-none tracking-wide">
                                FULL
                            </span>
                        </button>
                    </div>
                </template>
            </div>
        </div>

        {{-- Legend --}}
        <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 border-t border-hp-slate/10 pt-4">
            <span class="flex items-center gap-1.5 text-xs text-hp-slate/50">
                <span class="h-3 w-3 rounded-full bg-hp-orange"></span> Selected
            </span>
            <span class="flex items-center gap-1.5 text-xs text-hp-slate/50">
                <span class="h-3 w-3 rounded-full border-2 border-hp-orange bg-white"></span> Today
            </span>
            <span class="flex items-center gap-1.5 text-xs text-hp-slate/50">
                <span class="h-3 w-3 rounded-full border border-hp-slate/20 bg-hp-bg"></span> Full
            </span>
            <span class="flex items-center gap-1.5 text-xs text-hp-slate/50">
                <span class="h-3 w-3 rounded-full border border-hp-slate/20 bg-white"></span> Available
            </span>
            <span class="flex items-center gap-1.5 text-xs text-hp-slate/50">
                <span class="h-3 w-3 rounded-full border border-hp-slate/10 bg-transparent opacity-40"></span> Unavailable
            </span>
        </div>
    </x-hp.card>

    {{-- ── Confirm Booking ──────────────────────────────────────────────────── --}}
    <button type="submit"
        :disabled="!selectedService || !selectedDate"
        class="w-full rounded-full py-3.5 text-sm font-semibold text-white transition-all focus:outline-none"
        :class="(!selectedService || !selectedDate)
            ? 'cursor-not-allowed bg-hp-slate/20 text-hp-slate/40'
            : 'cursor-pointer bg-hp-orange shadow-sm hover:bg-orange-500'">
        Confirm Booking
    </button>

    <p x-show="!selectedService || !selectedDate" x-cloak
       class="mt-2 text-center text-xs text-hp-slate/40">
        Select a service and a date to continue
    </p>

</form>

</x-layout.sidebar>
