<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\StoreBpReadingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Bluetooth blood-pressure readings for the kiosk (D-58, FR-KSK-07a).
 *
 * The A&D UA-651BLE cuff talks Bluetooth, not USB serial, so its readings take
 * a different road from the ESP32's (FR-KSK-07):
 *
 *   Pi daemon  → POST /api/kiosk/bp-reading     → store()  reading into the cache
 *   kiosk page → GET  /kiosk/bp-reading/latest  → latest() polled every 2 s
 *   kiosk page → POST /kiosk/bp-reading/claim   → claim()  cache → THIS session
 *   kiosk page → POST /kiosk/submit             → KioskController@submit reads the session
 *
 * A reading in flight lives in the CACHE, not a table: it is transient and
 * expires by itself (healthpass.kiosk.bp_reading_ttl) if nobody claims it. It
 * reaches the database only through submit, as vital_signs.bp_device_reading.
 *
 * One slot for one cuff: the deployment has a single kiosk and a single
 * monitor, so readings are not keyed per device. A second cuff would need that.
 */
final class BpReadingController extends Controller
{
    /** Cache key of the reading waiting to be claimed. */
    public const CACHE_KEY = 'kiosk-bp-reading';

    /** Session key of the reading this kiosk session claimed. */
    public const SESSION_KEY = 'kiosk.bp_reading';

    /**
     * The daemon posts a reading. StoreBpReadingRequest has already checked the
     * X-Kiosk-Key header (403) and the numbers (422).
     */
    public function store(StoreBpReadingRequest $request): JsonResponse
    {
        $data = $request->validated();

        $reading = [
            'systolic' => (int) $data['systolic'],
            'diastolic' => (int) $data['diastolic'],
            'pulse' => isset($data['pulse']) ? (int) $data['pulse'] : null,
            'mean_arterial' => $data['mean_arterial'] ?? null,
            'taken_at' => $data['taken_at'] ?? null,
            'device_model' => $data['device_model'] ?? null,
            'raw' => $data['raw'] ?? null,
            // Only the known flags, as real booleans; a key a newer daemon adds is dropped.
            'flags' => array_map('boolval', Arr::only($data['flags'] ?? [], StoreBpReadingRequest::FLAGS)),
            'suspect' => (bool) $data['suspect'],
            // Server clock, to the microsecond: the kiosk tells a new reading from
            // one it has already seen by this value, so two readings can't share it.
            'received_at' => now()->format('Y-m-d\TH:i:s.uP'),
        ];

        Cache::put(self::CACHE_KEY, $reading, (int) config('healthpass.kiosk.bp_reading_ttl'));

        return response()->json(['ok' => true, 'received_at' => $reading['received_at']], 201);
    }

    /**
     * The newest unclaimed reading, or null. Only what the kiosk needs leaves
     * the server: the numbers, `suspect` (to suggest a retake), the monitor's
     * five status `flags` (shown as "Monitor checks", display only — D-82) and
     * `received_at`. The raw bytes, device model, mean arterial pressure and
     * device clock stay server-side.
     */
    public function latest(): JsonResponse
    {
        $reading = Cache::get(self::CACHE_KEY);

        return response()->json([
            'reading' => $reading === null ? null : $this->forScreen($reading),
        ]);
    }

    /**
     * Bind the reading the kiosk just saw to this kiosk session — the same way
     * scan/login bind the student — so submit takes the device's record from
     * the session, never from the request body. Removing it from the cache
     * means nobody else (a second tab, the next student) can claim it again.
     *
     * 409 when that reading is gone or a newer one replaced it; the kiosk then
     * simply ignores it.
     */
    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'received_at' => ['required', 'string', 'max:64'],
        ]);

        // A reading belongs to a visit, so a student must be signed in at the kiosk.
        if (! $request->session()->has('kiosk.student_id')) {
            return response()->json(['ok' => false, 'message' => 'Session expired — please start again.'], 422);
        }

        $reading = Cache::get(self::CACHE_KEY);

        if ($reading === null || $reading['received_at'] !== $validated['received_at']) {
            return response()->json(['ok' => false, 'message' => 'That reading is no longer available.'], 409);
        }

        Cache::forget(self::CACHE_KEY);
        $request->session()->put(self::SESSION_KEY, $reading);

        return response()->json(['ok' => true, 'reading' => $this->forScreen($reading)]);
    }

    /**
     * The part of a reading the kiosk screen may see. `flags` is for display
     * only (D-82): submit still takes the device record from the session copy
     * claim() stored, never from anything the kiosk sends back.
     */
    private function forScreen(array $reading): array
    {
        return Arr::only($reading, ['systolic', 'diastolic', 'pulse', 'flags', 'suspect', 'received_at']);
    }
}
