/**
 * The month-calendar arithmetic shared by the Director's Review page
 * (director/batch-schedule.js, D-108) and the read-only Clinic Calendar card
 * (shared/clinic-calendar.js, D-119). Plain functions, no Alpine: each
 * component keeps its own state and calls these with it.
 *
 * Dates are 'YYYY-MM-DD' strings from the SERVER — "today" is never read from
 * the browser's clock (BR-20).
 */

/** "October 2026" */
export function monthLabel(year, month) {
    return new Date(year, month - 1, 1).toLocaleString('en-US', { month: 'long', year: 'numeric' });
}

/** Is (year, month) after the server's current month? Earlier months are never shown. */
export function isAfterTodaysMonth(year, month, today) {
    const [y, m] = today.split('-').map(Number);
    return year > y || (year === y && month > m);
}

/** The calendar endpoints answer up to December, two years ahead. */
export function isBeforeLastMonth(year, month, today) {
    const [y] = today.split('-').map(Number);
    return year < y + 2 || month < 12;
}

/** The month `step` (+1 / -1) away from (year, month), as [year, month]. */
export function stepMonth(year, month, step) {
    const date = new Date(year, month - 1 + step, 1);
    return [date.getFullYear(), date.getMonth() + 1];
}

/**
 * One cell per day, padded to the right weekday column with blanks. Each day
 * keeps its server fields and gains its number, whether it is today, and
 * `fill` — the ring: booked ÷ daily capacity, full at capacity.
 */
export function monthCells(year, month, days, today) {
    const firstDow = new Date(year, month - 1, 1).getDay();
    const blanks = Array.from({ length: firstDow }, (_, i) => ({ blank: true, key: `b${i}` }));

    return blanks.concat(days.map((day) => ({
        ...day,
        blank: false,
        key: day.date,
        number: Number(day.date.slice(8)),
        isToday: day.date === today,
        fill: Math.min(1, day.booked / day.capacity),
    })));
}

/** "Tue, Oct 6, 2026" — built from the date's parts, so no timezone shift. */
export function dateLabel(date) {
    if (date === '') return '';
    const [y, m, d] = date.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('en-US', {
        weekday: 'short', month: 'short', day: 'numeric', year: 'numeric',
    });
}

/** GET a JSON feed; throws on a non-2xx answer so the caller's catch runs. */
export async function getJson(url) {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return response.json();
}
