# CLAUDE.md — HealthPass

Capstone project, Pampanga State University CCS. Web-based scheduling and
digital medical clearance system with a self-service vitals kiosk.
**One unified Laravel app** — the kiosk is just a Blade route displayed
full-screen on a Raspberry Pi. Deadline: **August 30, 2026** (internal
freeze Aug 28).

## Read first

Before any significant feature work, read the relevant section of:

- `docs/HealthPass_PRD.md` — single source of truth: requirements (FR-IDs),
  business rules, data dictionary, decisions log, release plan
- `docs/HealthPass_Context.md` — system spec; its 11-table schema is canonical
- `docs/HealthPass_Project_Plan.md` — 12-week sprint plan

If a request conflicts with the PRD, **stop and say so** before coding.
Do not silently reconcile conflicts.

## Behavioral rules

(Adapted from Karpathy's observations on LLM coding pitfalls.)

1. **Don't assume — surface it.** State assumptions explicitly. If a request
   is ambiguous or has multiple valid interpretations, list them and ask
   before proceeding. If a simpler approach exists than what was asked,
   say so. Push back when warranted.
2. **Confusion is a stop sign.** If something is unclear (a Laravel concept,
   a requirement, a file's purpose), name what's confusing and ask. Never
   guess and run with it.
3. **Minimum code that solves the problem.** No speculative features, no
   abstractions for single-use code, no "flexibility" nobody asked for.
   If 200 lines could be 50, rewrite it. Idiomatic Laravel beats clever.
4. **Don't touch what you don't understand.** Never modify or delete code
   or comments unrelated to the task, even if they look wrong. Flag them
   instead.
5. **Goals over steps.** When given success criteria (e.g. "this test
   passes", "this page matches the prototype"), iterate until verified —
   don't declare done without checking.

## Team context

- **Nat** — lead dev, web application. **Baldo** — hardware + Web Serial.
- Both are **new to Laravel** (baseline: plain PHP CRUD). When introducing
  a Laravel concept for the first time (middleware, policies, observers,
  form requests, etc.), add a one-to-two sentence explanation of what it
  is and why it's used here. Keep code beginner-readable.
- Five non-programmer teammates handle docs/QA — code is read only by
  Nat, Baldo, and the defense panel. Optimize for clarity.

## Stack & environment

- Laravel 12 + Breeze (Blade stack) + Tailwind, MySQL via XAMPP, Windows
- Project root: `C:\Capstone\healthpass`
- Repo: `https://github.com/Nat-G1t/Healthpass.git`
- Charts: Chart.js (Director analytics). Print: Blade view + `window.print()`;
  PDF: `barryvdh/laravel-dompdf` from the same view
- Production target: internet deployment for web app; Raspberry Pi 4 running
  Chromium kiosk mode hitting Laravel on `localhost` (Web Serial requires
  a secure context — this is why the Pi runs the app locally)

## Kiosk architecture

- **State machine: Alpine.js, one reactive `state` object.** Chosen over
  vanilla JS because Alpine is already the house framework (registered in
  `app.js`, `[x-cloak]` styled), it's beginner-readable for Nat/Baldo
  (declarative `x-show="state.screen==='…'"` beats hand-rolled DOM swaps),
  and a single reactive object makes reset-to-Welcome a wholesale replacement
  with no per-field teardown (FR-KSK-13). ALL session state lives in
  `state` (`resources/js/kiosk/state-machine.js`).
- **Dedicated Vite entry** `resources/js/kiosk/kiosk.js` (not `app.js`) so the
  kiosk bundle stays lean (no `html5-qrcode` etc.) and grows independently
  (Web Serial, virtual keyboards). The kiosk page is a standalone Blade doc
  (`resources/views/kiosk/index.blade.php`) — no app nav/sidebar.
- **One Blade partial per screen** under `resources/views/kiosk/screens/`;
  `vitals` is a single screen with an internal `state.vitalStep` (1–4).
- The kiosk route is **public** (no Laravel auth) — identity is established
  inside the flow via QR scan / email login.
- **The screens shown follow the server-resolved form type of today's
  appointment; submit re-resolves it.** One shared resolution,
  `Appointment::todayFor()` (the D-54 rule), serves scan/login and submit, so
  the screens a student saw and the row the server writes can never be about
  different appointments. A `formType` in the request body is never read.
- **Kiosk endpoints are network-restricted** (loopback or authenticated
  clinic staff, nurse or physician — `KioskAccess` middleware) but auth-less for the person at the
  terminal. Therefore **NEVER trust client-supplied identity or derived
  values on kiosk endpoints**: student identity binds server-side in the
  session at scan/login, and BMI/flags are always recomputed server-side.
- **Rest & re-check (D-72):** a first pass with high temp/BP/HR is saved as a
  `resting` visit (never queued, never counted); the student re-scans after the
  rest time and re-takes only those readings; the server keeps the first reading
  in `vital_signs.first_reading`. The "Bypass the nurse queue flow" rule still
  holds — a resting visit reaches the clinic only through the queue. Every count
  of visits goes through `ClinicVisit::scopeSubmitted()`.
- **Seven-sample capture (D-74):** height, weight and temperature buffer 7
  sensor readings (≥ 3 after a 6 s window) and record the steadiest cluster's
  average (`resources/js/kiosk/sample-cluster.js`) through the normal sensor
  path; the tolerances live on the `VITALS` field metadata
  (`clusterTolerance`), never as loose constants. BP is not sampled.
- **One kiosk endpoint sits outside `kiosk.access`:** `POST /api/kiosk/bp-reading`
  (D-58, `routes/api.php`) is called by the Pi's Bluetooth BP daemon with no
  session and authenticates by `X-Kiosk-Key` against `HEALTHPASS_KIOSK_KEY`.
  The kiosk claims a reading into its session; submit trusts only that copy.
- **Display sizing: responsive fill + zoom, portrait target 1080×1920**
  (D-26 — supersedes both the original fixed 800×480 letterbox and the 7″
  landscape target; the hardware is now a 15.6″ 1080p panel used in
  portrait). The panel fills the viewport (`.kiosk-panel { inset: 0 }`)
  so there are no black bars on any screen; on the 1080×1920 target it
  maps 1:1. A single `--k-zoom` CSS var (default `2`, vitals `2.25`)
  scales every rem-based size for standing-distance readability — tune
  it in `kiosk/index.blade.php`. Screens stack vertically (single
  column); the old side-by-side layouts were landscape-only.

## Deployment shapes

- **Primary = single hosted app over HTTPS (D-34).** One Laravel app on a
  public domain serves the web app and the kiosk; **the defense demos this
  deployment**, with Chromium on the Pi opening `https://<domain>/kiosk`.
  The Pi is a terminal, not a server — see `docs/deployment-hosted.md`.
  Requires `TRUSTED_PROXIES` set to the real proxy IP (never `*`),
  `HEALTHPASS_KIOSK_ALLOW_LOOPBACK=false`, and the Pi enrolled as a kiosk
  device (D-27).
- **Fallback = Pi-local (was D-9).** The app runs on the Pi, Chromium opens
  `http://localhost/kiosk`. Kept and **rehearsed** for venue-internet
  failure — `docs/deployment-pi.md` §10.
- **Web Serial grants are per-origin.** Changing the kiosk's origin
  (localhost ↔ domain) invalidates the ESP32's grant; it must be re-issued
  once per origin. This is why the launcher no longer uses `--incognito`.
- **Never key HTTPS-forcing logic on `APP_ENV`** — the fallback Pi is
  `APP_ENV=production` over plain `http://localhost` by design. HTTPS is
  forced at nginx.
- **Trusted proxies live in `AppServiceProvider::boot()`**, not
  `bootstrap/app.php` — that middleware closure runs before the config files
  load, so `config()` is unavailable and `env()` reads empty once
  `config:cache` has run.

## Run / dev commands

```
php artisan serve --port=8080     # terminal 1
npm run dev                       # terminal 2
```

- Always use `127.0.0.1`, never `localhost`, in local URLs and config
- MySQL via XAMPP must be running before artisan commands that touch DB
- **Testing:** the suite runs on **SQLite in-memory** (`phpunit.xml`) while
  dev/prod use MySQL. Keep all query-builder raw SQL portable — no
  MySQL-only functions (e.g. `DAY()`/`MONTH()`) in `selectRaw`/`havingRaw`.
  Any query that must use raw SQL needs a feature test so SQLite catches drift.

## Conventions

- **Git:** feature branches off `main`, e.g. `feature/kiosk-vitals`.
  Never commit directly to `main`. Small, focused commits.
- **Reference numbers:** appointments `APT-YYYY-####`, batch requests
  `BR-YYYY-###`, clinic visits / clearances `HP-YYYY-####`
- **Time slots** are always the canonical string `'H:i:s'` (`'07:00:00'`) —
  in the `<select>`, in the DB column, and in every WHERE. MySQL TIME and
  SQLite TEXT round-trip that form identically; mixing in `'07:00'` would
  pass the SQLite suite and silently miscount on MySQL.
- **Migrations:** follow the migration order in the PRD data dictionary.
  Never run `migrate:fresh` or other destructive DB commands without
  asking first — seeded data may be in use.
- **Validation:** Form Request classes for non-trivial forms
- **Tables page at ten** (FR-UI-06) via `<x-hp.pager>` and
  `healthpass.ui.rows_per_page`; the nurse dashboard is the exception at fifteen.
- **New packages:** propose and justify before installing anything

## Design system

- Light palette: white `#FFFFFF`, off-white `#F6F2ED`, peach `#FFCAA0`,
  orange `#FF8C2A` (primary), slate `#4B5563` (text)
- **Colour tokens are `R G B` channel triplets, not hex** — that is what
  lets Tailwind derive `text-hp-slate/50` etc. In plain CSS you must write
  `rgb(var(--hp-bg))`; a bare `var(--hp-bg)` is not a valid colour and
  renders transparent. (Motion tokens are normal values — `var()` is fine.)
- Font: Poppins. Kiosk viewport: **1080×1920px portrait** (15.6″ panel,
  D-26; the original 800×480 prototype canvas is superseded — layouts
  restack vertically, don't copy its side-by-side compositions).
- The Claude Design HTML prototypes (web app + kiosk) are the visual
  source of truth — match them, don't improvise layouts.

## Locked decisions — do not change or "improve"

- **No AI features.** No predictive risk profiling, no LLM calls. The
  system is scheduling + digital clearance with simple rule-based vital
  flagging only. (BP flag threshold locked at **140/90**;
  heart rate > 100 and respiratory rate < 12 / > 20 (D-66); other
  thresholds per PRD business rules.)
- **Five roles:** Student, College Admin, Nurse, Physician (D-64), Clinic
  Director. Nurse and Physician share the Clinic Dashboard (`/nurse/*`) and
  encode Fit/Unfit; the physician's name/license print only on records a
  physician encoded. (Case categories were dropped by D-32.) Check clinic
  access with `User::isClinicStaff()`, never `role === 'nurse'`.
- **Kiosk never shows Fit/Unfit to the student.** It captures vitals +
  the official form's twelve Physical Signs rows (D-63) and, for Medical
  Assessment Form batches, the form's Personal / Social History (D-68), and
  routes to the clinic queue (nurse or physician). On a Medical Clearance a
  "Yes" requires details (≥ 3 characters, checked in the kiosk AND in
  `KioskSubmitRequest` — D-75); on a Medical Assessment they stay optional.
- **Students never self-schedule and never walk in (D-61)** — only
  Director-approved college batches create appointments; the kiosk refuses
  a student with no appointment today.
- **Every batch names its form (D-62)** — `clearance` (Medical Clearance) or
  `assessment` (Medical Assessment Form); the form type drives the kiosk
  questions, the encode fields and the printed document, and the batch
  reason is the printed purpose.
- **Manual vitals entry is a first-class kiosk path**, sensors are
  progressive enhancement. Every reading records `entry_method`.
- **Clinic capacity is TWO config values, never constants in a controller
  (D-37):** `hourly_capacity` (**12**) and `daily_capacity` (**120**). The
  clinic day is **ten one-hour slots derived from `clinic_hours`**
  (7–8 AM … 4–5 PM, **lunch included**) — never hardcode 7–5 in a view; ask
  `App\Services\ClinicScheduleService`. Every appointment carries a
  `scheduled_time` slot key in canonical `'H:i:s'` form; there is **one
  counter per hour** (the constraint is clinic congestion, not kiosk
  throughput). A day is full only when **every** slot is at 12. Pre-D-37
  rows keep `scheduled_time` NULL, render as "—", and are seen by the daily
  cap only — **never backfill them**.
- **On today, an hour stops being bookable once it has ENDED (BR-23)** — at
  12:00 the 11–12 slot is gone, 12–1 is not. Binds the College Admin's
  batch start hour *and* Director approval (any elapsed hour
  in the span refuses it — D-36 still allows same-day batches, so their hours
  can lapse while pending; `BatchRequest::elapsedSpanHours()` is the one
  definition the page and the endpoint share). Always decided on the **server**
  clock and shipped to the page as data; never compute "now" in the browser
  (same rule as BR-20). Not re-checked under lock — elapsed-ness can't race.
- Batch clinic dates are admin-requested and **confirm-only** at approval —
  **the Director cannot adjust the date (D-36, supersedes that clause of
  D-29)** and since D-37 confirms the hour span read-only too. Approval reads
  `requested_date` off the locked row, never the request body. A batch can't
  be approved at all when `requested_date` is NULL, **has already passed**
  (`BatchRequest::hasStaleRequestedDate()` — today still counts as valid),
  it has **no hour span** (pre-D-37), or **any hour in its span is at the
  hourly cap** — that last one is a **hard block since D-37, replacing
  FR-DIRA-06's old warn-but-allow**. The pushback path is
  **reject with a written reason** (required, 10–500 chars), which the
  College Admin reads on Batch Tracking.
- The printed Medical Clearance must match official form
  **PSU-QSP-OSS-004-FO002-R04** (D-67), and the printed Medical Assessment
  Form must match **PSU-QSP-OSS-004-FO010-R00** (D-71): US Legal, two pages
  back-to-back; the clinic prints front and back separately (manual duplex).
  Print and PDF share one template per form (`resources/views/forms/`),
  written in dompdf-safe CSS (tables, no flex/grid, no JS).

## Database

11 tables, canonical in `HealthPass_Context.md` / PRD data dictionary:
`colleges`, `users`, `student_profiles`, `appointments`, `batch_requests`,
`batch_request_students`, `clinic_visits`, `vital_signs`,
`screening_responses`, `clearance_records`, `medical_assessments` (D-69 —
the Medical Assessment Form's own sections, one row per ENCODED `assessment`
visit, none for a Medical Clearance).
(`clearance_case_categories`, added as table #11 by D-23, was **removed by
D-32** with the case-category concept. The `kiosk_devices` device-auth
table, D-27, is a flagged extension tracked in the PRD data dictionary.)
Do not add tables or columns without checking the PRD and flagging the
schema change explicitly.

## Never do

- Re-introduce AI/predictive features in code, comments, or docs
- Re-introduce dark mode, a theme toggle or any `dark:` utility — the app
  is light-only and the decision that added it was struck from the PRD
- Use `localhost` in URLs (use `127.0.0.1`; the Pi deployment is the
  only localhost exception, and it's handled by config)
- Show clearance outcomes on the kiosk UI
- Bypass the nurse queue flow
- Commit `.env`, credentials, or real student data