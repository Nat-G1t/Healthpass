# Release Notes

## [Unreleased](https://github.com/laravel/laravel/compare/v12.12.1...12.x)

### Added

* **Batch Results card on Batch Tracking, with an 8 PM absent rule** (D-55,
  new FR-ADM-12). **No schema change, no new table, no new route, no new
  package.** A College Admin who booked a cohort had to open each batch's
  roster to see who had finished. **This reverses D-53's rejection of a
  separate results view** — Nat wants every batch's outcome in one place.
  - New **Batch Results** card above the requests list, rendered once the
    college has an approved batch: every **approved** batch, newest clinic
    date first, as Batch ID · Time of Completion · View. Time of Completion is
    **In progress** until every non-withdrawn student is Completed or Absent,
    then the **last encode** ("Sep 10, 2026 · 3:42 PM"), or **No one
    attended**. The rule is on the model — `BatchRequest::isResultsFinished()`
    and `resultsCompletedAt()` — and reads the same `clearanceProgress()` as
    the popup, so the column and the popup cannot disagree.
  - **View** opens a teleported popup: reference, service, clinic date, hour
    span, and per student name, student no., hour, Status (*Not yet attended ·
    At the clinic · Completed · Absent · Withdrawn*) and Result (Fit / Unfit /
    —), dental included. Rows are built server-side in `index()` and embedded
    with `Js::from()`. **Outcome only:** each row has exactly five keys, and
    the query does not even select the clinical columns.
  - **Absent replaces "Did not attend".** `clearanceProgress()`'s `missed` key
    is renamed `absent` and no longer flips at midnight: a student with no
    visit is absent once the **server clock** reaches new config
    `healthpass.absent_cutoff` (`HEALTHPASS_ABSENT_CUTOFF`, default `20:00`)
    on the clinic date.
  - **Roster trimmed:** the batch roster loses D-53's Status and Result columns
    and its "Clearance results" roll-up. The summary, Appointment, Time,
    Withdraw, the cancelled notice and the withdrawal guidance stay, and
    `show()` no longer loads the clinic visit or clearance record.
  - `tests/Feature/Admin/BatchResultsTest.php` reworked (20 cases, including a
    query-count guard) plus `tests/Unit/AppointmentClearanceProgressTest.php`
    (8); `DemoBatchSeederTest` updated for the renamed key.

* **Batches and self-bookings can no longer double-book a student** (D-54, new
  BR-25). **No schema change, no new table, no new package.** A student could
  self-book Sept 10, 9–10 AM and their College Admin could still put them in a
  batch for Sept 10 starting 9 AM — or the other way round — and nothing
  refused either.
  - New **`App\Services\ScheduleClashService`**, the one definition of a clash,
    read by the student booking, the batch submission and the Director's
    approval: an **hour overlap, for any service**; a batch holds its **whole
    span** for every student on it while `pending` or `approved`; rejected and
    cancelled batches, and a student withdrawn from an approved batch, hold
    nothing; NULL-time (pre-D-37) rows never clash. Overlap is computed in PHP
    from `requestedSpan()` — no raw SQL.
  - **First come wins.** The student is refused on Book with "… has already
    been scheduled for you by your college admin." (no batch reference, under
    its own modal heading). The College Admin's submit comes back with a popup
    listing every clashing student and what they clash with, plus **Remove
    these students from the batch** / **Close**. The Director's approval
    refuses a batch that already clashes. Student and admin writes re-check
    under lock inside their transactions, exactly like the capacity checks.
  - **BR-04 counts self-bookings only** — an approved 9 AM batch appointment no
    longer forbids a 2 PM self-booking of the same service.
  - **Kiosk link:** among today's scheduled appointments, the one whose hour
    starts closest to check-in wins (a tie goes to the earlier hour; NULL-time
    only if nothing else), **superseding D-33's "medical wins" edge rule**. The
    `KioskSubmitTest` case that asserted medical-wins was rewritten for the new
    rule, not deleted.
  - The New Batch **Requested clinic date** is now a compact month calendar
    with the student calendar's rules (past, FULL, cutoff and non-booking days
    disabled; "today" from the server clock), fed by new
    `GET /admin/batches/availability` with its own `batch-availability`
    throttle bucket. `fullDaysForMonth()` and `cutoffDaysForMonth()` moved from
    `BookAppointmentController` into `ClinicScheduleService`; the student
    availability JSON is unchanged.
  - 40 new cases: `tests/Unit/ScheduleClashServiceTest.php` (17) plus the
    student booking, batch submit/create, Director approval and kiosk submit
    suites, including two race tests that inject the competing booking between
    the Form Request's read and the locked re-check.

* **The Director can now provision staff accounts in the app** (D-47,
  FR-AUTH-10). **No schema change, no migration, no new package, and no fifth
  role.** Until now a College Admin or Nurse account could only be created by
  running `database/seeders/StaffSeeder.php`, so on the hosted deployment
  (D-34) adding one admin needed a developer with server and database access,
  and there was no way at all to deactivate someone who had left.
  - **New `/director/staff`** ("Staff Accounts" in the Director's sidebar,
    `Director\StaffAccountController` + `director/staff.blade.php`): list the
    `college_admin` and `nurse` accounts, create one, activate/deactivate it,
    and move a College Admin to another college. Four routes inside the existing
    `['auth', 'role:director']` group, each with **its own throttle prefix** —
    an authenticated inline throttle keys on the user id with no path, so a
    shared bucket would have coupled them to each other and to the batch
    endpoints.
  - **Super-admin is a capability layer, not a role.** `users.role` stays the
    four-value enum FR-AUTH-02 mandates (D-2 unchanged), which is why this
    needed no migration.
  - **Credentials reuse D-35 exactly** — `Str::password(16, symbols: false)`,
    `Hash::make`, `must_change_password = true`, and the existing
    `RequirePasswordChange` middleware forcing the change at first login. The
    plaintext is shown **once** in a show-once panel, never emailed and stored
    nowhere; only the bcrypt hash reaches the database. The Director sets that
    password once, at creation, and can never set it again — see D-47a below.
  - **Anti-escalation, enforced server-side in three places.** The role
    whitelist lives in `StoreStaffAccountRequest`, so a posted `role=director`
    or `role=student` is a validation failure rather than an account; every
    write endpoint refuses a target that is not a `college_admin`/`nurse`; and
    it refuses the signed-in Director's own row, so the only super-admin cannot
    lock themselves out. A `college_admin` can be neither created nor left
    without a `managed_college_id` — `college.scope` would 403 such an account
    on every Admin page (FR-AUTH-06).
  - **Nothing is ever deleted.** `clearance_records.encoded_by` and
    `batch_requests.reviewed_by` are `restrictOnDelete`, so deactivation is the
    only removal this system has, and it leaves every historical record intact
    — a deactivated nurse's past clearances still render, asserted by test.
  - **`StaffSeeder` is unchanged.** It stays the bootstrap path for the very
    first Director account; this is an in-app path alongside it. Self-
    registration with an approval queue was considered and **rejected**: it
    would contradict FR-AUTH-05 and add a public unauthenticated endpoint where
    none exists today.
  - 28 new cases in `tests/Feature/Director/StaffAccountTest.php`.

