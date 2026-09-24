<?php

use App\Http\Controllers\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Admin\AnalyticsController as AdminAnalyticsController;
use App\Http\Controllers\Admin\BatchRequestController as AdminBatchRequestController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\MonthlyReportController as AdminMonthlyReportController;
use App\Http\Controllers\Admin\YearlyReportController as AdminYearlyReportController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Director\AnalyticsController as DirectorAnalyticsController;
use App\Http\Controllers\Director\AnomaliesController as DirectorAnomaliesController;
use App\Http\Controllers\Director\BatchApprovalController as DirectorBatchApprovalController;
use App\Http\Controllers\Director\DashboardController as DirectorDashboardController;
use App\Http\Controllers\Director\StaffAccountController as DirectorStaffAccountController;
use App\Http\Controllers\Kiosk\BpReadingController;
use App\Http\Controllers\Kiosk\KioskController;
use App\Http\Controllers\Nurse\DashboardController as NurseDashboardController;
use App\Http\Controllers\Nurse\EncodeController as NurseEncodeController;
use App\Http\Controllers\Nurse\KioskDeviceController as NurseKioskDeviceController;
use App\Http\Controllers\Nurse\PrintClearanceController as NursePrintClearanceController;
use App\Http\Controllers\Nurse\QueueController as NurseQueueController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Student\DashboardController as StudentDashboardController;
use App\Http\Controllers\Student\ProfileController as StudentProfileController;
use App\Http\Controllers\Student\RecordsController as StudentRecordsController;
use App\Http\Controllers\Student\TutorialCompletionController;
use App\Http\Middleware\EnsureRole;
use App\Models\BatchRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (Auth::check()) {
        return redirect(EnsureRole::dashboardFor(Auth::user()));
    }

    return redirect()->route('login');
});

// ── Profile (any authenticated user) ────────────────────────────────────────

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // ── Change Password — OTP-confirmed, all four roles ──────────────────────
    // Submitting stages the new hash + emails a code; nothing changes until the
    // code is verified. Throttle prefixes: authed users key rate limits by user
    // id with no path, so each endpoint needs its own bucket (see routes/auth.php).
    Route::get('/password/change', [PasswordChangeController::class, 'show'])
        ->name('password.change');
    // Sends real email — backstop throttle on top of the 60s resend cooldown
    // enforced inside the controller.
    Route::post('/password/change', [PasswordChangeController::class, 'store'])
        ->middleware('throttle:10,1,pwc-store')
        ->name('password.change.store');
    Route::get('/password/change/verify', [PasswordChangeController::class, 'showVerify'])
        ->name('password.change.verify');
    Route::post('/password/change/verify', [PasswordChangeController::class, 'verify'])
        ->middleware('throttle:10,1,pwc-verify')
        ->name('password.change.verify.submit');
    Route::post('/password/change/verify/resend', [PasswordChangeController::class, 'resend'])
        ->middleware('throttle:3,5,pwc-resend')
        ->name('password.change.verify.resend');
    Route::post('/password/change/cancel', [PasswordChangeController::class, 'cancel'])
        ->name('password.change.cancel');
});

// ── Student (FR-AUTH-03) ─────────────────────────────────────────────────────

