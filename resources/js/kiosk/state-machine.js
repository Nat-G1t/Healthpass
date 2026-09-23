// Explicit .js extension so the module also resolves under Node's test runner
// (`npm run test:js`), which — unlike Vite — does not guess extensions.
import { createSerialReader } from './serial.js';
import { BP_POLL_MS, fetchLatestBpReading } from './bp-poll.js';
import { pickSteadiest, roundTo } from './sample-cluster.js';
import { prefersReducedMotion } from '../shared/motion.js';

/**
 * Kiosk state machine (Module KSK).
 *
 * One Alpine component drives the whole kiosk. Per the build spec, ALL session
 * state lives in a single object (`state`) so that resetting to Welcome is a
 * wholesale replacement — no field-by-field teardown, no chance of a previous
 * student's data leaking into the next session (FR-KSK-13).
 *
 * Screens are swapped declaratively in Blade via `x-show="state.screen === '…'"`.
 */

// Ordered flow. `vitals` is a single screen with an internal 1..4 step
// (FR-KSK-05), so the four vital steps are NOT separate screens here.
export const SCREENS = [
    'welcome',
    'email_login',
    'identity',
    'no-schedule',
    // FR-KSK-03b: "You're all done for today" — the appointment is used.
    'already-screened',
    'consent',
    'vitals',
    'questionnaire',
    // D-68: Medical Assessment Form batches only — skipped on a Medical
    // Clearance, whose paper has no Personal / Social History section.
    'social-history',
    'review',
    'complete',
    // D-72: the "sit and rest, come back at 9:27 AM" screen. Reached from
    // Review instead of Complete when a reading is high; it resets like
    // Complete does, and the student returns by scanning again.
    'rest',
];

export const VITAL_STEPS = 4;

// The blood-pressure step — the one the Bluetooth monitor fills (D-58).
const BP_STEP = 4;

// D-72: which vital step each re-check key re-takes. 'bp' covers systolic,
// diastolic AND heart rate, because one cuff measurement produces all three.
// There is no key for height or weight: resting cannot change those.
export const RECHECK_STEPS = { temp: 3, bp: BP_STEP };

// Every step, in order — the steps a normal first pass walks.
const ALL_STEPS = [1, 2, 3, 4];

// Shown under Start when a BP wait runs out with no reading (D-59).
const BP_WAIT_EXPIRED_NOTICE = 'No reading came from the blood pressure monitor. Tap Start to try again.';

/**
 * The questionnaire (FR-KSK-10, D-63): the new official forms' twelve "Physical
 * Signs Disorder of" rows, reading DOWN each of the form's three columns. Pure
 * DATA — the Blade renders all twelve cards from this list, so they are not
 * twelve copies of markup. `key` is the screening_responses boolean column (and
 * the suffix of the nurse's matching clearance_records.ps_* row); `label` is
 * the form's wording VERBATIM; `helper` is the plain-language line shown under it.
 *
 * Mirrors ScreeningResponse::QUESTIONS (app/Models/ScreeningResponse.php) —
 * keep the two lists in step.
 */
export const SYSTEMS = [
    { key: 'skin', label: 'SKIN', helper: 'Rashes, wounds, itching or other skin problems' },
    { key: 'head', label: 'HEAD', helper: 'Headaches, head injury or dizziness' },
    { key: 'eyes', label: 'EYES', helper: 'Blurred vision, eye pain or redness' },
    { key: 'ears', label: 'EARS', helper: 'Hearing problems, ear pain or discharge' },
    { key: 'nose', label: 'NOSE', helper: 'Nosebleeds, or a blocked or runny nose' },
    { key: 'throat', label: 'THROAT', helper: 'Sore throat or trouble swallowing' },
    { key: 'chest_lungs', label: 'CHEST/LUNGS', helper: 'Breathing problems, cough or asthma' },
    { key: 'heart', label: 'HEART', helper: 'Chest pain, palpitations or heart problems' },
    { key: 'abdomen', label: 'ABDOMEN', helper: 'Stomach pain or digestive problems' },
    { key: 'kidney_bladder', label: 'KIDNEY/BLADDER', helper: 'Kidney, bladder or urination problems' },
    { key: 'brain', label: 'BRAIN', helper: 'Seizures, fainting or other nerve problems' },
    { key: 'mental_disorder', label: 'MENTAL DISORDER', helper: 'Anxiety, depression or other mental health concerns' },
];

// 12 form rows + the pregnancy item = 13 questions to answer (FR-KSK-10).
export const QUESTION_COUNT = SYSTEMS.length + 1;

/**
 * Personal / Social History (FR-KSK-10a, D-68): section I of the Medical
 * Assessment Form's back page. Pure DATA, like SYSTEMS — the Blade renders
 * all four rows from this list. `key` is the field in state.socialHistory;
 * `label` is the form's wording VERBATIM; `options` are the paper's boxes.
 *
 * The first three offer a third box, Quit; Sexually Active offers only Yes
 * and No, which is why it is stored as a boolean and the other three as the
 * paper's own word.
 *
 * Mirrors ScreeningResponse::SOCIAL_HISTORY — keep the two lists in step.
 */
export const SOCIAL_HISTORY = [
    { key: 'smoking', label: 'Smoking', options: ['yes', 'no', 'quit'] },
    { key: 'alcohol', label: 'Alcohol', options: ['yes', 'no', 'quit'] },
    { key: 'illicitDrugs', label: 'Illicit Drugs', options: ['yes', 'no', 'quit'] },
    { key: 'sexuallyActive', label: 'Sexually Active', options: ['yes', 'no'] },
];

/** The paper's box label for a stored value. */
export const SOCIAL_HISTORY_LABELS = { yes: 'Yes', no: 'No', quit: 'Quit' };

/**
 * The questionnaire heading, by form type (D-68). The Medical Assessment Form
 * labels its own twelve-row table "(Self Assessment)"; the Medical Clearance
 * does not, so its students see the heading they have always seen.
 */
const QUESTIONNAIRE_HEADINGS = {
    clearance: 'Physical Signs Disorder of:',
    assessment: 'Physical Signs Disorder of: (Self Assessment)',
};

// Longest optional detail under a YES answer — the same cap the server enforces
// (ScreeningResponse::DETAIL_MAX_LENGTH, D-56).
export const DETAIL_MAX = 120;

// Shortest detail a Medical Clearance YES accepts, after trimming (D-75) — the
// same minimum the server enforces (ScreeningResponse::DETAIL_MIN_LENGTH).
export const DETAIL_MIN = 3;

// How long the "scanning" animation runs before a sensor reading settles to
// "captured". Long enough to read the animation, short enough to feel snappy.
const SCAN_MS = 1200;

// Max gap between taps of the disguised manual-entry gesture (see logoTap).
const GESTURE_WINDOW_MS = 1500;

// Discreet staff-exit gesture (FR-KSK-16): all five taps must land within this
// window of the FIRST tap, so stray single taps never reveal the staff prompt.
const EXIT_TAPS = 5;
const EXIT_GESTURE_WINDOW_MS = 3000;

// Seven-sample capture (D-74) for height, weight and temperature. A step
// collects SAMPLE_COUNT readings and records the average of the steadiest
// group. If SAMPLE_WINDOW_MS passes (counted from the FIRST reading) with at
// least MIN_SAMPLES, it decides on those; with fewer it keeps waiting rather
// than guessing — the idle reset and the "sensor is quiet" nudge cover a
// sensor that has really stopped.
const SAMPLE_COUNT = 7;
const MIN_SAMPLES = 3;
const SAMPLE_WINDOW_MS = 6000;

// Height is shown in feet and inches too (FR-KSK-17) — display only.
const CM_PER_INCH = 2.54;
const INCHES_PER_FOOT = 12;

/**
 * Per-step metadata for the vitals sequence (FR-KSK-05). Each step is pure
 * DATA — the Blade renders ANY step from this config, so the four steps are not
 * four copies of code. A step owns one or more `fields`; most steps have a
 * single field, but Blood Pressure (step 4) groups three (systolic, diastolic,
 * heart rate) captured together in one reading.
 *
 * Per field: `range` names the bounds key in config/healthpass.php (FR-KSK-08,
 * single source of truth); `sensorKey` is the letter the Web Serial handoff
 * uses (§11.2 → H / W / T / S / D / R); `sample` feeds the dev-only "Simulate
 * reading" button; `decimals` fixes display precision (temperature shows 1).
 * A field with `clusterTolerance` is captured from seven sensor samples (D-74):
 * readings within that many units of each other count as one steady group,
 * and the group's average is rounded to `averageDecimals` (the column stores
 * one decimal; height is kept to whole cm, as it is displayed).
 *
 * Step extras: `showsBmi` renders the computed BMI panel (FR-KSK-09);
 * `showsFeetInches` adds the height in feet and inches (FR-KSK-17); `badge`
 * selects the neutral status badge ('temperature' | 'bp') — never a diagnosis
 * or Fit/Unfit (FR-KSK-14).
 */
export const VITALS = {
    1: {
        label: 'Height',
        instruction: 'Stand straight under the stadiometer, heels together, looking forward.',
        fields: [
            { key: 'height', unit: 'cm', range: 'height_cm', sensorKey: 'H', sample: 163, clusterTolerance: 2, averageDecimals: 0 },
        ],
        showsFeetInches: true,
    },
    2: {
        label: 'Weight',
        instruction: 'Step onto the scale and stand still, arms relaxed at your sides.',
        fields: [
            { key: 'weight', unit: 'kg', range: 'weight_kg', sensorKey: 'W', sample: 58, clusterTolerance: 1, averageDecimals: 1 },
        ],
        showsBmi: true,
    },
    3: {
        label: 'Temperature',
        instruction: 'Hold your forehead a few centimetres from the infrared thermometer and stay still.',
        badge: 'temperature',
        fields: [
            { key: 'temperature', unit: '°C', range: 'temperature_c', sensorKey: 'T', sample: 36.8, decimals: 1, clusterTolerance: 0.3, averageDecimals: 1 },
        ],
    },
    4: {
        label: 'Blood Pressure',
        instruction: 'Rest your arm in the cuff, palm up, and stay relaxed while it inflates.',
        badge: 'bp',
        fields: [
            { key: 'systolic', label: 'Systolic', unit: 'mmHg', range: 'bp_systolic', sensorKey: 'S', sample: 118 },
            { key: 'diastolic', label: 'Diastolic', unit: 'mmHg', range: 'bp_diastolic', sensorKey: 'D', sample: 76 },
            { key: 'heartRate', label: 'Heart Rate', unit: 'bpm', range: 'heart_rate', sensorKey: 'R', sample: 72 },
        ],
    },
};