* **A "Last active" column on Staff Accounts** (D-48, FR-AUTH-10 amended).
  **Schema change: `users.last_active_at` (TIMESTAMP, nullable, no default,
  never backfilled).** `users.status` answers "is this account allowed in",
  which the Director had been reading as "is this account still in use" — a
  different question. A College Admin who left in June reads as Active until
  somebody thinks to deactivate them; this column is what makes the Deactivate
  button actionable rather than guesswork.
  - New **`App\Http\Middleware\RecordLastActive`**, appended to the web group
    **after** `EnsureAccountIsActive` and `RequirePasswordChange` — a request
    those gates turn away is not the account being used, so being bounced at
    the door must not register as activity.
  - **Throttled to at most one write per account per minute.** Stamping every
    request would put a database write on every page load, and the Live Queue
    polls every four seconds, while the question this column answers is
    measured in days.
  - Written through the **query builder, not Eloquent**: an Eloquent `update()`
    silently adds `updated_at` to the SET clause, which would turn the user
    record's "last edited" stamp into a second "last seen" stamp. A test caught
    that, not review.
  - Accounts not seen since the column existed render **"Never"** — the rule
    `clinic_visits.course` (D-43) and `appointments.scheduled_time` (D-37)
    already follow. **Known limitation:** the stamp tracks requests, so a
    browser left open on the auto-polling Live Queue keeps a nurse looking
    active. At the day-level granularity the column exists for, that changes
    no answer.
  - 7 cases in `tests/Feature/Auth/RecordLastActiveTest.php`.

* **A College Activity Log, so two admins in one college can see each other's
  work** (D-49, FR-ADM-10). **No schema change and no new table.** A college can
  have more than one College Admin — nothing has ever constrained
  `users.managed_college_id` to a single account, and the new Staff Accounts
  screen makes a second one a two-minute job. Neither could see what the other
  had submitted, and the Director's approvals and rejections left no trace
  anywhere the college could read as history.
  - **New `/admin/activity`** ("Activity Log" in the College Admin sidebar,
    `Admin\ActivityLogController` + `admin/activity.blade.php`): each batch
    **submission** (who, when, student count, reason) and each **Director
    decision** — approved with the date appointments were generated for, or
    rejected carrying the Director's written D-36 reason. Newest first, 15 per
    page, each row linking to the batch.
  - **Derived, not logged — the point of the whole design.** There is no audit
    table and nothing writes a log row. Every entry is read back out of
    `batch_requests`, which already stores `requested_by`/`created_at` and
    `reviewed_by`/`reviewed_at`/`rejection_reason`, so **the log cannot drift
    from the rows Batch Tracking renders**. An `activity_logs` table was
    considered and rejected: an 11th table in a canon D-32 had just brought back
    to 10, a write path on every action that a later edit can forget, and a
    record able to disagree with the data it describes.
  - **Flattened in PHP, not as a SQL UNION.** A UNION of submission and decision
    rows means raw SQL, and the suite runs on SQLite while dev and prod are
    MySQL. A college's batch history is tens of rows, so there is nothing to buy
    by risking that drift. Sorting is tie-broken so a decision outranks the
    submission it decided when both share a timestamp.
  - Scope is the standard `/admin` rule: `managedCollege()`, with `?college=`
    never read — asserted by test, alongside a case proving one college cannot
    see another's entries.
  - **Deliberately excluded**, each for a reason: appointment withdrawals
    (`appointments` has no `cancelled_by`, so the actor cannot be named and
    would have to be guessed from `updated_at`); student self-bookings (by far
    the highest-volume event, which would bury the admin actions the page
    exists to surface); page views and other reads (a coordination log is not
    surveillance, and read-logging a health system creates its own privacy
    problem); and anything clinical, since a College Admin must never see vitals
    or Fit/Unfit.
  - 14 cases in `tests/Feature/Admin/ActivityLogPageTest.php`.

* **Staff accounts are announced by email, and a transferred admin is told
  twice** (D-50, extends FR-AUTH-10). **No schema change, no new table, no new
  package.** A provisioned account used to be invisible until somebody
  remembered to phone the person, and an admin moved between colleges just
  watched their students vanish with no explanation on screen.
  - **Welcome email on creation** (`StaffAccountCreatedMail`): role, college,
    and a sign-in link built from `route('login')` — so it follows `APP_URL` and
    needs **no edit of its own** when the real domain is set on deployment day.
  - **It carries no password, and says so.** The one-time credential is shown on
    the Director's screen once and handed over in person (D-35, D-47); putting
    it in a mailbox would undo that. Stating the rule in the message is what
    makes a later "HealthPass needs your password" mail obviously fake. A test
    asserts the issued password does not appear in the rendered email.
  - **Transfer email** (`StaffTransferredMail`) naming the college left and the
    college gained. Both are **fixed at dispatch**, not re-read when the worker
    runs, so if the same admin is moved again while the first job is still
    queued each message still describes the move it was sent for.
  - **A one-time dialog on the transferred admin's next dashboard visit**, same
    modal UI as Log out. Held in the **cache** (`App\Support\TransferNotice`)
    and read with `Cache::pull()`, which returns and clears it in one step so it
    cannot reappear on a refresh. Cache rather than a column on purpose: the
    notice must outlive the request that created it, but it is a courtesy nudge
    and not a record — the email is the durable one, so losing it to a
    `cache:clear` costs nothing and it does not deserve a schema change.
  - Both jobs share a new **`StaffMailJob`** base — retry policy (3 tries,
    60s/300s), `deleteWhenMissingModels`, recipient resolved from the User
    record and never from a request, PII-free failure logging. The staff-side
    twin of `AppointmentMailJob`, deliberately separate because that one is
    built around an Appointment. As on D-41 the shared property is **not**
    `readonly`, or the job throws the moment the queue rehydrates it.
  - **Nothing is emailed when the action did not happen:** a rejected creation,
    a refused transfer, and a move to the college the admin is already on all
    email nobody, each asserted by test.
  - `StaffTransferredMail`'s properties are `$fromCollege`/`$toCollege`, never
    `$from`/`$to` — `Illuminate\Mail\Mailable` already declares both and
    redeclaring them is a fatal error.
  - 15 cases in `tests/Feature/Director/StaffNotificationTest.php`.
  - **Not built, by Nat's decision:** logging a transfer in both colleges'
    Activity Logs. It cannot be derived — `users.managed_college_id` is
    overwritten in place, so the origin college id is gone the moment the
    transfer happens — and would have needed either an 11th table or lossy
    columns on `users` holding only the most recent move. He chose to drop the
    feature rather than pay that; D-49's derived Activity Log is unchanged.

