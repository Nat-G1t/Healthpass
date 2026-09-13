<x-layout.sidebar title="Book Appointment">

{{-- ── Page header ────────────────────────────────────────────────────────── --}}
<div class="mb-7">
    <h2 class="text-xl font-semibold text-hp-slate">Book an Appointment</h2>
    <p class="mt-0.5 text-sm text-hp-slate/50">Choose a service and a date to get started.</p>
</div>

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
        // D-37: the one-hour clinic slot. The option list comes from the
        // server (derived from clinic_hours), and its per-slot remaining
        // counts are refreshed from the availability endpoint on every date
        // pick — a client that picks a full hour is still refused server-side.
        selectedTime:    null,
        slots:           @json($slots),
        slotCapacity:    {{ $slotCapacity }},
        slotAvailability: {},   // slot value => { remaining, full }
        slotsLoading:    false,
        // D-28: purpose of the medical clearance, chosen here so the printed
        // form auto-populates. Empty for dental (server nulls it anyway).
        purpose:         '',
        purposeOther:    '',
        purposeOthers:   @js(\App\Models\ClearanceRecord::PURPOSE_OTHERS),
        currentYear:     {{ $year }},
        currentMonth:    {{ $month }},
        fullDays:        @json($fullDays),
        cutoffDays:      @json($cutoffDays),
        bookingDays:     @json($bookingDays),
        loading:         false,

        bookingUrl:   @json(route('student.appointments.store')),
        confirmModal: false,
        errorModal:   false,
        cutoffModal:  false,
        errorMessage: '',
        submitting:   false,

        /** "June 2026" — re-evaluates when currentYear / currentMonth change. */
        get monthLabel() {
            return new Date(this.currentYear, this.currentMonth - 1, 1)
                .toLocaleString('en-US', { month: 'long', year: 'numeric' });
        },

        get serviceLabel() {
            return this.selectedService === 'medical' ? 'Medical Clearance' : 'Dental Check';
        },

        /**
         * D-54: being already scheduled by the college is not a booking problem
         * the student caused, so that refusal gets its own heading.
         */
        get errorTitle() {
            return this.errorMessage.includes('scheduled for you by your college admin')
                ? 'Already Scheduled by Your College'
                : 'Booking Not Available';
        },

        /**
         * D-28: purpose is only required for a medical clearance. Dental needs
         * none; picking "Others" needs the specify text. Mirrors the server's
         * StoreAppointmentRequest rules — the server stays the real gate.
         */
        get purposeReady() {
            if (this.selectedService !== 'medical') return true;
            if (!this.purpose) return false;
            return this.purpose !== this.purposeOthers || this.purposeOther.trim() !== '';
        },

        /** All steps satisfied and not mid-submit — enables Confirm Booking. */
        get canBook() {
            return !!this.selectedService && !!this.selectedDate && !!this.selectedTime
                && this.purposeReady && !this.submitting;
        },

        /** Seats left in a slot; 0 until the availability fetch answers. */
        remainingFor(slot) {
            const row = this.slotAvailability[slot];
            return row ? row.remaining : this.slotCapacity;
        },

        isSlotFull(slot) {
            const row = this.slotAvailability[slot];
            return row ? row.full : false;
        },

        /** BR-23: the hour already ended today. Decided server-side. */
        isSlotElapsed(slot) {
            const row = this.slotAvailability[slot];
            return row ? row.elapsed : false;
        },

        /** The only thing the picker disables on. */
        isSlotAvailable(slot) {
            const row = this.slotAvailability[slot];
            return row ? row.available : true;
        },

        /** "7:00 AM – 8:00 AM" for the confirm modal. */
        get timeLabel() {
            return this.slots.find(s => s.value === this.selectedTime)?.label ?? '';
        },

        /** Human-readable purpose for the confirm modal (Others shows the event). */
        get purposeSummary() {
            return this.purpose === this.purposeOthers
                ? `Others — ${this.purposeOther}`
                : this.purpose;
        },

        /** Human-readable date for modal copy — parsed as local time to avoid TZ shift. */
        get formattedDate() {
            if (!this.selectedDate) return '';
            const [y, m, d] = this.selectedDate.split('-').map(Number);
            return new Date(y, m - 1, d).toLocaleDateString('en-US', {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
            });
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
                const date         = new Date(this.currentYear, this.currentMonth - 1, d);
                const dow          = date.getDay();
                const isPast       = date < today;
                const isBlockedDay = !this.bookingDays.includes(dow);
                // Same-day closing cutoff (BR-20). cutoffDays is decided server-side
                // (never from this browser clock) — today only, and only after closing.
                const isCutoff     = this.cutoffDays.includes(d);
                // A cutoff day can also arrive as "full" (BR-23 greys today out
                // once no hour is left, and past closing that is always true).
                // Let cutoff win so the cell keeps its explain-why click.
                const isFull       = this.fullDays.includes(d) && !isCutoff;
                const isDisabled   = isPast || isBlockedDay || isFull || isCutoff;
                const mm           = String(this.currentMonth).padStart(2, '0');
                const dd           = String(d).padStart(2, '0');
                const dateStr      = `${this.currentYear}-${mm}-${dd}`;
                const isToday      = date.getTime() === today.getTime();
                cells.push({ blank: false, d, isDisabled, isFull, isCutoff, isPast, isToday, dateStr, key: dateStr });
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
            this.clearDate();
            this.fetchAvailability();
        },

        nextMonth() {
            if (this.currentMonth === 12) { this.currentYear++; this.currentMonth = 1; }
            else { this.currentMonth++; }
            this.clearDate();
            this.fetchAvailability();
        },

        clearDate() {
            this.selectedDate     = null;
            this.selectedTime     = null;
            this.slotAvailability = {};
        },

        /** A new date means a new set of hours — refetch, and drop the old pick. */
        selectDate(dateStr) {
            this.selectedDate     = dateStr;
            this.selectedTime     = null;
            this.slotAvailability = {};
            this.fetchSlots();
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

        /** Per-slot remaining capacity for the selected date (D-37). */
        async fetchSlots() {
            const date = this.selectedDate;
            if (!date) return;

            this.slotsLoading = true;
            try {
                const r = await fetch(
                    `/student/appointments/availability?year=${this.currentYear}` +
                    `&month=${this.currentMonth}&date=${date}`
                );
                const data = await r.json();

                // Ignore a stale answer if the student moved on to another date.
                if (date !== this.selectedDate) return;

                this.slotAvailability = Object.fromEntries(
                    (data.slots ?? []).map(s => [s.value, {
                        remaining: s.remaining, full: s.full,
                        elapsed: s.elapsed, available: s.available,
                    }])
                );

                // The hour may have filled up — or simply ended (BR-23) —
                // while it was selected. Drop a pick that is no longer valid
                // rather than letting Confirm submit something the server
                // will reject.
                if (this.selectedTime && !this.isSlotAvailable(this.selectedTime)) {
                    this.selectedTime = null;
                }
            } catch {
                // Leave the counts blank — the server is still the real gate.
                this.slotAvailability = {};
            } finally {
                this.slotsLoading = false;
            }
        },

        /** Step 2: open the confirm-before-book modal. */
        openConfirmModal() {
            if (!this.canBook) return;
            this.confirmModal = true;
        },

        /**
         * Step 1 (on "Yes, book it"): POST via fetch with Accept: application/json.
         * Laravel returns 422 JSON on validation failure (duplicate / full day / past
         * date), or 200 JSON {redirect: url} on success. Server is the sole authority.
         */
        async submitBooking() {
            if (this.submitting) return;
            this.submitting   = true;
            this.confirmModal = false;

            try {
                const token = document.querySelector('meta[name="csrf-token"]').content;
                const body  = new URLSearchParams({
                    _token:        token,
                    service:       this.selectedService,
                    date:          this.selectedDate,
                    time:          this.selectedTime,   // D-37
                    // Sent for every booking; the server drops purpose for dental
                    // and clears purpose_other unless "Others" was chosen (D-28).
                    purpose:       this.purpose,
                    purpose_other: this.purposeOther,
                });

                const response = await fetch(this.bookingUrl, {
                    method:  'POST',
                    headers: {
                        'Accept':       'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: body.toString(),
                });

                const data = await response.json();

                if (response.status === 422) {
                    const message = data.errors?.date?.[0]
                        ?? data.errors?.time?.[0]
                        ?? data.errors?.service?.[0]
                        ?? data.errors?.purpose?.[0]
                        ?? data.errors?.purpose_other?.[0]
                        ?? data.message
                        ?? 'Booking could not be completed.';

                    // The same-day closing cutoff (BR-20) gets its own, friendlier modal
                    // that points the student at the next day; everything else is generic.
                    if (message.includes('clinic is closed for today')) {
                        this.cutoffModal = true;
                    } else {
                        this.errorMessage = message;
                        this.errorModal   = true;
                    }
                    return;
                }

                if (response.ok) {
                    window.location.href = data.redirect;
                    return;
                }

                this.errorMessage = 'An unexpected error occurred. Please try again.';
                this.errorModal   = true;
            } catch {
                this.errorMessage = 'Could not reach the server. Please check your connection.';
                this.errorModal   = true;
            } finally {
                this.submitting = false;
            }
        },

        /**
         * Close the error modal without losing selected service / date / calendar
         * month. The slot counts are refetched because the most likely reason we
         * got here is that somebody else took the hour (D-37).
         */
        closeErrorModal() {
            this.errorModal   = false;
            this.errorMessage = '';
            this.fetchSlots();
        },
    };
}
</script>

