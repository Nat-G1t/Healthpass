import test from 'node:test';
import assert from 'node:assert/strict';

import { kioskMachine, SAMPLE_COUNT } from '../../resources/js/kiosk/state-machine.js';

/**
 * D-74, D-80 — the kiosk takes five readings per vital (height, weight,
 * temperature) and records the steadiest group's average; FR-KSK-17 shows
 * height in feet and inches too.
 *
 * Run with `npm run test:js`. Headless, like kiosk-state-machine.test.js: the
 * Alpine magics are stubbed and the timers are Node's mock timers.
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
    thresholds: { tempMax: 37.2, bpSystolic: 140, bpDiastolic: 90, hrMax: 100 },
    bmiNormalMin: 18.5,
    bmiNormalMax: 25,
    kiosk: { idleTimeoutSeconds: 90, completeResetSeconds: 12, recheckRestMinutes: 10 },
};

const SCAN_SETTLE_MS = 1300; // > SCAN_MS (1200)
const WINDOW_MS = 6000; // SAMPLE_WINDOW_MS

function machineAtVitals(step) {
    const m = kioskMachine();
    m.$refs = { root: { dataset: {} } };
    m.$nextTick = (cb) => cb && cb();
    m.config = CONFIG;
    m.state.screen = 'vitals';
    m.state.vitalStep = step;
    return m;
}

function feed(m, sensorKey, values) {
    for (const v of values) m.onSerialReading({ [sensorKey]: v });
}

// ── Five samples, steadiest group (D-74, D-80) ───────────────────────────────

test("Nat's weight stream is recorded as 52.7 kg from the sensor", (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(2);

    // The last five of Nat's D-74 stream (48.7, 49.3, 50.4, 52.9, 52.2, 52.8,
    // 52.9) — the kiosk now decides at five (D-80).
    feed(m, 'W', [50.4, 52.9, 52.2, 52.8, 52.9]);
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'captured');
    assert.equal(m.currentStep().values.weight, 52.7); // not the first reading, 50.4
    assert.equal(m.currentStep().method, 'sensor');
});

test('the step shows Measuring while it collects, and does not decide early', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(1);

    feed(m, 'H', Array(SAMPLE_COUNT - 1).fill(170));

    assert.equal(m.stepPhase(), 'sampling'); // rendered by the scanning card
    assert.deepEqual(m.currentStep().values, {});
});

test('height is averaged to whole centimetres', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(1);

    feed(m, 'H', [150, 175, 174, 175, 175]); // steady group averages 174.75
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.currentStep().values.height, 175);
});

test('after the window, three samples are enough to decide', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(3);

    feed(m, 'T', [36.7, 36.9, 36.8]);
    t.mock.timers.tick(WINDOW_MS);
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'captured');
    assert.equal(m.currentStep().values.temperature, 36.8);
});

test('with fewer than three samples the window passes and it keeps waiting', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(3);

    feed(m, 'T', [36.7, 36.9]);
    t.mock.timers.tick(WINDOW_MS + SCAN_SETTLE_MS);
    assert.equal(m.stepPhase(), 'sampling'); // no number invented from two readings

    feed(m, 'T', [36.8]); // the third, after the window, decides straight away
    t.mock.timers.tick(SCAN_SETTLE_MS);
    assert.equal(m.currentStep().values.temperature, 36.8);
});

test('a sensor that stops mid-sampling still idle-resets, never inventing a number', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(2);

    feed(m, 'W', [60, 60.2]);
    t.mock.timers.tick(90 * 1000);

    assert.equal(m.state.screen, 'welcome');
    assert.deepEqual(m.state.vitalSteps[2].values, {});
});

test('every buffered reading re-arms the idle timer', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(2);

    // Two samples 60 s apart, then 60 s more: 120 s in all, but never 90 s
    // without a reading, so the session is still here.
    feed(m, 'W', [60]);
    t.mock.timers.tick(60 * 1000);
    feed(m, 'W', [60.1]);
    t.mock.timers.tick(60 * 1000);

    assert.equal(m.state.screen, 'vitals');
});

test('an out-of-range steady group still fails the range check', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(3);

    feed(m, 'T', Array(SAMPLE_COUNT).fill(99));
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'ready');
    assert.match(m.currentStep().notice, /enter it manually/i);
    assert.deepEqual(m.currentStep().samples, []); // a retry starts empty
});

test('blood pressure is not sampled — one reading captures', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(4);

    m.onSerialReading({ S: 118, D: 76, R: 72 });
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'captured');
    assert.deepEqual(m.currentStep().values, { systolic: 118, diastolic: 76, heartRate: 72 });
});

// ── The manual pad, Retry, Previous and the re-check start fresh ─────────────

test('opening the pad mid-sampling drops the buffer and the typed value wins', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(2);

    feed(m, 'W', [70, 70.1, 70.2]);
    m.openPad();
    assert.equal(m.state.pad.open, true);
    assert.deepEqual(m.currentStep().samples, []);

    // The scale keeps streaming while the nurse types; none of it is collected.
    feed(m, 'W', Array(SAMPLE_COUNT).fill(70));
    assert.equal(m.stepPhase(), 'ready');
    assert.deepEqual(m.currentStep().samples, []);

    m.padKey('6');
    m.padKey('4');
    m.padConfirm();
    t.mock.timers.tick(WINDOW_MS + SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'captured');
    assert.equal(m.currentStep().values.weight, 64);
    assert.equal(m.currentStep().method, 'manual');
});

test('Retry after a capture samples a fresh full set of readings', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(1);

    feed(m, 'H', Array(SAMPLE_COUNT).fill(160));
    t.mock.timers.tick(SCAN_SETTLE_MS);
    m.retryVital();

    feed(m, 'H', Array(SAMPLE_COUNT - 1).fill(170));
    assert.equal(m.stepPhase(), 'sampling'); // four new ones are not yet five
    feed(m, 'H', [170]);
    t.mock.timers.tick(SCAN_SETTLE_MS);
    assert.equal(m.currentStep().values.height, 170);
});

test('Previous step mid-sampling leaves nothing half-collected behind', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(2);

    feed(m, 'W', [60, 60.1, 60.2]);
    m.prevVital();
    t.mock.timers.tick(WINDOW_MS + SCAN_SETTLE_MS);

    assert.equal(m.state.vitalSteps[2].phase, 'ready');
    assert.deepEqual(m.state.vitalSteps[2].samples, []);
    assert.deepEqual(m.state.vitalSteps[2].values, {});
});

test('a D-72 re-check samples the temperature afresh', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(3);
    feed(m, 'T', [38.4, 38.5, 38.4]); // a first pass left mid-sampling

    m.arriveAtIdentity({
        studentUserId: 7,
        loginMethod: 'qr',
        fullName: 'Juan Santos',
        hasAppointmentToday: true,
        formType: 'clearance',
        recheck: {
            steps: ['temp'],
            kept: { vitals: { height: 170, weight: 60, temperature: 38.5, systolic: 118, diastolic: 76, heartRate: 72 } },
        },
    });
    m.confirmIdentity();
    assert.deepEqual(m.currentStep().samples, []);

    feed(m, 'T', Array(SAMPLE_COUNT).fill(36.9));
    t.mock.timers.tick(WINDOW_MS + SCAN_SETTLE_MS);
    assert.equal(m.currentStep().values.temperature, 36.9); // no 38.x leaked in
});

test('reset to Welcome clears a half-filled buffer', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(2);

    feed(m, 'W', [60, 60.1]);
    m.reset();

    assert.equal(m._sampleTimer, null);
    assert.deepEqual(m.state.vitalSteps[2].samples, []);
});

test('the dev Simulate button shows the steady average, not the first number', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(2);

    m.simulateReading();
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.currentStep().values.weight, 58); // its first reading was 55
});

// ── Height in feet and inches (FR-KSK-17) ────────────────────────────────────

test('175 cm reads 5 ft 8.9 in', () => {
    assert.equal(kioskMachine().formatFeetInches(175), '5 ft 8.9 in');
});

test('183 cm reads 6 ft 0.0 in', () => {
    assert.equal(kioskMachine().formatFeetInches(183), '6 ft 0.0 in');
});

test('inches that round up to 12.0 carry into the next foot', () => {
    // 182.8 cm = 71.97 in = 5 ft 11.97 in, which rounds to 12.0.
    assert.equal(kioskMachine().formatFeetInches(182.8), '6 ft 0.0 in');
});

test('no height yet shows nothing', () => {
    assert.equal(kioskMachine().formatFeetInches(null), '');
});
