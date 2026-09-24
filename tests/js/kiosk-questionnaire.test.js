import test from 'node:test';
import assert from 'node:assert/strict';

import { kioskMachine, SYSTEMS, DETAIL_MAX, DETAIL_MIN } from '../../resources/js/kiosk/state-machine.js';

/**
 * Kiosk questionnaire — the new forms' twelve rows (D-63) + optional YES
 * details (FR-KSK-10/11/13, D-56), and the on-screen keyboard now serving both the
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

test('the questionnaire asks the new forms\' twelve rows, verbatim, down each column', () => {
    assert.deepEqual(
        SYSTEMS.map((s) => [s.key, s.label]),
        [
            ['skin', 'SKIN'], ['head', 'HEAD'], ['eyes', 'EYES'], ['ears', 'EARS'],
            ['nose', 'NOSE'], ['throat', 'THROAT'], ['chest_lungs', 'CHEST/LUNGS'], ['heart', 'HEART'],
            ['abdomen', 'ABDOMEN'], ['kidney_bladder', 'KIDNEY/BLADDER'], ['brain', 'BRAIN'],
            ['mental_disorder', 'MENTAL DISORDER'],
        ],
    );
    assert.ok(SYSTEMS.every((s) => s.helper.length > 0));
    assert.equal(kioskMachine().questionCount(), 13); // twelve rows + pregnancy (female / no identity yet)
    assert.equal(DETAIL_MAX, 120);
});

test('Review unlocks only once all 13 are answered', () => {
    const m = machineAtQuestionnaire();
    for (const s of SYSTEMS) m.setSystem(s.key, false);
    assert.equal(m.answeredCount(), 12);
    assert.equal(m.questionnaireComplete(), false);

    m.setPregnant(false);
    assert.equal(m.answeredCount(), 13);
    assert.equal(m.questionnaireComplete(), true);
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

    m.openDetail('brain'); // unanswered
    assert.equal(m.state.detailPanel.question, null);

    m.setSystem('brain', false);
    m.openDetail('brain'); // answered NO
    assert.equal(m.state.detailPanel.question, null);
});

test('a detail stops growing at 120 characters', () => {
    const m = machineAtQuestionnaire();
    m.setSystem('throat', true);
    m.openDetail('throat');

    type(m, 'a'.repeat(DETAIL_MAX + 5), 'detail');

    assert.equal(m.detailText('throat').length, DETAIL_MAX);
});

test('switching a YES to NO clears its detail and closes its panel', () => {
    const m = machineAtQuestionnaire();
    m.setSystem('skin', true);
    m.openDetail('skin');
    type(m, 'rash', 'detail');
    m.closeDetail();

    m.setSystem('kidney_bladder', true);
    m.openDetail('kidney_bladder');
    type(m, 'pain', 'detail');

    m.setSystem('kidney_bladder', false);

    assert.equal(m.detailText('kidney_bladder'), '');
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

test('the submission carries all twelve answers and only trimmed YES details', () => {
    const m = machineAtQuestionnaire();
    for (const s of SYSTEMS) m.setSystem(s.key, false);
    m.setSystem('skin', true);
    m.setSystem('mental_disorder', true);
    // A NO detail can't be typed through the UI — force one to prove the filter.
    m.state.questionnaire.details = { skin: ' rash ', mental_disorder: '   ', brain: 'stale' };

    const { screening } = m.buildSubmission();

    assert.deepEqual(
        SYSTEMS.map((s) => screening[s.key]),
        [true, false, false, false, false, false, false, false, false, false, false, true],
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

// ── D-75: on a Medical Clearance, a YES must carry details ──────────────────

/** Every row answered NO, pregnancy answered, then SKIN flipped to YES. */
function allAnsweredWithSkinYes(formType) {
    const m = machineAtQuestionnaire();
    m.state.formType = formType; // set from the SERVER's identity at scan/login
    for (const s of SYSTEMS) m.setSystem(s.key, false);
    m.setPregnant(false);
    m.setSystem('skin', true);
    return m;
}

test('a Medical Clearance YES without details keeps Review locked and names the question', () => {
    const m = allAnsweredWithSkinYes('clearance');

    assert.equal(DETAIL_MIN, 3);
    assert.equal(m.detailsRequired(), true);
    assert.equal(m.answeredCount(), 13);
    assert.equal(m.detailMissing('skin'), true);
    assert.equal(m.firstMissingDetail()?.label, 'SKIN');
    assert.equal(m.questionnaireComplete(), false);

    m.goReview(); // the gate holds even if the disabled button were bypassed
    assert.equal(m.state.screen, 'questionnaire');
});

test('spaces or fewer than DETAIL_MIN characters still block; a real detail unlocks', () => {
    const m = allAnsweredWithSkinYes('clearance');

    m.openDetail('skin');
    type(m, '  ', 'detail');
    m.closeDetail();
    assert.equal(m.questionnaireComplete(), false);

    m.openDetail('skin');
    type(m, 'x', 'detail');
    m.closeDetail();
    assert.equal(m.detailText('skin'), 'x');
    assert.equal(m.questionnaireComplete(), false);

    m.openDetail('skin');
    m.keyPress('backspace', 'detail');
    type(m, 'blurry vision', 'detail');
    m.closeDetail();
    assert.equal(m.firstMissingDetail(), null);
    assert.equal(m.questionnaireComplete(), true);
});

test('flipping a Medical Clearance YES back to NO lifts the requirement', () => {
    const m = allAnsweredWithSkinYes('clearance');
    assert.equal(m.questionnaireComplete(), false);

    m.setSystem('skin', false);
    assert.equal(m.firstMissingDetail(), null);
    assert.equal(m.questionnaireComplete(), true);
});

test('a Medical Assessment YES keeps its details optional (D-56)', () => {
    const m = allAnsweredWithSkinYes('assessment');

    assert.equal(m.detailsRequired(), false);
    assert.equal(m.detailMissing('skin'), false);
    assert.equal(m.questionnaireComplete(), true);
});