Route::middleware(['auth', 'role:student'])
    ->prefix('student')
    ->name('student.')
    ->group(function () {
        Route::get('/dashboard', StudentDashboardController::class)->name('dashboard');
        // D-61: there is no student booking any more — no Book Appointment,
        // no My Appointments. Only a Director-approved college batch creates
        // an appointment, so every one of those old URLs is simply a 404.
        //
        // nav.seen (D-57): opening the page marks its sidebar badge as seen —
        // the argument is the badge's key. See App\Http\Middleware\MarkNavSeen.
        Route::get('/records', [StudentRecordsController::class, 'index'])
            ->middleware('nav.seen:student.records')->name('records');
        // One visit's full record (FR-STU-07, D-73) and its Save as PDF
        // (FR-STU-15). {visit} is NOT route-model-bound: the controller looks
        // the id up through the signed-in student's own clinicVisits(), so
        // another student's id is a 404 rather than a 403.
        //
        // The PDF gets its own throttle bucket — for an authed user the
        // rate-limit key is the user id with no path, so without the 3rd-arg
        // prefix it would share one counter with every other student route.
        Route::get('/records/{visit}', [StudentRecordsController::class, 'show'])->name('records.show');
        Route::get('/records/{visit}/pdf', [StudentRecordsController::class, 'pdf'])
            ->middleware('throttle:10,1,record-pdf')->name('records.pdf');
        // Kiosk Tutorial (FR-STU-11): static walkthrough, no data to prepare —
        // Route::view renders the Blade view directly, no controller needed.
        Route::view('/tutorial', 'student.tutorial')->name('tutorial');
        // D-57: the walkthrough POSTs here on reaching its last step, which
        // clears the tutorial's sidebar dot. Its own throttle bucket: an authed
        // throttle keys on the user id with no path, so without the 3rd-arg
        // prefix it would share one counter with every other student write.
        Route::post('/tutorial/complete', TutorialCompletionController::class)
            ->middleware('throttle:10,1,tutorial-complete')->name('tutorial.complete');
        Route::get('/id-profile', [StudentProfileController::class, 'show'])->name('id-profile');
        Route::patch('/id-profile', [StudentProfileController::class, 'update'])->name('id-profile.update');
        Route::post('/id-profile/link-id', [StudentProfileController::class, 'linkId'])->name('id-profile.link-id');
        Route::get('/id-profile/verify-email', [StudentProfileController::class, 'showEmailVerification'])->name('id-profile.verify-email');
        // throttle:10,1 caps OTP guesses (per-code 5-attempt cap still applies inside
        // verifyEmail); resend is throttle:3,5 — it sends real email to the new address,
        // so this is the mail-bomb chokepoint (3 per 5 minutes). The 3rd arg is a bucket
        // prefix: for an authed user the rate-limit key is sha1(user id) with no path, so
        // without distinct prefixes submit + resend would share one counter and the tight
        // resend cap would trip on the user's own verify attempts.
        Route::post('/id-profile/verify-email', [StudentProfileController::class, 'verifyEmail'])->middleware('throttle:10,1,idp-verify')->name('id-profile.verify-email.submit');
        Route::post('/id-profile/verify-email/resend', [StudentProfileController::class, 'resendEmailOtp'])->middleware('throttle:3,5,idp-resend')->name('id-profile.verify-email.resend');
        Route::post('/id-profile/verify-email/cancel', [StudentProfileController::class, 'cancelEmailChange'])->name('id-profile.verify-email.cancel');
    });

// ── College Admin (FR-AUTH-03) ───────────────────────────────────────────────
// `college.scope` (FR-AUTH-06): every /admin/* request is refused unless the
// admin has a non-null managed_college_id — controllers then derive ALL data
// from that college via the ScopedToManagedCollege trait, never from request
// input, so the scope cannot be tampered with client-side (FR-ADM-06).

