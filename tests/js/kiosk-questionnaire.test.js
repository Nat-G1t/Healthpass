import test from 'node:test';
import assert from 'node:assert/strict';

import { kioskMachine, SYSTEMS, DETAIL_MAX } from '../../resources/js/kiosk/state-machine.js';

/**
 * Kiosk questionnaire — the official form's nine rows + optional YES details
 * (FR-KSK-10/11/13, D-56), and the on-screen keyboard now serving both the
 * credential fields and the details panel.
 *
 * Run with `npm run test:js`. Same headless approach as
 * kiosk-state-machine.test.js: the Alpine component is a plain object factory.
 */

/** A headless kiosk component parked on the questionnaire. */
function machineAtQuestionnaire() {
    const m = kioskMachine();
    m.$refs = { root: { dataset: {} } }; // no reset URL → forgetKioskIdentity no-ops
    // No-op: the card scroll nudge and wedge focus need a DOM we don't have.
    m.$nextTick = () => {};
    m.state.screen = 'questionnaire';
    return m;
}

/** Type lowercase text on a keyboard target, one key press per character. */
function type(m, text, target) {
    for (const ch of text) m.keyPress(ch === ' ' ? 'space' : ch, target);
}

test('the questionnaire asks the form\'s nine rows, verbatim, in the form\'s order', () => {
    assert.deepEqual(
        SYSTEMS.map((s) => s.label),
        ['SKIN', 'ABDOMEN (GIT)', 'HEENT', 'GUT', 'CHEST/LUNGS', 'EXTREMITIES', 'HEART/CVS', 'NEUROLOGICAL', 'BREAST'],
    );
    assert.ok(SYSTEMS.every((s) => s.helper.length > 0));
    assert.equal(DETAIL_MAX, 120);
});

test('the detail keyboard types into the open YES detail, not the login fields', () => {
    const m = machineAtQuestionnaire();
    m.setSystem('skin', true);
    m.openDetail('skin');

    type(m, 'rash on arm', 'detail');

    assert.equal(m.detailText('skin'), 'rash on arm');
    assert.equal(m.state.login.email, '');
    assert.equal(m.state.login.password, '');
});

test('shift and caps on the detail keyboard never leak into the login keyboard', () => {
    const m = machineAtQuestionnaire();
    m.setSystem('skin', true);
    m.openDetail('skin');

    m.keyPress('shift', 'detail');
    type(m, 'rash', 'detail');
    assert.equal(m.detailText('skin'), 'Rash'); // one-shot shift released after R
    assert.equal(m.state.login.shift, false);

    m.keyPress('caps', 'login');
    type(m, 'x', 'detail');
    assert.equal(m.detailText('skin'), 'Rashx'); // login's caps lock is not the detail's
});

test('a details panel only opens for a question answered YES', () => {
    const m = machineAtQuestionnaire();

    m.openDetail('gut'); // unanswered
    assert.equal(m.state.detailPanel.question, null);

    m.setSystem('gut', false);
    m.openDetail('gut'); // answered NO
    assert.equal(m.state.detailPanel.question, null);
});

test('a detail stops growing at 120 characters', () => {
    const m = machineAtQuestionnaire();
    m.setSystem('heent', true);
    m.openDetail('heent');

    type(m, 'a'.repeat(DETAIL_MAX + 5), 'detail');

    assert.equal(m.detailText('heent').length, DETAIL_MAX);
});

test('switching a YES to NO clears its detail and closes its panel', () => {
    const m = machineAtQuestionnaire();
    m.setSystem('skin', true);
    m.openDetail('skin');
    type(m, 'rash', 'detail');
    m.closeDetail();

    m.setSystem('gut', true);
    m.openDetail('gut');
    type(m, 'pain', 'detail');

    m.setSystem('gut', false);

    assert.equal(m.detailText('gut'), '');
    assert.equal(m.state.detailPanel.question, null);
    assert.equal(m.detailText('skin'), 'rash'); // other details untouched
});

