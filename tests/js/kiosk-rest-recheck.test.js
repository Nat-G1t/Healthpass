import test from 'node:test';
import assert from 'node:assert/strict';

import { SCREENS, kioskMachine } from '../../resources/js/kiosk/state-machine.js';

/**
 * Kiosk "Rest & re-check" front-end (FR-KSK-11a / FR-KSK-13a, D-72).
 *
 * Run with `npm run test:js`. The Alpine component is a plain object factory,
 * so it runs headless: we stub the Alpine magics the tested paths touch
 * ($refs, $nextTick) and inject the SAME config the Blade injects from
 * config/healthpass.php, so the client's display flags use the server's
 * thresholds.
 *
 * Nothing here is a security boundary — the server recomputes the flags at
 * /kiosk/rest and re-reads every kept value at /kiosk/recheck. These tests are
 * about the SCREENS: which button Review offers, which vital steps a re-check
 * pass walks, and that a reset leaves nothing of it behind.
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

/** A headless kiosk component with no network wired up. */
function machine() {
    const m = kioskMachine();
    m.$refs = { root: { dataset: {} } }; // no URLs → the POST helpers are never reached
    m.$nextTick = (cb) => cb && cb();
    m.config = CONFIG;
    return m;
}

/** Park a machine on Review with a full first-pass reading. */
function atReview(vitals = {}) {
    const m = machine();
    const v = { height: 170, weight: 60, temperature: 36.8, systolic: 118, diastolic: 76, heartRate: 72, ...vitals };

    m.state.vitalSteps[1] = { ...m.state.vitalSteps[1], phase: 'captured', values: { height: v.height } };
    m.state.vitalSteps[2] = { ...m.state.vitalSteps[2], phase: 'captured', values: { weight: v.weight } };
    m.state.vitalSteps[3] = { ...m.state.vitalSteps[3], phase: 'captured', values: { temperature: v.temperature } };
    m.state.vitalSteps[4] = {
        ...m.state.vitalSteps[4],
        phase: 'captured',
        values: { systolic: v.systolic, diastolic: v.diastolic, heartRate: v.heartRate },
    };
    m.state.screen = 'review';

    return m;
}

/** The identity payload a scan returns for a student whose rest is over. */
function recheckIdentity(steps) {
    return {
        studentUserId: 7,
        loginMethod: 'qr',
        fullName: 'Juan Santos',
        hasAppointmentToday: true,
        formType: 'clearance',
        recheck: {
            steps,
            kept: {
                vitals: { height: 170, weight: 60, temperature: 36.8, systolic: 150, diastolic: 95, heartRate: 72 },
                screening: { skin: true, head: false },
                details: { skin: 'itchy rash' },
                isPregnant: false,
                lastMenstrualPeriod: null,
                socialHistory: null,
            },
        },
    };
}

// ── The Review button swap (D-72 decision 1) ─────────────────────────────────

test("'rest' is a real screen in the ordered flow", () => {
    assert.ok(SCREENS.includes('rest'));
});

test('a normal reading offers Submit, not Rest', () => {
    const m = atReview();
    assert.equal(m.needsRest(), false);
});

test('a high blood pressure swaps Submit for Rest & re-check', () => {
    const m = atReview({ systolic: 150, diastolic: 95 });
    assert.equal(m.needsRest(), true);
    assert.match(m.restReadingLabel(), /blood pressure/);
});

test('a high temperature swaps Submit for Rest & re-check', () => {
    const m = atReview({ temperature: 38.1 });
    assert.equal(m.needsRest(), true);
    assert.equal(m.restReadingLabel(), 'temperature');
});

test('a high heart rate swaps Submit for Rest & re-check', () => {
    const m = atReview({ heartRate: 118 });
    assert.equal(m.needsRest(), true);
    assert.equal(m.restReadingLabel(), 'heart rate');
});

test('an obese BMI alone never asks for a rest', () => {
    // 106 kg at 160 cm = BMI 41.4 — flagged, but resting cannot change it.
    const m = atReview({ height: 160, weight: 106 });
    assert.equal(m.bmiFlagged(m.bmiValue()), true);
    assert.equal(m.needsRest(), false);
});

