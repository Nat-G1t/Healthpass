import {
    dateLabel, getJson, isAfterTodaysMonth, isBeforeLastMonth, monthCells, monthLabel, stepMonth,
} from './month-calendar';

/**
 * Alpine.data component for the read-only Clinic Calendar card (D-119) — on
 * the Director's Batch Tracking page and the clinic's Upcoming Batches page.
 *
 * The same month calendar and fill rings the Director schedules with, but
 * nothing here books anything: pick a day (today onward) and the other half of
 * the card lists that day's clinic hours — booked out of the hourly capacity,
 * and the batches holding those seats. Everything shown comes from the server
 * (ClinicCalendarController): the month's days and the picked day's hours.
 *
 * Registered in app.js before Alpine.start().
 *
 * @param {Alpine} Alpine - the single Alpine instance from app.js
 */
export function registerClinicCalendar(Alpine) {
    Alpine.data('clinicCalendar', (config) => ({
        today: config.today,          // 'YYYY-MM-DD', the SERVER's date
        monthUrl: config.monthUrl,
        dayUrl: config.dayUrl,

        calYear: config.year,
        calMonth: config.month,
        days: [],                     // [{date, booked, capacity, open, why}]
        calLoading: false,

        selectedDate: '',
        day: null,                    // {booked, capacity, hours: [{slot, label, booked, capacity, batches}]}
        dayLoading: false,
        dayFailed: false,

        /** Load this month, then open today so the right half is never empty on a clinic day. */
        async init() {
            await this.fetchMonth();
            const today = this.days.find((day) => day.date === this.today && day.open);
            if (today) this.pickDay(today);
        },

        get monthLabel() { return monthLabel(this.calYear, this.calMonth); },
        get canGoBack() { return isAfterTodaysMonth(this.calYear, this.calMonth, this.today); },
        get canGoForward() { return isBeforeLastMonth(this.calYear, this.calMonth, this.today); },
        get cells() { return monthCells(this.calYear, this.calMonth, this.days, this.today); },
        get dateLabel() { return dateLabel(this.selectedDate); },

        /** A cell's tooltip / screen-reader text: its load, and why it can't be opened. */
        cellTitle(cell) {
            const load = `${cell.booked} of ${cell.capacity} booked`;
            return cell.open ? load : `${cell.why} · ${load}`;
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
            const isCurrent = () => year === this.calYear && month === this.calMonth;
            this.calLoading = true;

            try {
                const data = await getJson(`${this.monthUrl}?year=${year}&month=${month}`);
                if (isCurrent()) this.days = data.days;
            } catch (error) {
                if (isCurrent()) this.days = [];
                console.error('Clinic calendar month fetch failed', error);
            } finally {
                if (isCurrent()) this.calLoading = false;
            }
        },

        /** Opening a day loads its hours; past days and non-clinic days stay shut. */
        async pickDay(cell) {
            if (cell.blank || !cell.open) return;

            const date = cell.date;
            this.selectedDate = date;
            this.dayLoading = true;
            this.dayFailed = false;

            try {
                const data = await getJson(`${this.dayUrl}?date=${date}`);
                if (date === this.selectedDate) this.day = data;
            } catch (error) {
                if (date === this.selectedDate) {
                    this.day = null;
                    this.dayFailed = true;
                }
                console.error('Clinic calendar day fetch failed', error);
            } finally {
                if (date === this.selectedDate) this.dayLoading = false;
            }
        },

        /** "BR-2026-920 (CCS) · 20" — who holds the hour, and how many seats each. */
        batchText(batch) {
            return `${batch.ref} (${batch.college}) · ${batch.count}`;
        },
    }));
}
