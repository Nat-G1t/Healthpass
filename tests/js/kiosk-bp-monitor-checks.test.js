import test from 'node:test';
import assert from 'node:assert/strict';

import { kioskMachine, BP_MONITOR_CHECKS } from '../../resources/js/kiosk/state-machine.js';

/**
 * The BP monitor's five checks on the kiosk's captured BP result (D-82).
 * Run with `npm run test:js`.
 *
 * A reading from the Bluetooth monitor carries `flags`; the kiosk lists them
 * as "Monitor checks" (display only). A typed BP, a reading with no status, and
 * a Retry show no panel. The fake server and timers follow kiosk-bp-poll.test.js.
 */

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
const LATEST_URL = '/kiosk/bp-reading/latest';
const CLAIM_URL = '/kiosk/bp-reading/claim';

/** Nat's sample (2026-09-25), as the latest endpoint returns it. */
function sampleReading(overrides = {}) {
    return {
        systolic: 134,
        diastolic: 79,
        pulse: 94,
        flags: {
            body_movement: false,
            cuff_too_loose: false,
            irregular_pulse: true,
            pulse_out_of_range: false,
            improper_position: false,
        },
        suspect: false,
        received_at: '2026-09-25T10:00:00.000001+08:00',
        ...overrides,
    };
}

function fakeServer() {
    const server = { reading: null };
    globalThis.fetch = async (url) => {
        if (url === LATEST_URL) {
            return { ok: true, status: 200, json: async () => ({ reading: server.reading }) };
        }
        return { ok: true, status: 200, json: async () => ({ ok: true }) };
    };
    return server;
}

function machineAtBp() {
    const m = kioskMachine();
    m.$refs = { root: { dataset: { bpLatestUrl: LATEST_URL, bpClaimUrl: CLAIM_URL, csrf: 'token' } } };
    m.$nextTick = (cb) => cb && cb();
    m.config = CONFIG;
    m.state.screen = 'vitals';
    m.state.vitalStep = 4;
    return m;
}

/** Start, take the baseline, then let `reading` arrive and settle. */
async function captureFromMonitor(t, m, server, reading) {
    await m.startBpWait();
    server.reading = reading;
    await m.pollBpReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);
}

function typeBp(m, systolic, diastolic, heartRate) {
    m.openPad();
    for (const value of [systolic, diastolic, heartRate]) {
        for (const digit of String(value)) m.padKey(digit);
        m.padConfirm();
    }
}

test('the labels list uses the same five keys as StoreBpReadingRequest::FLAGS', () => {
    assert.deepEqual(
        BP_MONITOR_CHECKS.map((c) => c.key),
        ['body_movement', 'cuff_too_loose', 'irregular_pulse', 'pulse_out_of_range', 'improper_position'],
    );
});

test('a monitor reading with status shows the checks, irregular pulse detected', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAtBp();

    await captureFromMonitor(t, m, server, sampleReading());

    assert.equal(m.stepPhase(), 'captured');
    assert.equal(m.currentStep().monitorChecks.irregular_pulse, true);
    assert.equal(m.showsMonitorChecks(), true);
    assert.equal(m.monitorCheckStatus('irregular_pulse'), 'detected');
    assert.equal(m.monitorCheckStatus('body_movement'), 'ok');
    // Irregular pulse is shown, not a retake prompt: the hint rule is unchanged.
    assert.equal(m.currentStep().suspect, false);
});

test('a missing check reads as not reported, never OK', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAtBp();

    await captureFromMonitor(t, m, server, sampleReading({ flags: { irregular_pulse: false } }));

    assert.equal(m.showsMonitorChecks(), true);
    assert.equal(m.monitorCheckStatus('irregular_pulse'), 'ok');
    assert.equal(m.monitorCheckStatus('cuff_too_loose'), 'unknown');
});

test('a reading with no status shows no panel', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAtBp();

    // The server sends an empty PHP array, which JSON-encodes as [].
    await captureFromMonitor(t, m, server, sampleReading({ flags: [] }));

    assert.equal(m.stepPhase(), 'captured');
    assert.equal(m.showsMonitorChecks(), false);
});

test('Retry clears the checks', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAtBp();

    await captureFromMonitor(t, m, server, sampleReading());
    m.retryVital();

    assert.equal(m.currentStep().monitorChecks, null);
    assert.equal(m.showsMonitorChecks(), false);
});

test('manual entry after a monitor capture shows no panel', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const server = fakeServer();
    const m = machineAtBp();

    await captureFromMonitor(t, m, server, sampleReading());
    assert.equal(m.showsMonitorChecks(), true);

    m.retryVital(); // the pad opens only on a step that is ready
    typeBp(m, 120, 80, 70);

    assert.equal(m.currentStep().method, 'manual');
    assert.equal(m.showsMonitorChecks(), false);
});

test('the dev simulate path carries no checks', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtBp();
    m.simulateReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.currentStep().method, 'sensor');
    assert.equal(m.currentStep().monitorChecks, null);
    assert.equal(m.showsMonitorChecks(), false);
});