* **A College Admin can cancel their own batch request — while it is still
  pending** (D-52, FR-ADM-11, BR-24). **Schema change: `batch_requests.status`
  gains a fourth value `cancelled`, plus `cancelled_at` (TIMESTAMP, nullable)
  and `cancelled_by` (FK → users, nullable) — no new table, the canon stays at
  10, and neither column is ever backfilled.** A college whose cohort event
  moved had exactly one way out: ask the Director to **reject** the batch. That
  recorded the college's own change of mind as a Director's rejection, on the
  college's permanent record, with a written reason somebody had to invent.
  - **New `DELETE /admin/batches/{batch}/cancel`**
    (`Admin\BatchRequestController@cancel`) with **its own `batch-cancel`
    throttle prefix** — an authenticated inline throttle keys on the user id
    with no path, so without it this would have shared one counter with batch
    submission. Batch Tracking grows a **blank-header trailing column**,
    rendered only when at least one row is cancellable (the same "no column of
    em dashes" rule the D-36 Rejection Reason column uses), holding a Cancel
    control on **pending rows only** and a teleported confirm dialog naming the
    reference, the student count and the requested clinic date.
  - **Pending-only is the design, not an omission.** Approval fans out one
    appointment per student and emails every one of them (BR-08, FR-STU-12);
    cancelling a whole batch after that would be a silent mass-cancellation of
    seats students have already been told to attend. That case already has a
    deliberate one-at-a-time answer in FR-ADM-07 (D-40), which frees one seat
    and emails one student. Extending cancel past `pending` was considered and
    **rejected**.
  - **Two columns rather than none, because the Activity Log is derived.**
    D-49 writes no audit row, so a cancellation reaches that timeline only if
    the row itself carries an actor and a timestamp. Reusing
    `reviewed_by`/`reviewed_at` was rejected — they mean "the Director decided"
    in the Approvals list, the Activity Log and the rejection modal, and
    overloading them would credit the Director with an action they never took.
    Deriving the actor from `requested_by` was also rejected: since D-47 a
    college can have two admins, so the log would routinely name the wrong
    person. `cancelled_by` is **whoever pressed the button**.
  - `BatchRequest::isCancellable()` is the single rule — the view draws the
    control from it and the endpoint re-reads it **under `lockForUpdate()`**, so
    a double-click, or a race with the Director approving in the next tab, is
    refused rather than raced.
  - **The Director's endpoints needed no logic change** — `approve()` and
    `reject()` already refuse a non-pending row under the same lock. Only their
    wording did: the Approvals list rendered "✕ Rejected" for anything
    non-pending, so a cancelled batch would have read as a Director rejection,
    and the refusal message said "already been decided". Both are now
    status-aware ("↩ Cancelled by college").
  - 20 cases in `tests/Feature/Admin/BatchCancelTest.php`.

* **The batch roster now shows each student's clearance result** (D-53,
  FR-ADM-07 amended). **No schema change, no new route, no new page** — the
  roster of D-40 grows two columns. **This required amending PRD §6.6**, which
  read "admins see only their college's *roster and batch* data (never clinical
  results)". A separate "Batch Results" page was considered and **rejected**: it
  would have duplicated the roster's student table, and the date, hour span and
  purpose it needed were already in the roster's header.
  - **Result** (Fit / Unfit, em dash until the nurse encodes) and a real
    **Status**: *Not yet attended · At the clinic · Completed · Did not
    attend · Withdrawn*. The summary card gains an explicit **Purpose** field
    and a roll-up — *"2 Fit · 1 Unfit · 1 still to attend"*.
  - **`appointments.status` could not drive the Status column.** The kiosk
    *links* a `clinic_visits` row to the appointment but leaves the status
    `scheduled` (`SubmitKioskVisit`), and only the nurse's encode sets
    `completed` — so a student standing at the kiosk would have rendered as
    "not yet attended", which is exactly the question the page exists to
    answer. `Appointment::clearanceProgress()` reads the visit and its
    clearance record; `clearanceResult()` returns the outcome. Both live on the
    model, so the row and the roll-up share one definition and cannot disagree.
    `checked_in` is not consulted — nothing in the app writes it.
  - **The §6.6 exception is one field wide.** Fit/Unfit, for a student on a
    batch the admin's own college submitted. Vitals, screening answers, nurse
    notes, the physician's details and the clinic-visit reference are not on the
    page and behind no link on it; the Activity Log (FR-ADM-10) stays
    clinical-free entirely. The justification: the college is the party that
    **requested** the clearance and cannot discharge that duty without knowing
    who was cleared — withholding it pushed the answer onto paper and
    side-channels, a worse privacy posture than one enum value behind a scoped,
    authenticated, college-filtered page. D-45 had already opened §6.6 at the
    aggregate level; §6.6 now states the full boundary in one place.
  - 12 cases in `tests/Feature/Admin/BatchResultsTest.php`.

* **`DemoBatchSeeder` — six CCS batches covering every state** (dev only).
  Two pending (cancellable), one approved on a past clinic day carrying all
  four outcomes at once (Fit, Unfit, at the clinic, did not attend), one
  approved upcoming with a withdrawn seat, one rejected with a written reason,
  one already cancelled. Deterministic, idempotent, and it runs **after**
  `DemoClinicVisitSeeder` on purpose — that seeder skips itself if any
  `APT-2026-9xxx` row exists, so this one reserves the `APT-2026-85xx` /
  `HP-2026-85xx` bands instead. 9 cases in
  `tests/Feature/Seeders/DemoBatchSeederTest.php`.

### Changed

* **The Director can no longer reset a staff account's password** (D-47a). The
  button, the `POST /director/staff/{user}/password` route and the controller
  method are gone, and `StaffAccountController` now generates a password in
  exactly one place — `store()`. A Director who can set an existing account's
  password can sign in as that person and read their medical records, which is
  precisely the impersonation D-47's own scope list rules out; keeping the
  button left a supported path to it. A staff member who forgets their password
  now uses the ordinary **forgot-password OTP** (FR-AUTH-09), which only their
  own mailbox can complete. Tests assert the route is absent and that the page
  offers no such control.

* **Reassigning a College Admin's college is confirm-on-change, using the app's
  own dialog** (D-47a). The Save button beside the dropdown read as an unclear
  second step. Choosing a college now opens a confirmation and submits on
  accept; **Cancel, Esc and a backdrop click all put the dropdown back**, so the
  control never shows a college the admin is not actually assigned to — the
  browser has already moved the visible selection by the time `change` fires, so
  every dismissal path has to revert it.
  - The dialog is the **same teleported Alpine modal `x-logout-confirm` uses**,
    extracted as `resources/views/components/college-reassign-confirm.blade.php`
    — same backdrop, same spring-in panel, same button pair. It replaces a
    native `window.confirm()`, which was unstyled, ignored the design system,
    and could not be dismissed by backdrop or Esc.
  - The form lives **inside** the modal rather than around the select, mirroring
    how the logout dialog holds its own POST form, so the confirm button is a
    real submit button and nothing reaches across the teleport to submit.
  - The target college's name is read at runtime from the chosen option's
    `data-name` and never interpolated into a JavaScript string — an apostrophe
    ("O'Brien") would break the literal and silently skip the guard, the same
    trap already noted on the kiosk-devices Revoke button.

### Fixed

* **Deactivating an account now ends the session it is already using**
  (FR-AUTH-07, amended by D-47). `users.status` was read in exactly one place —
  `LoginRequest::authenticate()` — so deactivation blocked the *next login* and
  nothing else: a user who was signed in when they were revoked kept full access
  until their session expired on its own, up to `SESSION_LIFETIME` (two hours by
  default). Harmless while deactivation meant editing the database by hand;
  not harmless now that the Director has a **Deactivate** button and it is the
  system's only form of removal.
  - New **`App\Http\Middleware\EnsureAccountIsActive`**, appended to the **web
    group** so no route can forget it, and ordered **ahead of
    `RequirePasswordChange`** — an inactive account is turned away rather than
    invited to set a new password. It logs the user out, invalidates the
    session and issues a fresh CSRF token (the same three steps Breeze's own
    logout takes), then returns them to the login page.
  - The login page gained a **red flash** for `session('error')`; it previously
    rendered only the green `session('status')`, and "your account is inactive"
    in the success style would have read as reassurance. The wording is the one
    `LoginRequest` already uses, so both paths say the same thing.
  - **JSON requests get a 403, not a redirect** — the Live Queue polls every
    four seconds and must never be handed an HTML login page to parse. Same
    rule `RequirePasswordChange` follows.
  - 8 cases in `tests/Feature/Auth/EnsureAccountIsActiveTest.php` (every role
    unaffected while active, guests untouched, student and staff sessions cut,
    the JSON branch, and inactive winning over `must_change_password`), plus a
    regression case in `StaffAccountTest` covering the Director revoking an
    admin who is signed in at that moment.

* **Dropdown arrows are no longer hidden behind long option labels**
  (`x-hp.select`, D-47a). The browser draws the arrow inside the select's own
  padding box, so with equal `px-3` padding a label like "CCS — College of
  Computing Studies" ran underneath it and the arrow could not be seen. Now
  `pl-3 pr-9`, which reserves the arrow its own lane. Affects every dropdown in
  the app, not only Staff Accounts.

