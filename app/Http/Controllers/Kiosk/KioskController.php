<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kiosk;

use App\Actions\Kiosk\SubmitKioskVisit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\KioskSubmitRequest;
use App\Models\Appointment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
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
     * Fresh CSRF token for the long-lived kiosk page.
     *
     * The kiosk page bakes a CSRF token at render time. If it stays open past the
     * life of its session (server restart, session expiry, or a DB reset), every
     * POST it makes then fails the CSRF check (419). Rather than dead-end, the
     * front-end calls this GET on a 419: it (re)establishes a session via the web
     * middleware and returns the CURRENT token, which the page then uses to retry.
     */
    public function token(): JsonResponse
    {
        return response()->json(['token' => csrf_token()]);
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

        $token = $this->normalizeQrToken($validated['token']);

        // Direct match on the stored qr_token. eager-load college for the payload.
        $profile = StudentProfile::with('college')
            ->where('qr_token', $token)
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
     * Normalize a scanned string into a lookup token (FR-KSK-01).
     *
     * Two QR shapes must both work. The physical student ID encodes a
     * MULTI-LINE payload whose token sits on an "IDNo:" line, e.g.
     *   Name: Juan Santos
     *   IDNo: 2021060001
     *   Course: BSCS
     * A keyboard-wedge scanner types that payload one Enter-terminated line at
     * a time, so each POST here is a single line; when we see the "IDNo:" line
     * we use its value. The simple backup QR encodes a single bare token with
     * no "IDNo:" line, so we fall back to the whole trimmed string. Doing this
     * server-side keeps it authoritative and testable — the client just sends
     * whatever the wedge typed.
     */
    private function normalizeQrToken(string $raw): string
    {
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (preg_match('/^\s*IDNo\s*:\s*(.+?)\s*$/i', $line, $matches) === 1) {
                return $matches[1];
            }
        }

        return trim($raw);
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
     * Final submit (FR-KSK-12): persist the whole kiosk session.
     *
     * The Form Request has already re-validated everything server-side (ranges,
     * completeness, consent, active-student gate). The Action then writes the
     * clinic_visits + vital_signs + screening_responses rows in one transaction
     * and computes the authoritative flag booleans (§7.4). We return the minted
     * HP-YYYY-#### reference so the Complete screen can show it (FR-KSK-13).
     */
    public function submit(KioskSubmitRequest $request, SubmitKioskVisit $action): JsonResponse
    {
        $visit = $action->handle($request->validated());

        return response()->json([
            'ok' => true,
            'reference' => $visit->reference_no,
        ]);
    }

    /**
     * Discreet staff exit (FR-KSK-16).
     *
     * The kiosk has no nav and the Pi runs Chromium in --kiosk mode, so a student
     * cannot leave /kiosk on their own. To END a shift a staff member taps the
     * hidden corner gesture (5 taps within ~3 s) which opens this prompt. We
     * authenticate the NURSE here — not just check a password — so the redirect
     * actually lands inside the (auth-gated) nurse queue instead of bouncing to
     * the login page. An email is required to know WHICH nurse to sign in.
     *
     * NURSE-ONLY by design: the kiosk lives at the clinic and hands off to the
     * nurse queue, so only an active nurse account may unlock it. As with the
     * student login, every failure returns the same generic message and runs a
     * constant-time hash check so the response never reveals whether an email
     * exists or which role it has.
     */
    public function exit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:191'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $invalid = response()->json([
            'ok' => false,
            'message' => 'Those credentials don\'t match a nurse account.',
        ], 422);

        $user = User::where('email', $validated['email'])->first();

        $hash = $user?->password ?? Hash::make('kiosk-no-such-user');
        $passwordOk = Hash::check($validated['password'], $hash);

        if ($user === null || ! $passwordOk) {
            return $invalid;
        }

        if ($user->role !== 'nurse' || $user->status !== 'active') {
            return $invalid;
        }

        // Establish a real authenticated session (the kiosk routes run in the web
        // group, so a session is available) and regenerate the id to prevent
        // session fixation from the long-lived public kiosk session.
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'ok' => true,
            'redirect' => route('nurse.queue'),
        ]);
    }

    /**
     * Shape the student identity sent to the front-end (FR-KSK-03).
     *
     * Only display-safe fields leave the server — the kiosk never needs the
     * full profile. `studentUserId` + `loginMethod` are carried so a later
     * week can stamp the clinic_visits row (login_method) at submit.
     *
     * `hasAppointmentToday` is computed HERE, server-side, so the Walk-in
     * Check (FR-KSK-03a) can never be spoofed by client state: the front-end
     * only uses this boolean to pick which screen to show. The authoritative
     * appointment_id linkage is still re-resolved at submit.
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
            'hasAppointmentToday' => $this->hasAppointmentToday($profile->user_id),
        ];
    }

    /**
     * Walk-in Check (FR-KSK-03a): does this student have ANY non-cancelled
     * appointment dated today — medical OR dental? When false (literally
     * nothing booked today), the kiosk shows the "No Scheduled Clearance
     * Today" screen; when true, it goes straight to Privacy Consent.
     *
     * Dental is still scheduling-only (Decision D-3): it suppresses this notice
     * but does NOT link at submit — a dental-only student proceeds through the
     * medical vitals flow and is recorded as a walk-in (appointment_id NULL).
     */
    private function hasAppointmentToday(int $studentId): bool
    {
        return Appointment::query()
            ->where('student_id', $studentId)
            ->whereDate('scheduled_date', Carbon::today())
            ->where('status', '!=', 'cancelled')
            ->exists();
    }
}
