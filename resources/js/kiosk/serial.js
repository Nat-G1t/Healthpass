/**
 * Kiosk Web Serial reader (Module KSK / FR-KSK-07, FR-HW-05).
 *
 * Reads the microcontroller's combined vitals line over USB serial and hands
 * PARSED readings to the kiosk state machine. It is deliberately framework-
 * agnostic — there is no Alpine in here — so it is easy to reason about and to
 * test: you give it a couple of callbacks and it does the messy part (open the
 * port, buffer bytes into whole lines, parse each line per §11.2, watch for a
 * silent sensor). The state machine (state-machine.js) owns the callbacks and
 * decides what to do with a reading; the parse never touches the UI.
 *
 * Web Serial 101 (Chromium-only, secure context — http://localhost on the Pi,
 * decision D-9): `navigator.serial.requestPort()` shows a one-time port picker
 * and REQUIRES a user gesture (a tap/click) to open. Once the user grants a
 * port, the browser REMEMBERS it for this origin, so `navigator.serial
 * .getPorts()` can silently reopen it on later loads with NO gesture — that is
 * what lets the unattended kiosk reconnect by itself after a reboot (FR-HW-05).
 */

// §11.2 wire keys → the internal sensor letters VITALS uses (state-machine.js).
// H / W / T pass straight through; HR is the heart-rate letter R. BP is NOT in
// here because it carries two numbers ("145/92" → systolic S + diastolic D) and
// is special-cased in the parser below.
const WIRE_TO_SENSOR = { H: 'H', W: 'W', T: 'T', HR: 'R' };

// Defaults — every one is overridable by the caller (config/healthpass.php →
// injected into the page). 9600 baud matches the agreed MCU firmware; the 10 s
// watchdog is the "the sensor has gone quiet" threshold that triggers the
// non-blocking degrade notice (FR-KSK-07).
const DEFAULT_BAUD = 9600;
const DEFAULT_READ_TIMEOUT_MS = 10000;

/** Parse a raw field into a finite number, or NaN if it is empty/garbage. */
function toNumber(raw) {
    if (raw == null || String(raw).trim() === '') return NaN;
    const n = Number(raw);
    return Number.isFinite(n) ? n : NaN;
}

/**
 * Parse ONE serial line in the §11.2 contract into internal sensor-letter keys.
 *
 *   wire in:  "H:163;W:64;T:37.9;BP:145/92;HR:78"
 *   returns:  { H: 163, W: 64, T: 37.9, S: 145, D: 92, R: 78 }
 *
 * Rules from the contract, all handled here so callers never have to:
 *   - Partial lines are valid — only the keys actually present are returned
 *     (e.g. "H:163" during the height step → { H: 163 }).
 *   - Unknown keys are ignored (forward compatibility — a future firmware can
 *     add "SPO2:98" and older kiosks keep working).
 *   - Any malformed token is skipped; a wholly malformed line yields {}. The
 *     caller treats {} as "nothing usable, keep reading" — this never crashes
 *     the step (§11.2: malformed lines are dropped silently with a retry).
 *
 * Pure function (no I/O, no side effects) so it can be unit-tested on its own.
 */
export function parseReadingLine(line) {
    const out = {};
    if (typeof line !== 'string') return out;

    for (const token of line.split(';')) {
        const idx = token.indexOf(':');
        if (idx < 1) continue; // no key, or no colon → skip this token
        const key = token.slice(0, idx).trim().toUpperCase();
        const raw = token.slice(idx + 1).trim();
        if (raw === '') continue;

        if (key === 'BP') {
            // Blood pressure carries two numbers: "systolic/diastolic". Both must
            // be valid or we drop the whole pair (a half-read BP is meaningless).
            const [sys, dia] = raw.split('/');
            const s = toNumber(sys);
            const d = toNumber(dia);
            if (!Number.isNaN(s) && !Number.isNaN(d)) {
                out.S = s;
                out.D = d;
            }
            continue;
        }

        const sensorKey = WIRE_TO_SENSOR[key];
        if (!sensorKey) continue; // unknown key — ignore (forward compatibility)
        const n = toNumber(raw);
        if (!Number.isNaN(n)) out[sensorKey] = n;
    }
    return out;
}

/**
 * Split buffered serial text into completed lines + the unfinished remainder.
 *
 * Serial data arrives in arbitrary chunks — a line can be torn anywhere, so a
 * half line ("H:16" of "H:163") must NEVER be parsed early: it would read as a
 * valid-but-wrong number. Only text up to a newline is a line; the rest stays
 * buffered until the next chunk completes it. Tolerates CRLF. Pure function
 * (no I/O) so the reassembly rule is unit-testable on its own.
 */
export function drainLines(buffer) {
    const lines = [];
    let rest = buffer;
    let nl;
    while ((nl = rest.indexOf('\n')) >= 0) {
        lines.push(rest.slice(0, nl).replace(/\r$/, ''));
        rest = rest.slice(nl + 1);
    }
    return { lines, rest };
}