* **Printing no longer opens a new tab** (FR-NRS-05, FR-ADM-09). The hidden
  print frame + arming script that `nurse/encode.blade.php` has used all along
  moved into a shared **`resources/views/partials/print-frame.blade.php`**, and
  the **Nurse Dashboard's Reprint** switched from `target="_blank"` to posting
  into that frame. Reprint still POSTs (it re-stamps `printed_at`), it just
  prints where you are — the nurse keeps their filters, their search and their
  page in the encode history. The encode screen keeps its own copy of the
  script: it also flips a hidden "printed" field belonging to Save & Close, and
  forking *that* is what the partial exists to avoid, not to cause. A print
  document now self-fires `window.print()` **only when it is the top window**,
  so a middle-clicked link still prints while the framed copy leaves the dialog
  to its parent.

* **A printable Monthly Clinic Report for College Admins, and the Director's
  by-College card drops to programs on a single college** (D-46, FR-ADM-09;
  FR-ANL-09/13 amended). **No schema change, no new package, no CSV** —
  FR-ANL-06's export stays struck by D-32.
  - **New `GET /admin/analytics/print`** (`Admin\MonthlyReportController`)
    renders `resources/views/admin/monthly-report.blade.php`: a standalone
    Blade document with no app shell — the same pattern the clearance form
    already uses (Module PRT). A **Print Monthly Report** button on
    `/admin/analytics` carries the current month and program filters into the
    URL and **prints in place**: the report loads into a hidden print frame and
    the dialog opens on the Analytics page, with **no new tab**. The browser's
    print dialog is the preview, so there was never anything for a tab to show.
  - **Tables, not charts.** Chart.js canvases print unreliably and a bar
    without a number on it is useless on paper, so every section is a table:
    summary line, Clinic Visits by Program (zero-visit programs included, with
    a totals row), Visits by Purpose, Vital-Sign Flags with counts and rates,
    BMI Distribution, and Students Screened by Sex with percentages.
  - The header carries the university, the college's full name and code, the
    month written out, and a **generated-at timestamp with the name of the
    admin who generated it**; the footer states the report covers **data
    captured by HealthPass only**. A filtered report **says** it is filtered.
  - **The flag captions come from `config('healthpass.thresholds')`** by way of
    the service — 140/90 and 37.2 are not restated in the print view.
  - **Permanently light**: the report is white paper.
  - **Same scope rule as the page it prints.** The college is
    `managedCollege()`; `?college=` is never read, so there is nothing for a
    hand-edited print URL to override (FR-AUTH-06 / FR-ADM-06). The program
    filter is checked against the *managed* college's catalog (D-42). Both the
    page and the report are built by the same `App\Services\ClinicAnalytics`
    under the same scope, so **the printout cannot quote a different number
    than the screen it came from** — a test asserts the two match key for key.
  - **Director analytics:** filtering to a single college now swaps *Clinic
    Visits by College* for *Clinic Visits by Program* for that college, through
    the existing `ClinicAnalytics::visitsByProgram()` — no new query logic. A
    single-college bar chart compares nothing; its programs are the breakdown
    the filter implies. "All colleges" leaves the card exactly as it was, and
    **no existing assertion in `Director/AnalyticsPageTest.php` was edited**.

* **College Admin Analytics, per program — and the analytics queries become a
  shared service** (D-45, FR-ADM-08). **No schema change.** A college can ask
  for a monthly report of its own students' clinic results; that lands here as
  analytics on the College Admin side, not as a new report format.
  - **`App\Services\ClinicAnalytics`** now owns all six card builders. They
    were moved out of `Director\AnalyticsController` (~390 lines, now ~65 and
    doing nothing but resolving `?month=` and `?college=`), not copied — two
    sets of the same aggregate queries would have drifted the first time a
    rule changed, and the two roles would then quote different numbers off the
    same database.
  - **The extraction's acceptance bar was that
    `tests/Feature/Director/AnalyticsPageTest.php` passed COMPLETELY UNEDITED**,
    which it did. It is untouched in this change; needing to edit it would have
    meant behaviour had moved.
  - **New page `/admin/analytics`** with the same six cards, **Clinic Visits by
    College replaced by Clinic Visits by Program**: one row per program the
    college offers, split Medical / Dental, sorted by volume with an
    alphabetical tie-break, **zero-visit programs included**.
  - **Medical reads the D-43 `clinic_visits.course` snapshot**, so re-running
    August's report in October cannot move a student's visit to the program
    they shifted to in September. Dental has no `clinic_visits` row and so
    attributes through the current profile — the same stated limitation dental
    already carries for college.
  - Visits whose snapshot is NULL (pre-D-43, never backfilled) land in a
    trailing **"Not specified"** row instead of being dropped, so the card's
    headline can never disagree with the other cards on the page.
  - **The college is never read from the request.** There is no `?college=`
    handling on this page — not validated, not rejected, simply never
    consulted — so there is nothing for it to override (FR-AUTH-06 /
    FR-ADM-06). The scope is fixed on the service at construction from
    `managedCollege()`. `?program=` is checked against the *managed* college's
    catalog (D-42), so another college's program is refused like a made-up one.
  - Two deliberate departures from a pure mirror of the Director page, both
    about scope: the FR-ANL-11 trend still ignores the month but **stays inside
    the college** (a clinic-wide aggregate is still other colleges' numbers),
    and the month picker offers only months **this** college has data in.
    `VisitMonths::available()`/`resolve()` gained an optional `?College` for
    that; the Director and Nurse call sites are unchanged.
  - The Chart.js bundle moved `resources/js/director/analytics.js` ->
    `resources/js/analytics.js` (both pages use it now) and its stacked-bar
    hook from `[data-college-bar]` to `[data-visits-bar]`. Program names are
    wrapped server-side into multi-line axis ticks; nothing else changed.
  - Month bounds stay Carbon, never `MONTH()`/`DATE_FORMAT` — the suite runs on
    SQLite while dev/prod is MySQL.

* **Nurse Dashboard — the nurse's encode log finally has a home** (D-44,
  FR-NRS-09). **No schema change.** The nurse was the only role without a
  history surface: once a visit is encoded it leaves the Live Queue
  (`scopeLiveQueue` only sees `captured`), so after a clinic day there was no
  way to see what had been encoded short of typing each URL.
  - **New page `/nurse/dashboard`** — four stat tiles (encoded today, encoded
    this month, awaiting encode, flagged vitals this month) above the encode
    history. One page, not a dashboard-plus-history pair.
  - **The history is CLINIC-WIDE, not per-nurse**, and every row names its
    encoder. Two nurses on shift must read one continuous log; "who encoded
    this" is answered by a column, not by hiding rows.
  - Columns: reference no., student, capture-time college, the **D-43 program
    snapshot** ("—" when null), Fit/Unfit, encoded by, encoded at, printed.
  - Filters are GET (`month`, `result`, `q`) so a filtered view is shareable,
    and `withQueryString()` carries them through the pager (15 rows a page).
    The month list reuses `App\Support\VisitMonths`; a month the picker does
    not offer degrades to "all months" rather than erroring.
  - **Rows reuse what already exists — no new detail page.** View opens the
    encode screen, which already renders read-only for an encoded visit;
    Reprint posts to the existing reprint route (in a new tab: the hidden print
    iframe and its script belong to the encode page, and duplicating them would
    fork the print flow).
  - **The dashboard is now the nurse's home** (`EnsureRole`). Live Queue is
    unchanged, still first in reach from the nav, and Save & Close still
    returns there so the reverse-stack ghost row is untouched.
  - Month bounds are Carbon, never `MONTH()`/`DATE_FORMAT` — the suite runs on
    SQLite while dev/prod is MySQL.

