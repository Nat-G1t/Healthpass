<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kiosk;

use App\Actions\Kiosk\RecheckKioskVisit;
use App\Actions\Kiosk\SubmitKioskVisit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\KioskRecheckRequest;
use App\Http\Requests\Kiosk\KioskSubmitRequest;
use App\Models\Appointment;
use App\Models\ClinicVisit;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * Everything a kiosk session binds server-side for ONE student: the
     * identity from scan/login, and a Bluetooth BP reading the kiosk claimed
     * (D-58). Always forgotten together.
     */
    private const SESSION_KEYS = [
        'kiosk.student_id',
        'kiosk.login_method',
        // D-72: the resting visit this session is re-checking. Bound by
        // scan/login, read by /kiosk/recheck — the client never names a visit.
        'kiosk.resting_visit_id',
        BpReadingController::SESSION_KEY,
    ];

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

        // Bind identity to the SERVER session (see submit() for why). The kiosk
        // stays public — this is NOT a Laravel login, just a kiosk-scoped key
        // the submit endpoint trusts instead of the (spoofable) request body.
        // Rotate the session id on this identity change so a session fixed before
        // the scan can't ride the newly-bound identity (regenerate keeps the CSRF
        // token, so the long-lived kiosk page's baked token stays valid).
        $request->session()->regenerate();
        // A new student starts clean: nothing an abandoned session left behind
        // (such as a claimed BP reading, D-58) may carry over to them.
        $request->session()->forget(self::SESSION_KEYS);
        $request->session()->put([
            'kiosk.student_id' => $profile->user_id,
            'kiosk.login_method' => 'qr',
        ]);

        return response()->json([
            'ok' => true,
            'identity' => $this->identityPayload($profile, 'qr', $this->bindRestingVisit($request, $profile->user_id)),
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

        // Bind identity to the SERVER session (see submit()). Same kiosk-scoped
        // key the QR path sets — the two flows converge here so submit trusts
        // the session, never the request body, regardless of how the student
        // signed in. Rotate the session id on this identity change (session-
        // fixation defense; regenerate keeps the CSRF token).
        $request->session()->regenerate();
        $request->session()->forget(self::SESSION_KEYS); // same clean start as scan()
        $request->session()->put([
            'kiosk.student_id' => $user->studentProfile->user_id,
            'kiosk.login_method' => 'email',
        ]);

        return response()->json([
            'ok' => true,
            'identity' => $this->identityPayload(
                $user->studentProfile,
                'email',
                $this->bindRestingVisit($request, $user->studentProfile->user_id),
            ),
        ]);
    }

    /**
     * Final submit (FR-KSK-12): persist the whole kiosk session.
     *
     * SECURITY: the student's identity is taken from the SERVER session (set by
     * scan()/login()), NEVER from the request body. Otherwise anyone who can
     * reach this public endpoint could forge a clinic visit for any active
     * student by posting their id. The body's studentUserId/loginMethod (if
     * present) are ignored; the Form Request no longer validates them.
     *
     * The Form Request has already re-validated the payload server-side (ranges,
     * completeness, consent). The Action then writes the clinic_visits +
     * vital_signs + screening_responses rows in one transaction and computes the
     * authoritative flag booleans (§7.4). We return the minted HP-YYYY-####
     * reference so the Complete screen can show it (FR-KSK-13).
     */
    public function submit(KioskSubmitRequest $request, SubmitKioskVisit $action): JsonResponse
    {
        // No established kiosk identity (never scanned/logged in, or the session
        // expired), or the bound id no longer resolves to an active student
        // (e.g. deactivated mid-session). Re-checked against the SESSION value.
        $identity = $this->boundIdentity($request);

        if ($identity === null) {
            return $this->expiredSession();
        }

        $visit = $action->handle([
            ...$request->validated(),
            ...$identity,
            // D-58: the Bluetooth BP reading claimed in THIS session (or null) —
            // from the session, never the body, like the identity above.
            'bpReading' => $request->session()->get(BpReadingController::SESSION_KEY),
        ]);

        // Identity is single-use: forget it so a replayed POST cannot mint a
        // second visit without a fresh scan/login (the kiosk browser session is
        // shared across every student on the Pi). The claimed BP reading goes too.
        $request->session()->forget(self::SESSION_KEYS);

        return response()->json([
            'ok' => true,
            'reference' => $visit->reference_no,
        ]);
    }

    /**
     * Forget the kiosk identity and any claimed BP reading (FR-KSK-13/15 support).
     *
     * Called by the Alpine state machine's reset() on every abandon/finish path
     * ("Not you?", consent Decline, the 90s idle reset, and the Complete
     * screen's reset) so a student's bound identity never lingers into the next
     * session. Submit already forgets on success; this covers everything else.
     */
    public function reset(Request $request): JsonResponse
    {
        $request->session()->forget(self::SESSION_KEYS);

        return response()->json(['ok' => true]);
    }

    /**
     * Discreet staff exit (FR-KSK-16).
     *
     * The kiosk has no nav and the Pi runs Chromium in --kiosk mode, so a student
     * cannot leave /kiosk on their own. To END a shift a staff member taps the
     * hidden corner gesture (5 taps within ~3 s) which opens this prompt. We
     * authenticate the staff member here — not just check a password — so the
     * redirect actually lands inside the (auth-gated) nurse queue instead of
     * bouncing to the login page. An email is required to know WHO to sign in.
     *
     * CLINIC-STAFF-ONLY by design: the kiosk lives at the clinic and hands off
     * to the nurse queue, so only an active nurse or physician (D-64) may
     * unlock it. As with the
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
            'message' => 'Those credentials don\'t match a clinic staff account.',
        ], 422);

        $user = User::where('email', $validated['email'])->first();

        $hash = $user?->password ?? Hash::make('kiosk-no-such-user');
        $passwordOk = Hash::check($validated['password'], $hash);

        if ($user === null || ! $passwordOk) {
            return $invalid;
        }

        // D-64: a nurse or a physician may leave kiosk mode.
        if (! $user->isClinicStaff() || $user->status !== 'active') {
            return $invalid;
        }

        // Drop any kiosk identity a student left bound (an abandoned session that
        // never hit reset()) BEFORE we authenticate the nurse — regenerate()
        // preserves session data, so without this a stale kiosk.student_id would
        // survive into the nurse's authenticated session and a later /kiosk/submit
        // could mint a visit for the wrong student (FR-KSK-13).
        $request->session()->forget(self::SESSION_KEYS);

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
     * full profile. `studentUserId` + `loginMethod` are echoed for the client's
     * own display/state; they are NOT trusted at submit, which reads identity
     * from the server session instead (see submit()).
     *
     * `hasAppointmentToday` and `formType` are computed HERE, server-side, so
     * neither the schedule check (FR-KSK-03a) nor the choice of screens
     * (D-68) can be spoofed by client state: the front-end only uses them to
     * pick which screens to show. Submit re-resolves the appointment — and
     * with it the form type — itself, and refuses the visit without one (D-61).
     */
    private function identityPayload(StudentProfile $profile, string $loginMethod, array $recheck = []): array
    {
        $first = trim($profile->first_name);
        $last = trim($profile->last_name);
        $initials = mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));

        // ONE resolution for both fields — and the same one SubmitKioskVisit
        // uses (Appointment::todayFor), so scan and submit can never pick
        // different appointments and therefore different forms.
        $appointment = Appointment::todayFor($profile->user_id);

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
            'hasAppointmentToday' => $appointment !== null,
            // D-62/D-68: which official form today's batch named. The kiosk
            // uses it ONLY to choose screens — never to decide what is stored.
            'formType' => $appointment?->formType() ?? 'clearance',
            // D-72: either nothing, `recheckWaitUntil` (still resting) or
            // `recheck: {steps: [...]}` (the rest is over). See bindRestingVisit().
            ...$recheck,
        ];
    }

    // -- Rest & re-check (FR-KSK-11a, D-72) ----------------------------------

    /**
     * Bind TODAY's resting visit for this student to the session, and say what
     * the kiosk should do about it.
     *
     * Called from scan() and login() only, i.e. exactly where identity is
     * established. Three outcomes:
     *   - no resting visit today  -> [], the normal first pass;
     *   - resting, rest not over  -> ['recheckWaitUntil' => ...], the kiosk shows
     *                                "keep resting, come back at ..." and resets;
     *   - resting, rest over      -> ['recheck' => ['steps' => [...]]], the kiosk
     *                                goes Identity Confirm -> the named vitals
     *                                steps only.
     *
     * The visit id goes into the SESSION, never into the payload: the client
     * never names a visit, exactly as it never names a student (CLAUDE.md).
     * The time is the SERVER's `resting_until`, formatted here, so no browser
     * clock is ever involved (the same rule as BR-23).
     *
     * @return array<string, mixed>
     */
    private function bindRestingVisit(Request $request, int $studentId): array
    {
        $visit = ClinicVisit::restingTodayFor($studentId);

        if ($visit === null) {
            return [];
        }

        $request->session()->put('kiosk.resting_visit_id', $visit->id);

        if (! $visit->restIsOver()) {
            return ['recheckWaitUntil' => $visit->resting_until->format('g:i A')];
        }

        // Which readings were flagged, from the STORED first-pass flags - not
        // from anything the browser remembers about the pass it walked away from.
        return ['recheck' => [
            'steps' => $visit->vitalSigns?->recheckSteps() ?? [],
            'kept' => $this->keptAnswers($visit),
        ]];
    }

    /**
     * What the resting visit already holds, for the re-check pass to SHOW
     * (FR-KSK-11a): the vitals that are not being re-taken and every answer
     * the student gave the first time.
     *
     * DISPLAY ONLY. The re-check submit re-reads all of this from the same
     * rows, and KioskRecheckRequest accepts nothing but the re-taken numbers,
     * so a tampered copy changes the Review screen and nothing else. It is
     * sent so the student can check their whole visit before submitting rather
     * than being asked to trust a screen showing two readings out of six.
     *
     * @return array<string, mixed>
     */
    private function keptAnswers(ClinicVisit $visit): array
    {
        $vitals = $visit->vitalSigns;
        $screening = $visit->screeningResponse;

        return [
            'vitals' => [
                'height' => (float) $vitals->height_cm,
                'weight' => (float) $vitals->weight_kg,
                'temperature' => (float) $vitals->temperature_c,
                'systolic' => (int) $vitals->bp_systolic,
                'diastolic' => (int) $vitals->bp_diastolic,
                'heartRate' => (int) $vitals->heart_rate_bpm,
            ],
            // The twelve Physical Signs rows (D-63) as the kiosk names them,
            // plus the optional YES details and the pregnancy pair.
            'screening' => $screening?->kioskAnswers() ?? [],
            'details' => $screening?->details ?? [],
            'isPregnant' => (bool) $screening?->is_pregnant,
            'lastMenstrualPeriod' => $screening?->last_menstrual_period?->toDateString(),
            // D-68: present only on a Medical Assessment Form visit.
            'socialHistory' => $screening?->kioskSocialHistory(),
        ];
    }

    /**
     * Rest & re-check (FR-KSK-11a, D-72): save the first pass as a `resting`
     * visit instead of submitting it.
     *
     * Same payload and same validation as submit() - the student has answered
     * everything, so everything is stored; only the STATUS differs. Identity
     * still comes from the session, and the action recomputes the flags and
     * refuses (422) when none of temperature, blood pressure or heart rate is
     * actually flagged, which sends the kiosk back to showing Submit to Clinic.
     *
     * The session identity is forgotten on success just as submit() forgets it:
     * the student walks away to rest and must scan again to come back, which is
     * what re-binds the resting visit (see bindRestingVisit()).
     */
    public function rest(KioskSubmitRequest $request, SubmitKioskVisit $action): JsonResponse
    {
        $identity = $this->boundIdentity($request);

        if ($identity === null) {
            return $this->expiredSession();
        }

        // A visit may rest ONCE. A student already holding a resting visit today
        // is coming BACK, not starting over - refused so the first pass, with
        // its consent and its answers, is never orphaned by a second one.
        if (ClinicVisit::restingTodayFor($identity['studentUserId']) !== null) {
            return response()->json([
                'ok' => false,
                'message' => 'You have already rested once today. Please see the clinic staff.',
            ], 422);
        }

        $visit = $action->rest([
            ...$request->validated(),
            ...$identity,
            'bpReading' => $request->session()->get(BpReadingController::SESSION_KEY),
        ]);

        $request->session()->forget(self::SESSION_KEYS);

        return response()->json([
            'ok' => true,
            // The SERVER's come-back time - the Rest screen shows this string
            // and never computes one of its own.
            'restingUntil' => $visit->resting_until->format('g:i A'),
            'steps' => $visit->vitalSigns?->recheckSteps() ?? [],
        ]);
    }

    /**
     * Re-check submit (FR-KSK-11a, D-72): fold the re-taken readings into the
     * resting visit and release it into the clinic queue.
     *
     * Its own endpoint rather than a mode of submit(), because the payload is a
     * different shape entirely - see KioskRecheckRequest. Everything the body
     * does not carry (height, weight, consent, the questionnaire, the social
     * history) is read from the saved rows and can never be re-posted.
     *
     * The visit is taken from the SESSION key bound at scan/login, so another
     * terminal's session cannot touch it; it must still be `resting`, still be
     * THIS student's, and its rest must be over.
     */
    public function recheck(KioskRecheckRequest $request, RecheckKioskVisit $action): JsonResponse
    {
        $identity = $this->boundIdentity($request);

        if ($identity === null) {
            return $this->expiredSession();
        }

        $visit = ClinicVisit::with('vitalSigns')->find($request->session()->get('kiosk.resting_visit_id'));

        // Every one of these is re-checked HERE, on the server, at submit time -
        // the scan payload that sent the student to the vitals screens is a
        // courtesy, not the gate (the same rule D-61 follows for appointments).
        $usable = $visit !== null
            && $visit->status === 'resting'
            && $visit->student_id === $identity['studentUserId']
            && $visit->restIsOver();

        if (! $usable) {
            return response()->json([
                'ok' => false,
                'message' => 'That re-check is no longer available — please start again.',
            ], 422);
        }

        $visit = $action->handle(
            $visit,
            $request->validated(),
            $request->session()->get(BpReadingController::SESSION_KEY),
        );

        // Single-use, like submit(): the identity and the bound visit go together.
        $request->session()->forget(self::SESSION_KEYS);

        return response()->json([
            'ok' => true,
            'reference' => $visit->reference_no,
        ]);
    }

    /**
     * The SERVER-bound identity for this kiosk session, or null when there is
     * none (never scanned/logged in, the session expired, or the bound id no
     * longer resolves to an active student). Shared by submit, rest and
     * recheck so all three apply the same gate.
     *
     * @return array{studentUserId: int, loginMethod: string}|null
     */
    private function boundIdentity(Request $request): ?array
    {
        $studentId = $request->session()->get('kiosk.student_id');
        $loginMethod = $request->session()->get('kiosk.login_method');

        $ok = $studentId !== null && $loginMethod !== null
            && User::where('id', $studentId)
                ->where('role', 'student')
                ->where('status', 'active')
                ->exists();

        return $ok
            ? ['studentUserId' => (int) $studentId, 'loginMethod' => $loginMethod]
            : null;
    }

    /** The one "your session is gone, start again" refusal. */
    private function expiredSession(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'Session expired — please start again.',
        ], 422);
    }
}
