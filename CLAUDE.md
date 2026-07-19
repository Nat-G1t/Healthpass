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
- Charts: Chart.js (Director analytics). Print: Blade view + `window.print()`
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
- **Kiosk endpoints are network-restricted** (loopback or authenticated
  nurse — `KioskAccess` middleware) but auth-less for the person at the
  terminal. Therefore **NEVER trust client-supplied identity or derived
  values on kiosk endpoints**: student identity binds server-side in the
  session at scan/login, and BMI/flags are always recomputed server-side.
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

- **Defense = Pi-local.** The app runs on the Pi; Chromium opens the kiosk
  at `http://localhost/kiosk` (D-9) — see `docs/deployment-pi.md`.
- **Internet = single hosted app over HTTPS.** The kiosk points at
  `https://<domain>/kiosk` (HTTPS is a secure context, so Web Serial works;
  serial permission grants are per-origin).
- **Never key HTTPS-forcing logic on `APP_ENV`** — the Pi is
  `APP_ENV=production` over plain `http://localhost` by design.

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
- **Migrations:** follow the migration order in the PRD data dictionary.
  Never run `migrate:fresh` or other destructive DB commands without
  asking first — seeded data may be in use.
- **Validation:** Form Request classes for non-trivial forms
- **New packages:** propose and justify before installing anything

## Design system

- Palette: white `#FFFFFF`, off-white `#F6F2ED`, peach `#FFCAA0`,
  orange `#FF8C2A` (primary), slate `#4B5563` (text)
- Font: Poppins. Kiosk viewport: **1080×1920px portrait** (15.6″ panel,
  D-26; the original 800×480 prototype canvas is superseded — layouts
  restack vertically, don't copy its side-by-side compositions).
- The Claude Design HTML prototypes (web app + kiosk) are the visual
  source of truth — match them, don't improvise layouts.

## Locked decisions — do not change or "improve"

- **No AI features.** No predictive risk profiling, no LLM calls. The
  system is scheduling + digital clearance with simple rule-based vital
  flagging only. (BP flag threshold locked at **140/90**; other
  thresholds per PRD business rules.)
- **Four roles only:** Student, College Admin, Nurse, Clinic Director.
  There is **no Doctor role** — the Nurse encodes **Fit/Unfit only**
  (case categories were dropped by D-32); the University Physician signs
  the printed form.
- **Kiosk never shows Fit/Unfit to the student.** It captures vitals +
  the 9-system questionnaire and routes to the Nurse queue.
- **Manual vitals entry is a first-class kiosk path**, sensors are
  progressive enhancement. Every reading records `entry_method`.
- Clinic capacity is a config value; batch clinic dates are
  admin-requested and Director-confirmed at approval (the Director may
  adjust — D-29); dental is scheduling-only, except that kiosk submit now
  links today's dental appointment so it can be completed (D-33).
- Printed clearance must match official form DHVSU-QSP-OSS-004-FO002-R03.

## Database

10 tables, canonical in `HealthPass_Context.md` / PRD data dictionary:
`colleges`, `users`, `student_profiles`, `appointments`, `batch_requests`,
`batch_request_students`, `clinic_visits`, `vital_signs`,
`screening_responses`, `clearance_records`.
(`clearance_case_categories`, added as table #11 by D-23, was **removed by
D-32** with the case-category concept. The `kiosk_devices` device-auth
table, D-27, is a flagged extension tracked in the PRD data dictionary.)
Do not add tables or columns without checking the PRD and flagging the
schema change explicitly.

## Never do

- Re-introduce AI/predictive features in code, comments, or docs
- Use `localhost` in URLs (use `127.0.0.1`; the Pi deployment is the
  only localhost exception, and it's handled by config)
- Show clearance outcomes on the kiosk UI
- Bypass the nurse queue flow
- Commit `.env`, credentials, or real student data