* **Senior High School removed; clinic visits now snapshot the student's
  program** (D-43). **SCHEMA CHANGE: `clinic_visits.course` VARCHAR(120) NULL.**
  Two consequences of D-42 making programs real data.
  - **SHS is gone — 12 colleges become 11.** It is not on the PSU main-campus
    (bicolor) program offerings, so it is not a unit HealthPass serves. Removed
    from `CollegeSeeder`, its administrator account from `StaffSeeder`, its two
    students from `StudentSeeder` (demo cohort 30 → 28), and its visit weight
    from `DemoClinicVisitSeeder`. Seeded college admins drop 12 → 11.
    `admin.shs@healthpass.test`, `jessa.chua@psu.edu.ph` and
    `renz.ong@psu.edu.ph` no longer exist — `docs/dev-notes.md` refreshed.
  - **New `clinic_visits.course`** — the student's program frozen at kiosk
    submit, beside the `college_id` snapshot D-17 already added.
    `student_profiles.course` stays live, so without this one program shift
    would silently restate every past per-program report. **Nullable and never
    backfilled:** a visit captured before this has no honest answer and renders
    as "—", the same pattern `appointments.scheduled_time` uses for pre-D-37
    rows.
  - `SubmitKioskVisit` reads college and program in **one** query from the
    **server-side bound student**, never the request body. A missing college
    still fails loudly — the visit could not be attributed at all — but a
    missing program stores NULL rather than turning away the person standing at
    the kiosk.
  - **Fixed a live bug found on the way.** The app stores `year_level` as a KEY
    (`'1'`…`'5'`, `'7'`…`'10'`) and validates against that set, but the seeder
    and factory wrote DISPLAY LABELS (`'3rd Year'`, `'Grade 11'`). Nothing
    re-validates a profile on read, so every seeded student hit "Please select a
    valid year level" the first time they saved their own profile — during a
    demo, most likely. Both now write keys.
  - **`StudentProfileFactory` drops its hand-written course/year arrays** and
    reads `config/programs.php`, so the forms, the factory and the seeders share
    one catalog. Those arrays had already drifted: they put a CAS student on
    Psychology, a CSSP program.
  - Nothing reads the new column yet — populating it is the whole job; the
    per-program reporting comes later.

* **Academic programs are now a validated, college-dependent catalog**
  (D-42 — FR-REG-03, FR-STU-09). **No schema change.** The adviser needs
  monthly clinic reports broken down per academic program, and
  `student_profiles.course` was free text validated only with `max:120` —
  "BSIT", "BS Info Tech" and "Bachelor of Science in Information Technology"
  are three programs to a `GROUP BY` and one to a human.
  - **New `config/programs.php`** — the catalog, keyed by college code (so it
    survives a reseed that renumbers college ids), holding each college's
    program list and its year levels as storage-key ⇒ display-label. A config
    file rather than a table: there is no admin CRUD requirement for programs,
    and this keeps the canonical schema at 10 tables. No migration, no foreign
    key, no backfill.
  - **New `App\Support\Programs`** — the one bridge from a college **id** (what
    forms post) to the catalog's college **code**. Never throws: an unknown
    college returns an empty list, so a hand-edited form fails validation
    instead of 500ing.
  - **Registration Step 2 and the My ID & Profile edit modal** replace the
    Course text input with a **Program** select, disabled until a College is
    chosen and repopulated when it changes. **Year Level now comes from the
    same per-college map** instead of a hardcoded 1–5 list, so Graduate Studies
    offers years 1–2 and Laboratory High School shows Grades 7–10. Alpine drives
    the cascade — no new package.
  - **The server is the authority.** Both Form Requests validate `course` and
    `year_level` against the college **actually submitted**, so a POST naming
    another college's program is rejected; a transfer that keeps the old
    college's program fails until the program changes too. A missing or invalid
    college reports "Select your college first, then choose your program."
    rather than a bare "selected course is invalid".
  - The column keeps the name `course` — the official form
    DHVSU-QSP-OSS-004-FO002-R03 prints "Course, Year & Section" (D-25) — while
    the UI labels it "Program". Majors are flat entries in one dropdown, not a
    second cascade level.

* **A withdrawn student is now emailed** (D-41 — FR-STU-13).
  **No schema change.** This closes the last hole in the chain: D-39 emailed
  students when their college booked them and stopped them cancelling a batch
  appointment themselves; D-40 gave the College Admin the withdraw action — but
  **nothing told the student when it fired**, so a cancelled student would still
  have turned up to a seat that no longer existed.
  - The notice names the cancelled date, one-hour slot, service and reference,
    identifies the college that withdrew it, and gives a route back to the
    college administrator if it was a mistake.
  - **It points the student at self-booking.** A withdrawn student is an
    ordinary student again, so they need not wait for their college to arrange
    another batch — this is what stops a withdrawal quietly becoming a lost
    clearance. Omitted once the clinic date has passed.
  - **Only a withdrawal that actually happened emails anyone.** A refusal
    (already checked in, completed, past-dated, or a duplicate submit) returns
    before the dispatch; asserted by test.
  - **A self-booked cancellation deliberately sends nothing** — the student
    performed that action themselves and an email confirming their own click is
    noise.
  - Queued and dispatched **after the withdrawal transaction commits**, on
    exactly the terms D-39 set, so a mail failure cannot roll back the
    withdrawal. Carries no clearance outcome, Fit/Unfit, vitals or
    questionnaire data (FR-STU-08).

