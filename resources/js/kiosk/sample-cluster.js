/**
 * D-74 — pick the steadiest group out of a burst of sensor readings.
 *
 * Pure logic, no Alpine and no DOM: the state machine buffers the readings for
 * a step (height, weight, temperature) and hands them here once it has enough.
 * A student still settling onto the scale produces a few stray numbers before
 * the steady ones, so instead of trusting the first reading we find the
 * largest group of readings that sit within the field's tolerance of each
 * other and average that group.
 */

// Floating-point slack for "within tolerance" and "same spread": 37.1 - 36.8
// is 0.30000000000000426 in JavaScript, which must still count as 0.3.
const EPSILON = 1e-9;

/**
 * The average of the steadiest cluster of `samples`, or null if there are none.
 *
 * Sort the readings, then for each starting reading take every reading within
 * `tolerance` above it — that is one "run". The longest run wins; a tie goes to
 * the run with the smaller spread, then to the run holding the most RECENT
 * reading (a student settles over time, so the later group is more trustworthy).
 *
 * The caller's array is never sorted or changed.
 */
export function pickSteadiest(samples, tolerance) {
    if (samples.length === 0) return null;

    // Keep each reading's arrival order so a tie can prefer the later group.
    const sorted = samples
        .map((value, order) => ({ value, order }))
        .sort((a, b) => a.value - b.value || a.order - b.order);

    let best = null;
    for (let i = 0; i < sorted.length; i++) {
        let j = i;
        while (j + 1 < sorted.length && sorted[j + 1].value - sorted[i].value <= tolerance + EPSILON) j++;

        const run = sorted.slice(i, j + 1);
        const candidate = {
            values: run.map((s) => s.value),
            spread: sorted[j].value - sorted[i].value,
            latest: Math.max(...run.map((s) => s.order)),
        };
        if (best === null || isSteadier(candidate, best)) best = candidate;
    }

    return best.values.reduce((sum, v) => sum + v, 0) / best.values.length;
}

/** Longer run first; then the tighter one; then the one with the more recent reading. */
function isSteadier(a, b) {
    if (a.values.length !== b.values.length) return a.values.length > b.values.length;
    if (Math.abs(a.spread - b.spread) > EPSILON) return a.spread < b.spread;
    return a.latest > b.latest;
}

/** Round to a number of decimal places (0 = whole numbers). 52.699999 → 52.7. */
export function roundTo(value, decimals) {
    const factor = 10 ** decimals;
    return Math.round(value * factor) / factor;
}
