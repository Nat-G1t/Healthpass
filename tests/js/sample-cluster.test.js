import test from 'node:test';
import assert from 'node:assert/strict';

import { pickSteadiest, roundTo } from '../../resources/js/kiosk/sample-cluster.js';

/**
 * D-74 — the steadiest-cluster average, tested as pure logic.
 *
 * Run with `npm run test:js`. pickSteadiest() returns the raw mean; the state
 * machine rounds it with roundTo() to the field's decimals, so the tests that
 * compare to a displayed number round the same way.
 */

test("Nat's weight example averages the steady four to 52.7 kg", () => {
    const samples = [48.7, 49.3, 50.4, 52.9, 52.2, 52.8, 52.9];

    assert.equal(roundTo(pickSteadiest(samples, 1.0), 1), 52.7);
});

test('five readings with one outlier average the steady four (D-80)', () => {
    // The kiosk's own count: averaging all five would give 168.6, a height
    // the student is not.
    const samples = [163, 163, 164, 190, 163];

    assert.equal(roundTo(pickSteadiest(samples, 2), 0), 163);
});

test('seven identical readings return that reading', () => {
    assert.equal(pickSteadiest(Array(7).fill(163), 2), 163);
});

test('a bimodal set picks the larger group, not the middle', () => {
    // Four at ~60, three at ~70: averaging all seven would give 64, a number
    // the scale never showed.
    const samples = [60.1, 70.0, 60.3, 70.2, 59.9, 70.1, 60.1];

    assert.equal(roundTo(pickSteadiest(samples, 1.0), 1), 60.1);
});

test('equal-sized groups go to the tighter one', () => {
    // 50.0/50.9/51.0 (spread 1.0) against 55.0/55.1/55.2 (spread 0.2).
    const samples = [50.0, 55.0, 50.9, 55.1, 51.0, 55.2, 42.0];

    assert.equal(roundTo(pickSteadiest(samples, 1.0), 1), 55.1);
});

test('a full tie goes to the group that arrived later', () => {
    // Same size, same spread — the student started near 70 and settled at 60,
    // so the 60 group (the last three readings) wins even though it sorts first.
    const samples = [70.0, 70.2, 70.4, 65.0, 60.0, 60.2, 60.4];

    assert.equal(roundTo(pickSteadiest(samples, 1.0), 1), 60.2);
});

test('exactly three samples still decide', () => {
    assert.equal(roundTo(pickSteadiest([36.7, 36.9, 36.8], 0.3), 1), 36.8);
});

test('a gap equal to the tolerance still counts as one group', () => {
    // 37.1 - 36.8 is 0.30000000000000426 in floating point.
    assert.equal(roundTo(pickSteadiest([36.8, 37.1, 36.8, 37.1], 0.3), 2), 36.95);
});

test("the caller's array is not sorted or changed", () => {
    const samples = [48.7, 49.3, 50.4, 52.9, 52.2, 52.8, 52.9];
    const copy = [...samples];

    pickSteadiest(samples, 1.0);

    assert.deepEqual(samples, copy);
});

test('no samples gives no number', () => {
    assert.equal(pickSteadiest([], 1.0), null);
});