Route::middleware(['auth', 'role:college_admin', 'college.scope'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');
        // New Batch Request (FR-ADM-02/03, BR-06/07). The create page ships
        // ONLY the managed college's roster; store re-checks every student id
        // against that college server-side.
        Route::get('/batches/create', [AdminBatchRequestController::class, 'create'])->name('batches.create');
        // The New Batch mini calendar's month data (D-54): the same full and
        // cutoff days the student calendar gets. Declared before any
        // /batches/{batch} route, with its own throttle bucket — an authed
        // throttle keys on the user id with no path, so it would otherwise
        // share one counter with batch-store.
        Route::get('/batches/availability', [AdminBatchRequestController::class, 'availability'])
            ->middleware('throttle:60,1,batch-availability')->name('batches.availability');
        // Card 1's live form tiles: the official form's front page, blank.
        // Only the two form types match; anything else is a 404.
        Route::get('/batches/form-preview/{formType}', [AdminBatchRequestController::class, 'formPreview'])
            ->whereIn('formType', array_keys(BatchRequest::FORM_TYPES))->name('batches.form-preview');
        Route::post('/batches', [AdminBatchRequestController::class, 'store'])
            ->middleware('throttle:15,1,batch-store')->name('batches.store');
        // Batch Tracking + post-submit confirmation (FR-ADM-04/05). Both fetch
        // through managedCollege(), so foreign batch ids 404.
        Route::get('/batches', [AdminBatchRequestController::class, 'index'])
            ->middleware('nav.seen:admin.batches.index')->name('batches.index');
        Route::get('/batches/{batch}/confirmation', [AdminBatchRequestController::class, 'confirmation'])
            ->whereNumber('batch')->name('batches.confirmation');
        // Batch roster (FR-ADM-07, D-40): the students in one batch and the
        // appointments approval generated for them. Scoped through
        // managedCollege() like every other /admin read, so a foreign id 404s.
        Route::get('/batches/{batch}', [AdminBatchRequestController::class, 'show'])
            ->whereNumber('batch')->name('batches.show');
        // Cancel a whole PENDING batch (FR-ADM-11, D-52). Its own throttle
        // bucket for the same reason as batch-appt-cancel below: an authed
        // throttle keys on the user id with no path, so without the 3rd arg
        // this would share one counter with batch-store.
        Route::delete('/batches/{batch}/cancel', [AdminBatchRequestController::class, 'cancel'])
            ->whereNumber('batch')
            ->middleware('throttle:20,1,batch-cancel')->name('batches.cancel');
        // Withdraw ONE student's appointment from an approved batch — the other
        // half of D-39, since a batch student cannot cancel their own. Its own
        // throttle bucket: authed throttles key on the user id with no path, so
        // without the 3rd arg this would share a counter with batch-store.
        Route::delete('/batches/{batch}/appointments/{appointment}', [AdminBatchRequestController::class, 'cancelAppointment'])
            ->whereNumber('batch')->whereNumber('appointment')
            ->middleware('throttle:30,1,batch-appt-cancel')->name('batches.appointments.cancel');
        // Analytics (FR-ADM-08, D-45): the Director's six cards, scoped to
        // this admin's college and broken out per program. Same
        // App\Services\ClinicAnalytics behind both pages. Filters are month
        // + program only — the college is never a request parameter here.
        Route::get('/analytics', AdminAnalyticsController::class)->name('analytics');
        // Printable Monthly Clinic Report (FR-ADM-09, D-46): the same cards as
        // the page above, rendered as TABLES in a standalone print document —
        // charts do not print reliably. Same scope rule: the college is
        // managedCollege(), never a request parameter; only month + program
        // carry over from the analytics page's query string.
        Route::get('/analytics/print', AdminMonthlyReportController::class)->name('analytics.print');
        // Yearly Clearance Report (FR-ADM-13, D-81): one calendar year's
        // clearances as a DOWNLOADED PDF (dompdf). Same scope rule — the
        // college is managedCollege(); the only input is ?year=. Its own
        // throttle bucket (3rd arg): rendering a PDF is expensive, and without
        // it this would share the per-user counter with the batch routes.
        Route::get('/analytics/yearly-report', AdminYearlyReportController::class)
            ->middleware('throttle:10,1,yearly-report')->name('analytics.yearly-report');
        // Activity Log (FR-ADM-10, D-49): who did what for THIS college —
        // batch submissions by any of its admins, and the Director's decisions
        // on them. Read-only and DERIVED from batch_requests; there is no
        // activity_logs table and nothing writes an audit row, so the log
        // cannot drift from the rows Batch Tracking renders.
        Route::get('/activity', AdminActivityLogController::class)
            ->middleware('nav.seen:admin.activity')->name('activity');
    });

// ── Clinic Dashboard: Nurse + Physician (FR-AUTH-03, D-64) ────────────────────
// Both clinic roles share every page below. The URLs and route names stay
// `/nurse/...` / `nurse.*` — only the labels say "Clinic".

