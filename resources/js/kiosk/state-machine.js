import { createSerialReader } from './serial';

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
    'walkin',
    'consent',
    'vitals',
    'questionnaire',
    'review',
    'complete',
];

export const VITAL_STEPS = 4;

/**
 * The nine body systems of the screening questionnaire (FR-KSK-10). Pure DATA —
 * the Blade renders all nine cards from this list, so they are not nine copies
 * of markup. `key` is the canonical screening_responses boolean column (PRD data
 * dictionary §6); `label` is the on-screen title. Order matches the PRD.
 */
export const SYSTEMS = [
    { key: 'vision', label: 'Vision / Eyes' },
    { key: 'hearing', label: 'Hearing / Ears' },
    { key: 'nose', label: 'Nose & Throat' },
    { key: 'skin', label: 'Skin' },
    { key: 'respiratory', label: 'Respiratory / Breathing' },
    { key: 'heart', label: 'Heart / Circulation' },
    { key: 'digestive', label: 'Digestive / Stomach' },
    { key: 'bones', label: 'Bones & Joints' },
    { key: 'nervous', label: 'Nervous / Neurological' },
];

// 9 system cards + the pregnancy item = 10 questions to answer (FR-KSK-10).
export const QUESTION_COUNT = SYSTEMS.length + 1;

// How long the "scanning" animation runs before a sensor reading settles to
// "captured". Long enough to read the animation, short enough to feel snappy.
const SCAN_MS = 1200;

// Max gap between taps of the disguised manual-entry gesture (see logoTap).
const GESTURE_WINDOW_MS = 1500;

// Discreet staff-exit gesture (FR-KSK-16): all five taps must land within this
// window of the FIRST tap, so stray single taps never reveal the staff prompt.
const EXIT_TAPS = 5;
const EXIT_GESTURE_WINDOW_MS = 3000;

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
 *
 * Step extras: `showsBmi` renders the computed BMI panel (FR-KSK-09); `badge`
 * selects the neutral status badge ('temperature' | 'bp') — never a diagnosis
 * or Fit/Unfit (FR-KSK-14).
 */
