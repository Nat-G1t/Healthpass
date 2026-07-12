<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use App\Models\KioskDevice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * FR-NRS-06 "Enable Kiosk Mode" — nurse-only management of enrolled kiosk
 * DEVICES (D-27). A nurse enrolls the browser/terminal they are on as a trusted
 * device; KioskAccess then lets that browser reach the public /kiosk flow
 * without anyone logging in at the terminal. Devices can be listed and revoked.
 *
 * SECURITY: the DEVICE is trusted, never client-supplied identity — the existing
 * kiosk rule (student identity binds server-side at scan/login) is untouched.
 * We store only a hash of each device token; the plaintext is shown to the nurse
 * exactly once (below) and never persisted.
 */
class KioskDeviceController extends Controller
{
    /** List enrolled devices (active first), plus the one-time token if just created. */
    public function index(): View
    {
        $devices = KioskDevice::with('creator')
            ->orderByRaw('revoked_at is null desc') // active devices first…
            ->latest()                              // …then newest first
            ->get();

        return view('nurse.kiosk-devices', compact('devices'));
    }

    /**
     * Enroll THIS browser as a kiosk device (FR-NRS-06).
     *
     * Generates a high-entropy token, stores only its hash, and drops the
     * plaintext into a long-lived HttpOnly cookie on this browser — so if the
     * nurse is standing at the actual kiosk terminal it is authorized instantly.
     * The plaintext is ALSO flashed once for the copy-and-paste provisioning URL
     * (the Pi's incognito launcher can't keep a cookie — it uses that URL).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        // Str::random() is backed by random_bytes() (cryptographically secure) and
        // is URL-safe, so it drops straight into the ?device_token=… link.
        $token = Str::random(64);

        KioskDevice::create([
            'name' => $validated['name'],
            'token_hash' => KioskDevice::hashToken($token),
            'created_by' => $request->user()->id,
        ]);

        $cookie = cookie(
            KioskDevice::COOKIE,
            $token,
            KioskDevice::COOKIE_LIFETIME_MINUTES,
            '/',
            null,
            $request->secure(),
            true,      // HttpOnly
            false,
            'lax',
        );

        // Flash the plaintext ONCE so the view can show it + the provisioning URL;
        // it is gone on the next request (never stored anywhere else).
        return redirect()->route('nurse.kiosk-devices')
            ->with('new_device_token', $token)
            ->with('status', 'This browser is now enrolled as a kiosk device.')
            ->withCookie($cookie);
    }

    /**
     * Revoke a device (idempotent). From the next request its token no longer
     * satisfies KioskAccess, so that browser falls back to the branded 403.
     */
    public function destroy(KioskDevice $device): RedirectResponse
    {
        if (! $device->isRevoked()) {
            $device->update(['revoked_at' => now()]);
        }

        return redirect()->route('nurse.kiosk-devices')
            ->with('status', "“{$device->name}” has been revoked.");
    }
}