{{-- x-data wraps the form AND both modals so Alpine scope covers all three. --}}
<div x-data="bookCalendar()">

<form @submit.prevent>
    @csrf
    <input type="hidden" name="service" :value="selectedService">
    <input type="hidden" name="date"    :value="selectedDate">
    <input type="hidden" name="time"    :value="selectedTime">

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
                     :class="selectedService === 'medical' ? 'bg-hp-orange' : 'bg-hp-white'">
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
                     :class="selectedService === 'dental' ? 'bg-hp-orange' : 'bg-hp-white'">
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
                 class="absolute inset-0 z-10 flex items-center justify-center rounded-lg bg-hp-white/70 backdrop-blur-[1px]">
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
                        <button
                            x-show="!cell.blank"
                            type="button"
                            {{-- Cutoff days stay clickable so the tap can explain WHY today is
                                 closed; every other disabled day is a hard no-op. --}}
                            :disabled="cell.isDisabled && !cell.isCutoff"
                            @click="cell.isCutoff
                                        ? (cutoffModal = true)
                                        : (!cell.isDisabled && selectDate(cell.dateStr))"
                            class="relative flex h-10 w-10 flex-col items-center justify-center rounded-full
                                   text-[13px] transition-colors focus:outline-none"
                            :class="{
                                {{-- hp-anim-pop (§5.7): the class lands only on the newly
                                     selected day, so picking a date pops it once. --}}
                                'bg-hp-orange text-white font-semibold shadow-sm ring-2 ring-hp-orange/25 hp-anim-pop':
                                    !cell.blank && selectedDate === cell.dateStr,
                                'ring-2 ring-hp-orange font-semibold':
                                    !cell.blank && cell.isToday && !cell.isDisabled && selectedDate !== cell.dateStr,
                                'bg-hp-bg text-hp-slate/40 cursor-default':
                                    !cell.blank && cell.isFull,
                                'text-hp-slate/25 cursor-pointer':
                                    !cell.blank && cell.isCutoff,
                                'text-hp-slate/25 cursor-not-allowed':
                                    !cell.blank && cell.isDisabled && !cell.isFull && !cell.isCutoff,
                                'text-hp-slate hover:bg-hp-peach/50 hover:text-hp-orange cursor-pointer':
                                    !cell.blank && !cell.isDisabled && selectedDate !== cell.dateStr,
                            }">
                            <span x-text="cell.d" class="leading-none"></span>
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
                <span class="h-3 w-3 rounded-full border-2 border-hp-orange bg-hp-white"></span> Today
            </span>
            <span class="flex items-center gap-1.5 text-xs text-hp-slate/50">
                <span class="h-3 w-3 rounded-full border border-hp-slate/20 bg-hp-bg"></span> Full
            </span>
            <span class="flex items-center gap-1.5 text-xs text-hp-slate/50">
                <span class="h-3 w-3 rounded-full border border-hp-slate/20 bg-hp-white"></span> Available
            </span>
            <span class="flex items-center gap-1.5 text-xs text-hp-slate/50">
                <span class="h-3 w-3 rounded-full border border-hp-slate/10 bg-transparent opacity-40"></span> Unavailable
            </span>
        </div>
    </x-hp.card>

    {{-- ── Step 3: Time Slot (D-37) ──────────────────────────────────────────
         The clinic day is ten one-hour slots holding 12 students each. The
         option list is rendered from $slots (derived server-side from
         clinic_hours) and the remaining counts come from the availability
         endpoint; a full hour is disabled here AND refused server-side. --}}
    <div x-show="selectedDate" x-cloak>
        <x-hp.card class="mb-6">
            <p class="mb-1 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Step 3 — Pick a Time
            </p>
            <p class="mb-4 text-xs text-hp-slate/50">
                Each hour takes up to {{ $slotCapacity }} students.
                <span x-show="slotsLoading" x-cloak>Checking availability…</span>
            </p>

            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                <template x-for="slot in slots" :key="slot.value">
                    <button type="button"
                        :disabled="!isSlotAvailable(slot.value)"
                        @click="selectedTime = slot.value"
                        class="rounded-xl border-2 px-2 py-3 text-center transition-colors
                               focus:outline-none focus-visible:ring-2 focus-visible:ring-hp-orange"
                        :class="{
                            'border-hp-orange bg-orange-50 hp-anim-pop':
                                selectedTime === slot.value,
                            'border-transparent bg-hp-bg text-hp-slate/30 cursor-not-allowed':
                                !isSlotAvailable(slot.value),
                            'border-transparent bg-hp-bg hover:border-hp-orange/30 cursor-pointer':
                                isSlotAvailable(slot.value) && selectedTime !== slot.value,
                        }">
                        <span class="block text-sm font-semibold"
                              :class="isSlotAvailable(slot.value) ? 'text-hp-slate' : 'text-hp-slate/40'"
                              x-text="slot.label"></span>
                        <span x-show="isSlotAvailable(slot.value)"
                              class="mt-0.5 block text-[11px] text-hp-slate/50">
                            <span x-text="remainingFor(slot.value)"></span> left
                        </span>
                        {{-- BR-23: "Past" and "Full" are different problems —
                             say which one the student is looking at. --}}
                        <span x-show="isSlotElapsed(slot.value)" x-cloak
                              class="mt-0.5 block text-[10px] font-bold uppercase tracking-wide">
                            Past
                        </span>
                        <span x-show="isSlotFull(slot.value) && !isSlotElapsed(slot.value)" x-cloak
                              class="mt-0.5 block text-[10px] font-bold uppercase tracking-wide">
                            Full
                        </span>
                    </button>
                </template>
            </div>
        </x-hp.card>
    </div>

    {{-- ── Step 4: Purpose of Medical Clearance (D-28) ──────────────────────────
         A medical clearance prints an official form that names its purpose, so
         the student chooses it here (shared <x-hp.purpose-fieldset>, same
         dropdown as nurse encode). Dental is scheduling-only — no clearance form
         — so this card is hidden for dental and until a service is picked.
         bookCalendar() supplies the `purpose`/`purposeOther` the fieldset binds. --}}
    <div x-show="selectedService === 'medical'" x-cloak>
        <x-hp.card class="mb-6">
            <p class="mb-4 text-[11px] font-semibold uppercase tracking-widest text-hp-slate/40">
                Step 4 — Purpose of Medical Clearance
            </p>
            <x-hp.purpose-fieldset placeholderOption="— Select a purpose —" />
        </x-hp.card>
    </div>

    {{-- ── Confirm Booking button ───────────────────────────────────────────── --}}
    <button type="button"
        @click="openConfirmModal()"
        :disabled="!canBook"
        class="w-full rounded-full py-3.5 text-sm font-semibold text-white transition-all focus:outline-none"
        :class="!canBook
            ? 'cursor-not-allowed bg-hp-slate/20 text-hp-slate/40'
            : 'cursor-pointer bg-hp-orange shadow-sm hover:bg-orange-500'">
        <span x-show="!submitting">Confirm Booking</span>
        <span x-show="submitting" x-cloak class="flex items-center justify-center gap-2">
            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10"
                        stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor"
                      d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            Booking…
        </span>
    </button>

    <p x-show="!canBook && !submitting" x-cloak
       class="mt-2 text-center text-xs text-hp-slate/40">
        <span x-show="selectedService === 'medical'">Select a service, a date, a time and the purpose to continue</span>
        <span x-show="selectedService !== 'medical'">Select a service, a date and a time to continue</span>
    </p>

