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
                // Foundation: lookup is a stub, so every scan returns an inline
                // error and refocuses — mirroring the FR-KSK-01 invalid-token AC.
                this.state.scan = {
                    status: 'error',
                    error: data.message ?? 'Could not read that ID. Please try again.',
                };
            } catch {
                this.state.scan = {
                    status: 'error',
                    error: 'Network problem reading the ID. Please try again.',
                };
            } finally {
                this.focusWedge();
            }
        },
    };
}