test('two high readings are both named on the Rest screen', () => {
    const m = atReview({ systolic: 150, diastolic: 95, temperature: 38.1 });
    assert.equal(m.restReadingLabel(), 'blood pressure and temperature');
});

test('a re-check pass never offers a second rest', () => {
    const m = atReview({ systolic: 150, diastolic: 95 });
    m.seedRecheck(recheckIdentity(['bp']).recheck);

    assert.equal(m.isRecheck(), true);
    assert.equal(m.needsRest(), false);
});

// ── The Rest screen (D-72 decision 4) ────────────────────────────────────────

test('the rest screen counts down and auto-resets like Complete', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] });

    const m = atReview({ systolic: 150, diastolic: 95 });
    m.init = undefined; // no $watch here — drive the countdown directly
    m.state.recheck.until = '9:27 AM';
    m.state.screen = 'rest';
    m.startCompleteCountdown();

    assert.equal(m.completeCountdown, 12);

    t.mock.timers.tick(1000);
    assert.equal(m.completeCountdown, 11);

    // Run it out: the countdown resets the session wholesale.
    t.mock.timers.tick(12 * 1000);
    assert.equal(m.state.screen, 'welcome');
    assert.equal(m.state.recheck.until, null);
});

test('the rest screen shows the configured rest length, not a literal', () => {
    const m = machine();
    assert.equal(m.restMinutes(), 10);

    m.config = { ...CONFIG, kiosk: { ...CONFIG.kiosk, recheckRestMinutes: 15 } };
    assert.equal(m.restMinutes(), 15);
});

// ── Coming back too early (D-72 decision 5) ──────────────────────────────────

test('an early re-scan shows the server time and drops the session', () => {
    const m = machine();
    m.state.screen = 'welcome';

    m.arriveAtIdentity({ recheckWaitUntil: '9:27 AM' });

    assert.equal(m.state.screen, 'welcome');
    assert.equal(m.state.identity, null); // nothing bound — they simply wait
    assert.match(m.state.scan.notice, /9:27 AM/);
    assert.match(m.state.scan.notice, /keep resting/i);
});

// ── The re-check pass (D-72 decision 5) ──────────────────────────────────────

test('a re-check goes Identity Confirm straight to vitals, skipping consent', () => {
    const m = machine();

    m.arriveAtIdentity(recheckIdentity(['bp']));
    assert.equal(m.state.screen, 'identity');
    assert.equal(m.isRecheck(), true);

    m.confirmIdentity();
    assert.equal(m.state.screen, 'vitals'); // not 'consent', not 'no-schedule'
    assert.equal(m.state.vitalStep, 4); // the blood-pressure step
    assert.equal(m.state.consentAt, null); // never re-asked
});

test('a bp re-check walks only the blood-pressure step', () => {
    const m = machine();
    m.arriveAtIdentity(recheckIdentity(['bp']));
    m.confirmIdentity();

    assert.deepEqual(m.activeSteps(), [4]);
    assert.equal(m.stepCount(), 1);
    assert.equal(m.stepIndex(), 1);
    assert.equal(m.isFirstStep(), true);
    assert.equal(m.isLastStep(), true);
});

test('a temp re-check walks only the temperature step', () => {
    const m = machine();
    m.arriveAtIdentity(recheckIdentity(['temp']));
    m.confirmIdentity();

    assert.deepEqual(m.activeSteps(), [3]);
    assert.equal(m.state.vitalStep, 3);
});

test('a two-step re-check walks temperature then blood pressure, then Review', () => {
    const m = machine();
    m.arriveAtIdentity(recheckIdentity(['bp', 'temp']));
    m.confirmIdentity();

    assert.deepEqual(m.activeSteps(), [3, 4]);
    assert.equal(m.state.vitalStep, 3);
    assert.equal(m.stepCount(), 2);

    m.nextVital();
    assert.equal(m.state.vitalStep, 4);
    assert.equal(m.stepIndex(), 2);
    assert.equal(m.isLastStep(), true);

    // No questionnaire and no social history — they were answered last time.
    m.nextVital();
    assert.equal(m.state.screen, 'review');
});