export const VITALS = {
    1: {
        label: 'Height',
        instruction: 'Stand straight under the stadiometer, heels together, looking forward.',
        fields: [
            { key: 'height', unit: 'cm', range: 'height_cm', sensorKey: 'H', sample: 163 },
        ],
    },
    2: {
        label: 'Weight',
        instruction: 'Step onto the scale and stand still, arms relaxed at your sides.',
        fields: [
            { key: 'weight', unit: 'kg', range: 'weight_kg', sensorKey: 'W', sample: 58 },
        ],
        showsBmi: true,
    },
    3: {
        label: 'Temperature',
        instruction: 'Hold your forehead a few centimetres from the infrared thermometer and stay still.',
        badge: 'temperature',
        fields: [
            { key: 'temperature', unit: '°C', range: 'temperature_c', sensorKey: 'T', sample: 36.8, decimals: 1 },
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
 * A fresh, uncaptured step. phase: ready → scanning → captured. `values` holds
 * each field's reading by key (one key for most steps, three for BP); `method`
 * is the step's provenance for vital_signs.entry_method (FR-KSK-06).
 */
function vitalStep() {
    return {
        phase: 'ready',
        method: null, // 'sensor' | 'manual'
        notice: '', // non-blocking nudge (e.g. sensor degraded to manual)
        values: {}, // field key → number, filled on capture
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

        // QR keyboard-wedge feedback shown on the Welcome screen.
        scan: { status: 'idle', error: '' }, // idle | sending | error

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

        // 9-system screening + pregnancy (FR-KSK-10). `systems` maps a system
        // key → true (Yes) | false (No); an unanswered system is simply absent.
        // `isPregnant` is true | false | null (unanswered); `lmp` holds the Last
        // Menstrual Period as an ISO 'YYYY-MM-DD' string, required only when
        // pregnant. `calMonth` is the {year, month} the inline LMP calendar is
        // viewing (month is 0-based, matching JS Date).
        questionnaire: {
            systems: {},
            isPregnant: null,
            lmp: null,
            calMonth: null,
        },

        // Final-submit request sub-state (FR-KSK-11/12). Mirrors login/scan;
        // `reference` holds the server-minted HP-YYYY-#### shown on Complete.
        submit: { status: 'idle', error: '', reference: null }, // idle | sending | error

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

        // The nine screening systems, exposed so Blade can x-for over them
        // (FR-KSK-10) — the cards are data-driven, not nine copies of markup.
        systemList: SYSTEMS,

        // Web Serial UI status (FR-KSK-07). Lives on the COMPONENT, not in
        // `state`, because the physical sensor connection outlives one student:
        // a reset-to-Welcome must not drop a working port. `status` mirrors the
        // serial module's lifecycle; `notice` is a non-blocking degrade nudge.
        serial: { supported: false, status: 'idle', notice: '' },
        _serial: null, // the createSerialReader() instance (I/O lives here)

        // Tap bookkeeping for the disguised manual-entry gesture (see logoTap).
        _logoTaps: 0,
        _lastLogoTap: 0,

        // Tap bookkeeping for the discreet staff-exit gesture (see exitTap).
        _exitTaps: 0,
        _exitFirstTap: 0,

        // Timers for the email keyboard's backspace long-press (see backspaceDown).
        _bsRepeat: null,
        _bsClearTimer: null,

        // Session-lifecycle timers (FR-KSK-13/15). Held on the component (not in
        // `state`) so a wholesale state reset never strands a running timer.
        _idleTimer: null,
        _completeInterval: null,
        // Seconds left on the Complete auto-reset pill; reactive so Blade tracks it.
        completeCountdown: 0,

        init() {
            this.config = JSON.parse(this.$refs.root.dataset.config || '{}');
            // React to every screen change: (re)arm the idle timer mid-flow, and
            // run the Complete countdown only while the Complete screen is up.
            this.$watch('state.screen', (screen) => {
                this.bumpIdle();
                if (screen === 'complete') this.startCompleteCountdown();
                else this.clearCompleteCountdown();
            });
            this.setupSerial();
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

        // ── Navigation ───────────────────────────────────────────────────────
        go(screen) {
            if (!SCREENS.includes(screen)) return;
            this.state.screen = screen;
            // Keep the scanner hot whenever we are back on Welcome.
            if (screen === 'welcome') {
                this.state.scan = { status: 'idle', error: '' };
                this.focusWedge();
            }
        },

        /** Wipe everything and return to Welcome (FR-KSK-13). */
        reset() {
            this.clearIdle();
            this.clearCompleteCountdown();
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
            if (screen === 'welcome' || screen === 'complete') {
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

        /** Whether letters should currently be uppercase (Shift XOR Caps Lock). */
        isUpper() {
            return this.state.login.shift !== this.state.login.caps;
        },

        /** Display label for a key — letters reflect the current Shift/Caps case. */
        keyLabel(key) {
            return /^[a-z]$/.test(key) && this.isUpper() ? key.toUpperCase() : key;
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
         * Virtual-keyboard key press (FR-KSK-02). Routes character keys to the
         * focused field. Special keys: 'backspace', 'space', 'enter', plus the
         * 'shift' (one-shot) and 'caps' (lock) modifiers.
         */
        keyPress(key) {
            const login = this.state.login;

            if (key === 'enter') {
                // The same keyboard drives both the student email login and the
                // staff-exit prompt (FR-KSK-16); route Enter to whichever is open.
                if (this.state.exit.open) this.submitExit();
                else this.submitLogin();
                return;
            }
            if (key === 'shift') {
                login.shift = !login.shift;
                return;
            }
            if (key === 'caps') {
                login.caps = !login.caps;
                return;
            }

            login.error = ''; // typing clears any stale error
            this.state.exit.error = ''; // …in either context
            const field = login.field;

            if (key === 'backspace') {
                login[field] = login[field].slice(0, -1);
                return;
            }
            if (key === 'space') {
                login[field] += ' ';
                return;
            }

            // Letters honour the current case; digits/symbols are inserted as-is.
            login[field] += this.keyLabel(key);

            // Shift is a one-shot modifier — it releases after a single key.
            if (login.shift) login.shift = false;
        },

        // Backspace long-press (email keyboard). A quick tap deletes one
        // character; holding repeats with an accelerating speed; holding for a
        // full 2 s clears the active field entirely. Driven by pointer events so
        // it behaves the same on the touchscreen and a mouse.
        backspaceDown() {
            this.keyPress('backspace'); // immediate single delete on tap
            // Hold for 2 s → wipe the whole field, then stop repeating.
            this._bsClearTimer = setTimeout(() => {
                this.state.login[this.state.login.field] = '';
                this.backspaceUp();
            }, 2000);
            // After a short initial hold, begin an accelerating repeat.
            this._bsRepeat = setTimeout(() => this.backspaceTick(150), 400);
        },

        backspaceTick(delay) {
            this.keyPress('backspace');
            const next = Math.max(40, delay - 15); // speeds up to a 40 ms floor
            this._bsRepeat = setTimeout(() => this.backspaceTick(next), delay);
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

        // ── Shared keyboard helpers (email login + staff exit) ───────────────
        // The on-screen keyboard's Enter key serves both flows; these expose the
        // active flow's busy state so the key can disable + relabel correctly.
        kbSending() {
            return (this.state.exit.open ? this.state.exit.status : this.state.login.status) === 'sending';
        },

        kbEnterLabel() {
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
                this.state.exit.error = data.message ?? 'Those credentials don\'t match a nurse account.';
            } catch {
                this.state.exit.status = 'error';
                this.state.exit.error = 'Network problem. Please try again.';
            }
        },

        // ── Identity Confirm (FR-KSK-03) ─────────────────────────────────────
        /** Store the resolved student and show Identity Confirm. */
        arriveAtIdentity(identity) {
            this.state.identity = identity;
            this.state.screen = 'identity';
        },

        /**
         * "That's me" → Walk-in Check (FR-KSK-03a). The server already decided,
         * at identity time, whether ANY non-cancelled appointment exists for
         * today — medical or dental (`hasAppointmentToday`). With one, we skip
         * straight to Privacy Consent; with nothing booked, we show the "No
         * Scheduled Clearance Today" screen so the student can proceed as a
         * walk-in. This is a UI gate only — the appointment_id linkage is
         * resolved (medical-only) at submit.
         */
        confirmIdentity() {
            if (this.state.identity?.hasAppointmentToday) {
                this.go('consent');
            } else {
                this.go('walkin');
            }
        },

        /** "Proceed as Walk-in" (FR-KSK-03a) → Privacy Consent. */
        proceedAsWalkin() {
            this.go('consent');
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

        /** Convenience: the current step's phase ('ready' | 'scanning' | 'captured'). */
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
         */
        receiveReading(reading) {
            const byStep = {};
            for (const [sensorKey, raw] of Object.entries(reading)) {
                const found = this.findField(sensorKey);
                if (!found) continue; // unknown key — ignore (forward compatibility)
                (byStep[found.step] ??= []).push({ field: found.field, value: Number(raw) });
            }
            for (const [step, items] of Object.entries(byStep)) {
                this.captureStepFromSensor(Number(step), items);
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
        captureStepFromSensor(step, items) {
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
                s.phase = 'captured';
                s.notice = '';
            }, SCAN_MS);
        },

        /** Dev-only: inject a plausible reading for every field of the current step. */
        simulateReading() {
            const meta = this.vitalMeta(this.state.vitalStep);
            if (!meta) return;
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
        /** Discard the current step's reading and return it to "ready". */
        retryVital() {
            this.state.vitalSteps[this.state.vitalStep] = vitalStep();
        },

        nextVital() {
            if (this.state.vitalStep < VITAL_STEPS) this.state.vitalStep += 1;
            else this.go('questionnaire');
        },

        prevVital() {
            if (this.state.vitalStep > 1) this.state.vitalStep -= 1;
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

        /** ≥ 30.0 → is_bmi_flagged, from config (BR-13, single source of truth). */
        bmiFlagged(bmi) {
            return bmi !== null && bmi >= (this.config.bmiObese ?? 30);
        },

        /**
         * Colour-coded BMI status badge (UI only): Underweight + Obese = red,
         * Normal = green, Overweight = orange. The ⚑/is_bmi_flagged semantics
         * stay obese-only (BR-13) — this is purely the visual cue.
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

        // ── 9-system questionnaire (FR-KSK-10) ───────────────────────────────
        /** Record a Yes (true) / No (false) answer for one system card. */
        setSystem(key, value) {
            this.state.questionnaire.systems[key] = value;
        },

        /** A system's answer: true (Yes) | false (No) | undefined (unanswered). */
        systemAnswer(key) {
            return this.state.questionnaire.systems[key];
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

        /** How many of the 10 questions are answered (footer "{N} of 10"). */
        answeredCount() {
            const answered = SYSTEMS.filter(
                (s) => this.state.questionnaire.systems[s.key] !== undefined,
            ).length;
            return answered + (this.pregnancyAnswered() ? 1 : 0);
        },

        /** All 10 answered → Review & Submit unlocks (FR-KSK-10). */
        questionnaireComplete() {
            return this.answeredCount() === QUESTION_COUNT;
        },

        /** Advance to Review only once every question is answered. */
        goReview() {
            if (this.questionnaireComplete()) this.go('review');
        },

        // ── Submit to clinic (FR-KSK-11 → stub) ──────────────────────────────
        /**
         * Assemble the full kiosk session for submission. Vitals are flattened
         * to the columns the server will persist; screening maps each system to
         * its boolean column (null if somehow unanswered) plus pregnancy/LMP.
         * The AUTHORITATIVE flag booleans are computed server-side (§7.4) — the
         * review screen's orange ⚑ are display-time hints only.
         */
        buildSubmission() {
            const q = this.state.questionnaire;
            const screening = {};
            for (const s of SYSTEMS) screening[s.key] = q.systems[s.key] ?? null;
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
                    isPregnant: q.isPregnant,
                    lastMenstrualPeriod: q.lmp,
                },
            };
        },

        /**
         * POST the session to the submit endpoint (currently a stub; the full
         * transactional write is FR-KSK-12, a later week). On success the kiosk
         * advances to the Complete screen (FR-KSK-13).
         */
        async submitToClinic() {
            if (this.state.submit.status === 'sending') return;
            this.state.submit = { status: 'sending', error: '' };
            try {
                const { response, data } = await this.kioskPost(this.$refs.root.dataset.submitUrl, this.buildSubmission());
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
                this.state.submit = {
                    status: 'error',
                    error: 'Network problem submitting. Please try again.',
                };
            }
        },
    };
}