</form>

{{-- ── Confirm-before-book modal ────────────────────────────────────────────── --}}
<div x-show="confirmModal" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="background-color: rgba(75,85,99,0.45);">
    <div class="w-full max-w-sm rounded-2xl bg-hp-white p-6 shadow-xl"
         @click.outside="confirmModal = false">

        <div class="mb-3 flex items-center gap-3">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-hp-peach">
                <svg class="h-4 w-4 text-hp-orange" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold text-hp-slate">Confirm Booking</h3>
        </div>

        <p class="text-sm text-hp-slate/70">
            Book
            <span class="font-semibold text-hp-slate" x-text="serviceLabel"></span>
            on
            <span class="font-semibold text-hp-slate" x-text="formattedDate"></span>
            at
            <span class="font-semibold text-hp-slate" x-text="timeLabel"></span>?
        </p>
        {{-- Purpose is a required dimension of a medical booking (D-28) — echo it
             back so the student confirms the exact choice before committing. --}}
        <p x-show="selectedService === 'medical'" x-cloak class="mt-1 text-sm text-hp-slate/70">
            Purpose: <span class="font-semibold text-hp-slate" x-text="purposeSummary"></span>
        </p>
        <p class="mt-1 text-xs text-hp-slate/50">Clinic hours: 7:00 AM – 5:00 PM</p>

        <div class="mt-5 flex gap-3">
            <button type="button"
                    @click="confirmModal = false"
                    :disabled="submitting"
                    class="flex-1 rounded-full border border-hp-slate/25 py-2.5 text-sm
                           font-semibold text-hp-slate transition-colors
                           hover:bg-hp-slate/5 disabled:opacity-50">
                Cancel
            </button>
            <button type="button"
                    @click="submitBooking()"
                    :disabled="submitting"
                    class="flex-1 rounded-full bg-hp-orange py-2.5 text-sm font-semibold
                           text-white transition-colors hover:bg-orange-500 disabled:opacity-50">
                Yes, book it
            </button>
        </div>

    </div>