test('a first pass still walks all four steps into the questionnaire', () => {
    const m = machine();
    m.arriveAtIdentity({ studentUserId: 7, loginMethod: 'qr', hasAppointmentToday: true, formType: 'clearance' });
    m.confirmIdentity();

    assert.equal(m.state.screen, 'consent'); // consent is NOT skipped
    assert.equal(m.isRecheck(), false);
    assert.deepEqual(m.activeSteps(), [1, 2, 3, 4]);

    m.state.vitalStep = 4;
    m.nextVital();
    assert.equal(m.state.screen, 'questionnaire');
});

test('Back from Review on a re-check returns to the last re-taken step', () => {
    const m = machine();
    m.arriveAtIdentity(recheckIdentity(['bp', 'temp']));
    m.confirmIdentity();
    m.state.screen = 'review';

    m.backFromReview();
    assert.equal(m.state.screen, 'vitals');
    assert.equal(m.state.vitalStep, 4);
});

test('the kept values are seeded for Review, and the re-taken step is blank', () => {
    const m = machine();
    m.arriveAtIdentity(recheckIdentity(['bp']));

    // Kept: shown on Review so the student checks their whole visit.
    assert.equal(m.fieldValue('height'), 170);
    assert.equal(m.fieldValue('weight'), 60);
    assert.equal(m.fieldValue('temperature'), 36.8);
    assert.equal(m.systemAnswer('skin'), true);
    assert.equal(m.detailText('skin'), 'itchy rash');

    // Re-taken: blanked back to 'ready', so the old high BP is not shown as new.
    assert.equal(m.state.vitalSteps[4].phase, 'ready');
    assert.deepEqual(m.state.vitalSteps[4].values, {});
    assert.equal(m.fieldValue('systolic'), null);
});

// ── The re-check payload ─────────────────────────────────────────────────────

test('the re-check payload carries only the re-taken reading', () => {
    const m = machine();
    m.arriveAtIdentity(recheckIdentity(['bp']));
    m.confirmIdentity();

    m.state.vitalSteps[4] = {
        ...m.state.vitalSteps[4],
        phase: 'captured',
        method: 'manual',
        values: { systolic: 124, diastolic: 80, heartRate: 74 },
    };

    const payload = m.buildRecheckSubmission();

    assert.deepEqual(payload, {
        vitalMethods: ['manual'],
        systolic: 124,
        diastolic: 80,
        heartRate: 74,
    });
    // No height, no weight, no consent, no questionnaire — the server has them.
    assert.equal('privacyConsentAt' in payload, false);
    assert.equal('screening' in payload, false);
    assert.equal('temperature' in payload, false);
});

test('a temp-only re-check payload carries only the temperature', () => {
    const m = machine();
    m.arriveAtIdentity(recheckIdentity(['temp']));
    m.confirmIdentity();

    m.state.vitalSteps[3] = {
        ...m.state.vitalSteps[3],
        phase: 'captured',
        method: 'sensor',
        values: { temperature: 36.9 },
    };

    assert.deepEqual(m.buildRecheckSubmission(), {
        vitalMethods: ['sensor'],
        temperature: 36.9,
    });
});

// ── Reset (FR-KSK-13) ────────────────────────────────────────────────────────

test('reset clears every trace of a re-check pass', () => {
    const m = machine();
    m.arriveAtIdentity(recheckIdentity(['bp', 'temp']));
    m.confirmIdentity();
    m.state.recheck.until = '9:27 AM';

    m.reset();

    assert.equal(m.state.screen, 'welcome');
    assert.equal(m.isRecheck(), false);
    assert.deepEqual(m.state.recheck.steps, []);
    assert.equal(m.state.recheck.until, null);
    assert.equal(m.state.identity, null);
    assert.deepEqual(m.state.questionnaire.systems, {});
    assert.deepEqual(m.state.vitalSteps[4].values, {});
    assert.deepEqual(m.activeSteps(), [1, 2, 3, 4]); // back to a normal first pass
});