* **College Admins can withdraw a student's appointment from an approved
  batch, and batches now have a roster page** (D-40 — FR-ADM-07).
  **No schema change.** This closes the gap D-39 opened: that change told batch
  students to contact their college admin to cancel, but **no such capability
  existed anywhere in the app** — Batch Tracking showed a student *count* and
  nothing else, and there was no cancel route on the admin side at all.
  - **Batch roster page** — clicking a batch reference on Batch Tracking now
    opens `/admin/batches/{batch}`, listing every student on the batch and,
    once the Director has approved it, their appointment reference, one-hour
    slot and status. There was previously no way to see who was in a batch.
  - **Withdraw one student.** The appointment flips to `cancelled`, and that
    alone **frees the hour-seat** — every capacity count in the app (daily cap,
    the D-37 per-hour cap, the calendar's full-day roll-up) already filters
    `status != 'cancelled'`, so no counter is maintained and none can drift.
    Covered by a test that fills an hour to capacity, withdraws one student,
    and books a self-booking student into the freed seat.
  - **The row is kept, not deleted**, and the pivot's `appointment_id` is
    retained, so a withdrawn student stays visible in the batch's history
    rather than becoming indistinguishable from one never submitted.
  - **Guards:** `scheduled` status only — a `checked_in` or `completed`
    appointment may already back a `clinic_visits` row, and cancelling it would
    contradict a real clinic encounter — and not a past date. **Today is still
    withdrawable**, deliberately unlike the student-side rule, because "phoned
    in sick this morning" is the commonest reason a seat needs freeing.
    `Appointment::isAdminCancellable()` is the single rule the view and the
    endpoint share, re-read under `lockForUpdate()` so a duplicate submit or a
    race with kiosk check-in cannot slip past the page's unlocked read.
  - **Scope** follows FR-ADM-06: the batch is fetched through
    `managedCollege()->batchRequests()` and the appointment must belong to it,
    so a foreign batch id *or* a foreign appointment id is a plain 404.
  - Deliberately **one student at a time** — that is the case the appointment
    email generates. Cancelling a whole approved cohort is not built.

* **Students are emailed when an appointment is created for them**
  (D-39 — FR-STU-12 added, FR-STU-06 amended). **No schema change.**
  Until now a student booked into a batch by their College Admin had **no
  notification of any kind** — the first they would learn of the appointment
  was failing to turn up for it. Both creation paths now send a scheduling
  notice: the Director's batch approval fan-out (one email per student) and
  the student's own booking, so the inbox experience is consistent.
  - **What the email contains:** name, reference number (`APT-YYYY-####`),
    service (Medical/Dental), the date and the **one-hour slot** ("9:00 AM –
    10:00 AM", D-37), the purpose, who booked it (self, or the college by
    name), the clinic location, what to bring, a Kiosk Tutorial pointer, and
    cancellation guidance. It is readable without logging in.
  - **What it deliberately does not contain:** any clearance outcome,
    Fit/Unfit status, vitals, or questionnaire answers. This is a scheduling
    notice; results reach the student through My Records after nurse encoding
    (FR-STU-08).
  - **Queued, and dispatched only after the transaction commits.** One
    `App\Mail\AppointmentScheduledMail` sent through one queued job **per
    student** (`App\Jobs\SendAppointmentScheduledMail`, 3 tries, 60s/300s
    backoff). Approving a 60-student batch costs the Director 60 INSERTs, not
    60 SMTP round-trips, and **a mail failure can no longer roll back real
    appointments** — dispatching inside the transaction would have made an
    SMTP outage into lost bookings. One job per student rather than one job
    looping the roster, so a single undeliverable address loses one message
    instead of 59. The job re-checks the appointment is still `scheduled` at
    send time, so a student who cancels while the job waits is not then told
    it is confirmed.
  - **Rate limiting:** none added, and no package installed. A single
    `queue:work` process consumes jobs serially, which is itself the send-rate
    ceiling — documented in `docs/deployment-hosted.md` so nobody "optimises"
    it by raising `numprocs`.
  - **⚠ Deployment:** these are queued jobs, so **without a running
    `php artisan queue:work` nothing is ever sent** — no error, no log line,
    just a `jobs` table that grows. Supervisor config and a go-live checklist
    item are in `docs/deployment-hosted.md` §3.
  - `healthpass.clinic_location` added as a config value (the clinic can move;
    the kiosk already has). `MAIL_*` guidance added to `.env.example`; the
    `MAIL_MAILER=log` dev default is unchanged.

* **Appointments now have a time, and capacity is enforced per hour**
  (D-37 — BR-01/BR-02/BR-04 amended, BR-21/BR-22 added, FR-STU-03/04,
  FR-ADM-04 and FR-DIRA-02 amended).
  The clinic day is **ten bookable one-hour slots** — 7–8 AM through 4–5 PM,
  **the lunch hour included** — derived at runtime from
  `healthpass.clinic_hours` by the new `App\Services\ClinicScheduleService`,
  so the grid is never hardcoded in a view. A kiosk session takes at most
  five minutes, so a slot holds **12** students: `hourly_capacity` = 12 and
  `daily_capacity` **raised from 40 to 120**. Both stay config values.
  **Medical and dental share one counter** — a dental appointment consumes an
  hour-seat exactly like a medical one, because what is being capped is
  clinic congestion, not kiosk throughput.
  - **Students** pick a one-hour slot after picking a date. Each slot shows
    its remaining seats, a full slot is disabled, and a date whose every hour
    is full is greyed out in the calendar just as a capacity-full day is. The
    existing availability endpoint was **extended** (no new route) to return
    per-slot `booked`/`remaining`/`full` for a given date.
  - **College Admins** pick a **start hour**; the system computes the span as
    `ceil(students ÷ 12)` contiguous hours and shows it back live — *"30
    students → 7:00 AM – 10:00 AM (3 slots)"*. Submission is blocked when any
    hour in the span is full (**the error names the hour**), when the span
    would run past 5 PM, or when the roster exceeds 120 (which cannot fit in
    one clinic day). The span **length is always derived server-side**, so a
    posted `requested_blocks` is inert.
  - **Directors** confirm the span read-only alongside the date, and the
    fan-out writes `scheduled_time` onto every generated appointment — 12 per
    hour in pivot-row id order, the last block taking the remainder.
  - Capacity is checked in the Form Request **and re-checked under
    `lockForUpdate()` inside the write transaction**, for both self-booking
    and batch submission: the Form Request's read is unlocked and races with a
    concurrent booking for the last seat in an hour. A feature test injects a
    competing booking at the exact moment that unlocked read returns and
    asserts only one booking wins.
  - Schema (flagged): `appointments.scheduled_time` TIME NULL plus
    `index(scheduled_date, scheduled_time, status)`;
    `batch_requests.requested_time` TIME NULL and `requested_blocks`
    TINYINT NULL — span **start + block count, never an end time and never
    both**. Slot keys are the canonical string `'07:00:00'` everywhere (select
    value, DB column, WHERE clause), because MySQL TIME and SQLite TEXT
    round-trip that form identically.
  - **An hour that has already ended cannot be booked on today** (BR-23). A
    slot dies when it **ends**, not when it starts: at 12:00 the 11 AM–12 PM
    hour is gone and 12–1 PM is the earliest still available — which keeps
    this consistent with BR-20's existing "today is bookable right up to
    16:59" rule. It binds all three write paths: the **student** slot
    picker (greyed and labelled "Past", distinct from "Full"), the **College
    Admin's** batch start hour (only the start needs checking at submission —
    a span runs forwards), and **Director approval**, where any elapsed hour in
    the batch's span refuses the approval. The Director case matters because
    D-36 still permits approving a batch requested for *today*, so its hours
    can end between submission and review; fanning out then would create
    appointments for a time already gone. A partially-elapsed span is refused
    too — some of the cohort would land in the past, and a same-day batch whose
    span is still ahead approves normally. Only today is
    affected; a future date has no elapsed hours. Decided on the server clock
    and shipped to both pages as data, **never computed in the browser**, the
    same rule BR-20 already follows. A date whose remaining hours have all
    elapsed greys out in the calendar, which makes BR-20's after-closing case
    fall out naturally. Deliberately **not** re-checked under lock, unlike
    capacity: no concurrent request can change whether an hour has ended.
  - **Pre-D-37 appointments were deliberately not backfilled.** They keep
    `scheduled_time` NULL, render as "—", are invisible to the per-slot
    counters, and are still counted by the daily cap — which is why the daily
    cap was kept alongside the hourly one. Backfilling them to 07:00 would
    have invented twelve fake bookings in the first hour of every past clinic
    day.

### Changed

* **The two appointment notices now share one queued-job base class**
  (D-41). `App\Jobs\AppointmentMailJob` holds the retry policy (3 tries,
  60s/300s backoff), `deleteWhenMissingModels`, recipient resolution from the
  User record, and PII-free failure logging; `SendAppointmentScheduledMail` and
  `SendAppointmentWithdrawnMail` each supply only their Mailable, their
  still-relevant check and a log label. Extracted rather than copy-pasted
  because the retry policy and the "no PII in logs" rule are exactly what drifts
  between two near-identical files and then only fails in production.
  > **Gotcha worth knowing:** the shared `$appointment` property must **not** be
  > `readonly`. PHP only lets a readonly property be initialized from the class
  > that declares it, and the queue rehydrates a job onto an instance of the
  > *subclass* — so a readonly property throws
  > `Cannot initialize readonly property … from scope` the moment the job
  > round-trips through the queue.

* **Batch-booked appointments can no longer be cancelled by the student**
  (D-39 — FR-STU-06 amended). Found while writing the email above: the
  cancel endpoint guarded ownership, `scheduled` status and a future date, but
  **not `source`** — so a student could quietly drop out of a cohort their
  College Admin had booked, leaving the college's roster wrong with nobody
  told. A batch appointment now belongs to the college that booked it; the
  dashboard and confirmation views show "Booked by your college — contact your
  college administrator" in place of the cancel button, and the endpoint
  returns 403. `Appointment::isSelfCancellable()` is the single rule both
  read, so the button and the server cannot disagree. Self-booked
  appointments are unchanged — still cancellable up to the day before.
  > The College Admin side of this — the roster page and the withdraw action
  > that makes "contact your college administrator" actually actionable — was
  > **missing when this shipped and is now covered by D-40 / FR-ADM-07 above.**

