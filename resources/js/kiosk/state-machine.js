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
    'consent',
    'vitals',
    'questionnaire',
    'review',
    'complete',
];

export const VITAL_STEPS = 4;

// How long the "scanning" animation runs before a sensor reading settles to
// "captured". Long enough to read the animation, short enough to feel snappy.
const SCAN_MS = 1200;

// Max gap between taps of the disguised manual-entry gesture (see logoTap).
const GESTURE_WINDOW_MS = 1500;

/**
 * Per-step metadata for the vitals sequence (FR-KSK-05). One entry drives one
 * step of the reusable 3-phase card, so adding Temperature/BP later is just two
 * more entries here — no new Blade. `range` names the bounds key in
 * config/healthpass.php (FR-KSK-08, single source of truth); `sensorKey` is the
 * letter the Web Serial reading line uses (§11.2); `sample` feeds the dev-only
 * "Simulate reading" button.
 *
 * Steps 3–4 are intentionally omitted for now — this slice builds 1–2.
 */
export const VITALS = {
    1: {
        key: 'height',
        label: 'Height',
        unit: 'cm',
        sensorKey: 'H',
        range: 'height_cm',
        instruction: 'Stand straight under the stadiometer, heels together, looking forward.',
        sample: 163,
    },
    2: {
        key: 'weight',
        label: 'Weight',
        unit: 'kg',
        sensorKey: 'W',
        range: 'weight_kg',
        instruction: 'Step onto the scale and stand still, arms relaxed at your sides.',
        sample: 58,
    },
};

/** A fresh, uncaptured vital record. phase: ready → scanning → captured. */
function vital() {
    return {
        value: null,
        phase: 'ready',
        method: null, // 'sensor' | 'manual' — provenance for vital_signs.entry_method
        notice: '', // non-blocking nudge (e.g. sensor degraded to manual)
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

        // Each vital is its own 3-phase record (FR-KSK-05). Step 4 (BP) reuses
        // systolic/diastolic/heartRate; only height + weight are wired so far.
        vitals: {
            height: vital(),
            weight: vital(),
            temperature: vital(),
            systolic: vital(),
            diastolic: vital(),
            heartRate: vital(),
        },

        // Numeric on-screen pad for manual entry (FR-KSK-06). `target` is the
        // vital key being edited; one pad serves every step.
        pad: { open: false, target: null, value: '', error: '' },

        questionnaire: {},
    };
}