</div>

{{-- ── Already-booked / full-day error modal ───────────────────────────────── --}}
<div x-show="errorModal" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="background-color: rgba(75,85,99,0.45);">
    <div class="w-full max-w-sm rounded-2xl bg-hp-white p-6 shadow-xl">

        <div class="mb-3 flex items-center gap-3">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-50">
                <svg class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667
                             1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77
                             1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold text-hp-slate" x-text="errorTitle">Booking Not Available</h3>
        </div>

        <p class="text-sm text-hp-slate/70" x-text="errorMessage"></p>

        <div class="mt-5">
            <button type="button"
                    @click="closeErrorModal()"
                    class="w-full rounded-full bg-hp-orange py-2.5 text-sm font-semibold
                           text-white transition-colors hover:bg-orange-500">
                Choose another date
            </button>
        </div>

    </div>
</div>

{{-- ── Clinic-closed-for-today (BR-20 closing cutoff) modal ─────────────────── --}}
<div x-show="cutoffModal" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="background-color: rgba(75,85,99,0.45);">
    <div class="w-full max-w-sm rounded-2xl bg-hp-white p-6 shadow-xl"
         @click.outside="cutoffModal = false">

        <div class="mb-3 flex items-center gap-3">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-hp-peach">
                <svg class="h-4 w-4 text-hp-orange" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold text-hp-slate">Clinic Closed for Today</h3>
        </div>

        <p class="text-sm text-hp-slate/70">
            The clinic has closed for today (hours: 7:00 AM – 5:00 PM). Same-day booking is
            no longer available — please schedule for the next day onwards.
        </p>

        <div class="mt-5">
            <button type="button"
                    @click="cutoffModal = false; clearDate()"
                    class="w-full rounded-full bg-hp-orange py-2.5 text-sm font-semibold
                           text-white transition-colors hover:bg-orange-500">
                Pick another date
            </button>
        </div>

    </div>
</div>

</div>{{-- /x-data wrapper --}}

</x-layout.sidebar>
