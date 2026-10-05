import {
    dateLabel, isAfterTodaysMonth, isBeforeLastMonth, monthCells, monthLabel, stepMonth,
} from '../shared/month-calendar';

/**
 * Alpine.data component for the Director's Review page — the Schedule card
 * (FR-DIRA-07/08, D-108).
 *
 * The Director picks a clinic DAY on a month calendar, then a START HOUR from
 * a native <select>, then approves. Everything this component shows comes
 * from the server: the month's days (GET …/calendar) and the day's hours
 * (GET …/hours). It never decides "now" or "full" itself (BR-20/BR-23) — and
 * the server re-checks every rule when Approve is posted, so this only helps.
 *
 * Registered in app.js before Alpine.start() (same pattern as qr-link-id.js).
 *
 * The month arithmetic (cells, labels, paging) is shared with the read-only
 * Clinic Calendar card (D-119) — see shared/month-calendar.js.
 *
 * @param {Alpine} Alpine - the single Alpine instance from app.js
 */
export function registerBatchSchedule(Alpine) {
    Alpine.data('batchSchedule', (config) => ({
        // ── Server data ─────────────────────────────────────────────────────
        ref: config.ref,
        today: config.today,                  // 'YYYY-MM-DD', the SERVER's date
        students: config.students,            // how many students this batch holds
        hourlyCapacity: config.hourlyCapacity,
        dailyCapacity: config.dailyCapacity,
        calendarUrl: config.calendarUrl,
        hoursUrl: config.hoursUrl,

        // ── Calendar state ──────────────────────────────────────────────────
        calYear: config.year,
        calMonth: config.month,
        days: config.days,                    // [{date, booked, capacity, fits, why}]
        calLoading: false,

        // ── Picked day + hour ───────────────────────────────────────────────
        selectedDate: '',
        hours: [],                            // [{slot, label, booked, colleges, selectable, why}]
        hoursLoading: false,
        clash: { count: 0, names: [] },       // BR-25: students already scheduled that day
        startTime: '',

        // ── Modals ──────────────────────────────────────────────────────────
        approveOpen: false,
        submitting: false,
        rejectOpen: config.rejectOpen,
        reason: config.oldReason ?? '',
        rejecting: false,
        reasonMin: config.reasonMin,

        /** After a refused Approve, come back to the day and hour that were picked. */
        init() {
            if (/^\d{4}-\d{2}-\d{2}$/.test(config.oldDate ?? '')) {
                const [y, m] = config.oldDate.split('-').map(Number);

                if (y !== this.calYear || m !== this.calMonth) {
                    this.calYear = y;
                    this.calMonth = m;
                    this.fetchMonth();
                }

                this.selectedDate = config.oldDate;
                this.fetchHours(config.oldStart ?? '');
            }
        },

        // ── Calendar ────────────────────────────────────────────────────────

        /** "October 2026" */
        get monthLabel() {
            return monthLabel(this.calYear, this.calMonth);
        },

        /** The server's current month is the earliest one worth showing. */
        get canGoBack() {
            return isAfterTodaysMonth(this.calYear, this.calMonth, this.today);
        },

        /** The calendar endpoint answers up to two years ahead. */
        get canGoForward() {
            return isBeforeLastMonth(this.calYear, this.calMonth, this.today);
        },

        /** One cell per day, padded to the right weekday column; `fill` is the ring. */
        get cells() {
            return monthCells(this.calYear, this.calMonth, this.days, this.today);
        },

        /** A cell's tooltip / screen-reader text: the date, its load, and why it can't be picked. */
        cellTitle(cell) {
            const load = `${cell.booked} of ${cell.capacity} booked`;
            return cell.fits ? load : `${cell.why} · ${load}`;
        },

        prevMonth() {
            if (!this.canGoBack) return;
            [this.calYear, this.calMonth] = stepMonth(this.calYear, this.calMonth, -1);
            this.fetchMonth();
        },

        nextMonth() {
            if (!this.canGoForward) return;
            [this.calYear, this.calMonth] = stepMonth(this.calYear, this.calMonth, 1);
            this.fetchMonth();
        },

        /** The month on screen, from the server. Stale answers are dropped. */
        async fetchMonth() {
            const year = this.calYear;
            const month = this.calMonth;
            this.calLoading = true;

            try {
                const response = await fetch(`${this.calendarUrl}?year=${year}&month=${month}`, {
                    headers: { Accept: 'application/json' },
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();

                if (year === this.calYear && month === this.calMonth) {
                    this.days = data.days;
                }
            } catch (error) {
                // Nothing pickable rather than last month's days; Approve is re-checked anyway.
                if (year === this.calYear && month === this.calMonth) this.days = [];
                console.error('Calendar fetch failed', error);
            } finally {
                if (year === this.calYear && month === this.calMonth) this.calLoading = false;
            }
        },

        /** Picking a day loads its hours; a day that can't take the batch can't be picked. */
        pickDay(cell) {
            if (cell.blank || !cell.fits) return;
            this.selectedDate = cell.date;
            this.fetchHours('');
        },

        // ── Hours ───────────────────────────────────────────────────────────

        /** The picked day's hours from the server; keeps `keep` picked if it still fits. */
        async fetchHours(keep) {
            const date = this.selectedDate;
            this.hoursLoading = true;
            this.startTime = '';

            try {
                const response = await fetch(`${this.hoursUrl}?date=${date}`, {
                    headers: { Accept: 'application/json' },
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();

                if (date !== this.selectedDate) return;

                this.hours = data.hours;
                this.clash = data.clash;
                if (this.hours.some((hour) => hour.slot === keep && hour.selectable)) {
                    // $nextTick: let x-for build the <option>s before the select takes the value.
                    this.$nextTick(() => { this.startTime = keep; });
                }
            } catch (error) {
                if (date === this.selectedDate) {
                    this.hours = [];
                    this.clash = { count: 0, names: [] };
                }
                console.error('Hours fetch failed', error);
            } finally {
                if (date === this.selectedDate) this.hoursLoading = false;
            }
        },

        /** "9:00 AM – 10:00 AM ~ scheduled for CCS (8/20)", or "… · open"; why it's greyed, if it is. */
        optionText(hour) {
            const who = hour.colleges.length > 0
                ? ' ~ scheduled for ' + hour.colleges.map((c) => `${c.code} (${c.count}/${this.hourlyCapacity})`).join(', ')
                : ' · open';

            return hour.label + who + (hour.selectable ? '' : ` — ${hour.why}`);
        },

        /** How many hours the batch fills: hourlyCapacity per hour, the rest in the last. */
        get blocks() {
            return Math.ceil(this.students / this.hourlyCapacity);
        },

        /** "9:00 AM – 11:00 AM" for the picked start hour, or ''. */
        get spanRange() {
            const start = this.hours.findIndex((hour) => hour.slot === this.startTime);
            const last = this.hours[start + this.blocks - 1];
            if (start === -1 || !last) return '';
            return `${this.hours[start].label.split(' – ')[0]} – ${last.label.split(' – ')[1]}`;
        },

        /** "40 students → 9:00 AM – 11:00 AM (2 hours)" */
        get spanSummary() {
            if (this.spanRange === '') return '';
            return `${this.students} students → ${this.spanRange} (${this.blocks} hour${this.blocks === 1 ? '' : 's'})`;
        },

        /** "Tue, Oct 6, 2026" */
        get dateLabel() {
            return dateLabel(this.selectedDate);
        },

        /** "A, B, C, D, E and 3 more" */
        get clashNames() {
            const shown = this.clash.names.slice(0, 5).join(', ');
            const more = this.clash.names.length - 5;
            return more > 0 ? `${shown} and ${more} more` : shown;
        },

        // ── Approve / Reject ────────────────────────────────────────────────

        /** A day, a selectable hour, and nobody already scheduled that day. */
        get canApprove() {
            const hour = this.hours.find((h) => h.slot === this.startTime);
            return !this.hoursLoading && hour !== undefined && hour.selectable && this.clash.count === 0;
        },

        openApprove() {
            if (!this.canApprove) return;
            this.submitting = false;
            this.approveOpen = true;
        },

        /** Mirrors RejectBatchRequest's min rule; the server still decides. */
        get reasonIsValid() {
            return this.reason.trim().length >= this.reasonMin;
        },

        openReject() {
            this.rejecting = false;
            this.rejectOpen = true;
        },
    }));
}