test('Done trims the detail and drops one left blank', () => {
    const m = machineAtQuestionnaire();
    m.setSystem('skin', true);
    m.openDetail('skin');
    type(m, '  rash  ', 'detail');
    m.closeDetail();
    assert.equal(m.detailText('skin'), 'rash');

    m.openDetail('skin');
    m.keyPress('backspace', 'detail');
    m.keyPress('backspace', 'detail');
    m.keyPress('backspace', 'detail');
    m.keyPress('backspace', 'detail');
    m.keyPress('space', 'detail');
    m.closeDetail();
    assert.equal('skin' in m.state.questionnaire.details, false);
});

test('Enter on the detail keyboard closes the panel and never submits a login', () => {
    const m = machineAtQuestionnaire();
    let submitted = false;
    m.submitLogin = () => { submitted = true; };
    m.submitExit = () => { submitted = true; };

    m.setSystem('skin', true);
    m.openDetail('skin');
    m.keyPress('enter', 'detail');

    assert.equal(m.state.detailPanel.question, null);
    assert.equal(submitted, false);
    assert.equal(m.kbSending('detail'), false);
    assert.equal(m.kbEnterLabel('detail'), 'Done ⏎');
});

test('the login keyboard still types into the focused credential field and submits', () => {
    const m = machineAtQuestionnaire();
    m.state.screen = 'email_login';
    let submittedLogin = false;
    m.submitLogin = () => { submittedLogin = true; };

    type(m, 'juan@psu.edu.ph'); // default target = login
    m.focusField('password');
    m.keyPress('shift');
    type(m, 'pw');

    assert.equal(m.state.login.email, 'juan@psu.edu.ph');
    assert.equal(m.state.login.password, 'Pw');

    m.keyPress('enter');
    assert.equal(submittedLogin, true);
});

test('the staff-exit prompt still owns the login keyboard\'s Enter', () => {
    const m = machineAtQuestionnaire();
    let submittedExit = false;
    m.submitExit = () => { submittedExit = true; };

    m.openExit();
    type(m, 'nurse');
    m.keyPress('enter');

    assert.equal(m.state.login.email, 'nurse');
    assert.equal(submittedExit, true);
});

test('backspace long-press on the detail keyboard clears only the detail', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = machineAtQuestionnaire();
    m.state.login.email = 'kept@psu.edu.ph';
    m.setSystem('skin', true);
    m.openDetail('skin');
    type(m, 'itchy rash', 'detail');

    m.backspaceDown('detail');
    assert.equal(m.detailText('skin'), 'itchy ras'); // tap deletes one

    t.mock.timers.tick(2000); // held 2 s → wiped
    m.backspaceUp();

    assert.equal(m.detailText('skin'), '');
    assert.equal(m.state.login.email, 'kept@psu.edu.ph');
});

test('the submission carries all nine answers and only trimmed YES details', () => {
    const m = machineAtQuestionnaire();
    for (const s of SYSTEMS) m.setSystem(s.key, false);
    m.setSystem('skin', true);
    m.setSystem('breast', true);
    // A NO detail can't be typed through the UI — force one to prove the filter.
    m.state.questionnaire.details = { skin: ' rash ', breast: '   ', gut: 'stale' };

    const { screening } = m.buildSubmission();

    assert.deepEqual(
        SYSTEMS.map((s) => screening[s.key]),
        [true, false, false, false, false, false, false, false, true],
    );
    assert.deepEqual(screening.details, { skin: 'rash' });
});

test('reset to Welcome wipes the details and closes the panel (FR-KSK-13)', () => {
    const m = machineAtQuestionnaire();
    m.setSystem('skin', true);
    m.openDetail('skin');
    type(m, 'rash', 'detail');

    m.reset();

    assert.equal(m.state.screen, 'welcome');
    assert.deepEqual(m.state.questionnaire.details, {});
    assert.equal(m.state.detailPanel.question, null);
});
