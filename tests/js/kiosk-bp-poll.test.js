import test from 'node:test';
import assert from 'node:assert/strict';

import { kioskMachine } from '../../resources/js/kiosk/state-machine.js';
import { fetchLatestBpReading } from '../../resources/js/kiosk/bp-poll.js';

/**
 * Bluetooth BP poll (D-58, FR-KSK-07a). Run with `npm run test:js`.
 *
 * The kiosk polls /kiosk/bp-reading/latest while the BP step waits, claims a
 * NEW reading, and feeds it through the same sensor path Web Serial uses. A
 * fake `fetch` stands in for the server; each test calls pollBpReading()
 * directly (one call = one 2 s tick) and uses Node's mock timers for the scan
 * animation and the idle timer.
 */

// Mirrors config/healthpass.php — the numbers the Blade injects at runtime.
const CONFIG = {
    validation: {
        height_cm: { min: 50, max: 250 },
        weight_kg: { min: 10, max: 300 },
        temperature_c: { min: 30.0, max: 45.0 },
        bp_systolic: { min: 60, max: 260 },
        bp_diastolic: { min: 30, max: 160 },
        heart_rate: { min: 30, max: 220 },
    },
    kiosk: { idleTimeoutSeconds: 90 },
};

const SCAN_SETTLE_MS = 1300; // > SCAN_MS (1200)
const LATEST_URL = '/kiosk/bp-reading/latest';
const CLAIM_URL = '/kiosk/bp-reading/claim';

/** A reading as the latest endpoint returns it. */
function reading(overrides = {}) {
    return {
        systolic: 128,
        diastolic: 82,
        pulse: 72,
        suspect: false,
        received_at: '2026-09-15T14:30:07.000001+08:00',
        ...overrides,
    };
}

/**
 * A fake server behind globalThis.fetch. `latest` answers whatever
 * server.reading holds; `claim` succeeds unless server.claimOk is false;
 * server.down makes every request fail. Each requested URL is recorded.
 */
function fakeServer() {
    const server = { reading: null, claimOk: true, down: false, requests: [] };
    globalThis.fetch = async (url) => {
        server.requests.push(url);
        if (server.down) throw new TypeError('Failed to fetch');
        if (url === LATEST_URL) {
            return { ok: true, status: 200, json: async () => ({ reading: server.reading }) };
        }
        return {
            ok: server.claimOk,
            status: server.claimOk ? 200 : 409,
            json: async () => ({ ok: server.claimOk }),
        };
    };
    return server;
}

/** A headless kiosk parked on a vitals step, pointed at the fake server. */
function machineAt(step) {
    const m = kioskMachine();
    m.$refs = { root: { dataset: { bpLatestUrl: LATEST_URL, bpClaimUrl: CLAIM_URL, csrf: 'token' } } };
    m.$nextTick = (cb) => cb && cb();
    m.config = CONFIG;
    m.state.screen = 'vitals';
    m.state.vitalStep = step;
    return m;
}

/** Type a whole BP step on the manual pad: systolic → diastolic → heart rate. */
function typeBp(m, systolic, diastolic, heartRate) {
    m.openPad();
    for (const value of [systolic, diastolic, heartRate]) {
        for (const digit of String(value)) m.padKey(digit);
        m.padConfirm();
    }
}

// ── Baseline: never a reading left over from before the step ────────────────

test('a reading already waiting when the BP step starts is not used', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    server.reading = reading(); // e.g. the previous student's
    const m = machineAt(4);

    await m.pollBpReading(); // first look = baseline
    await m.pollBpReading(); // the same reading again
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'ready');
    assert.deepEqual(m.currentStep().values, {});
    assert.ok(!server.requests.includes(CLAIM_URL));
});

test('a failed poll does not count as the baseline', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    server.reading = reading(); // left over, but the first poll can't see it
    server.down = true;
    const m = machineAt(4);

    await m.pollBpReading(); // fails: nothing is known yet
    server.down = false;
    await m.pollBpReading(); // the first real look is the baseline

    assert.ok(!server.requests.includes(CLAIM_URL));
    assert.equal(m.stepPhase(), 'ready');
});

// ── A new reading ────────────────────────────────────────────────────────────

