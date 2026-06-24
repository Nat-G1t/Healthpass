<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Self-service clinic kiosk (Module KSK, FR-KSK-01..16).
 *
 * The kiosk is a single Blade page rendered full-screen by Chromium on the
 * Raspberry Pi. It is intentionally PUBLIC (no Laravel auth): the student's
 * identity is established inside the kiosk flow via QR scan / email login,
 * not via a logged-in session.
 */
final class KioskController extends Controller
{
    /**
     * Render the kiosk shell (letterbox + Alpine state machine).
     */
    public function index(): View
    {
        return view('kiosk.index');
    }

    /**
     * Stub endpoint for the QR keyboard-wedge (FR-KSK-01).
     *
     * The USB scanner types the `qr_token` + Enter into the page's hidden
     * input; the page POSTs it here. Identity lookup is not implemented yet —
     * this foundation just acknowledges the token so the Welcome screen can
     * exercise its scanning / inline-error states.
     */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:2048'],
        ]);

        return response()->json([
            'ok' => false,
            'message' => 'ID lookup is not wired up yet (kiosk foundation).',
            'token_length' => strlen($validated['token']),
        ]);
    }
}
