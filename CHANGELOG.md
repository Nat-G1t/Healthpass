# Release Notes

## [Unreleased](https://github.com/laravel/laravel/compare/v12.12.1...12.x)

### Added

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

* Site-wide **light/dark theme** for the web app (D-38, FR-UI-04 / NFR-10). A
  toggle sits in the top-right of the sticky header on every page that uses the
  sidebar layout, and fixed top-right on the auth/guest shells. The choice is
  remembered per device in `localStorage` and defaults to the OS
  `prefers-color-scheme` — no schema change, no user column, no request on
  toggle. The theme class is applied by an inline `<head>` script before first
  paint, so a dark reload never flashes light. Colour transitions use the
  existing `--hp-dur-base` / `--hp-ease-out` motion tokens and still collapse
  under `prefers-reduced-motion`.
  Implemented by **re-pointing the five `--hp-*` colour tokens** rather than
  scattering `dark:` variants: the tokens are now `R G B` channel triplets and
  Tailwind resolves `hp-*` through `rgb(var(--…) / <alpha-value>)`, so ~300
  existing `text-hp-slate/NN` utilities re-theme untouched. **In plain CSS a
  colour token must now be wrapped — `rgb(var(--hp-bg))`, not `var(--hp-bg)`.**
  Dark peach becomes a dark amber chip (`#3B2A17`) because the app pairs it with
  orange text, which was 1.55:1 on a light peach; dark slate is `#F3F4F6` so the
  muted alpha steps still clear AA. Brand orange is unchanged — it measures
  7.3:1 on the dark card. Known issue, pre-existing and identical in light: the
  white-on-orange button label is 2.32:1.
  **The kiosk and all print output are excluded and stay light** — the kiosk is
  a fixed clinic appliance (D-26) and printed clearances must match the official
  form. Verified with a per-page contrast audit across all four roles in both
  themes, plus no-flash-on-reload, kiosk-stays-light and print-stays-light checks.

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