test('a new reading is claimed and captured through the sensor path', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.pollBpReading(); // baseline: nothing waiting
    server.reading = reading();
    await m.pollBpReading();

    assert.ok(server.requests.includes(CLAIM_URL));
    assert.equal(m.stepPhase(), 'scanning');
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'captured');
    assert.equal(m.currentStep().method, 'sensor');
    assert.deepEqual(m.currentStep().values, { systolic: 128, diastolic: 82, heartRate: 72 });
    assert.equal(m.currentStep().suspect, false);
});

test('the same reading is never applied twice, even after Retry', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.pollBpReading();
    server.reading = reading();
    await m.pollBpReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);
    assert.equal(m.stepPhase(), 'captured');

    m.retryVital(); // back to ready; the fake server still offers the old reading
    await m.pollBpReading();
    await m.pollBpReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'ready');
    assert.deepEqual(m.currentStep().values, {});
});

// ── Manual entry stays first-class ───────────────────────────────────────────

test('a reading waits while the manual pad is open, and lands once it is cancelled', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.pollBpReading(); // baseline
    m.openPad();
    server.reading = reading();
    await m.pollBpReading();

    assert.ok(!server.requests.includes(CLAIM_URL)); // nothing lands on numbers being typed
    assert.equal(m.state.pad.open, true);

    m.padCancel();
    await m.pollBpReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'captured');
    assert.equal(m.currentStep().method, 'sensor');
});

test('numbers typed on the pad are never overwritten by a later reading', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.pollBpReading(); // baseline
    typeBp(m, 120, 80, 70);
    server.reading = reading();
    await m.pollBpReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.currentStep().method, 'manual');
    assert.deepEqual(m.currentStep().values, { systolic: 120, diastolic: 80, heartRate: 70 });
    assert.ok(!server.requests.includes(CLAIM_URL));
});

// ── Failing quietly ──────────────────────────────────────────────────────────

test('an unreachable server fails quietly and the pad still works', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    server.down = true;
    const m = machineAt(4);

    await m.pollBpReading();
    await m.pollBpReading();

    assert.equal(m.stepPhase(), 'ready');
    assert.equal(m.currentStep().notice, ''); // no error on screen
    m.openPad();
    assert.equal(m.state.pad.open, true);
});

test('a refused claim is ignored quietly', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.pollBpReading();
    server.reading = reading();
    server.claimOk = false; // expired, or another session took it
    await m.pollBpReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'ready');
    assert.deepEqual(m.currentStep().values, {});
    assert.equal(m.currentStep().notice, '');
});

// ── What the reading carries ─────────────────────────────────────────────────

test('a suspect reading is marked for the retake suggestion, and Retry clears it', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.pollBpReading();
    server.reading = reading({ suspect: true });
    await m.pollBpReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'captured'); // kept — only a suggestion
    assert.equal(m.currentStep().suspect, true);

    m.retryVital();
    assert.equal(m.currentStep().suspect, false);
});

test('a reading without a pulse is not captured and nudges to retry or enter it manually', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.pollBpReading();
    server.reading = reading({ pulse: null });
    await m.pollBpReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'ready');
    assert.match(m.currentStep().notice, /enter it manually/i);
});

test('nothing is requested unless the BP step is waiting', async () => {
    const server = fakeServer();
    const m = machineAt(3); // temperature step

    await m.pollBpReading();

    assert.equal(server.requests.length, 0);
});

// ── fetchLatestBpReading ─────────────────────────────────────────────────────

test('fetchLatestBpReading tells "no reading" apart from a failed request', async () => {
    const ok = (body) => async () => ({ ok: true, json: async () => body });

    assert.deepEqual(await fetchLatestBpReading(LATEST_URL, ok({ reading: null })), { reachable: true, reading: null });
    assert.deepEqual(await fetchLatestBpReading(LATEST_URL, ok({ reading: reading() })), { reachable: true, reading: reading() });
    assert.deepEqual(await fetchLatestBpReading(LATEST_URL, async () => ({ ok: false })), { reachable: false, reading: null });
    assert.deepEqual(
        await fetchLatestBpReading(LATEST_URL, async () => {
            throw new TypeError('offline');
        }),
        { reachable: false, reading: null },
    );
});
