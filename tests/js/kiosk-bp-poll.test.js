import test from 'node:test';
import assert from 'node:assert/strict';

import { kioskMachine } from '../../resources/js/kiosk/state-machine.js';
import { fetchLatestBpReading } from '../../resources/js/kiosk/bp-poll.js';

/**
 * Bluetooth BP poll (D-58, FR-KSK-07a) and the Start button (D-59).
 * Run with `npm run test:js`.
 *
 * The student taps Start on the BP step; the kiosk then polls
 * /kiosk/bp-reading/latest, claims a NEW reading, and feeds it through the same
 * sensor path Web Serial uses. A fake `fetch` stands in for the server; each
 * test calls pollBpReading() directly (one call = one poll tick) and uses
 * Node's mock timers for the scan animation, the wait limit and the idle timer
 * — every test that taps Start enables them, or the real 2-minute timer hangs.
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
    kiosk: { idleTimeoutSeconds: 90, bpWaitSeconds: 120 },
};

const SCAN_SETTLE_MS = 1300; // > SCAN_MS (1200)
const IDLE_MS = 90 * 1000;
const WAIT_MS = 120 * 1000;
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

/** How many times the kiosk asked for the latest reading. */
function latestPolls(server) {
    return server.requests.filter((url) => url === LATEST_URL).length;
}

// ── Start ────────────────────────────────────────────────────────────────────

test('nothing is asked of the server until Start is tapped', async () => {
    const server = fakeServer();
    const m = machineAt(4);

    await m.pollBpReading();
    await m.pollBpReading();

    assert.equal(server.requests.length, 0);
    assert.equal(m.stepPhase(), 'ready');
});

test('Start shows the waiting phase and takes the baseline straight away', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.startBpWait();

    assert.equal(m.stepPhase(), 'waiting');
    assert.deepEqual(server.requests, [LATEST_URL]);
});

test('Start does nothing off the blood-pressure step', async () => {
    const server = fakeServer();
    const m = machineAt(3); // temperature step

    await m.startBpWait();

    assert.equal(m.stepPhase(), 'ready');
    assert.equal(server.requests.length, 0);
});

// ── Baseline: never a reading from before Start ─────────────────────────────

test('a reading already waiting when Start is tapped is not used', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    server.reading = reading(); // e.g. the previous student's
    const m = machineAt(4);

    await m.startBpWait(); // first look = baseline
    await m.pollBpReading(); // the same reading again
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'waiting');
    assert.deepEqual(m.currentStep().values, {});
    assert.ok(!server.requests.includes(CLAIM_URL));
});

test('a failed poll does not count as the baseline', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    server.reading = reading(); // left over, but the first poll can't see it
    server.down = true;
    const m = machineAt(4);

    await m.startBpWait(); // fails: nothing is known yet
    server.down = false;
    await m.pollBpReading(); // the first real look is the baseline

    assert.ok(!server.requests.includes(CLAIM_URL));
    assert.equal(m.stepPhase(), 'waiting');
});

// ── A new reading ────────────────────────────────────────────────────────────

test('a new reading is claimed and captured through the sensor path', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.startBpWait(); // baseline: nothing waiting
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

test('the same reading is never applied twice, even after Retry and Start', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.startBpWait();
    server.reading = reading();
    await m.pollBpReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);
    assert.equal(m.stepPhase(), 'captured');

    m.retryVital(); // back to Start; the fake server still offers the old reading
    await m.startBpWait();
    await m.pollBpReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'waiting');
    assert.deepEqual(m.currentStep().values, {});
});

test('a refused claim is ignored quietly and the step keeps waiting', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.startBpWait();
    server.reading = reading();
    server.claimOk = false; // expired, or another session took it
    await m.pollBpReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'waiting');
    assert.deepEqual(m.currentStep().values, {});
    assert.equal(m.currentStep().notice, '');
});

// ── The wait limit, Cancel and the idle reset ───────────────────────────────

test('with no reading within 2 minutes the step goes back to Start with a nudge', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.startBpWait();
    t.mock.timers.tick(WAIT_MS);

    assert.equal(m.stepPhase(), 'ready');
    assert.match(m.currentStep().notice, /Tap Start to try again/);
    await m.pollBpReading();
    assert.equal(latestPolls(server), 1); // stopped asking
});

test('Cancel goes back to Start and stops asking', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.startBpWait();
    m.endBpWait();
    await m.pollBpReading();

    assert.equal(m.stepPhase(), 'ready');
    assert.equal(m.currentStep().notice, '');
    assert.equal(latestPolls(server), 1);
});

test('a long wait never idle-resets the session; the idle countdown returns after it', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    fakeServer();
    const m = machineAt(4);
    m.bumpIdle(); // the tap on Start

    await m.startBpWait();
    t.mock.timers.tick(60 * 1000);
    m.bumpIdle(); // the student touches the screen mid-wait
    t.mock.timers.tick(59 * 1000); // 119 s in — well past the 90 s idle timeout

    assert.equal(m.state.screen, 'vitals');
    assert.equal(m.stepPhase(), 'waiting');

    t.mock.timers.tick(1000); // the 2-minute limit
    assert.equal(m.stepPhase(), 'ready');
    t.mock.timers.tick(IDLE_MS); // then an abandoned kiosk still resets
    assert.equal(m.state.screen, 'welcome');
});

test('a captured reading restarts the idle countdown', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.startBpWait();
    assert.equal(m._idleTimer, null); // paused while waiting
    server.reading = reading();
    await m.pollBpReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'captured');
    t.mock.timers.tick(IDLE_MS); // the student walks away after the reading
    assert.equal(m.state.screen, 'welcome');
});

// ── Manual entry stays first-class ───────────────────────────────────────────

test('opening the manual pad stops the wait, so a reading then is not used', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.startBpWait();
    m.openPad();
    assert.equal(m.state.pad.open, true);
    assert.equal(m.stepPhase(), 'ready');

    server.reading = reading();
    await m.pollBpReading();
    assert.ok(!server.requests.includes(CLAIM_URL)); // nothing lands on numbers being typed

    m.padCancel();
    assert.equal(m.stepPhase(), 'ready'); // back to Start
});

test('numbers typed on the pad are never overwritten by a later reading', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.startBpWait();
    typeBp(m, 120, 80, 70);
    server.reading = reading();
    await m.pollBpReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.currentStep().method, 'manual');
    assert.deepEqual(m.currentStep().values, { systolic: 120, diastolic: 80, heartRate: 70 });
    assert.ok(!server.requests.includes(CLAIM_URL));
});

test('Previous step stops the wait', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.startBpWait();
    m.prevVital();
    await m.pollBpReading();

    assert.equal(m.state.vitalStep, 3);
    assert.equal(m.state.vitalSteps[4].phase, 'ready');
    assert.equal(latestPolls(server), 1);
});

// ── Failing quietly ──────────────────────────────────────────────────────────

test('an unreachable server fails quietly and the pad still works', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    server.down = true;
    const m = machineAt(4);

    await m.startBpWait();
    await m.pollBpReading();

    assert.equal(m.stepPhase(), 'waiting');
    assert.equal(m.currentStep().notice, ''); // no error on screen
    m.openPad();
    assert.equal(m.state.pad.open, true);
});

// ── What the reading carries ─────────────────────────────────────────────────

test('a suspect reading is marked for the retake suggestion, and Retry clears it', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAt(4);

    await m.startBpWait();
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

    await m.startBpWait();
    server.reading = reading({ pulse: null });
    await m.pollBpReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'ready'); // Start is back
    assert.match(m.currentStep().notice, /enter it manually/i);
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