/**
 * A fresh, uncaptured step. phase: ready → scanning → captured; the blood-
 * pressure step also has 'waiting', from tapping Start until the Bluetooth
 * monitor's reading arrives (D-59), and the height/weight/temperature steps
 * have 'sampling' (D-74), from their first sensor reading until the seven
 * samples are decided — it shows the same "Measuring…" card as scanning.
 * `samples` buffers those readings; `windowOver` is set once SAMPLE_WINDOW_MS
 * has passed since the first one. Both live here so a fresh step (Retry, a
 * D-72 re-check, reset-to-Welcome) always starts an empty buffer. `values` holds
 * each field's reading by key (one key for most steps, three for BP); `method`
 * is the step's provenance for vital_signs.entry_method (FR-KSK-06).
 */
function vitalStep() {
    return {
        phase: 'ready',
        method: null, // 'sensor' | 'manual'
        notice: '', // non-blocking nudge (e.g. sensor degraded to manual)
        values: {}, // field key → number, filled on capture
        suspect: false, // D-58: the BP monitor itself flagged the captured reading
        samples: [], // D-74: sensor readings buffered while 'sampling'
        windowOver: false, // D-74: SAMPLE_WINDOW_MS has passed since the first sample
    };
}

/**
 * A clean, empty session. Called on first load and on every reset-to-welcome.
 * The nested shells (identity / consent / vitals / questionnaire) are
 * placeholders for the coming weeks — they define the shape now so later
 * screens have somewhere to write.
 */
function freshState() {
    return {
        screen: 'welcome',
        vitalStep: 1,

        // QR keyboard-wedge feedback shown on the Welcome screen. `notice` is
        // the neutral counterpart of `error` — D-72 uses it for "please keep
        // resting, come back at 9:27 AM", which is not a failure.
        scan: { status: 'idle', error: '', notice: '' }, // idle | sending | error

        // Email-login sub-state (FR-KSK-02). `field` is which input the
        // on-screen keyboard types into; reset wholesale with the session.
        login: {
            email: '',
            password: '',
            field: 'email', // 'email' | 'password' — receives keyboard input
            showPassword: false,
            shift: false, // one-shot uppercase: releases after one character
            caps: false, // caps lock: stays on until toggled off
            status: 'idle', // idle | sending | error
            error: '',
        },

        // --- per-student session data (filled by later screens) ---
        identity: null,
        consentAt: null,

        // Which official form today's batch named (D-68), decided by the
        // SERVER and copied out of the identity payload at scan/login. It
        // chooses SCREENS ONLY — the server re-resolves it at submit and never
        // reads this value, so a tampered copy changes nothing that is stored.
        // 'clearance' is the neutral default for a fresh session.
        formType: 'clearance',

        // Each vital step is its own 3-phase record (FR-KSK-05): ready →
        // scanning → captured. Steps 1–3 hold a single reading; step 4 (BP)
        // groups systolic/diastolic/heart-rate captured together.
        vitalSteps: {
            1: vitalStep(),
            2: vitalStep(),
            3: vitalStep(),
            4: vitalStep(),
        },

        // Numeric on-screen pad for manual entry (FR-KSK-06). It walks the
        // fields of the step being edited one at a time (BP = three prompts);
        // `draft` collects them and commits only when the last is confirmed.
        pad: { open: false, step: null, fieldIndex: 0, value: '', error: '', draft: {} },

        // The form's twelve rows + pregnancy (FR-KSK-10, D-63). `systems` maps a
        // question key → true (Yes) | false (No); an unanswered one is simply
        // absent. `details` maps a key → the optional text typed under a YES.
        // `isPregnant` is true | false | null (unanswered); `lmp` holds the Last
        // Menstrual Period as an ISO 'YYYY-MM-DD' string, required only when
        // pregnant. `calMonth` is the {year, month} the inline LMP calendar is
        // viewing (month is 0-based, matching JS Date).
        questionnaire: {
            systems: {},
            details: {},
            isPregnant: null,
            lmp: null,
            calMonth: null,
        },

        // Personal / Social History (FR-KSK-10a, D-68) — asked only on an
        // assessment visit. The three habits hold the paper's own word;
        // sexuallyActive holds true | false. null = unanswered, and all four
        // must be answered before Continue. Living in `state` means the
        // reset-to-Welcome wholesale replacement clears them (FR-KSK-13).
        socialHistory: { smoking: null, alcohol: null, illicitDrugs: null, sexuallyActive: null },

        // The docked YES-details panel (D-56). `question` is the key being
        // typed into (null = closed). `shift`/`caps` are the on-screen
        // keyboard's modifiers while it types a detail — kept apart from
        // state.login so the two keyboards never share a Caps Lock.
        detailPanel: { question: null, shift: false, caps: false },

        // Final-submit request sub-state (FR-KSK-11/12). Mirrors login/scan;
        // `reference` holds the server-minted HP-YYYY-#### shown on Complete.
        submit: { status: 'idle', error: '', reference: null }, // idle | sending | error

        // Rest & re-check (FR-KSK-11a, D-72).
        //
        // `active` is true only on a RETURN pass — the student rested and came
        // back — and `steps` holds the server's re-check keys ('bp' / 'temp')
        // for it. `until` is the SERVER's come-back time as a ready-made
        // string; the kiosk never formats a time of its own, and never reads
        // the browser clock for one. `status`/`error` mirror submit's, for the
        // POST to /kiosk/rest.
        recheck: { active: false, steps: [], until: null, status: 'idle', error: '' },

        // Discreet staff-exit modal (FR-KSK-16). Only `open`/`status`/`error`
        // live here — the prompt BORROWS the login fields + on-screen keyboard
        // (`state.login`) for the nurse's email/password, since the two are
        // never shown at once and share the exact same input shape.
        exit: { open: false, status: 'idle', error: '' }, // idle | sending | error
    };
}