* **Batch approval capacity is now a HARD BLOCK, not a warning** (D-37,
  amending FR-DIRA-06). If any one-hour slot in a batch's span has reached the
  hourly cap when the Director opens *or* submits the approval, approval is
  refused — the Approve button is disabled with the offending hour named, and
  the endpoint refuses the POST after re-reading the counts under lock. The
  Director is directed to reject with a reason so the college can resubmit.
  **Stated plainly: combined with D-36's confirm-only approval, a batch whose
  slots filled up between submission and review has exactly one outcome —
  rejection and resubmission. That is the intended workflow, not a bug.** A
  batch submitted before D-37 (no `requested_time`) is a third un-approvable
  case alongside D-36's two, with the same remedy.

* **Batch approval is now confirm-only** (D-36, FR-DIRA-02 — **supersedes the
  "the Director may adjust the date" clause of D-29**). The Director's approve
  modal no longer has a date picker: it shows the College Admin's requested
  clinic date read-only, and the batch is approved on that date. The date is
  read from the `batch_requests` row **locked inside the approval transaction**,
  never from the request — `ApproveBatchRequest` now accepts no input at all, so
  a `scheduled_date` posted by a client is ignored.
  Two kinds of batch **cannot be confirm-approved at all**, because neither
  leaves a date to confirm — Approve is disabled on the row with a one-line
  notice *and* the endpoint refuses the POST, so the UI is never the gate:
  batches submitted before D-29, which have no requested date (reject with
  "predates the requested-date field — please resubmit"), and batches whose
  **requested date passed while they sat pending**, where approving would
  schedule the cohort in the past. A batch requested for *today* is still
  confirmable; the staleness rule lives in one place,
  `BatchRequest::hasStaleRequestedDate()`, used by both the view and the
  endpoint's locked-row check. *(Superseded in part by D-37 above: capacity at
  approval is no longer a warning, and a batch with no hour span is a third
  case that cannot be approved.)*
  When a Director can't take the requested date, the move is now to **reject
  with a reason** ("date unavailable, please resubmit for …") rather than
  silently shifting the cohort — the original request survives, the objection is
  on the record, and the college resubmits for a date it has agreed to. The
  FR-DIRA-06 capacity **warning** was unchanged by D-36 — **D-37 has since
  replaced it with a hard block on the batch's hour span** (see above).

### Added

* **Rejecting a batch now requires a written reason** (D-36, FR-DIRA-04).
  New nullable `batch_requests.rejection_reason` (TEXT — nullable because
  rejections made before this change have none). The reason is required,
  10–500 characters, validated server-side by the new
  `App\Http\Requests\Director\RejectBatchRequest`; the reject modal's live
  character counter and disabled-until-valid submit are convenience only. The
  existing `lockForUpdate()` + already-decided no-op guard is untouched, so a
  replayed reject still no-ops and cannot overwrite a reason already on record.
* **College admins can read why a batch was rejected** (D-36, FR-ADM-05). Batch
  Tracking grows a **Rejection Reason** column beside Status, rendered only when
  the list actually contains a rejected row; that row gets a View button opening
  a modal with the reason, the reviewing Director's name and the review
  timestamp, and every other row shows an em dash. The reason is Director-written
  free text, so it is escaped on output and set via `x-text`, never raw HTML.
  Works in both the desktop table and the mobile stacked-card rendering.

### Fixed

* Mobile layout of the College Admin pages (FR-ADM-01 / FR-ADM-05). The
  Dashboard and Batch Tracking tables no longer scroll the page sideways on a
  phone — a new `<x-hp.table>` / `<x-hp.table-row>` / `<x-hp.table-cell>`
  component set re-flows each row into a stacked label/value card below `md`
  and renders the unchanged table at `md`+. Status badges keep long labels
  ("Pending Director Approval") inside the pill, and the sidebar drawer is
  sized to `100dvh` so its Log out footer is reachable without scrolling.
  Verified at 360×800 and 390×844 with no horizontal page scroll; desktop
  tables and the collapsible rail are unchanged. See
  `docs/qa/mobile-checklist.md`.

## [v12.12.1](https://github.com/laravel/laravel/compare/v12.12.0...v12.12.1) - 2026-03-10

* [12.x] Makes imports consistent by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6760

## [v12.12.0](https://github.com/laravel/laravel/compare/v12.11.2...v12.12.0) - 2026-03-09