Route::middleware(['auth', 'role:nurse,physician'])
    ->prefix('nurse')
    ->name('nurse.')
    ->group(function () {
        // Dashboard (FR-NRS-09, D-44): stat tiles + the clinic-wide encode
        // history. The nurse's HOME — see EnsureRole::DASHBOARDS.
        Route::get('/dashboard', NurseDashboardController::class)->name('dashboard');
        Route::get('/queue', [NurseQueueController::class, 'index'])->name('queue');
        // JSON feed for the 4 s Live Queue poll (FR-NRS-02). Nurse-only, same
        // guard as the page — it exposes the same captured-visit rows.
        Route::get('/queue/feed', [NurseQueueController::class, 'feed'])->name('queue.feed');
        // Encode Result / "Doctor's Assessment" (FR-NRS-03). {visit} is
        // route-model-bound to ClinicVisit by the controller's type-hint —
        // Laravel looks the id up and 404s unknown ids before our code runs.
        Route::get('/visits/{visit}/encode', [NurseEncodeController::class, 'show'])->name('visits.encode');
        // Save & Close (FR-NRS-04): creates the clearance record and flips the
        // visit to encoded — one-time, guarded in the controller + DB unique.
        Route::post('/visits/{visit}/encode', [NurseEncodeController::class, 'store'])
            ->middleware('throttle:40,1,encode-store')->name('visits.encode.store');
        // Medical Clearance document (Module PRT, FR-PRT-01..06 / FR-NRS-05) —
        // official form PSU-QSP-OSS-004-FO002-R04 (D-67) as a standalone
        // document, rendered from one Blade template by both renderers.
        //  GET  print         — plain view of an encoded visit's form, no side effects
        //  POST print-preview — captured visit: the encode form posts its unsaved
        //                       fields into the hidden print iframe (pre-save preview)
        //  POST print         — encoded visit: Reprint — re-stamps printed_at and
        //                       returns the form for the iframe to print
        //  GET  pdf           — Save as PDF (FR-PRT-06): the same document via
        //                       dompdf, as a download; never stamps printed_at
        Route::get('/visits/{visit}/print', [NursePrintClearanceController::class, 'show'])->name('visits.print');
        Route::post('/visits/{visit}/print-preview', [NursePrintClearanceController::class, 'preview'])->name('visits.print.preview');
        Route::post('/visits/{visit}/print', [NursePrintClearanceController::class, 'reprint'])->name('visits.print.reprint');
        Route::get('/visits/{visit}/pdf', [NursePrintClearanceController::class, 'pdf'])->name('visits.pdf');

        // Enable Kiosk Mode (FR-NRS-06, D-27): enroll/list/revoke trusted kiosk
        // DEVICES so a clinic terminal can open /kiosk without anyone signing in
        // at the screen. See App\Http\Middleware\KioskAccess.
        Route::get('/kiosk-devices', [NurseKioskDeviceController::class, 'index'])->name('kiosk-devices');
        Route::post('/kiosk-devices', [NurseKioskDeviceController::class, 'store'])
            ->middleware('throttle:15,1,kioskdev-store')->name('kiosk-devices.store');
        Route::delete('/kiosk-devices/{device}', [NurseKioskDeviceController::class, 'destroy'])
            ->middleware('throttle:15,1,kioskdev-destroy')->name('kiosk-devices.destroy');
    });

// ── Director (FR-AUTH-03) ────────────────────────────────────────────────────

