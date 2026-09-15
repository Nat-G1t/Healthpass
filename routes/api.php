<?php

use App\Http\Controllers\Kiosk\BpReadingController;
use Illuminate\Support\Facades\Route;

// ── Device API (D-58) ─────────────────────────────────────────────────────────
// bootstrap/app.php registers this file with the `api` middleware group and an
// /api URL prefix. Unlike routes/web.php there is no session, no cookies and no
// CSRF check — right for a device that has none of those — so every route here
// must check its own credentials.
//
// The Bluetooth BP daemon on the Pi (outside this repo) POSTs each reading here
// with the shared secret in X-Kiosk-Key, checked in StoreBpReadingRequest. No IP
// allowlist on purpose: on the hosted deploy the daemon arrives over the internet.
Route::post('/kiosk/bp-reading', [BpReadingController::class, 'store'])
    ->middleware('throttle:60,1,kiosk-bp-store')
    ->name('api.kiosk.bp-reading.store');
