/**
 * Bluetooth blood-pressure reading fetch (D-58, FR-KSK-07a).
 *
 * The A&D UA-651BLE cuff is read by a daemon on the Pi, which POSTs each
 * reading to the server. The kiosk page can't hear Bluetooth itself, so while
 * the blood-pressure step waits it asks the server for the latest unclaimed
 * reading every BP_POLL_MS. Like serial.js this module only does the I/O; the
 * state machine (state-machine.js) decides what a reading means.
 */

// One poll a second (D-59; was 2 s): a reading shows up moments after the
// daemon sends it. The kiosk only polls after the student taps Start, and at
// most 60 requests a minute stays under the latest route's throttle of 90.
export const BP_POLL_MS = 1000;

/**
 * GET the latest reading. Resolves to { reachable, reading } and NEVER throws:
 *   reachable: false → the request failed (network, 403, 500…) — nothing is known;
 *   reachable: true  → `reading` is { systolic, diastolic, pulse, suspect,
 *                      received_at }, or null when no reading is waiting.
 * The two cases differ on purpose: "no reading" can set the kiosk's baseline,
 * a failed request must not. A failure is silent — manual entry keeps working
 * with nothing on screen (FR-KSK-07).
 */
export async function fetchLatestBpReading(url, fetchImpl = globalThis.fetch) {
    try {
        const response = await fetchImpl(url, { headers: { Accept: 'application/json' } });
        if (!response.ok) return { reachable: false, reading: null };
        const data = await response.json();
        return { reachable: true, reading: data?.reading ?? null };
    } catch {
        return { reachable: false, reading: null };
    }
}