export function kioskMachine() {
    return {
        state: freshState(),

        // Server-injected plausibility ranges + BMI threshold (config/healthpass.php).
        // Read once so client validation uses the SAME numbers as the server (FR-KSK-08).
        config: {},

        // Tap bookkeeping for the disguised manual-entry gesture (see logoTap).
        _logoTaps: 0,
        _lastLogoTap: 0,

        init() {
            this.config = JSON.parse(this.$refs.root.dataset.config || '{}');
            this.focusWedge();
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
            this.state = freshState();
            this.focusWedge();
        },

        // ── QR keyboard-wedge (FR-KSK-01) ────────────────────────────────────
        // The hidden input must stay focused so a USB scanner can type the
        // token + Enter at any time. We read the field's own value on Enter
        // rather than tracking keystrokes by hand.
        focusWedge() {
            this.$nextTick(() => this.$refs.wedge?.focus());
        },

        onWedgeEnter(event) {
            const token = event.target.value.trim();
            event.target.value = '';
            if (token) this.submitToken(token);
        },

        async submitToken(token) {
            this.state.scan = { status: 'sending', error: '' };
            try {
                const response = await fetch(this.$refs.root.dataset.scanUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.$refs.root.dataset.csrf,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ token }),
                });
                const data = await response.json();
                // Valid token → straight to Identity Confirm (FR-KSK-03).
                if (response.ok && data.ok && data.identity) {
                    this.state.scan = { status: 'idle', error: '' };
                    this.arriveAtIdentity(data.identity);
                    return;
                }
                // Invalid → inline error, stay on Welcome, scanner stays hot.
                this.state.scan = {
                    status: 'error',
                    error: data.message ?? 'Could not read that ID. Please try again.',
                };
                this.focusWedge();
            } catch {
                this.state.scan = {
                    status: 'error',
                    error: 'Network problem reading the ID. Please try again.',
                };
                this.focusWedge();
            }
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
                this.submitLogin();
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
                const response = await fetch(this.$refs.root.dataset.loginUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.$refs.root.dataset.csrf,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        email: login.email.trim(),
                        password: login.password,
                    }),
                });
                const data = await response.json();
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

        // ── Identity Confirm (FR-KSK-03) ─────────────────────────────────────
        /** Store the resolved student and show Identity Confirm. */
        arriveAtIdentity(identity) {
            this.state.identity = identity;
            this.state.screen = 'identity';
        },

        /** "That's me" → privacy consent. */
        confirmIdentity() {
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

        // ── Vitals sequence (FR-KSK-05/06/08/09) ─────────────────────────────
        // One reusable 3-phase step (ready → scanning → captured). Steps 1–2
        // (Height, Weight+BMI) are 1:1 with a single vital, so "the current
        // step's vital" is unambiguous; step 4 (BP) will group three values.

        /** Metadata for a step (1–4), or null for not-yet-built steps. */
        vitalMeta(step) {
            return VITALS[step] ?? null;
        },

        /** The vital record for the current step. */
        currentVital() {
            const meta = this.vitalMeta(this.state.vitalStep);
            return meta ? this.state.vitals[meta.key] : null;
        },

        /** {min,max} bounds for a step, from injected config (FR-KSK-08). */
        rangeFor(meta) {
            return this.config.validation?.[meta.range] ?? { min: 0, max: 0 };
        },

        inRange(meta, value) {
            const r = this.rangeFor(meta);
            return !Number.isNaN(value) && value >= r.min && value <= r.max;
        },

        // ── Sensor path (FR-KSK-07 stub — wired now, hardware in W5) ──────────
        /**
         * THE entry point for sensor readings. The Web Serial module (W5) parses
         * the combined reading line and calls this with an object keyed by sensor
         * letters — e.g. receiveReading({ H: 163 }) or { H, W, T, S, D, R }. The
         * dev "Simulate reading" button calls this SAME function, so manual
         * testing exercises the exact production path.
         */
        receiveReading(reading) {
            for (const [sensorKey, raw] of Object.entries(reading)) {
                const step = Object.keys(VITALS).find(
                    (s) => VITALS[s].sensorKey === sensorKey,
                );
                if (step) this.captureFromSensor(VITALS[step], Number(raw));
            }
        },

        /** Run a reading through scanning → captured, degrading if implausible. */
        captureFromSensor(meta, value) {
            const v = this.state.vitals[meta.key];
            v.phase = 'scanning';
            v.notice = '';
            setTimeout(() => {
                // Graceful degrade (FR-KSK-07): an out-of-range/garbled reading is
                // never a dead end — fall back to ready with a nudge to manual.
                if (!this.inRange(meta, value)) {
                    const r = this.rangeFor(meta);
                    v.phase = 'ready';
                    v.notice = `Sensor reading looked off (expected ${r.min}–${r.max} ${meta.unit}). Try again or enter it manually.`;
                    return;
                }
                v.value = value;
                v.method = 'sensor';
                v.phase = 'captured';
                v.notice = '';
            }, SCAN_MS);
        },

        /** Dev-only: inject a plausible reading for the current step. */
        simulateReading() {
            const meta = this.vitalMeta(this.state.vitalStep);
            if (meta) this.receiveReading({ [meta.sensorKey]: meta.sample });
        },

        // ── Manual entry pad (FR-KSK-06 — first-class on every step) ──────────
        /**
         * Disguised trigger for manual entry. The visible "Enter manually" button
         * was removed so a student can't simply fake a reading; instead an operator
         * (nurse/staff) triple-taps the corner HealthPass logo to reveal the numeric
         * pad for whatever vital is on screen. Taps must land within GESTURE_WINDOW_MS
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
            this.state.pad = { open: true, target: meta.key, value: '', error: '' };
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
            const meta = this.vitalMeta(this.state.vitalStep);
            const value = Number(pad.value);
            if (pad.value === '' || Number.isNaN(value)) {
                pad.error = 'Enter a number.';
                return;
            }
            // Same ranges as the sensor path; out-of-range prompts re-entry (FR-KSK-08).
            if (!this.inRange(meta, value)) {
                const r = this.rangeFor(meta);
                pad.error = `Enter a value between ${r.min} and ${r.max} ${meta.unit}.`;
                return;
            }
            const v = this.state.vitals[meta.key];
            v.value = value;
            v.method = 'manual';
            v.phase = 'captured';
            v.notice = '';
            pad.open = false;
        },

        // ── Step navigation + retake ─────────────────────────────────────────
        /** Discard the current step's reading and return it to "ready". */
        retryVital() {
            const meta = this.vitalMeta(this.state.vitalStep);
            if (meta) this.state.vitals[meta.key] = vital();
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
            const h = this.state.vitals.height.value;
            const w = this.state.vitals.weight.value;
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

        // ── Provenance roll-up for vital_signs.entry_method (FR-KSK-06) ───────
        // Consumed at final submit (FR-KSK-12). 'sensor' / 'manual' / 'mixed'.
        entryMethod() {
            const methods = Object.values(this.state.vitals)
                .map((v) => v.method)
                .filter(Boolean);
            if (methods.length === 0) return null;
            if (methods.every((m) => m === 'sensor')) return 'sensor';
            if (methods.every((m) => m === 'manual')) return 'manual';
            return 'mixed';
        },
    };
}
