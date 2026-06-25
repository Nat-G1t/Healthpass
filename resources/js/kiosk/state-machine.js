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
        vitals: {
            height: null,
            weight: null,
            temperature: null,
            systolic: null,
            diastolic: null,
            heartRate: null,
        },
        questionnaire: {},
    };
}

export function kioskMachine() {
    return {
        state: freshState(),

        init() {
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
    };
}