Route::middleware(['auth', 'role:director'])
    ->prefix('director')
    ->name('director.')
    ->group(function () {
        // Dashboard (FR-ANL-01): KPI cards + the two preview panels.
        Route::get('/dashboard', DirectorDashboardController::class)->name('dashboard');
        // Analytics (FR-ANL-09..13 + amended FR-ANL-04): captured-data
        // charts — visits by college, flags, trend, BMI, by-sex donut —
        // scoped by the month + college filters (D-32 rescope).
        Route::get('/analytics', DirectorAnalyticsController::class)->name('analytics');
        // Flagged Anomalies (FR-ANL-05): stat cards + the flagged-visits
        // table. Flags surface from CAPTURE (FR-ANL-07) — un-encoded visits
        // are included, unlike every case statistic on Analytics.
        Route::get('/anomalies', [DirectorAnomaliesController::class, 'index'])
            ->middleware('nav.seen:director.anomalies')->name('anomalies');
        // Read-only record detail behind each table row's "View" link.
        Route::get('/anomalies/{visit}', [DirectorAnomaliesController::class, 'show'])
            ->whereNumber('visit')->name('anomalies.show');
        // Batch Approvals (FR-DIRA-01/05/06): ALL colleges' requests — no
        // college scope, the Director reviews everything.
        Route::get('/batches', [DirectorBatchApprovalController::class, 'index'])->name('batches.index');
        // JSON for the approve modal's capacity warning (FR-DIRA-06). Must be
        // declared BEFORE /batches/{batch}-style routes would ever match it.
        Route::get('/batches/capacity', [DirectorBatchApprovalController::class, 'capacity'])->name('batches.capacity');
        // Decision endpoints — both terminal (FR-DIRA-05). Approve: FR-DIRA-02,
        // BR-08 (transaction + appointment fan-out). Reject: FR-DIRA-04
        // (reviewer stamps, zero appointments).
        Route::post('/batches/{batch}/approve', [DirectorBatchApprovalController::class, 'approve'])
            ->whereNumber('batch')->middleware('throttle:20,1,batch-approve')->name('batches.approve');
        Route::post('/batches/{batch}/reject', [DirectorBatchApprovalController::class, 'reject'])
            ->whereNumber('batch')->middleware('throttle:20,1,batch-reject')->name('batches.reject');
        // Staff Accounts (FR-AUTH-10, D-47): the Director provisions the
        // college_admin, nurse and physician (D-64) accounts that used to need a
        // developer running StaffSeeder on the server. A capability layer on
        // the existing director role, not a role of its own.
        //
        // Every write below carries its OWN throttle bucket (the 3rd arg). For an
        // authenticated user the inline throttle keys on the user id with NO
        // path, so without distinct prefixes these five would share one counter
        // with each other and with the batch endpoints above — the same bug
        // documented at batch-appt-cancel.
        Route::get('/staff', [DirectorStaffAccountController::class, 'index'])->name('staff.index');
        Route::post('/staff', [DirectorStaffAccountController::class, 'store'])
            ->middleware('throttle:15,1,staff-store')->name('staff.store');
        // NOTE: there is deliberately no "reissue password" endpoint. A Director
        // who can set someone else's password can sign in as them and read their
        // records, which is the impersonation D-47 rules out. A staff member who
        // forgets their password uses the ordinary forgot-password OTP (D-20).
        Route::patch('/staff/{user}/status', [DirectorStaffAccountController::class, 'status'])
            ->whereNumber('user')->middleware('throttle:30,1,staff-status')->name('staff.status');
        Route::patch('/staff/{user}/college', [DirectorStaffAccountController::class, 'college'])
            ->whereNumber('user')->middleware('throttle:30,1,staff-college')->name('staff.college');
        // Correct a physician's license number (D-64). Its own bucket, like
        // the two above — see the shared-authed-bucket note on this group.
        Route::patch('/staff/{user}/license', [DirectorStaffAccountController::class, 'license'])
            ->whereNumber('user')->middleware('throttle:30,1,staff-license')->name('staff.license');
    });

