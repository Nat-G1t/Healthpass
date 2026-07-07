import test from 'node:test';
import assert from 'node:assert/strict';

import { kioskMachine } from '../../resources/js/kiosk/state-machine.js';

/**
 * Kiosk state-machine hardening (FR-KSK-05/06/07/08/15).
 *
 * Run with `npm run test:js`. The Alpine component is a plain object factory,
 * so it runs headless: we stub the two Alpine magics the tested paths touch
 * ($refs, $nextTick) and inject the SAME validation config the server injects
 * from config/healthpass.php. Timer-driven behaviour (the SCAN_MS settle, the
 * 90s idle reset) uses Node's mock timers.
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
    thresholds: { tempMax: 37.2, bpSystolic: 140, bpDiastolic: 90 },
    bmiObese: 30,
    kiosk: { idleTimeoutSeconds: 90, completeResetSeconds: 12 },
};

const SCAN_SETTLE_MS = 1300; // > SCAN_MS (1200) — lets the scanning phase resolve
const IDLE_MS = 90 * 1000;

/** A headless kiosk component parked on a vitals step. */
function machineAtVitals(step = 1) {
    const m = kioskMachine();
    m.$refs = { root: { dataset: {} } }; // no reset URL → forgetKioskIdentity no-ops
    m.$nextTick = (cb) => cb && cb();
    m.config = CONFIG;
    m.state.screen = 'vitals';
    m.state.vitalStep = step;
    return m;
}

// ── Absurd-but-parseable sensor values (FR-KSK-08) ───────────────────────────
// The parser hands T:99 / H:999 through (see serial-parser.test.js); the state
// machine must FAIL them against the config ranges and fall back to 'ready'
// with a retry/manual nudge — never crash, never silently accept.

test('T:99 from the sensor fails range validation and prompts retry/manual', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(3); // temperature step

    m.onSerialReading({ T: 99 });
    assert.equal(m.stepPhase(), 'scanning');

    t.mock.timers.tick(SCAN_SETTLE_MS);
    assert.equal(m.stepPhase(), 'ready'); // rejected — back to ready, not captured
    assert.match(m.currentStep().notice, /enter it manually/i);
    assert.deepEqual(m.currentStep().values, {}); // nothing silently accepted
});

test('H:999 from the sensor fails range validation and prompts retry/manual', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(1); // height step

    m.onSerialReading({ H: 999 });
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'ready');
    assert.deepEqual(m.currentStep().values, {});
});

test('an incomplete BP reading is rejected whole — no partial capture', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(4);

    m.onSerialReading({ S: 118, D: 76 }); // heart rate missing from the group
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'ready');
    assert.deepEqual(m.currentStep().values, {});
});

test('a plausible sensor reading captures with sensor provenance', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(3);

    m.onSerialReading({ T: 36.8 });
    t.mock.timers.tick(SCAN_SETTLE_MS);

    assert.equal(m.stepPhase(), 'captured');
    assert.equal(m.currentStep().method, 'sensor');
    assert.equal(m.currentStep().values.temperature, 36.8);
});

// ── entry_method provenance (FR-KSK-06) ──────────────────────────────────────
// The client reports each step's provenance; the server rolls the list up to
// sensor / manual / mixed (covered in KioskSubmitTest). This locks the client
// half: a mixed session must actually SAY sensor+manual.

test('a session mixing sensor and manual steps reports both provenances', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(1);

    m.onSerialReading({ H: 163 }); // height via sensor
    t.mock.timers.tick(SCAN_SETTLE_MS);
    assert.equal(m.currentStep().method, 'sensor');

    m.nextVital(); // weight via the manual pad
    m.openPad();
    m.padKey('6');
    m.padKey('4');
    m.padConfirm();
    assert.equal(m.currentStep().method, 'manual');

    assert.deepEqual(m.buildSubmission().vitalMethods, ['sensor', 'manual']);
});

// ── Mid-step disconnect (FR-KSK-07 / FR-HW-05) ───────────────────────────────

test('mid-step disconnect shows a non-blocking notice and manual entry still works', () => {
    const m = machineAtVitals(1);

    m.onSerialStatus('disconnected');
    assert.match(m.serial.notice, /manual entry still works/i); // notice, not a modal

    // No dead end: the disguised pad still opens and commits the step.
    m.openPad();
    assert.equal(m.state.pad.open, true);
    m.padKey('1');
    m.padKey('6');
    m.padKey('3');
    m.padConfirm();
    assert.equal(m.currentStep().phase, 'captured');
    assert.equal(m.currentStep().method, 'manual');
    assert.equal(m.currentStep().values.height, 163);
});

test('manual pad rejects an out-of-range value with a re-entry prompt', () => {
    const m = machineAtVitals(1);

    m.openPad();
    m.padKey('9');
    m.padKey('9');
    m.padKey('9');
    m.padConfirm();

    assert.equal(m.state.pad.open, true); // still open, asking again
    assert.match(m.state.pad.error, /between 50 and 250/);
    assert.equal(m.currentStep().phase, 'ready'); // nothing committed
});

// ── Serial activity pings the 90s idle timer (FR-KSK-15) ─────────────────────
// A slow sensor wait (e.g. the BP cuff inflating while the hub streams other
// keys) must not let the idle reset fire mid-measurement. Any line arriving
// while the current step is still uncaptured counts as interaction.

test('a serial line mid-measurement re-arms the idle timer', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(4); // waiting on the BP cuff

    assert.equal(m._idleTimer, null);
    m.onSerialReading({ H: 163 }); // hub is alive, but no BP keys yet
    assert.notEqual(m._idleTimer, null); // idle countdown restarted
});

test('a slow BP wait with a streaming hub never idle-resets mid-measurement', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(4);
    m.bumpIdle(); // student tapped into the step

    // 80s of waiting, hub line every 10s, still no BP keys.
    for (let i = 0; i < 8; i += 1) {
        t.mock.timers.tick(10 * 1000);
        m.onSerialReading({ T: 36.8 });
    }
    t.mock.timers.tick(80 * 1000); // reading finally lands within the fresh window
    assert.equal(m.state.screen, 'vitals'); // never reset mid-measurement
});

test('serial lines after the step is captured do NOT hold the session open', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(3);

    m.onSerialReading({ T: 36.8 });
    t.mock.timers.tick(SCAN_SETTLE_MS);
    assert.equal(m.stepPhase(), 'captured');

    // The hub keeps streaming, but the student has walked away: the idle
    // reset must still fire so their data leaves the screen (FR-KSK-15).
    for (let i = 0; i < 12; i += 1) {
        t.mock.timers.tick(10 * 1000);
        m.onSerialReading({ T: 36.8 });
    }
    assert.equal(m.state.screen, 'welcome');
    assert.equal(m.state.identity, null); // wholesale reset, nothing lingers
});

test('serial lines off the vitals screen do not touch the idle timer', () => {
    const m = machineAtVitals(1);
    m.state.screen = 'questionnaire';

    m.onSerialReading({ H: 163 });
    assert.equal(m._idleTimer, null);
});

test('a fully silent abandon still idle-resets after 90s', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtVitals(2);
    m.bumpIdle();

    t.mock.timers.tick(IDLE_MS);
    assert.equal(m.state.screen, 'welcome');
});