* Update phpunit version to ^11.5.50 to address CVE by [@PerryvanderMeer](https://github.com/PerryvanderMeer) in https://github.com/laravel/laravel/pull/6746
* [12.x] Add `APP_NAME` fallback in mail config by [@apoorvdarshan](https://github.com/apoorvdarshan) in https://github.com/laravel/laravel/pull/6755
* [12.x] Neutralize DB_URL in default phpunit.xml by [@Husseinadq](https://github.com/Husseinadq) in https://github.com/laravel/laravel/pull/6761

## [v12.11.2](https://github.com/laravel/laravel/compare/v12.11.1...v12.11.2) - 2026-01-19

* [12.x] Update composer dev script to ensure no timeout by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6735
* [12.x] Update jobs/cache migrations by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6736
* [12.x] Remove failed jobs indexes by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6739
* [12.x] Add `APP_URL` fallback in filesystems config by [@KentarouTakeda](https://github.com/KentarouTakeda) in https://github.com/laravel/laravel/pull/6742
* chore: Update outdated GitHub Actions version by [@pgoslatara](https://github.com/pgoslatara) in https://github.com/laravel/laravel/pull/6743

## [v12.11.1](https://github.com/laravel/laravel/compare/v12.11.0...v12.11.1) - 2025-12-23

* Use environment variable for `DB_SSLMODE` - Postgres by [@robsontenorio](https://github.com/robsontenorio) in https://github.com/laravel/laravel/pull/6727
* fix: ensure APP_URL does not have trailing slash in filesystem by [@msamgan](https://github.com/msamgan) in https://github.com/laravel/laravel/pull/6728

## [v12.11.0](https://github.com/laravel/laravel/compare/v12.10.1...v12.11.0) - 2025-11-25

* fix: cookies are not available for subdomains by default by [@joostdebruijn](https://github.com/joostdebruijn) in https://github.com/laravel/laravel/pull/6705
* Fix PHP 8.5 PDO Driver Specific Constant Deprecation by [@RyanSchaefer](https://github.com/RyanSchaefer) in https://github.com/laravel/laravel/pull/6710
* Ignore Laravel compiled views for Vite  by [@QistiAmal1212](https://github.com/QistiAmal1212) in https://github.com/laravel/laravel/pull/6714

## [v12.10.1](https://github.com/laravel/laravel/compare/v12.10.0...v12.10.1) - 2025-11-06

* Update schema URL in package.json by [@robinmiau](https://github.com/robinmiau) in https://github.com/laravel/laravel/pull/6701

## [v12.10.0](https://github.com/laravel/laravel/compare/v12.9.1...v12.10.0) - 2025-11-04

* Add background driver by [@barryvdh](https://github.com/barryvdh) in https://github.com/laravel/laravel/pull/6699

## [v12.9.1](https://github.com/laravel/laravel/compare/v12.9.0...v12.9.1) - 2025-10-23

* [12.x] Replace Bootcamp with Laravel Learn by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6692
* [12.x] Comment out CLI workers for fresh applications by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/laravel/pull/6693

## [v12.9.0](https://github.com/laravel/laravel/compare/v12.8.0...v12.9.0) - 2025-10-21

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.8.0...v12.9.0

## [v12.8.0](https://github.com/laravel/laravel/compare/v12.7.1...v12.8.0) - 2025-10-20

* [12.x] Makes test suite using broadcast's `null` driver by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6691

## [v12.7.1](https://github.com/laravel/laravel/compare/v12.7.0...v12.7.1) - 2025-10-15

* Added `failover` driver to the `queue` config comment.  by [@sajjadhossainshohag](https://github.com/sajjadhossainshohag) in https://github.com/laravel/laravel/pull/6688

## [v12.7.0](https://github.com/laravel/laravel/compare/v12.6.0...v12.7.0) - 2025-10-14

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.6.0...v12.7.0

## [v12.6.0](https://github.com/laravel/laravel/compare/v12.5.0...v12.6.0) - 2025-10-02

* Fix setup script by [@goldmont](https://github.com/goldmont) in https://github.com/laravel/laravel/pull/6682

## [v12.5.0](https://github.com/laravel/laravel/compare/v12.4.0...v12.5.0) - 2025-09-30

* [12.x] Fix type casting for environment variables in config files by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6670
* Fix CVEs affecting vite by [@faissaloux](https://github.com/faissaloux) in https://github.com/laravel/laravel/pull/6672
* Update .editorconfig to target compose.yaml by [@fredikaputra](https://github.com/fredikaputra) in https://github.com/laravel/laravel/pull/6679
* Add pre-package-uninstall script to composer.json by [@cosmastech](https://github.com/cosmastech) in https://github.com/laravel/laravel/pull/6681

## [v12.4.0](https://github.com/laravel/laravel/compare/v12.3.1...v12.4.0) - 2025-08-29

* [12.x] Add default Redis retry configuration by [@mateusjatenee](https://github.com/mateusjatenee) in https://github.com/laravel/laravel/pull/6666

## [v12.3.1](https://github.com/laravel/laravel/compare/v12.3.0...v12.3.1) - 2025-08-21

* [12.x] Bump Pint version by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6653
* [12.x] Making sure all related processed are closed when terminating the currently command by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6654
* [12.x] Use application name from configuration by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6655
* Bring back postAutoloadDump script by [@jasonvarga](https://github.com/jasonvarga) in https://github.com/laravel/laravel/pull/6662

## [v12.3.0](https://github.com/laravel/laravel/compare/v12.2.0...v12.3.0) - 2025-08-03

* Fix Critical Security Vulnerability in form-data Dependency by [@izzygld](https://github.com/izzygld) in https://github.com/laravel/laravel/pull/6645
* Revert "fix" by [@RobertBoes](https://github.com/RobertBoes) in https://github.com/laravel/laravel/pull/6646
* Change composer post-autoload-dump script to Artisan command by [@lmjhs](https://github.com/lmjhs) in https://github.com/laravel/laravel/pull/6647

## [v12.2.0](https://github.com/laravel/laravel/compare/v12.1.0...v12.2.0) - 2025-07-11

* Add Vite 7 support by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/laravel/pull/6639

## [v12.1.0](https://github.com/laravel/laravel/compare/v12.0.11...v12.1.0) - 2025-07-03

* [12.x] Disable nightwatch in testing by [@laserhybiz](https://github.com/laserhybiz) in https://github.com/laravel/laravel/pull/6632
* [12.x] Reorder environment variables in phpunit.xml for logical grouping by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6634
* Change to hyphenate prefixes and cookie names by [@u01jmg3](https://github.com/u01jmg3) in https://github.com/laravel/laravel/pull/6636
* [12.x] Fix type casting for environment variables in config files by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6637

## [v12.0.11](https://github.com/laravel/laravel/compare/v12.0.10...v12.0.11) - 2025-06-10

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.0.10...v12.0.11

## [v12.0.10](https://github.com/laravel/laravel/compare/v12.0.9...v12.0.10) - 2025-06-09

* fix alphabetical order by [@Khuthaily](https://github.com/Khuthaily) in https://github.com/laravel/laravel/pull/6627
* [12.x] Reduce redundancy and keeps the .gitignore file cleaner by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6629
* [12.x] Fix: Add void return type to satisfy Rector analysis by [@Aluisio-Pires](https://github.com/Aluisio-Pires) in https://github.com/laravel/laravel/pull/6628

## [v12.0.9](https://github.com/laravel/laravel/compare/v12.0.8...v12.0.9) - 2025-05-26

* [12.x] Remove apc by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6611
* [12.x] Add JSON Schema to package.json by [@martinbean](https://github.com/martinbean) in https://github.com/laravel/laravel/pull/6613
* Minor language update by [@woganmay](https://github.com/woganmay) in https://github.com/laravel/laravel/pull/6615
* Enhance .gitignore to exclude common OS and log files by [@mohammadRezaei1380](https://github.com/mohammadRezaei1380) in https://github.com/laravel/laravel/pull/6619

## [v12.0.8](https://github.com/laravel/laravel/compare/v12.0.7...v12.0.8) - 2025-05-12

* [12.x] Clean up URL formatting in README by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6601

## [v12.0.7](https://github.com/laravel/laravel/compare/v12.0.6...v12.0.7) - 2025-04-15

* Add `composer run test` command by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/laravel/pull/6598
* Partner Directory Changes in ReadME by [@joshcirre](https://github.com/joshcirre) in https://github.com/laravel/laravel/pull/6599

## [v12.0.6](https://github.com/laravel/laravel/compare/v12.0.5...v12.0.6) - 2025-04-08

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.0.5...v12.0.6

## [v12.0.5](https://github.com/laravel/laravel/compare/v12.0.4...v12.0.5) - 2025-04-02

* [12.x] Update `config/mail.php` to match the latest core configuration by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6594

## [v12.0.4](https://github.com/laravel/laravel/compare/v12.0.3...v12.0.4) - 2025-03-31

* Bump vite from 6.0.11 to 6.2.3 - Vulnerability patch by [@abdel-aouby](https://github.com/abdel-aouby) in https://github.com/laravel/laravel/pull/6586
* Bump vite from 6.2.3 to 6.2.4 by [@thinkverse](https://github.com/thinkverse) in https://github.com/laravel/laravel/pull/6590

## [v12.0.3](https://github.com/laravel/laravel/compare/v12.0.2...v12.0.3) - 2025-03-17

* Remove reverted change from CHANGELOG.md by [@AJenbo](https://github.com/AJenbo) in https://github.com/laravel/laravel/pull/6565
* Improves clarity in app.css file by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6569
* [12.x] Refactor: Structural improvement for clarity by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6574
* Bump axios from 1.7.9 to 1.8.2 - Vulnerability patch by [@abdel-aouby](https://github.com/abdel-aouby) in https://github.com/laravel/laravel/pull/6572
* [12.x] Remove Unnecessarily [@source](https://github.com/source) by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6584

## [v12.0.2](https://github.com/laravel/laravel/compare/v12.0.1...v12.0.2) - 2025-03-04

* Make the github test action run out of the box independent of the choice of testing framework by [@ndeblauw](https://github.com/ndeblauw) in https://github.com/laravel/laravel/pull/6555

## [v12.0.1](https://github.com/laravel/laravel/compare/v12.0.0...v12.0.1) - 2025-02-24

* [12.x] prefer stable stability by [@pataar](https://github.com/pataar) in https://github.com/laravel/laravel/pull/6548

## [v12.0.0 (2025-??-??)](https://github.com/laravel/laravel/compare/v11.0.2...v12.0.0)

Laravel 12 includes a variety of changes to the application skeleton. Please consult the diff to see what's new.
