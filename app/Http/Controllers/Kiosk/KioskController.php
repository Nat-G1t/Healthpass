<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Self-service clinic kiosk (Module KSK, FR-KSK-01..16).
 *
 * The kiosk is a single Blade page rendered full-screen by Chromium on the
 * Raspberry Pi. It is intentionally PUBLIC (no Laravel auth): the student's
 * identity is established inside the kiosk flow via QR scan / email login,
 * not via a logged-in session. The two endpoints below each return the SAME
 * identity payload so the Alpine front-end can route to Identity Confirm
 * (FR-KSK-03) the same way regardless of how the student arrived.
 */
final class KioskController extends Controller
{
    /**
     * Render the kiosk shell (responsive-fill panel + Alpine state machine).
     */
    public function index(): View
    {
        return view('kiosk.index');
    }

    /**
     * QR keyboard-wedge lookup (FR-KSK-01 → FR-KSK-03).
     *
     * The USB scanner types the `qr_token` + Enter into the page's hidden
     * input; the page POSTs it here. A valid token resolves to a student and
     * returns the identity payload (the page then shows Identity Confirm). An
     * unknown token returns a generic 422 so the Welcome screen can show its
     * inline "couldn't read that ID" error and refocus the scanner.
     */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:2048'],
        ]);

        // Direct match on the stored qr_token. eager-load college for the payload.
        $profile = StudentProfile::with('college')
            ->where('qr_token', $validated['token'])
            ->first();

        if ($profile === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Could not read that ID. Please try again.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'identity' => $this->identityPayload($profile, 'qr'),
        ]);
    }

    /**
     * Email + password login (FR-KSK-02 → FR-KSK-03).
     *
     * Kiosk-scoped, STUDENTS ONLY: staff accounts (college_admin / nurse /
     * director) are rejected here even with correct credentials — the kiosk
     * is a student vitals terminal. To avoid leaking which emails exist or
     * which are staff, every failure (unknown email, wrong password, wrong
     * role, inactive) returns the same generic message. We never start a
     * Laravel session — identity lives only in the kiosk's per-session state.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:191'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $invalid = response()->json([
            'ok' => false,
            'message' => 'Those credentials don\'t match a student account.',
        ], 422);

        $user = User::with('studentProfile.college')
            ->where('email', $validated['email'])
            ->first();

        // Always run Hash::check (real hash when the user exists) so the
        // response time does not reveal whether the email is registered.
        $hash = $user?->password ?? Hash::make('kiosk-no-such-user');
        $passwordOk = Hash::check($validated['password'], $hash);

        if ($user === null || ! $passwordOk) {
            return $invalid;
        }

        // Student-only gate: reject staff, inactive accounts, or students
        // whose profile row is missing (can't confirm identity without it).
        if ($user->role !== 'student' || $user->status !== 'active' || $user->studentProfile === null) {
            return $invalid;
        }

        return response()->json([
            'ok' => true,
            'identity' => $this->identityPayload($user->studentProfile, 'email'),
        ]);
    }

    /**
     * Shape the student identity sent to the front-end (FR-KSK-03).
     *
     * Only display-safe fields leave the server — the kiosk never needs the
     * full profile. `studentUserId` + `loginMethod` are carried so a later
     * week can stamp the clinic_visits row (login_method) at submit.
     */
    private function identityPayload(StudentProfile $profile, string $loginMethod): array
    {
        $first = trim($profile->first_name);
        $last = trim($profile->last_name);
        $initials = mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));

        return [
            'studentUserId' => $profile->user_id,
            'loginMethod' => $loginMethod,
            'firstName' => $first,
            'fullName' => trim("{$first} {$last}"),
            'initials' => $initials,
            'studentNumber' => $profile->student_number,
            'college' => $profile->college?->name,
            'course' => $profile->course,
            'yearLevel' => $profile->year_level,
        ];
    }
}