/**
 * Build a serial reader bound to a set of callbacks.
 *
 *   onReading(reading)        — a non-empty parsed line { H: 163, … }
 *   onStatus(status, detail)  — a lifecycle change (see STATUSES below)
 *
 * The returned object exposes the verbs the state machine drives:
 *   isSupported() · connect() · autoConnect() · disconnect()
 *
 * Statuses emitted (all non-fatal — manual entry is ALWAYS available, FR-KSK-07):
 *   'unsupported'  — this browser has no Web Serial (not Chromium / insecure)
 *   'connecting'   — opening the port
 *   'connected'    — streaming
 *   'cancelled'    — user dismissed the port picker
 *   'timeout'      — connected but no reading for readTimeoutMs (sensor quiet)
 *   'disconnected' — cable/MCU dropped; we will auto-reopen when it returns
 *   'error'        — open/read failed
 *   'closed'       — we closed it on purpose
 */
export function createSerialReader({
    onReading = () => {},
    onStatus = () => {},
    baudRate = DEFAULT_BAUD,
    readTimeoutMs = DEFAULT_READ_TIMEOUT_MS,
} = {}) {
    let port = null;          // the granted SerialPort (kept across reconnects)
    let reader = null;        // active ReadableStream reader, when streaming
    let keepReading = false;  // read-loop run flag; false asks the loop to stop
    let connected = false;    // guards against double-open
    let watchdog = null;      // "sensor went quiet" timer
    let buffer = '';          // bytes decoded but not yet split on a newline

    const isSupported = () =>
        typeof navigator !== 'undefined' && 'serial' in navigator;

    // ── Watchdog: fire 'timeout' if no good line arrives within the window ──
    // Re-armed on every valid reading, so a healthy fixed-cadence stream never
    // trips it; only a genuine stall does.
    function armWatchdog() {
        clearWatchdog();
        watchdog = setTimeout(() => onStatus('timeout'), readTimeoutMs);
    }
    function clearWatchdog() {
        if (watchdog) clearTimeout(watchdog);
        watchdog = null;
    }

    // Accumulate decoded text and emit one reading per completed line.
    function handleChunk(text) {
        const { lines, rest } = drainLines(buffer + text);
        buffer = rest;
        for (const line of lines) {
            const reading = parseReadingLine(line);
            if (Object.keys(reading).length === 0) continue; // malformed → drop
            armWatchdog(); // a good line proves the sensor is alive
            onReading(reading);
        }
    }

    // The read loop: pull bytes until the port stops being readable (which is
    // what a disconnect looks like from here) or we ask it to stop.
    async function readLoop() {
        const decoder = new TextDecoder();
        while (port && port.readable && keepReading) {
            reader = port.readable.getReader();
            try {
                for (;;) {
                    const { value, done } = await reader.read();
                    if (done) break; // reader was cancelled / stream closed
                    if (value) handleChunk(decoder.decode(value, { stream: true }));
                }
            } catch {
                // A read error is almost always the device being yanked; the
                // 'disconnect' event below owns the UX, so we just fall through.
            } finally {
                try {
                    reader.releaseLock();
                } catch {
                    /* already released */
                }
                reader = null;
            }
        }
        connected = false;
        clearWatchdog();
    }

    // Open a specific (already-granted) port and start streaming.
    async function open(selectedPort) {
        if (connected) return true; // idempotent — ignore a second open
        port = selectedPort;
        try {
            onStatus('connecting');
            await port.open({ baudRate });
        } catch (err) {
            onStatus('error', err);
            return false;
        }
        connected = true;
        keepReading = true;
        buffer = '';
        onStatus('connected');
        armWatchdog();
        readLoop();
        return true;
    }

    // ── Public verbs ────────────────────────────────────────────────────────

    // First-time connect: shows the port picker (needs the caller's click as the
    // user gesture). A dismissed picker is NOT an error — just no sensors.
    async function connect() {
        if (!isSupported()) {
            onStatus('unsupported');
            return false;
        }
        try {
            const selected = await navigator.serial.requestPort();
            return await open(selected);
        } catch (err) {
            // NotFoundError = the user closed the picker without choosing.
            onStatus(err?.name === 'NotFoundError' ? 'cancelled' : 'error', err);
            return false;
        }
    }

    // Silent reconnect to a port granted on a previous visit — no gesture, so
    // the unattended kiosk comes back by itself after a reboot (FR-HW-05).
    async function autoConnect() {
        if (!isSupported()) {
            onStatus('unsupported');
            return false;
        }
        try {
            const [granted] = await navigator.serial.getPorts();
            if (!granted) return false; // nothing granted yet → wait for a tap
            return await open(granted);
        } catch (err) {
            onStatus('error', err);
            return false;
        }
    }

    async function disconnect() {
        keepReading = false;
        clearWatchdog();
        try {
            await reader?.cancel();
        } catch {
            /* nothing to cancel */
        }
        try {
            await port?.close();
        } catch {
            /* already closed */
        }
        connected = false;
        port = null;
        onStatus('closed');
    }

    // Physical plug events (FR-HW-05): jiggling the cable or resetting the MCU
    // must recover WITHOUT a page reload. On disconnect we mark the UI degraded;
    // on reconnect of the SAME granted port we silently reopen it.
    if (isSupported()) {
        navigator.serial.addEventListener('disconnect', (event) => {
            if (event.target !== port) return; // not our device
            keepReading = false;
            connected = false;
            clearWatchdog();
            onStatus('disconnected');
        });
        navigator.serial.addEventListener('connect', (event) => {
            if (event.target !== port) return; // only auto-reopen OUR port
            open(port);
        });
    }

    return { isSupported, connect, autoConnect, disconnect };
}