export function kioskMachine() {
    return {
        state: freshState(),

        // Server-injected plausibility ranges + BMI threshold (config/healthpass.php).
        // Read once so client validation uses the SAME numbers as the server (FR-KSK-08).
        config: {},

        // The form's twelve rows, exposed so Blade can x-for over them
        // (FR-KSK-10) — the cards are data-driven, not twelve copies of markup.
        systemList: SYSTEMS,
        // The four Personal / Social History rows, so Blade can x-for over
        // them the same way (D-68), plus the box labels for their buttons.
        socialHistoryList: SOCIAL_HISTORY,
        socialHistoryLabels: SOCIAL_HISTORY_LABELS,
        detailMax: DETAIL_MAX, // shown as the details panel's "N / 120" counter
        detailMin: DETAIL_MIN, // the panel's "at least 3 characters" hint (D-75)

        // Web Serial UI status (FR-KSK-07). Lives on the COMPONENT, not in
        // `state`, because the physical sensor connection outlives one student:
        // a reset-to-Welcome must not drop a working port. `status` mirrors the
        // serial module's lifecycle; `notice` is a non-blocking degrade nudge.
        serial: { supported: false, status: 'idle', notice: '' },
        _serial: null, // the createSerialReader() instance (I/O lives here)

        // Bluetooth BP poll bookkeeping (D-58). On the component, not in
        // `state`, for the same reason as the serial link: the poll outlives one
        // student. `_bpBaseline` is the received_at already on the server when
        // the BP step started waiting (undefined = not waiting), so only a NEWER
        // reading is used — never one left over from the previous student.
        _bpTimer: null,
        _bpBaseline: undefined,
        _bpBusy: false,
        // The bp_wait_seconds limit on one wait after Start (D-59).
        _bpWaitTimer: null,

        // Tap bookkeeping for the disguised manual-entry gesture (see logoTap).
        _logoTaps: 0,
        _lastLogoTap: 0,

        // Tap bookkeeping for the discreet staff-exit gesture (see exitTap).
        _exitTaps: 0,
        _exitFirstTap: 0,

        // Timers for the on-screen keyboard's backspace long-press (see backspaceDown).
        _bsRepeat: null,
        _bsClearTimer: null,

        // Session-lifecycle timers (FR-KSK-13/15). Held on the component (not in
        // `state`) so a wholesale state reset never strands a running timer.
        _idleTimer: null,
        _completeInterval: null,
        // The SAMPLE_WINDOW_MS timer of the step being sampled (D-74).
        _sampleTimer: null,
        // Seconds left on the Complete auto-reset pill; reactive so Blade tracks it.
        completeCountdown: 0,

        init() {
            this.config = JSON.parse(this.$refs.root.dataset.config || '{}');
            // React to every screen change: (re)arm the idle timer mid-flow, and
            // run the Complete countdown only while the Complete screen is up.
            this.$watch('state.screen', (screen) => {
                this.bumpIdle();
                // D-72: the Rest screen auto-resets on the same countdown as
                // Complete — both are end-of-session screens the next student
                // must not walk up to.
                if (screen === 'complete' || screen === 'rest') this.startCompleteCountdown();
                else this.clearCompleteCountdown();
            });
            this.setupSerial();
            this.setupBpPoll();
            this.focusWedge();
        },

        // ── Web Serial sensor path (FR-KSK-07, FR-HW-05) ─────────────────────
        // Build the serial reader and wire its callbacks. The reader is pure
        // I/O + parsing (serial.js); THIS component decides what a reading means
        // and how a status shows in the UI. On load we try a silent reconnect to
        // an already-granted port so the unattended kiosk recovers by itself
        // after a reboot (FR-HW-05); if none is granted yet, the student/staff
        // tap "Connect sensor" (the gesture requestPort needs) on the vitals step.
        setupSerial() {
            this._serial = createSerialReader({
                baudRate: this.config.kiosk?.serialBaud ?? 9600,
                readTimeoutMs: this.config.kiosk?.serialTimeoutMs ?? 10000,
                onReading: (reading) => this.onSerialReading(reading),
                onStatus: (status) => this.onSerialStatus(status),
            });
            this.serial.supported = this._serial.isSupported();
            if (this.serial.supported) this._serial.autoConnect();
        },

        /** Connect button on the vitals step — the tap is the user gesture. */
        connectSensors() {
            this._serial?.connect();
        },

        /**
         * A parsed line arrived. Route ONLY the current step's fields into the
         * existing sensor path (receiveReading), so a full combined line fills
         * the step the student is on — not all four at once (FR-KSK-05). We only
         * act while a vital step is still 'ready'; a captured or mid-scan step is
         * left alone, so a finished reading can't be silently overwritten.
         */
        onSerialReading(reading) {
            if (this.state.screen !== 'vitals') return;
            if (this.stepPhase() === 'captured') return;
            // Any line while the current step is still being measured counts as
            // interaction: a slow reading (the BP cuff inflating while the hub
            // streams other keys) must not let the 90s idle reset fire
            // mid-measurement (FR-KSK-15). Captured steps stop counting — see
            // the guard above — so an abandoned session still resets even if
            // the hub keeps streaming.
            this.bumpIdle();
            // D-74: height, weight and temperature collect seven samples
            // before deciding, so their readings keep flowing in while the
            // step is 'sampling'. bumpIdle above already ran for every one.
            const sampled = this.sampledField();
            if (sampled) {
                const value = Number(reading[sampled.sensorKey]);
                if (reading[sampled.sensorKey] != null && Number.isFinite(value)) this.addSample(value);
                return;
            }
            if (this.stepPhase() !== 'ready') return;
            const meta = this.vitalMeta(this.state.vitalStep);
            if (!meta) return;
            const forStep = {};
            for (const f of meta.fields) {
                if (reading[f.sensorKey] != null) forStep[f.sensorKey] = reading[f.sensorKey];
            }
            if (Object.keys(forStep).length === 0) return; // nothing for this step yet
            this.receiveReading(forStep);
        },

        /**
         * Reflect a serial lifecycle change in the UI. Every message is a
         * NON-BLOCKING nudge — manual entry is always available, so the sensor is
         * never a dead end (FR-KSK-07). 'disconnected' is reassuring because the
         * module auto-reopens the same port when it returns (FR-HW-05).
         */
        onSerialStatus(status) {
            this.serial.status = status;
            const notices = {
                unsupported: 'Sensors need Chromium. You can enter each vital manually.',
                timeout: 'The sensor is quiet. Wait a moment, or enter it manually.',
                disconnected: 'Sensor unplugged — reconnecting… Manual entry still works.',
                error: 'Sensor problem. You can enter each vital manually.',
            };
            this.serial.notice = notices[status] ?? '';
        },

        // ── Bluetooth BP monitor (D-58, FR-KSK-07a) ──────────────────────────
        /** Start the poll timer (it asks only while the BP step waits). A page without the URL (e.g. a test) doesn't poll. */
        setupBpPoll() {
            if (!this.$refs.root.dataset.bpLatestUrl) return;
            this._bpTimer = setInterval(() => this.pollBpReading(), BP_POLL_MS);
        },

        /** True while the BP step is on screen and waiting for the monitor (after Start). */
        isAwaitingBp() {
            return this.state.screen === 'vitals'
                && this.state.vitalStep === BP_STEP
                && this.stepPhase() === 'waiting';
        },

        /**
         * The student tapped Start on the BP step (D-59): show the waiting
         * animation and listen for the monitor. The poll taken right now is the
         * BASELINE, so a reading that finished before the tap is never used.
         * While waiting, the 90 s idle reset is paused (see bumpIdle) and the
         * bp_wait_seconds limit stands in for it: with no reading by then, the
         * step goes back to Start with a nudge. Returns the baseline poll, so a
         * test can await it.
         */
        startBpWait() {
            if (this.state.screen !== 'vitals' || this.state.vitalStep !== BP_STEP) return Promise.resolve();
            if (this.stepPhase() !== 'ready' || this.state.pad.open) return Promise.resolve();
            const s = this.currentStep();
            s.phase = 'waiting';
            s.notice = '';
            this._bpBaseline = undefined;
            this.clearIdle();
            this.clearBpWaitTimer();
            const secs = this.config.kiosk?.bpWaitSeconds ?? 120;
            this._bpWaitTimer = setTimeout(() => this.endBpWait(BP_WAIT_EXPIRED_NOTICE), secs * 1000);
            return this.pollBpReading();
        },

        /**
         * Stop waiting and put the Start button back — from Cancel, the wait
         * limit, Previous step or the manual pad. `notice` is shown under Start.
         * The idle countdown resumes.
         */
        endBpWait(notice = '') {
            this.clearBpWaitTimer();
            const s = this.state.vitalSteps[BP_STEP];
            if (s.phase !== 'waiting') return;
            s.phase = 'ready';
            s.notice = notice;
            this.bumpIdle();
        },

        clearBpWaitTimer() {
            if (this._bpWaitTimer) clearTimeout(this._bpWaitTimer);
            this._bpWaitTimer = null;
        },

        /**
         * One poll tick; it only asks the server while the BP step waits. The
         * first answer after Start is the BASELINE: whatever is already there
         * predates this student's measurement and is skipped. A later answer
         * with a different received_at is a new reading.
         */
        async pollBpReading() {
            if (!this.isAwaitingBp()) {
                this._bpBaseline = undefined;
                return;
            }
            if (this._bpBusy) return;
            this._bpBusy = true;
            try {
                const { reachable, reading } = await fetchLatestBpReading(this.$refs.root.dataset.bpLatestUrl);
                if (!reachable) return; // a failed poll proves nothing, so it can't be the baseline
                const receivedAt = reading?.received_at ?? null;
                if (this._bpBaseline === undefined) {
                    this._bpBaseline = receivedAt;
                    return;
                }
                if (receivedAt === null || receivedAt === this._bpBaseline) return;
                if (!this.isAwaitingBp()) return;
                this._bpBaseline = receivedAt; // seen: never used twice
                await this.acceptBpReading(reading);
            } finally {
                this._bpBusy = false;
            }
        },

        /**
         * Use a new reading. Claim it into this kiosk session first, so the
         * server keeps the device's record (irregular pulse and all) for submit
         * and never trusts the browser with it. Then feed the numbers through
         * the SAME sensor path Web Serial uses, so the reading scans,
         * range-checks and captures like any other. A refused claim (expired,
         * or another session took it) or a failed request is ignored quietly.
         */
        async acceptBpReading(reading) {
            try {
                const { response, data } = await this.kioskPost(this.$refs.root.dataset.bpClaimUrl, {
                    received_at: reading.received_at,
                });
                if (!response.ok || !data.ok) return;
            } catch {
                return;
            }
            if (!this.isAwaitingBp()) return; // Cancel, the wait limit or the pad got there first
            this.clearBpWaitTimer();
            // A missing pulse is left out: the step then reads as incomplete and
            // shows the usual "try again or enter it manually" nudge.
            const forStep = { S: reading.systolic, D: reading.diastolic };
            if (reading.pulse != null) forStep.R = reading.pulse;
            this.receiveReading(forStep, { suspect: reading.suspect === true });
            // The step has left 'waiting', so this restarts the idle countdown:
            // a reading arriving counts as activity (FR-KSK-15).
            this.bumpIdle();
        },

        // ── Navigation ───────────────────────────────────────────────────────
        go(screen) {
            if (!SCREENS.includes(screen)) return;
            this.state.screen = screen;
            // Keep the scanner hot whenever we are back on Welcome.
            if (screen === 'welcome') {
                // Keep any notice already on screen (D-72's "keep resting"),
                // which is set immediately before returning here.
                this.state.scan = { status: 'idle', error: '', notice: this.state.scan.notice };
                this.focusWedge();
            }
        },

        /** Wipe everything and return to Welcome (FR-KSK-13). */
        reset() {
            this.clearIdle();
            this.clearCompleteCountdown();
            this.clearBpWaitTimer();
            this.clearSampleTimer();
            // Drop the server-side kiosk identity too. This is the single choke
            // point for every abandon/finish path ("Not you?", consent Decline,
            // the 90s idle reset, Complete's Done + auto-reset), so clearing it
            // here covers them all (submit() already forgets on success).
            this.forgetKioskIdentity();
            this.state = freshState();
            this.focusWedge();
        },

        /**
         * Best-effort: tell the server to forget the bound kiosk identity
         * (kiosk.* session keys). Fire-and-forget so it never blocks the UI —
         * a failed clear is self-healed by the next scan/login overwriting the
         * keys, and by submit()'s own server-side forget on success.
         */
        forgetKioskIdentity() {
            const url = this.$refs.root.dataset.resetUrl;
            if (!url) return;
            this.kioskPost(url, {}).catch(() => {});
        },

        // ── Session lifecycle timers (FR-KSK-13/15) ──────────────────────────
        // Idle: 90s of no interaction MID-FLOW discards the session and resets,
        // so an abandoned kiosk can't leave a student's data on screen (FR-KSK-15).
        // Welcome (nothing entered yet) and Complete (its own countdown) are exempt.
        startIdle() {
            this.clearIdle();
            const secs = this.config.kiosk?.idleTimeoutSeconds ?? 90;
            this._idleTimer = setTimeout(() => this.reset(), secs * 1000);
        },

        clearIdle() {
            if (this._idleTimer) clearTimeout(this._idleTimer);
            this._idleTimer = null;
        },

        /** Restart the idle countdown on any interaction — but only mid-flow. */
        bumpIdle() {
            const screen = this.state.screen;
            if (screen === 'welcome' || screen === 'complete' || screen === 'rest') {
                this.clearIdle();
                return;
            }
            // D-59: while the BP step waits for the monitor, the wait limit
            // guards an abandoned kiosk instead, so a cuff measurement plus the
            // Bluetooth transfer can never reset the session halfway.
            if (this.isAwaitingBp()) {
                this.clearIdle();
                return;
            }
            this.startIdle();
        },

        // Complete: a countdown pill ticks down and auto-resets to Welcome after
        // 12s (FR-KSK-13); a Done tap resets instantly via reset().
        startCompleteCountdown() {
            this.clearCompleteCountdown();
            this.completeCountdown = this.config.kiosk?.completeResetSeconds ?? 12;
            this._completeInterval = setInterval(() => {
                this.completeCountdown -= 1;
                if (this.completeCountdown <= 0) this.reset();
            }, 1000);
        },

        clearCompleteCountdown() {
            if (this._completeInterval) clearInterval(this._completeInterval);
            this._completeInterval = null;
        },

        // ── Shared kiosk POST (CSRF self-heal) ───────────────────────────────
        // Every kiosk POST (scan / login / submit / exit) goes through here so
        // they share one behaviour: if the page's CSRF token has gone stale —
        // the kiosk outlived its session after a server restart, a DB reset, or
        // session expiry — the first POST returns 419. We then fetch a fresh
        // token and retry ONCE, so the kiosk heals itself instead of failing
        // every request until someone reloads the page.
        async kioskPost(url, payload) {
            const send = () => fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.$refs.root.dataset.csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify(payload),
            });

            let response = await send();
            if (response.status === 419) {
                await this.refreshCsrf();
                response = await send();
            }

            let data = {};
            try {
                data = await response.json();
            } catch {
                data = {}; // a non-JSON body (e.g. an error page) leaves data empty
            }
            return { response, data };
        },

        /** Pull a current CSRF token from the server and update the page's copy. */
        async refreshCsrf() {
            try {
                const r = await fetch(this.$refs.root.dataset.tokenUrl, {
                    headers: { Accept: 'application/json' },
                });
                const d = await r.json();
                if (d.token) this.$refs.root.dataset.csrf = d.token;
            } catch {
                // Leave the old token in place; the retry will surface the error.
            }
        },

        // ── QR keyboard-wedge (FR-KSK-01) ────────────────────────────────────
        // The hidden input must stay focused so a USB scanner can type the
        // token + Enter — but ONLY on Welcome. We read the field's own value on
        // Enter rather than tracking keystrokes by hand.

        /**
         * Whether the QR wedge should currently own keyboard focus. It listens
         * ONLY on Welcome, and never while the on-screen keyboard is up (email
         * login or the staff-exit prompt). Otherwise a scanner — or a stray
         * physical keyboard — would type into the hidden input and its Enter
         * would fire a lookup right over the virtual keyboard the student is
         * actually using.
         */
        wedgeHot() {
            return this.state.screen === 'welcome' && !this.state.exit.open;
        },

        /**
         * (Re)assert wedge focus. Called on load, whenever Welcome is (re)shown,
         * and on the wedge's own blur — so the scanner stays hot on Welcome. On
         * every other screen it does the opposite and BLURS the wedge, so the
         * hidden input can never steal input from the virtual keyboard.
         */
        focusWedge() {
            this.$nextTick(() => {
                const wedge = this.$refs.wedge;
                if (!wedge) return;
                if (this.wedgeHot()) wedge.focus();
                else wedge.blur();
            });
        },

        onWedgeEnter(event) {
            const raw = event.target.value;
            event.target.value = '';
            // Ignore anything typed while the wedge isn't hot (off Welcome, or
            // with the on-screen keyboard up) — that input isn't ours to act on.
            if (!this.wedgeHot()) return;
            const token = raw.trim(); // server extracts the IDNo line if present
            if (token) this.submitToken(token);
        },

        async submitToken(token) {
            this.state.scan = { status: 'sending', error: '' };
            try {
                const { response, data } = await this.kioskPost(this.$refs.root.dataset.scanUrl, { token });
                // Valid token → straight to Identity Confirm (FR-KSK-03).
                if (response.ok && data.ok && data.identity) {
                    this.state.scan = { status: 'idle', error: '' };
                    this.arriveAtIdentity(data.identity);
                    return;
                }
                this.showScanError(data.message ?? 'Could not read that ID. Please try again.');
            } catch {
                this.showScanError('Network problem reading the ID. Please try again.');
            }
        },

        /**
         * Surface a scan failure — but only while still on Welcome. A multi-line
         * ID arrives as several wedge submits (one per line), firing one lookup
         * per line; once the IDNo line succeeds and we navigate to Identity, the
         * earlier lines' late failures must not clobber the screen or yank focus.
         */
        showScanError(message) {
            if (this.state.screen !== 'welcome') return;
            this.state.scan = { status: 'error', error: message };
            this.focusWedge();
        },

        // ── Email login (FR-KSK-02) ──────────────────────────────────────────
        // Opens the email screen with a clean login sub-state. The hidden QR
        // wedge keeps focus elsewhere; here the on-screen keyboard is the only
        // input, so we don't touch real input focus.
        goEmailLogin() {
            this.state.screen = 'email_login';
            this.state.login = {
                email: '',
                password: '',
                field: 'email',
                showPassword: false,
                shift: false,
                caps: false,
                status: 'idle',
                error: '',
            };
        },

        /** Choose which field the virtual keyboard types into. */
        focusField(field) {
            this.state.login.field = field;
        },

        togglePassword() {
            this.state.login.showPassword = !this.state.login.showPassword;
        },

        // ── On-screen keyboard targets (FR-KSK-02, FR-KSK-16, D-56) ──────────
        // One keyboard partial types into two kinds of target, and every
        // rendered keyboard passes its own, so a key never guesses where it
        // belongs:
        //   'login'  → state.login[field] — the email login AND the staff-exit
        //              prompt (never on screen together);
        //   'detail' → the YES detail open in the questionnaire's panel.

        /** The object holding a target's Shift / Caps Lock modifiers. */
        kbMods(target = 'login') {
            return target === 'detail' ? this.state.detailPanel : this.state.login;
        },

        /** The text a target currently holds. */
        kbText(target = 'login') {
            if (target === 'detail') return this.detailText(this.state.detailPanel.question);
            return this.state.login[this.state.login.field];
        },

        /** Replace a target's text. A detail stops growing at DETAIL_MAX. */
        setKbText(target, value) {
            if (target !== 'detail') {
                this.state.login[this.state.login.field] = value;
                return;
            }
            const question = this.state.detailPanel.question;
            if (question === null || value.length > DETAIL_MAX) return;
            this.state.questionnaire.details = { ...this.state.questionnaire.details, [question]: value };
        },

        /** Whether letters should currently be uppercase (Shift XOR Caps Lock). */
        isUpper(target = 'login') {
            const mods = this.kbMods(target);
            return mods.shift !== mods.caps;
        },

        /** Display label for a key — letters reflect the target's Shift/Caps case. */
        keyLabel(key, target = 'login') {
            return /^[a-z]$/.test(key) && this.isUpper(target) ? key.toUpperCase() : key;
        },

        /**
         * Play the press-pop animation on a key element. Removing the class and
         * forcing a reflow restarts the animation, so rapid repeat taps on the
         * same key each get their own pop (otherwise re-adding a class the node
         * already has does nothing).
         */
        pressKey(el) {
            el.classList.remove('k-key-press');
            void el.offsetWidth; // force reflow to restart the CSS animation
            el.classList.add('k-key-press');
        },

        /**
         * Virtual-keyboard key press (FR-KSK-02, D-56). Routes character keys to
         * the keyboard's target (see kbText). Special keys: 'backspace',
         * 'space', 'enter', plus the 'shift' (one-shot) and 'caps' (lock)
         * modifiers.
         */
        keyPress(key, target = 'login') {
            const mods = this.kbMods(target);

            if (key === 'enter') {
                // Enter finishes whichever flow owns this keyboard: the details
                // panel closes; the credential keyboard submits the student email
                // login or the staff-exit prompt (FR-KSK-16), whichever is open.
                if (target === 'detail') this.closeDetail();
                else if (this.state.exit.open) this.submitExit();
                else this.submitLogin();
                return;
            }
            if (key === 'shift') {
                mods.shift = !mods.shift;
                return;
            }
            if (key === 'caps') {
                mods.caps = !mods.caps;
                return;
            }

            if (target === 'login') {
                this.state.login.error = ''; // typing clears any stale error
                this.state.exit.error = ''; // …in either credential context
            }
            const text = this.kbText(target);

            if (key === 'backspace') {
                this.setKbText(target, text.slice(0, -1));
                return;
            }
            if (key === 'space') {
                this.setKbText(target, text + ' ');
                return;
            }

            // Letters honour the current case; digits/symbols are inserted as-is.
            this.setKbText(target, text + this.keyLabel(key, target));

            // Shift is a one-shot modifier — it releases after a single key.
            if (mods.shift) mods.shift = false;
        },

        // Backspace long-press (on-screen keyboard). A quick tap deletes one
        // character; holding repeats with an accelerating speed; holding for a
        // full 2 s clears the target's text entirely. Driven by pointer events
        // so it behaves the same on the touchscreen and a mouse.
        backspaceDown(target = 'login') {
            this.keyPress('backspace', target); // immediate single delete on tap
            // Hold for 2 s → wipe the whole text, then stop repeating.
            this._bsClearTimer = setTimeout(() => {
                this.setKbText(target, '');
                this.backspaceUp();
            }, 2000);
            // After a short initial hold, begin an accelerating repeat.
            this._bsRepeat = setTimeout(() => this.backspaceTick(150, target), 400);
        },

        backspaceTick(delay, target = 'login') {
            this.keyPress('backspace', target);
            const next = Math.max(40, delay - 15); // speeds up to a 40 ms floor
            this._bsRepeat = setTimeout(() => this.backspaceTick(next, target), delay);
        },

        backspaceUp() {
            clearTimeout(this._bsRepeat);
            clearTimeout(this._bsClearTimer);
            this._bsRepeat = null;
            this._bsClearTimer = null;
        },

        async submitLogin() {
            const login = this.state.login;
            if (login.status === 'sending') return;
            if (login.email.trim() === '' || login.password === '') {
                login.error = 'Enter your email and password.';
                return;
            }

            login.status = 'sending';
            login.error = '';
            try {
                const { response, data } = await this.kioskPost(this.$refs.root.dataset.loginUrl, {
                    email: login.email.trim(),
                    password: login.password,
                });
                if (response.ok && data.ok && data.identity) {
                    this.arriveAtIdentity(data.identity);
                    return;
                }
                login.status = 'error';
                login.error = data.message ?? 'Those credentials don\'t match a student account.';
            } catch {
                login.status = 'error';
                login.error = 'Network problem signing in. Please try again.';
            }
        },

        // ── Shared keyboard Enter-key helpers ────────────────────────────────
        // The credential keyboard's Enter serves the email login and the staff
        // exit; these expose the active flow's busy state so the key can
        // disable + relabel correctly. A details keyboard never sends anything
        // — its Enter just closes the panel (D-56).
        kbSending(target = 'login') {
            if (target === 'detail') return false;
            return (this.state.exit.open ? this.state.exit.status : this.state.login.status) === 'sending';
        },

        kbEnterLabel(target = 'login') {
            if (target === 'detail') return 'Done ⏎';
            if (this.kbSending()) return this.state.exit.open ? 'Exiting…' : 'Signing in…';
            return 'Enter ⏎';
        },

        // ── Discreet staff exit (FR-KSK-16) ──────────────────────────────────
        // A student cannot navigate out of /kiosk; ending a shift requires a
        // hidden corner gesture — five taps within ~3 s of the first — then a
        // nurse's credentials. Stray taps reset the count, so it never opens by
        // accident.
        exitTap() {
            const now = Date.now();
            if (this._exitTaps === 0 || now - this._exitFirstTap > EXIT_GESTURE_WINDOW_MS) {
                this._exitFirstTap = now;
                this._exitTaps = 1;
            } else {
                this._exitTaps += 1;
            }
            if (this._exitTaps >= EXIT_TAPS) {
                this._exitTaps = 0;
                this.openExit();
            }
        },

        /** Open the staff prompt with a clean credential field set (borrows login). */
        openExit() {
            this.state.login = {
                email: '',
                password: '',
                field: 'email',
                showPassword: false,
                shift: false,
                caps: false,
                status: 'idle',
                error: '',
            };
            this.state.exit = { open: true, status: 'idle', error: '' };
            // If the prompt opened over Welcome, drop wedge focus so the nurse's
            // typing goes to the on-screen keyboard, not the hidden scanner input.
            this.focusWedge();
        },

        closeExit() {
            this.state.exit.open = false;
            // Back on Welcome the scanner should be hot again; elsewhere this is
            // a no-op (focusWedge blurs when the wedge isn't hot).
            this.focusWedge();
        },

        /**
         * Authenticate the nurse and hand off to the queue (FR-KSK-16). On success
         * the server has started a real session, so a full navigation lands inside
         * the (auth-gated) nurse queue rather than bouncing to the login page.
         */
        async submitExit() {
            const login = this.state.login;
            if (this.state.exit.status === 'sending') return;
            if (login.email.trim() === '' || login.password === '') {
                this.state.exit.error = 'Enter the nurse email and password.';
                return;
            }

            this.state.exit.status = 'sending';
            this.state.exit.error = '';
            try {
                const { response, data } = await this.kioskPost(this.$refs.root.dataset.exitUrl, {
                    email: login.email.trim(),
                    password: login.password,
                });
                if (response.ok && data.ok && data.redirect) {
                    window.location.href = data.redirect; // leave kiosk mode → nurse queue
                    return;
                }
                this.state.exit.status = 'error';
                this.state.exit.error = data.message ?? 'Those credentials don\'t match a clinic staff account.';
            } catch {
                this.state.exit.status = 'error';
                this.state.exit.error = 'Network problem. Please try again.';
            }
        },

        // ── Identity Confirm (FR-KSK-03) ─────────────────────────────────────
        /** Store the resolved student and show Identity Confirm. */
        arriveAtIdentity(identity) {
            // D-72, still resting: the server says the rest is not over. Show
            // the come-back time on Welcome and drop the session — the student
            // walks away and scans again later.
            if (identity?.recheckWaitUntil) {
                this.reset();
                this.state.scan.notice = `Please keep resting. Come back at ${identity.recheckWaitUntil}.`;
                return;
            }

            this.state.identity = identity;
            // D-68: the server decided which form today's batch named; we keep
            // it beside the identity so every later screen reads one value.
            this.state.formType = identity?.formType === 'assessment' ? 'assessment' : 'clearance';

            // D-72, the rest is over: seed everything the resting visit already
            // holds so Review can show the whole visit, and remember which
            // steps must be re-taken. The server decides both.
            if (Array.isArray(identity?.recheck?.steps) && identity.recheck.steps.length > 0) {
                this.seedRecheck(identity.recheck);
            }

            this.state.screen = 'identity';
        },

        /**
         * D-72 — prepare a RE-CHECK pass from the server's payload.
         *
         * The kept vitals and answers are display data: they fill the Review
         * screen so the student checks their whole visit, not two numbers out
         * of six. The server re-reads every one of them from the saved rows at
         * submit and accepts none of them from the browser, so nothing here is
         * trusted — it is only shown.
         */
        seedRecheck(recheck) {
            this.state.recheck.active = true;
            this.state.recheck.steps = [...recheck.steps];

            const kept = recheck.kept ?? {};
            const v = kept.vitals ?? {};

            // Every step starts as already-captured with its saved value; the
            // ones being re-taken are then blanked back to 'ready' below.
            const capture = (values) => ({ ...vitalStep(), phase: 'captured', values });
            this.state.vitalSteps = {
                1: capture({ height: v.height }),
                2: capture({ weight: v.weight }),
                3: capture({ temperature: v.temperature }),
                4: capture({ systolic: v.systolic, diastolic: v.diastolic, heartRate: v.heartRate }),
            };

            for (const step of this.activeSteps()) {
                this.state.vitalSteps[step] = vitalStep();
            }

            // The questionnaire and social history are NOT asked again — they
            // are seeded only so the Review cards can show them.
            this.state.questionnaire = {
                systems: { ...(kept.screening ?? {}) },
                details: { ...(kept.details ?? {}) },
                isPregnant: kept.isPregnant ?? null,
                lmp: kept.lastMenstrualPeriod ?? null,
                calMonth: null,
            };

            if (kept.socialHistory) {
                this.state.socialHistory = { ...kept.socialHistory };
            }
        },

        /** D-72 — is this pass a re-check rather than a first pass? */
        isRecheck() {
            return this.state.recheck.active;
        },

        /**
         * D-72 — which reading(s) the Rest screen names, in plain words:
         * "blood pressure", "temperature", "heart rate", or a list of them.
         *
         * Read from the same display-time flag helpers the vitals badges use,
         * on the pass that is still in front of us. It names the reading and
         * nothing else — no value, no interpretation (FR-KSK-14).
         */
        restReadingLabel() {
            const names = [];
            if (this.bpFlagged(this.fieldValue('systolic'), this.fieldValue('diastolic'))) names.push('blood pressure');
            if (this.tempFlagged(this.fieldValue('temperature'))) names.push('temperature');
            if (this.hrFlagged(this.fieldValue('heartRate'))) names.push('heart rate');

            if (names.length === 0) return 'blood pressure';
            if (names.length === 1) return names[0];

            return `${names.slice(0, -1).join(', ')} and ${names.at(-1)}`;
        },

        /** The configured rest length in minutes (D-72) — never a literal. */
        restMinutes() {
            return this.config.kiosk?.recheckRestMinutes ?? 10;
        },

        /**
         * "That's me" → schedule check (FR-KSK-03a, D-61). The server already
         * decided, at identity time, whether this student has a `scheduled`
         * appointment today (`hasAppointmentToday`). With one, we go straight to
         * Privacy Consent; without one, the "No Clinic Schedule Today" screen,
         * whose only way out is back to Welcome — there are no walk-ins. The
         * server refuses the submit on its own as well, so this is a courtesy,
         * not the gate.
         */
        confirmIdentity() {
            // D-72: a re-check pass goes straight to the vitals screen. The
            // resting visit already holds the appointment link, the consent,
            // the questionnaire and the social history, so asking again would
            // be asking the same student the same questions twice.
            if (this.isRecheck()) {
                this.state.vitalStep = this.activeSteps()[0];
                this.go('vitals');
                return;
            }

            // FR-KSK-03b: already submitted a visit on today's appointment —
            // say so (reference only, never an outcome) rather than "no schedule".
            if (this.state.identity?.alreadyScreenedToday) {
                this.go('already-screened');
                return;
            }

            this.go(this.state.identity?.hasAppointmentToday ? 'consent' : 'no-schedule');
        },

        // ── Privacy consent (FR-KSK-04) ──────────────────────────────────────
        // Per session: agreeing stamps a timestamp in kiosk state only; it is
        // persisted to clinic_visits.privacy_consent_at at final submit. Decline
        // resets everything and stores NOTHING.
        agreeConsent() {
            this.state.consentAt = new Date().toISOString();
            this.go('vitals');
        },

        // ── Vitals sequence (FR-KSK-05/06/08/09/14) ──────────────────────────
        // One reusable 3-phase step (ready → scanning → captured) renders EVERY
        // step from VITALS metadata. A step owns one or more fields; BP (step 4)
        // groups three values captured in a single reading.

        /** Metadata for a step (1–4), or null if out of range. */
        vitalMeta(step) {
            return VITALS[step] ?? null;
        },

        /** The 3-phase record for the current step. */
        currentStep() {
            return this.state.vitalSteps[this.state.vitalStep];
        },

        /** Convenience: the current step's phase ('ready' | 'waiting' | 'sampling' | 'scanning' | 'captured'). */
        stepPhase() {
            return this.currentStep().phase;
        },

        /** A step's first field — the primary reading for single-field steps. */
        primaryField(step = this.state.vitalStep) {
            return this.vitalMeta(step)?.fields[0] ?? null;
        },

        /** A captured field value by key, searched across all steps (null if unset). */
        fieldValue(key) {
            for (const s of Object.values(this.state.vitalSteps)) {
                if (s.values[key] != null) return s.values[key];
            }
            return null;
        },

        /** Display string for a value, honouring the field's decimals (e.g. 36.8). */
        formatField(field, value) {
            if (value == null) return '';
            return field.decimals != null ? value.toFixed(field.decimals) : String(value);
        },

        /**
         * Height in feet and inches, e.g. 175 → "5 ft 8.9 in" (FR-KSK-17).
         * Display only — cm is what is stored and printed. Inches are rounded
         * to one decimal, and a round-up to 12.0 carries into the next foot.
         */
        formatFeetInches(cm) {
            if (cm == null) return '';
            const totalInches = cm / CM_PER_INCH;
            let feet = Math.floor(totalInches / INCHES_PER_FOOT);
            let inches = roundTo(totalInches - feet * INCHES_PER_FOOT, 1);
            if (inches >= INCHES_PER_FOOT) {
                feet += 1;
                inches -= INCHES_PER_FOOT;
            }
            return `${feet} ft ${inches.toFixed(1)} in`;
        },

        /** {min,max} bounds for a field, from injected config (FR-KSK-08). */
        rangeFor(field) {
            return this.config.validation?.[field.range] ?? { min: 0, max: 0 };
        },

        inRange(field, value) {
            const r = this.rangeFor(field);
            return !Number.isNaN(value) && value >= r.min && value <= r.max;
        },

        // ── Sensor path (FR-KSK-07 stub — wired now, hardware in W5) ──────────
        /**
         * THE entry point for sensor readings. The Web Serial module (W5) parses
         * the combined reading line (§11.2) and calls this with an object keyed by
         * sensor letters — e.g. { H: 163 } or { T: 37.9, S: 118, D: 76, R: 72 }.
         * The dev "Simulate reading" button calls this SAME function, so manual
         * testing exercises the exact production path. Readings are grouped by the
         * step they belong to, so BP's three values capture together.
         * `suspect` (D-58) marks a reading the BP monitor itself flagged.
         */
        receiveReading(reading, { suspect = false } = {}) {
            const byStep = {};
            for (const [sensorKey, raw] of Object.entries(reading)) {
                const found = this.findField(sensorKey);
                if (!found) continue; // unknown key — ignore (forward compatibility)
                (byStep[found.step] ??= []).push({ field: found.field, value: Number(raw) });
            }
            for (const [step, items] of Object.entries(byStep)) {
                this.captureStepFromSensor(Number(step), items, suspect);
            }
        },

        /** Locate which step + field a sensor letter belongs to. */
        findField(sensorKey) {
            for (const step of Object.keys(VITALS)) {
                const field = VITALS[step].fields.find((f) => f.sensorKey === sensorKey);
                if (field) return { step: Number(step), field };
            }
            return null;
        },

        /**
         * Run a step's reading through scanning → captured. Degrades gracefully
         * (FR-KSK-07): an incomplete or out-of-range reading is never a dead end —
         * it falls back to ready with a nudge to retry or enter it manually.
         */
        captureStepFromSensor(step, items, suspect = false) {
            const s = this.state.vitalSteps[step];
            const meta = VITALS[step];
            s.phase = 'scanning';
            s.notice = '';
            setTimeout(() => {
                const complete = meta.fields.every((f) => items.some((i) => i.field.key === f.key));
                const bad = items.find((i) => !this.inRange(i.field, i.value));
                if (!complete || bad) {
                    s.phase = 'ready';
                    s.notice = `Sensor reading for ${meta.label.toLowerCase()} looked off. Try again or enter it manually.`;
                    return;
                }
                const values = {};
                for (const i of items) values[i.field.key] = i.value;
                s.values = values;
                s.method = 'sensor';
                s.suspect = suspect;
                s.phase = 'captured';
                s.notice = '';
            }, SCAN_MS);
        },

        // ── Seven-sample capture (D-74 — height, weight, temperature) ─────────
        /** The current step's field when it is captured by sampling, else null (BP never is). */
        sampledField(step = this.state.vitalStep) {
            const field = this.primaryField(step);
            return field?.clusterTolerance != null ? field : null;
        },

        /**
         * Buffer one sensor reading for the current step. The first reading
         * turns the step to 'sampling' (the "Measuring…" card) and starts the
         * SAMPLE_WINDOW_MS window; every reading then checks whether the
         * buffer can be decided.
         */
        addSample(value) {
            const step = this.state.vitalStep;
            const s = this.state.vitalSteps[step];
            if (s.phase !== 'ready' && s.phase !== 'sampling') return;
            // While the manual pad is open the typed value wins: the sensor
            // keeps streaming, but none of it is collected underneath the pad.
            if (this.state.pad.open) return;

            if (s.phase === 'ready') {
                s.phase = 'sampling';
                s.notice = '';
                this.clearSampleTimer();
                this._sampleTimer = setTimeout(() => {
                    this._sampleTimer = null;
                    // Only if this SAME step record is still sampling — a
                    // Retry, the pad or a reset replaces or empties it.
                    if (this.state.vitalSteps[step] !== s || s.phase !== 'sampling') return;
                    s.windowOver = true;
                    this.decideSamples(step);
                }, SAMPLE_WINDOW_MS);
            }

            s.samples = [...s.samples, value];
            this.decideSamples(step);
        },

        /**
         * Decide once the buffer holds SAMPLE_COUNT readings, or the window has
         * passed with at least MIN_SAMPLES. With fewer, keep waiting — never
         * invent a number from one or two readings. The steadiest group's
         * average then goes through the ordinary sensor path
         * (receiveReading), so range checks, entry_method and the captured
         * phase behave exactly as for any other sensor reading.
         */
        decideSamples(step) {
            const s = this.state.vitalSteps[step];
            const enough = s.samples.length >= SAMPLE_COUNT
                || (s.windowOver && s.samples.length >= MIN_SAMPLES);
            if (s.phase !== 'sampling' || !enough) return;

            const field = this.sampledField(step);
            const average = roundTo(pickSteadiest(s.samples, field.clusterTolerance), field.averageDecimals);
            this.discardSamples(step);
            this.receiveReading({ [field.sensorKey]: average });
        },

        /** Drop a step's sample buffer and put it back to 'ready' (the pad, Previous step). */
        discardSamples(step) {
            this.clearSampleTimer();
            const s = this.state.vitalSteps[step];
            s.samples = [];
            s.windowOver = false;
            if (s.phase === 'sampling') s.phase = 'ready';
        },

        clearSampleTimer() {
            if (this._sampleTimer) clearTimeout(this._sampleTimer);
            this._sampleTimer = null;
        },

        /**
         * Dev-only: inject a plausible reading for every field of the current
         * step. A sampled step (D-74) gets seven readings through the serial
         * path instead — two unsettled ones first, then a steady group — so
         * the result shown is the group's average, not the first number.
         */
        simulateReading() {
            const meta = this.vitalMeta(this.state.vitalStep);
            if (!meta) return;
            const sampled = this.sampledField();
            if (sampled) {
                const offsets = [-3, -2, 0.3, -0.3, 0.2, 0, -0.2]; // × tolerance
                for (const offset of offsets) {
                    const value = roundTo(sampled.sample + offset * sampled.clusterTolerance, 1);
                    this.onSerialReading({ [sampled.sensorKey]: value });
                }
                return;
            }
            const reading = {};
            for (const f of meta.fields) reading[f.sensorKey] = f.sample;
            this.receiveReading(reading);
        },

        // ── Manual entry pad (FR-KSK-06 — first-class on every step) ──────────
        /**
         * Disguised trigger for manual entry. The visible "Enter manually" button
         * was removed so a student can't simply fake a reading; instead an operator
         * (nurse/staff) triple-taps the corner HealthPass logo to reveal the numeric
         * pad for whatever step is on screen. Taps must land within GESTURE_WINDOW_MS
         * of each other, so stray single taps never open it.
         */
        logoTap() {
            const now = Date.now();
            if (now - this._lastLogoTap > GESTURE_WINDOW_MS) this._logoTaps = 0;
            this._lastLogoTap = now;
            this._logoTaps += 1;
            if (this._logoTaps >= 3) {
                this._logoTaps = 0;
                this.openPad();
            }
        },

        openPad() {
            const meta = this.vitalMeta(this.state.vitalStep);
            if (!meta) return;
            // D-59: the pad stays first-class while the BP step waits for the
            // monitor — opening it stops the wait.
            if (this.isAwaitingBp()) this.endBpWait();
            // D-74: likewise mid-sampling — the buffer is thrown away and the
            // typed value wins; the sensor readings are never mixed into it.
            if (this.stepPhase() === 'sampling') this.discardSamples(this.state.vitalStep);
            // Manual entry is a first-class path only BEFORE a reading is taken
            // (the 'ready' phase) — "just about to read each vital". Once the
            // step is captured (or mid-scan), the disguised gesture does nothing,
            // so a finished result can never be silently retyped.
            if (this.stepPhase() !== 'ready') return;
            this.state.pad = {
                open: true,
                step: this.state.vitalStep,
                fieldIndex: 0,
                value: '',
                error: '',
                draft: {},
            };
        },

        /** The field the pad is currently collecting. */
        padField() {
            const meta = this.vitalMeta(this.state.pad.step);
            return meta?.fields[this.state.pad.fieldIndex] ?? null;
        },

        /** Whether the pad is on the last field of its step (BP has three). */
        padIsLastField() {
            const meta = this.vitalMeta(this.state.pad.step);
            return meta ? this.state.pad.fieldIndex >= meta.fields.length - 1 : true;
        },

        padCancel() {
            this.state.pad.open = false;
        },

        padKey(k) {
            const pad = this.state.pad;
            pad.error = '';
            if (k === 'backspace') {
                pad.value = pad.value.slice(0, -1);
                return;
            }
            if (k === 'clear') {
                pad.value = '';
                return;
            }
            if (k === '.') {
                if (!pad.value.includes('.')) pad.value += pad.value === '' ? '0.' : '.';
                return;
            }
            if (pad.value.replace('.', '').length >= 5) return; // sane digit cap
            pad.value += k;
        },

        padConfirm() {
            const pad = this.state.pad;
            const field = this.padField();
            const value = Number(pad.value);
            if (pad.value === '' || Number.isNaN(value)) {
                pad.error = 'Enter a number.';
                return;
            }
            // Same ranges as the sensor path; out-of-range prompts re-entry (FR-KSK-08).
            if (!this.inRange(field, value)) {
                const r = this.rangeFor(field);
                pad.error = `Enter a value between ${r.min} and ${r.max} ${field.unit}.`;
                return;
            }
            pad.draft = { ...pad.draft, [field.key]: value };

            // More fields to collect (e.g. BP: systolic → diastolic → heart rate).
            if (!this.padIsLastField()) {
                pad.fieldIndex += 1;
                pad.value = '';
                pad.error = '';
                return;
            }

            // Last field confirmed → commit the whole step in one go.
            const s = this.state.vitalSteps[pad.step];
            s.values = { ...s.values, ...pad.draft };
            s.method = 'manual';
            s.phase = 'captured';
            s.notice = '';
            pad.open = false;
        },

        // ── Step navigation + retake ─────────────────────────────────────────
        /**
         * The vital steps THIS pass walks, in order.
         *
         * A first pass walks all four. A D-72 re-check walks only the steps the
         * server named — the student already gave their height and weight, and
         * resting cannot change them. Everything below (the progress dots, the
         * "Step 2 of 2" heading, Next/Continue, Previous) reads this one list,
         * so nothing counts to four any more.
         */
        activeSteps() {
            if (!this.isRecheck()) return ALL_STEPS;

            return this.state.recheck.steps
                .map((key) => RECHECK_STEPS[key])
                .filter((step) => step != null)
                .sort((a, b) => a - b);
        },

        /** 1-based position of the current step within activeSteps(). */
        stepIndex() {
            return this.activeSteps().indexOf(this.state.vitalStep) + 1;
        },

        /** How many steps this pass has — 4 normally, 1 or 2 on a re-check. */
        stepCount() {
            return this.activeSteps().length;
        },

        isFirstStep() {
            return this.stepIndex() <= 1;
        },

        isLastStep() {
            return this.stepIndex() >= this.stepCount();
        },

        /** Discard the current step's reading and return it to "ready". */
        retryVital() {
            this.clearSampleTimer();
            this.state.vitalSteps[this.state.vitalStep] = vitalStep();
        },

        nextVital() {
            const steps = this.activeSteps();
            const next = steps[steps.indexOf(this.state.vitalStep) + 1];

            if (next !== undefined) {
                this.state.vitalStep = next;
                return;
            }

            // D-72: a re-check has nothing left to ask — the questionnaire and
            // the social history were answered on the first pass — so it goes
            // straight to Review.
            this.go(this.isRecheck() ? 'review' : 'questionnaire');
        },

        prevVital() {
            if (this.isAwaitingBp()) this.endBpWait(); // leaving the BP step stops listening (D-59)
            if (this.stepPhase() === 'sampling') this.discardSamples(this.state.vitalStep); // half a buffer is not kept (D-74)
            const steps = this.activeSteps();
            const previous = steps[steps.indexOf(this.state.vitalStep) - 1];
            if (previous !== undefined) this.state.vitalStep = previous;
        },

        // ── BMI (FR-KSK-09 — computed, never entered) ────────────────────────
        /** weight(kg) ÷ height(m)², rounded to 1 decimal; null until both exist. */
        bmiValue() {
            const h = this.fieldValue('height');
            const w = this.fieldValue('weight');
            if (!h || !w) return null;
            const m = h / 100;
            return Math.round((w / (m * m)) * 10) / 10;
        },

        bmiStatus(bmi) {
            if (bmi < 18.5) return 'Underweight';
            if (bmi < 25) return 'Normal';
            if (bmi < 30) return 'Overweight';
            return 'Obese';
        },

        /**
         * D-78: outside Normal → is_bmi_flagged — below bmiNormalMin or at/above
         * bmiNormalMax (exclusive: 24.9 Normal, 25.0 flagged). From config
         * (BR-13, single source of truth); the server recomputes it anyway.
         */
        bmiFlagged(bmi) {
            if (bmi === null) return false;
            return bmi < (this.config.bmiNormalMin ?? 18.5) || bmi >= (this.config.bmiNormalMax ?? 25);
        },

        /**
         * Colour-coded BMI status badge (UI only): Underweight + Obese = red,
         * Normal = green, Overweight = orange. This is purely the visual cue;
         * the ⚑/is_bmi_flagged rule is bmiFlagged() — anything not Normal (D-78).
         */
        bmiBadgeClass(bmi) {
            switch (this.bmiStatus(bmi)) {
                case 'Normal':
                    return 'bg-emerald-50 text-emerald-600';
                case 'Overweight':
                    return 'bg-hp-orange/15 text-hp-orange';
                default: // Underweight or Obese
                    return 'bg-red-50 text-red-600';
            }
        },

        // ── Temperature status badge (FR-KSK-14 — neutral wording ONLY) ──────
        // Threshold from config (BR-13). The kiosk shows ONLY "Normal" /
        // "Slightly Elevated" — never "Fever" or any clinical interpretation.
        tempFlagged(t) {
            return t != null && t > (this.config.thresholds?.tempMax ?? 37.2);
        },
        tempStatus(t) {
            return this.tempFlagged(t) ? 'Slightly Elevated' : 'Normal';
        },
        tempBadgeClass(t) {
            return this.tempFlagged(t)
                ? 'bg-hp-orange/15 text-hp-orange'
                : 'bg-emerald-50 text-emerald-600';
        },

        // ── Blood-pressure status badge (FR-KSK-14 — neutral wording ONLY) ───
        // Flag if systolic ≥ 140 OR diastolic ≥ 90 (D-10, config). Neutral
        // wording only — never "High Blood Pressure" or any diagnosis.
        bpFlagged(sys, dia) {
            const t = this.config.thresholds ?? {};
            return (
                (sys != null && sys >= (t.bpSystolic ?? 140)) ||
                (dia != null && dia >= (t.bpDiastolic ?? 90))
            );
        },
        bpStatus(sys, dia) {
            return this.bpFlagged(sys, dia) ? 'Slightly Elevated' : 'Normal';
        },
        bpBadgeClass(sys, dia) {
            return this.bpFlagged(sys, dia)
                ? 'bg-hp-orange/15 text-hp-orange'
                : 'bg-emerald-50 text-emerald-600';
        },

        // ── Heart-rate status badge (D-66; FR-KSK-14 — neutral wording ONLY) ─
        // > hrMax bpm → is_hr_flagged, from config (BR-13). No low-heart-rate
        // flag: a resting rate under 60 is common in healthy young students.
        // The server recomputes this at submit — the badge is display only.
        hrFlagged(bpm) {
            return bpm != null && bpm > (this.config.thresholds?.hrMax ?? 100);
        },
        hrStatus(bpm) {
            return this.hrFlagged(bpm) ? 'High' : 'Normal';
        },
        hrBadgeClass(bpm) {
            return this.hrFlagged(bpm)
                ? 'bg-hp-orange/15 text-hp-orange'
                : 'bg-emerald-50 text-emerald-600';
        },

        // ── Questionnaire: the form's twelve rows (FR-KSK-10, D-63) ────────────
        /**
         * Record a Yes (true) / No (false) answer for one card. Switching to No
         * clears any detail typed under the Yes, and closes its panel (D-56).
         */
        setSystem(key, value) {
            const q = this.state.questionnaire;
            q.systems[key] = value;
            if (value === false) {
                const { [key]: _cleared, ...kept } = q.details;
                q.details = kept;
                if (this.state.detailPanel.question === key) this.closeDetail();
            }
            this.scrollToNextUnanswered(key);
        },

        /**
         * Motion pass (§7): after answering, nudge the NEXT unanswered card
         * into view. `block: 'nearest'` scrolls the minimum distance (or not
         * at all if it is already visible) — a gentle guide, never a hijack.
         * Reduced motion swaps the smooth glide for an instant jump.
         */
        scrollToNextUnanswered(afterKey) {
            const index = SYSTEMS.findIndex((s) => s.key === afterKey);
            const next = SYSTEMS.slice(index + 1).find(
                (s) => this.state.questionnaire.systems[s.key] === undefined,
            );
            if (!next) return;
            // Outside Alpine (the Node test runner) there is no $nextTick and
            // no DOM — the nudge is purely cosmetic, so just skip it.
            if (typeof this.$nextTick !== 'function') return;
            this.$nextTick(() => {
                document.querySelector(`[data-system-card="${next.key}"]`)?.scrollIntoView({
                    block: 'nearest',
                    behavior: prefersReducedMotion() ? 'auto' : 'smooth',
                });
            });
        },

        /** A question's answer: true (Yes) | false (No) | undefined (unanswered). */
        systemAnswer(key) {
            return this.state.questionnaire.systems[key];
        },

        // ── YES details (D-56 — the form: "If YES, give details under Remarks")
        // ≤ DETAIL_MAX characters. Optional on a Medical Assessment; REQUIRED
        // (≥ DETAIL_MIN after trimming) on a Medical Clearance, where a YES
        // without one blocks Review (D-75). Typed in a
        // full-width panel docked at the bottom of the questionnaire, because a
        // card in the 2-column grid is too narrow to type in on the portrait
        // panel. The text lives in state.questionnaire.details, so a reset to
        // Welcome wipes it with everything else (FR-KSK-13).

        /** The detail typed for a question, exactly as typed ('' if none). */
        detailText(key) {
            return this.state.questionnaire.details[key] ?? '';
        },

        /** True when this form requires a detail under every YES (D-75). */
        detailsRequired() {
            return !this.isAssessment();
        },

        /** A Medical Clearance YES still waiting for its detail (D-75). */
        detailMissing(key) {
            return (
                this.detailsRequired() &&
                this.systemAnswer(key) === true &&
                this.detailText(key).trim().length < DETAIL_MIN
            );
        },

        /** The first question still missing its detail, or null (D-75). */
        firstMissingDetail() {
            return SYSTEMS.find((s) => this.detailMissing(s.key)) ?? null;
        },

        /** The form label of the question whose panel is open ('' when closed). */
        detailLabel() {
            return SYSTEMS.find((s) => s.key === this.state.detailPanel.question)?.label ?? '';
        },

        /** Open the details panel — only for a question answered Yes. */
        openDetail(key) {
            if (this.systemAnswer(key) !== true) return;
            this.state.detailPanel = { question: key, shift: false, caps: false };
        },

        /** Close the panel, trimming the detail; a blank one is removed, not kept. */
        closeDetail() {
            const question = this.state.detailPanel.question;
            this.state.detailPanel = { question: null, shift: false, caps: false };
            if (question === null) return;
            const { [question]: typed = '', ...others } = this.state.questionnaire.details;
            const text = typed.trim();
            this.state.questionnaire.details = text === '' ? others : { ...others, [question]: text };
        },

        // ── Pregnancy + Last Menstrual Period (FR-KSK-10) ────────────────────
        /**
         * Answer the pregnancy question. "No" clears any LMP. "Yes" opens the
         * inline calendar on the current month (so a date is one tap away) and
         * leaves LMP unset — it stays required until a day is picked.
         */
        setPregnant(value) {
            const q = this.state.questionnaire;
            q.isPregnant = value;
            if (value === false) {
                q.lmp = null;
                q.calMonth = null;
                return;
            }
            if (q.calMonth === null) {
                const now = new Date();
                q.calMonth = { year: now.getFullYear(), month: now.getMonth() };
            }
        },

        /** Heading for the calendar's current month, e.g. "June 2026". */
        calMonthLabel() {
            const c = this.state.questionnaire.calMonth;
            if (!c) return '';
            return new Date(c.year, c.month, 1).toLocaleDateString('en-US', {
                month: 'long',
                year: 'numeric',
            });
        },

        /**
         * Calendar cells for the current month: leading blanks (null) to align
         * the 1st under its weekday, then 1..daysInMonth. Sunday-first grid.
         */
        calDays() {
            const c = this.state.questionnaire.calMonth;
            if (!c) return [];
            const firstWeekday = new Date(c.year, c.month, 1).getDay(); // 0 = Sun
            const daysInMonth = new Date(c.year, c.month + 1, 0).getDate();
            const cells = [];
            for (let i = 0; i < firstWeekday; i += 1) cells.push(null);
            for (let d = 1; d <= daysInMonth; d += 1) cells.push(d);
            return cells;
        },

        /** True when the calendar is showing the present month (block forward nav). */
        calIsCurrentMonth() {
            const c = this.state.questionnaire.calMonth;
            const now = new Date();
            return !!c && c.year === now.getFullYear() && c.month === now.getMonth();
        },

        calPrevMonth() {
            const c = this.state.questionnaire.calMonth;
            const d = new Date(c.year, c.month - 1, 1);
            this.state.questionnaire.calMonth = { year: d.getFullYear(), month: d.getMonth() };
        },

        calNextMonth() {
            // No future months — an LMP can't be after today (FR-KSK-10).
            if (this.calIsCurrentMonth()) return;
            const c = this.state.questionnaire.calMonth;
            const d = new Date(c.year, c.month + 1, 1);
            this.state.questionnaire.calMonth = { year: d.getFullYear(), month: d.getMonth() };
        },

        /** ISO 'YYYY-MM-DD' for a day in the current calendar month. */
        calDayIso(day) {
            const c = this.state.questionnaire.calMonth;
            const mm = String(c.month + 1).padStart(2, '0');
            const dd = String(day).padStart(2, '0');
            return `${c.year}-${mm}-${dd}`;
        },

        /** A future date — disabled and unselectable (FR-KSK-10). */
        calDayIsFuture(day) {
            const c = this.state.questionnaire.calMonth;
            const date = new Date(c.year, c.month, day);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            return date > today;
        },

        calDayIsSelected(day) {
            return this.state.questionnaire.lmp === this.calDayIso(day);
        },

        /** Pick a day as the LMP (no-op for future dates). */
        selectLmp(day) {
            if (this.calDayIsFuture(day)) return;
            this.state.questionnaire.lmp = this.calDayIso(day);
        },

        /** Human label for the chosen LMP, e.g. "June 3, 2026" ('' if none). */
        lmpLabel() {
            const lmp = this.state.questionnaire.lmp;
            if (!lmp) return '';
            const [y, m, d] = lmp.split('-').map(Number);
            return new Date(y, m - 1, d).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
            });
        },

        // ── Completion gate (FR-KSK-10) ──────────────────────────────────────
        /**
         * Whether the pregnancy item counts as answered: "No" alone is enough,
         * but "Yes" also requires an LMP date (FR-KSK-10).
         */
        pregnancyAnswered() {
            const q = this.state.questionnaire;
            return q.isPregnant === false || (q.isPregnant === true && q.lmp !== null);
        },

        /** How many of the 13 questions are answered (footer "{N} of 13"). */
        answeredCount() {
            const answered = SYSTEMS.filter(
                (s) => this.state.questionnaire.systems[s.key] !== undefined,
            ).length;
            return answered + (this.pregnancyAnswered() ? 1 : 0);
        },

        /**
         * All 13 answered → Review & Submit unlocks (FR-KSK-10) — and, on a
         * Medical Clearance, every YES carries its detail (D-75).
         */
        questionnaireComplete() {
            return this.answeredCount() === QUESTION_COUNT && this.firstMissingDetail() === null;
        },

        /**
         * Advance once every question is answered (FR-KSK-10). D-68: an
         * Assessment student answers the Personal / Social History first; a
         * Clearance student, whose form has no such section, goes straight to
         * Review.
         */
        goReview() {
            if (!this.questionnaireComplete()) return;
            this.go(this.isAssessment() ? 'social-history' : 'review');
        },

        // -- Personal / Social History (FR-KSK-10a, D-68) --------------------
        /** True when today's batch named the Medical Assessment Form. */
        isAssessment() {
            return this.state.formType === 'assessment';
        },

        /** The questionnaire heading for this student's form (D-68). */
        questionnaireHeading() {
            return QUESTIONNAIRE_HEADINGS[this.state.formType] ?? QUESTIONNAIRE_HEADINGS.clearance;
        },

        /**
         * Record one Personal / Social History answer. Sexually Active is
         * stored as a boolean because the paper offers it only Yes/No; the
         * three habits keep the paper's own word.
         */
        setSocialHistory(key, value) {
            this.state.socialHistory = {
                ...this.state.socialHistory,
                [key]: key === 'sexuallyActive' ? value === 'yes' : value,
            };
        },

        /** Whether this row currently holds `value` — drives the button styling. */
        socialHistoryAnswer(key, value) {
            const stored = this.state.socialHistory[key];
            return key === 'sexuallyActive' ? stored === (value === 'yes') : stored === value;
        },

        /** All four answered -> Continue unlocks. */
        socialHistoryComplete() {
            return SOCIAL_HISTORY.every(({ key }) => this.state.socialHistory[key] !== null);
        },

        /** Continue -> Review, only once all four are answered. */
        goReviewFromSocialHistory() {
            if (this.socialHistoryComplete()) this.go('review');
        },

        /** One row's answer as the paper words it, for the Review card. */
        socialHistoryLabel(key) {
            const stored = this.state.socialHistory[key];
            if (stored === null) return '';
            if (key === 'sexuallyActive') return stored ? 'Yes' : 'No';
            return SOCIAL_HISTORY_LABELS[stored] ?? '';
        },

        /**
         * Review's Back button. It steps back through the ORDERED flow, so an
         * Assessment student lands on their Personal / Social History and a
         * Clearance student, who never saw that screen, on the questionnaire.
         */
        backFromReview() {
            // D-72: a re-check never saw the questionnaire, so Back returns to
            // the last step it actually walked.
            if (this.isRecheck()) {
                this.state.vitalStep = this.activeSteps().at(-1);
                this.go('vitals');
                return;
            }

            this.go(this.isAssessment() ? 'social-history' : 'questionnaire');
        },

        // ── Submit to clinic (FR-KSK-11 → stub) ──────────────────────────────
        /**
         * Assemble the full kiosk session for submission. Vitals are flattened
         * to the columns the server will persist; screening maps each form row
         * to its boolean column (null if somehow unanswered), plus the YES
         * details and pregnancy/LMP. The AUTHORITATIVE flag booleans are
         * computed server-side (§7.4) — the review screen's orange ⚑ are
         * display-time hints only — and the server re-cleans the details too
         * (KioskSubmitRequest, D-56): nothing here is trusted.
         */
        buildSubmission() {
            const q = this.state.questionnaire;
            const screening = {};
            const details = {};
            for (const s of SYSTEMS) {
                screening[s.key] = q.systems[s.key] ?? null;
                const text = this.detailText(s.key).trim();
                if (q.systems[s.key] === true && text !== '') details[s.key] = text;
            }
            return {
                studentUserId: this.state.identity?.studentUserId ?? null,
                loginMethod: this.state.identity?.loginMethod ?? null,
                privacyConsentAt: this.state.consentAt,
                // Per-step provenance; the server rolls these up to the stored
                // entry_method (sensor / manual / mixed) (FR-KSK-06).
                vitalMethods: Object.values(this.state.vitalSteps)
                    .map((s) => s.method)
                    .filter(Boolean),
                vitals: {
                    height: this.fieldValue('height'),
                    weight: this.fieldValue('weight'),
                    bmi: this.bmiValue(),
                    temperature: this.fieldValue('temperature'),
                    systolic: this.fieldValue('systolic'),
                    diastolic: this.fieldValue('diastolic'),
                    heartRate: this.fieldValue('heartRate'),
                },
                screening: {
                    ...screening,
                    details,
                    isPregnant: q.isPregnant,
                    lastMenstrualPeriod: q.lmp,
                },
                // D-68: sent only when this student's form has the section. The
                // server decides that for itself either way — it drops the block
                // on a Clearance visit and requires it on an Assessment one,
                // whatever the browser sends.
                ...(this.isAssessment() ? { socialHistory: { ...this.state.socialHistory } } : {}),
            };
        },

        /**
         * POST the session to the submit endpoint (currently a stub; the full
         * transactional write is FR-KSK-12, a later week). On success the kiosk
         * advances to the Complete screen (FR-KSK-13).
         */
        /**
         * Motion pass (§7): the Review screen shows a "Saving your visit…"
         * overlay while status is 'sending'. A fast server would flash it for
         * ~50 ms — worse than nothing — so we keep it up for a minimum of
         * 400 ms (skipped under reduced motion). The wait happens AFTER the
         * response, so it never delays data that isn't ready anyway.
         */
        holdSubmitOverlay(startedAt) {
            const MIN_OVERLAY_MS = 400;
            if (prefersReducedMotion()) return Promise.resolve();
            const left = MIN_OVERLAY_MS - (Date.now() - startedAt);
            return left > 0 ? new Promise((resolve) => setTimeout(resolve, left)) : Promise.resolve();
        },

        /**
         * D-72 — does the Review screen offer "Rest & re-check" instead of
         * "Submit to Clinic"?
         *
         * True when the temperature, the blood pressure or the heart rate trips
         * its threshold on a FIRST pass. BMI never counts — resting will not
         * change a height or a weight — and a re-check pass never offers a
         * second rest, which the server enforces too.
         *
         * These are the same display-time helpers the vitals badges use. The
         * SERVER recomputes the flags at /kiosk/rest and refuses with a 422 if
         * it disagrees, which sends the student back to Submit (see rest()).
         */
        needsRest() {
            if (this.isRecheck()) return false;

            return (
                this.tempFlagged(this.fieldValue('temperature')) ||
                this.bpFlagged(this.fieldValue('systolic'), this.fieldValue('diastolic')) ||
                this.hrFlagged(this.fieldValue('heartRate'))
            );
        },

        /**
         * POST the first pass to /kiosk/rest (FR-KSK-11a). Same payload as a
         * submit — everything the student answered is stored — but the visit is
         * parked as `resting` and never reaches the clinic queue.
         *
         * On a 422 the server has recomputed the flags and found nothing worth
         * re-taking; `steps` stays empty, so the Review screen falls back to
         * showing Submit to Clinic and the student carries on normally.
         */
        async restAndRecheck() {
            if (this.state.recheck.status === 'sending') return;
            this.state.recheck.status = 'sending';
            this.state.recheck.error = '';
            const startedAt = Date.now();

            try {
                const { response, data } = await this.kioskPost(this.$refs.root.dataset.restUrl, this.buildSubmission());
                await this.holdSubmitOverlay(startedAt);

                if (response.ok && data.ok) {
                    // The come-back time is the SERVER's, shown verbatim.
                    this.state.recheck.until = data.restingUntil ?? null;
                    this.state.recheck.status = 'idle';
                    this.go('rest');
                    return;
                }

                this.state.recheck.status = 'error';
                this.state.recheck.error = data.message ?? 'Could not save that. Please try again.';
            } catch {
                await this.holdSubmitOverlay(startedAt);
                this.state.recheck.status = 'error';
                this.state.recheck.error = 'Network problem. Please try again.';
            }
        },

        /**
         * The re-check payload (D-72): ONLY the re-taken readings and how they
         * were taken. Height, weight, consent, the questionnaire and the social
         * history are all in the resting visit already, and the server reads
         * them from there — sending them would change nothing.
         */
        buildRecheckSubmission() {
            const steps = this.activeSteps();
            const payload = {
                vitalMethods: steps
                    .map((step) => this.state.vitalSteps[step].method)
                    .filter(Boolean),
            };

            if (this.state.recheck.steps.includes('temp')) {
                payload.temperature = this.fieldValue('temperature');
            }

            if (this.state.recheck.steps.includes('bp')) {
                payload.systolic = this.fieldValue('systolic');
                payload.diastolic = this.fieldValue('diastolic');
                payload.heartRate = this.fieldValue('heartRate');
            }

            return payload;
        },

        async submitToClinic() {
            if (this.state.submit.status === 'sending') return;
            this.state.submit = { status: 'sending', error: '' };
            const startedAt = Date.now();
            // D-72: a re-check goes to its own endpoint with its own (much
            // smaller) payload; everything else about this method is the same.
            const url = this.isRecheck()
                ? this.$refs.root.dataset.recheckUrl
                : this.$refs.root.dataset.submitUrl;
            const payload = this.isRecheck() ? this.buildRecheckSubmission() : this.buildSubmission();
            try {
                const { response, data } = await this.kioskPost(url, payload);
                await this.holdSubmitOverlay(startedAt);
                if (response.ok && data.ok) {
                    this.state.submit = { status: 'idle', error: '', reference: data.reference ?? null };
                    this.go('complete');
                    return;
                }
                this.state.submit = {
                    status: 'error',
                    error: data.message ?? 'Could not submit. Please try again.',
                    reference: null,
                };
            } catch {
                await this.holdSubmitOverlay(startedAt);
                this.state.submit = {
                    status: 'error',
                    error: 'Network problem submitting. Please try again.',
                };
            }
        },
    };
}