// ── Kiosk (Module KSK, FR-KSK-01..16) — PUBLIC clinic terminal ───────────────
// No auth: identity is established inside the flow (QR scan / email login),
// not via a logged-in session. On the Pi this is opened full-screen.
//
// Auth-less for the person AT the terminal, but the NETWORK is restricted by the
// `kiosk.access` middleware (security audit fix): only the Pi's own loopback or an
// authenticated active nurse may reach these endpoints — everyone else gets 403.
// This stops /kiosk/scan being used from the LAN/internet as a PII oracle against
// guessable QR tokens. Toggle off with HEALTHPASS_KIOSK_RESTRICT=false for LAN dev.
Route::prefix('kiosk')->name('kiosk.')->middleware('kiosk.access')->group(function () {
    Route::get('/', [KioskController::class, 'index'])->name('index');
    // Fresh CSRF token for self-healing (see KioskController@token). A GET, so
    // it needs no token itself; the kiosk calls it to recover from a stale token
    // (page outlived its session) and retry, instead of dead-ending on a 419.
    Route::get('/token', [KioskController::class, 'token'])->name('token');
    // Throttle prefixes (3rd arg): for an UNAUTHENTICATED request the inline
    // throttle keys its counter on sha1(domain|ip) with NO path — so without a
    // distinct prefix every kiosk POST from one IP (the Pi) would share ONE
    // counter, and mashing /scan could lock out the nurse's /exit. A per-endpoint
    // prefix gives each its own bucket. (D — security upgrade.)
    Route::post('/scan', [KioskController::class, 'scan'])
        ->middleware('throttle:30,1,kiosk-scan')
        ->name('scan');
    // Email fallback login (FR-KSK-02). Tighter throttle than scan — this is a
    // credential check, so we cap brute-force attempts per kiosk IP.
    Route::post('/login', [KioskController::class, 'login'])
        ->middleware('throttle:10,1,kiosk-login')
        ->name('login');
    // Final submit (FR-KSK-12): re-validates server-side and writes the
    // clinic_visits + vital_signs + screening_responses trio in one transaction,
    // returning the minted HP-YYYY-#### for the Complete screen.
    //
    // ->block() = SESSION LOCKING: Laravel holds an atomic lock on THIS session
    // for the request's duration, so two submits from the same kiosk session
    // (a double-tap, or a network retry of an in-flight POST) run one-at-a-time
    // instead of overlapping. The first mints the visit and forgets the identity
    // (see KioskController@submit); the second then loads the now-cleared session
    // and is refused — so one screening can never become two clinic visits. The
    // throttle caps volume; the lock guarantees the single-use identity is
    // actually single-use under concurrency.
    Route::post('/submit', [KioskController::class, 'submit'])
        ->middleware('throttle:20,1,kiosk-submit')
        ->block()
        ->name('submit');
    // Rest & re-check (FR-KSK-11a, D-72). `rest` saves the first pass as a
    // `resting` visit — same payload, same KioskSubmitRequest validation as
    // submit, but it never enters the queue; `recheck` folds the re-taken
    // reading back in and releases it. Both get their OWN throttle prefix (see
    // the note above) and both ->block() for the same reason submit does: one
    // kiosk session must not be able to rest twice or release a visit twice
    // by double-tapping.
    Route::post('/rest', [KioskController::class, 'rest'])
        ->middleware('throttle:20,1,kiosk-rest')
        ->block()
        ->name('rest');
    Route::post('/recheck', [KioskController::class, 'recheck'])
        ->middleware('throttle:20,1,kiosk-recheck')
        ->block()
        ->name('recheck');
    // Forget the server-side kiosk identity (kiosk.* session keys). The Alpine
    // reset() calls this on every abandon/finish path so a bound student never
    // lingers into the next session. Same throttle as scan — it is unauthenticated.
    Route::post('/reset', [KioskController::class, 'reset'])
        ->middleware('throttle:30,1,kiosk-reset')
        ->name('reset');
    // Discreet staff exit (FR-KSK-16): the 5-tap corner gesture opens a prompt
    // for a nurse's credentials; a valid nurse is logged in and the kiosk hands
    // off to the nurse queue. Same tight throttle as login — it is a credential
    // check on a public terminal, so brute-force attempts are capped per IP.
    Route::post('/exit', [KioskController::class, 'exit'])
        ->middleware('throttle:10,1,kiosk-exit')
        ->name('exit');
    // Bluetooth BP monitor (D-58). The Pi daemon POSTs readings to
    // /api/kiosk/bp-reading (routes/api.php); the kiosk page polls `latest`
    // every 2 s while the BP step waits, then `claim`s a new reading into THIS
    // kiosk session so submit can trust it. Both stay inside kiosk.access, so a
    // reading can't be read from anywhere but an allowed terminal. `latest` gets
    // a roomier bucket: one poll every 2 s is already 30 a minute.
    Route::get('/bp-reading/latest', [BpReadingController::class, 'latest'])
        ->middleware('throttle:90,1,kiosk-bp-latest')
        ->name('bp-reading.latest');
    Route::post('/bp-reading/claim', [BpReadingController::class, 'claim'])
        ->middleware('throttle:30,1,kiosk-bp-claim')
        ->name('bp-reading.claim');
});

// ── Dev component showcase (local only) ──────────────────────────────────────
if (app()->isLocal()) {
    Route::get('/dev/components', fn () => view('dev.components'))->name('dev.components');
}

require __DIR__.'/auth.php';
