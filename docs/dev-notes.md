# HealthPass — Dev Notes

Scratch pad for Nat and Baldo. Commands to run, tinker examples, integration notes.
php artisan serve --port=8080
npm run dev
---

## Seeded credentials (dev only — all passwords: `password`)

Run `php artisan migrate:fresh --seed` to restore this state at any time.

### Staff accounts

| Role | Email | Password | Notes |
|---|---|---|---|
| Director | `director@healthpass.test` | `password` | Full analytics + batch approvals |
| Nurse | `nurse@healthpass.test` | `password` | Live Queue + Encode Result |
| Admin — COE | `admin.coe@healthpass.test` | `password` | College of Education |
| Admin — CEA | `admin.cea@healthpass.test` | `password` | College of Architecture and Engineering |
| Admin — CBS | `admin.cbs@healthpass.test` | `password` | College of Business Studies |
| Admin — CAS | `admin.cas@healthpass.test` | `password` | College of Arts and Science |
| Admin — CSSP | `admin.cssp@healthpass.test` | `password` | College of Social Science and Philosophy |
| Admin — CCS | `admin.ccs@healthpass.test` | `password` | College of Computing Studies |
| Admin — CHTM | `admin.chtm@healthpass.test` | `password` | College of Hospitality and Management |
| Admin — CIT | `admin.cit@healthpass.test` | `password` | College of Industrial Technology |
| Admin — LAW | `admin.law@healthpass.test` | `password` | School of Law |
| Admin — GS | `admin.gs@healthpass.test` | `password` | Graduate Studies |
| Admin — SHS | `admin.shs@healthpass.test` | `password` | Senior High School |
| Admin — LHS | `admin.lhs@healthpass.test` | `password` | Laboratory High School |

### Student accounts (30 total)

| Email | Password | College | Year | Course |
|---|---|---|---|---|
| `juan.santos@psu.edu.ph` | `password` | CCS | 4th Year | BS Computer Science |
| `maria.reyes@psu.edu.ph` | `password` | CCS | 3rd Year | BS Information Technology |
| `carlo.cruz@psu.edu.ph` | `password` | CCS | 2nd Year | BS Information Systems |
| `angel.garcia@psu.edu.ph` | `password` | CCS | 1st Year | BS Computer Science |
| `jose.bautista@psu.edu.ph` | `password` | COE | 4th Year | Bachelor of Secondary Education |
| `sofia.ocampo@psu.edu.ph` | `password` | COE | 2nd Year | Bachelor of Elementary Education |
| `darwin.mendoza@psu.edu.ph` | `password` | COE | 3rd Year | Bachelor of Physical Education |
| `marco.torres@psu.edu.ph` | `password` | CEA | 3rd Year | BS Civil Engineering |
| `kristine.ramirez@psu.edu.ph` | `password` | CEA | 2nd Year | BS Architecture |
| `paolo.flores@psu.edu.ph` | `password` | CEA | 4th Year | BS Electrical Engineering |
| `ana.mercado@psu.edu.ph` | `password` | CBS | 3rd Year | BS Accountancy |
| `kenneth.castillo@psu.edu.ph` | `password` | CBS | 2nd Year | BS Business Administration |
| `maricel.gonzales@psu.edu.ph` | `password` | CBS | 4th Year | BS Marketing Management |
| `jayson.diaz@psu.edu.ph` | `password` | CAS | 2nd Year | BS Psychology |
| `camille.castro@psu.edu.ph` | `password` | CAS | 3rd Year | BA Communication |
| `erwin.ramos@psu.edu.ph` | `password` | CAS | 1st Year | BS Biology |
| `tricia.tolentino@psu.edu.ph` | `password` | CSSP | 3rd Year | BS Social Work |
| `luis.villanueva@psu.edu.ph` | `password` | CSSP | 2nd Year | BA Sociology |
| `diane.padilla@psu.edu.ph` | `password` | CHTM | 2nd Year | BS Hospitality Management |
| `bryan.aquino@psu.edu.ph` | `password` | CHTM | 3rd Year | BS Tourism Management |
| `jennifer.manalo@psu.edu.ph` | `password` | CIT | 2nd Year | BIT Electronics |
| `rex.david@psu.edu.ph` | `password` | CIT | 3rd Year | BIT Computer Technology |
| `aldrin.fernandez@psu.edu.ph` | `password` | LAW | 2nd Year | Juris Doctor |
| `rina.lim@psu.edu.ph` | `password` | LAW | 3rd Year | Juris Doctor |
| `christian.tan@psu.edu.ph` | `password` | GS | 1st Year | MS Computer Science |
| `sheila.go@psu.edu.ph` | `password` | GS | 2nd Year | MA Education |
| `jessa.chua@psu.edu.ph` | `password` | SHS | Grade 12 | STEM Strand |
| `renz.ong@psu.edu.ph` | `password` | SHS | Grade 11 | ABM Strand |
| `hazel.abalos@psu.edu.ph` | `password` | LHS | Grade 10 | General Secondary Education |
| `mark.pangan@psu.edu.ph` | `password` | LHS | Grade 9 | General Secondary Education |

---

## Tinker — testing Eloquent relationships

Run `php artisan tinker` first. MySQL must be running and migrations must have run (`php artisan migrate`).

### Load a college and navigate outward

```php
// Seed colleges first (php artisan db:seed --class=CollegeSeeder)
$ccs = App\Models\College::where('code', 'CCS')->first();

$ccs->studentProfiles;          // all CCS student profiles
$ccs->admins;                   // the CCS admin user account
$ccs->batchRequests;            // all batch requests from CCS
```

### Student: user → profile → college

```php
$student = App\Models\User::where('role', 'student')->first();

$student->studentProfile;                   // StudentProfile model
$student->studentProfile->college;          // College model
$student->studentProfile->college->code;    // "CCS"

// Eager-load to avoid N+1
$students = App\Models\User::where('role', 'student')
    ->with('studentProfile.college')
    ->get();
```

### Student → appointments → clinic visit → vitals → clearance

```php
$student = App\Models\User::where('role', 'student')->first();

// All appointments for this student
$student->appointments;

// Follow one appointment into the kiosk visit
$appt = $student->appointments->first();
$appt->clinicVisit;                      // ClinicVisit or null (not yet at kiosk)
$appt->clinicVisit?->vitalSigns;         // VitalSigns
$appt->clinicVisit?->screeningResponse;  // ScreeningResponse
$appt->clinicVisit?->clearanceRecord;    // ClearanceRecord or null (not yet encoded)

// Eagerly load the full chain for a student
$student->load([
    'appointments.clinicVisit.vitalSigns',
    'appointments.clinicVisit.screeningResponse',
    'appointments.clinicVisit.clearanceRecord.encoder',
]);
```

### Batch request → students → generated appointments

```php
$batch = App\Models\BatchRequest::where('status', 'approved')->first();

$batch->college;            // College
$batch->requester;          // User (the college admin)
$batch->reviewer;           // User (the director)

// The pivot rows — each links one student to one generated appointment
$batch->batchRequestStudents;

$batch->batchRequestStudents->each(function ($pivot) {
    echo $pivot->student->studentProfile->first_name;
    echo $pivot->appointment?->reference_no;  // APT-2026-0001 etc.
});

// Eager-load the whole batch chain
$batch->load([
    'batchRequestStudents.student.studentProfile',
    'batchRequestStudents.appointment.clinicVisit',
]);
```

### Walk a full encoded visit

```php
// Pick any encoded visit
$visit = App\Models\ClinicVisit::where('status', 'encoded')
    ->with(['student.studentProfile.college', 'vitalSigns', 'screeningResponse', 'clearanceRecord.encoder'])
    ->first();

$visit->student->studentProfile->first_name;
$visit->vitalSigns->bmi;
$visit->vitalSigns->is_bp_flagged;           // true/false (cast to bool)
$visit->clearanceRecord->result;             // "Fit" or "Unfit"
$visit->clearanceRecord->encoder->name;      // nurse name
```

### Flagged anomalies query (Director dashboard)

```php
// All visits with at least one flag — the Director's anomaly feed
$flagged = App\Models\VitalSigns::where(function ($q) {
        $q->where('is_bp_flagged', true)
          ->orWhere('is_temp_flagged', true)
          ->orWhere('is_bmi_flagged', true);
    })
    ->with('clinicVisit.student.studentProfile.college')
    ->get();
```

---

## Tinker — ReferenceNumberService

```php
$svc = app(App\Services\ReferenceNumberService::class);

$svc->generateAppointmentRef();   // "APT-2026-0001" (increments each call)
$svc->generateBatchRef();         // "BR-2026-001"
$svc->generateVisitRef();         // "HP-2026-0001"
```

**Always call from inside `DB::transaction()`** in real code so the lock covers
the INSERT. Example in a controller action:

```php
use App\Services\ReferenceNumberService;
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($request, $svc) {
    $appointment = App\Models\Appointment::create([
        'reference_no'   => $svc->generateAppointmentRef(),
        'student_id'     => auth()->id(),
        'service_type'   => $request->service_type,
        'scheduled_date' => $request->scheduled_date,
        'status'         => 'scheduled',
        'source'         => 'self',
    ]);
});
```

---

## Useful one-liners

```php
// Capacity check for a given date (§5.1 / BR-02)
$date = '2026-07-01';
$count = App\Models\Appointment::whereDate('scheduled_date', $date)
    ->whereNotIn('status', ['cancelled'])
    ->count();
$capacity = config('healthpass.clinic_capacity');
$isFull = $count >= $capacity;

// Live queue (nurse view — oldest first)
App\Models\ClinicVisit::where('status', 'captured')
    ->with(['student.studentProfile', 'vitalSigns'])
    ->oldest('checked_in_at')
    ->get();

// All students scoped to one college (admin scoping pattern)
$collegeId = auth()->user()->managed_college_id;
App\Models\StudentProfile::where('college_id', $collegeId)
    ->with('user')
    ->get();
```

---

## Booking UX — Day 15 S2

- **Confirm-before-book:** clicking "Confirm Booking" opens an in-page confirmation modal
  ("Book {Service} on {date}?" → Yes, book it / Cancel) before the `POST` fires. The form
  submits via `fetch` with `Accept: application/json`; success returns 201 and routes to
  the confirmation screen; a BR-04 / FR-STU-05 duplicate returns 422.
- **Duplicate rejection as modal:** a 422 response is surfaced as an in-page modal with a
  "Choose another date" action — no page reload, selected service and date preserved.
- **Cancel from dashboard:** the Next Appointment card's "Cancel appointment" ghost action
  opens a confirm modal; on confirm the JS re-queries the nearest upcoming non-cancelled
  appointment via `fetch` and swaps the card content — no full page reload.
- **No schema change. No new packages.**

---

## Kiosk staff exit (FR-KSK-16) — Day 27

The kiosk is a locked-down public terminal: the Blade page (`kiosk/index.blade.php`)
has **no app nav or sidebar**, the hidden QR wedge re-focuses itself on blur, and on
the Pi, Chromium runs `--kiosk` (no URL bar, back button, or gestures). So a student
has **no way to navigate out of `/kiosk`**. The dev screen-jumper is `@includeWhen(app()->isLocal())`,
so it never ships to the Pi.

The **only** exit is a discreet staff gesture:

- **Gesture:** an invisible 40×40 hotspot in the **top-left corner**, present on every
  screen (`index.blade.php`). **5 taps within ~3 s** of the first tap open the prompt
  (`exitTap()` in `state-machine.js`); stray taps reset the count. Top-left is chosen so
  it never collides with the vitals **top-right** triple-tap manual-entry logo (FR-KSK-06).
- **Prompt:** a modal (`partials/staff-exit.blade.php`) reusing the login fields + the
  shared on-screen keyboard (`partials/credential-keyboard.blade.php`, also used by the
  email-login screen). It asks for a **nurse email + password**.
- **Why email, not just a password:** the spec says "nurse password", but the redirect
  target `/nurse/queue` is auth-gated — a bare password check that redirected would just
  bounce to `/login`. So `POST /kiosk/exit` (`KioskController@exit`) **authenticates** the
  nurse (`Auth::login` + `session()->regenerate()` against session fixation) and the page
  navigates straight into the queue, already signed in. An email is needed to know *which*
  nurse to log in.
- **Nurse-only.** Students, college admins, the director, wrong passwords, inactive and
  unknown accounts all get the same generic 422 (constant-time hash check, like the
  student login) and the request stays a guest. Route is throttled `10,1` per IP.
- **Tests:** `tests/Feature/Kiosk/KioskExitTest.php` (6 cases).

### Day 27 end-to-end verification (W4 exit criterion)

Ran the full flow three ways against the **seeded MySQL DB**, each inside a rolled-back
transaction so the seed was left untouched (clinic_visits 8 → 8):

| Path | Result |
|---|---|
| (a) booked student via QR (typed token) | scan → identity (Nathaniel Medina), submit → `HP-…`, **`appointment_id` linked to today's appt** |
| (b) walk-in via email login | login → identity (Maria Reyes), submit → `login_method=email`, `appointment_id=NULL` (BR-10) |
| (c) declined consent | Decline calls `reset()` client-side, **no server call** → nothing written; submit is the only writer |

All submissions used `vitalMethods = ['manual','manual','manual','manual']`, persisting
`entry_method=manual` — confirming **the kiosk is completable end-to-end on manual entry
alone**. Server-side flag booleans (temp/BP/BMI) computed from `config/healthpass.php`.
Backed by `php artisan test tests/Feature/Kiosk` — **22 passing**.

**Gesture map (don't confuse the two):**

| Gesture | Where | Action |
|---|---|---|
| **3 taps** on the logo | **vitals screen, top-RIGHT** | manual-entry numeric pad (FR-KSK-06) |
| **5 taps** on the corner | **every screen, top-LEFT** (invisible 48px hotspot) | staff-exit prompt (FR-KSK-16) |

## Kiosk Web Serial sensors (FR-KSK-07 / FR-HW-05) — Week 5

The sensor path is its own module — `resources/js/kiosk/serial.js` — kept free of
Alpine so the parsing/I/O is easy to read and test. The state machine
(`state-machine.js`) owns the callbacks and decides what a reading means; the
module just opens the port, buffers bytes into lines, parses them, and calls back.

**Browser support:** Chromium **only**, and only in a **secure context**. On the Pi
that is `http://localhost`, which counts as secure (decision **D-9**) — this is why
the app runs *on* the Pi. Any other browser has no `navigator.serial`; the kiosk
detects that (`serial.supported === false`) and silently falls back to manual entry.
Loopback dev URLs (`127.0.0.1`) are also treated as secure, so Web Serial works in
local dev on Chrome/Edge too.

**Serial contract (§11.2):** one newline-terminated ASCII line, e.g.

```
H:163;W:64;T:37.9;BP:145/92;HR:78
```

`parseReadingLine()` translates the wire keys into the internal sensor letters the
`VITALS` config uses: `H W T` pass through, `HR` → `R` (heart rate), and `BP:145/92`
splits into `S:145` (systolic) + `D:92` (diastolic). Partial lines are valid (only
the keys present are consumed), unknown keys are ignored (forward compatibility), and
any malformed token/line is dropped silently — a bad line never crashes the step.

**Routing to the current step:** the MCU may stream the *whole* line every cadence
tick. `onSerialReading()` filters each parsed line down to the **current** vital
step's fields before handing it to the existing `receiveReading()` path, so a full
line fills only the step the student is on (FR-KSK-05) — not all four at once. It
only acts while the step is still `ready`, so a captured/mid-scan reading is never
silently overwritten.

**Connecting:** `requestPort()` needs a user gesture, so the vitals *ready* phase
shows a **"🔌 Connect sensor"** button (Chromium only) — the tap is the gesture.
Once a port is granted the browser remembers it for the origin, so on later loads
`autoConnect()` reopens it via `getPorts()` with **no** gesture — that is how the
unattended kiosk comes back after a reboot (FR-HW-05).

**Disconnect / reconnect (FR-HW-05):** cable jiggle or MCU reset fires the
`navigator.serial` `disconnect` event → a non-blocking "reconnecting…" notice, no
page reload. When the same port returns, the `connect` event auto-reopens it.

**Degradation (FR-KSK-07 — never a dead end):** no Web Serial API, a dismissed port
picker, a 10 s read timeout (connected but silent), a read error — each surfaces a
short non-blocking notice while **manual entry stays available** (the disguised
corner triple-tap, FR-KSK-06). Manual entry needs no sensor at all.

**Config (single source of truth):** `config/healthpass.php → kiosk.serial_baud`
(9600, must match MCU firmware) and `kiosk.serial_timeout_ms` (10000). Injected into
the page via `index.blade.php`'s `data-config`, read once by `setupSerial()`.

**Dev testing without hardware:** the **⚡ Simulate reading** button (local only) is
untouched — it injects a plausible reading for the current step through the exact
same `receiveReading()` path the real sensor uses. To exercise the parser directly,
`kiosk.js` exposes it on `window` under `npm run dev` only (stripped from prod):

```js
// in the browser console on /kiosk (dev server running)
parseReadingLine('H:163;W:64;T:37.9;BP:145/92;HR:78');
// → {H:163, W:64, T:37.9, S:145, D:92, R:78}
```

(Don't `import('/resources/js/kiosk/serial.js')` — that path 404s because Laravel
serves the page while Vite serves the JS bundle from its own dev-server origin.)

To test against a real MCU on Windows dev, wire the Arduino/ESP32 over USB, run
`npm run dev` + `php artisan serve`, open `/kiosk` in Chrome, reach a vital step, tap
**Connect sensor**, and pick the COM port. (`vendor:product` filters can be added to
`requestPort()` later to skip the picker's noise.)

### CSRF token mismatch (419) on kiosk POSTs — fixed

**Symptom:** every kiosk POST (scan / login / submit / staff-exit) returned a 419
"CSRF token mismatch", while the website login was fine.

**Cause:** the kiosk page bakes a CSRF token at render time (`data-csrf`). The kiosk is a
**long-lived page** — if it outlives its server session (a `php artisan serve` restart,
`migrate:fresh --seed` which truncates the `sessions` table, session expiry, or logging
into the website in the same browser, which regenerates the session), the baked token no
longer matches the server's session token → 419 on every POST until the page is reloaded.
The website "works" only because each visit loads a fresh page + token. Server-side CSRF
was never broken (a fresh page POSTs fine).

**Fix:** the kiosk now self-heals. All four POSTs go through one wrapper (`kioskPost()` in
`state-machine.js`); on a 419 it GETs a fresh token from `GET /kiosk/token`
(`KioskController@token`) — which re-establishes a session and returns the current token —
updates `data-csrf`, and retries the request **once**. Verified live: stale token → 419 →
`/kiosk/token` → retry → 200/422 (CSRF passes). If you still see a 419 after this, just
reload `/kiosk` once to mint a fresh session.

---

## Pi deployment notes (gotchas hit during real Pi bring-up)

Companion to `docs/deployment-pi.md` — the things that bit us on the actual
Raspberry Pi 4 (Pi OS Bookworm 64-bit, Wayland/labwc) that the guide now bakes in.

- **Run artisan as `www-data` on the Pi.** Every artisan command in the §6
  update cycle (`migrate --force`, `config:cache`, `route:cache`, `view:cache`,
  …) must run as `sudo -u www-data php artisan …`. `storage/` and
  `bootstrap/cache/` are owned by `www-data`; running artisan as your login user
  writes root/pi-owned cache and log files that php-fpm (running as `www-data`)
  then can't read, and the app 500s. (This does not apply to local Windows dev —
  only the Pi, where php-fpm serves as `www-data`.)

- **nginx symlink — no trailing slash.** Enable the site with
  `ln -sf …/sites-available/healthpass …/sites-enabled/healthpass`. A **trailing
  slash** on the source (`…/sites-available/healthpass/ …`) creates a **broken
  directory symlink**; `sudo nginx -t` then fails with a misleading
  "No such file or directory". Link the file, not a directory.

- **Port 80 already in use.** If `sudo systemctl restart nginx` fails to bind,
  another service is holding port 80. Find it with `sudo ss -tlnp | grep :80`
  and disable the conflicting service, then restart nginx.

- **CRLF line endings on shell scripts.** A `.sh` checked out with Windows CRLF
  fails on the Pi with a misleading "No such file or directory" (it's the `\r`
  in the shebang, not a missing file). On-Pi one-off fix:
  `sed -i 's/\r$//' scripts/pi/*.sh`. **The repo is already protected against
  this** — root `.gitattributes` has `* text=auto eol=lf` and the committed
  script blobs are LF (verified), so a *fresh* clone is clean; the `sed` is only
  needed for an older checkout made before that rule, or a file hand-copied from
  Windows.

- **Slow kiosk appearance after boot is expected.** The systemd `ExecStartPre`
  curl loop (deployment-pi.md §4) deliberately waits for nginx/php-fpm/MariaDB to
  answer `http://localhost/kiosk` before launching Chromium, so the terminal
  never opens on a connection-refused page. A few seconds of black/desktop before
  Chromium appears is the loop doing its job, not a hang.

---

## Kiosk failure modes (defense Q&A)

Hardening pass over the serial + kiosk path (Jul 2026). Every mode below is
covered by an automated test — JS via `npm run test:js` (Node's built-in
runner, zero packages: `tests/js/serial-parser.test.js` +
`tests/js/kiosk-state-machine.test.js`) and PHP via
`php artisan test --filter=KioskSubmitTest`. Use this table when the panel asks
"what happens if…".

| # | Failure mode | What the kiosk does | Where |
|---|---|---|---|
| 1 | **Garbage bytes / binary noise on the wire** | `parseReadingLine()` skips every malformed token; a wholly malformed line parses to `{}` and the reader drops it and keeps listening. Nothing throws, the step stays usable. | `serial.js` parser |
| 2 | **Half line (chunk torn mid-value, e.g. `H:16` of `H:163`)** | Bytes are buffered until a newline completes the line (`drainLines()`), so a truncated number is **never parsed early**. The torn halves reassemble across chunks. If a dropped newline welds two tokens (`H:16H:163`), the welded value isn't numeric → token skipped, no fake reading. | `serial.js` reader |
| 3 | **Unknown keys (`SPO2:98`)** | Ignored; known keys on the same line still parse. Future firmware can add keys without breaking deployed kiosks. | `serial.js` parser |
| 4 | **Absurd-but-parseable values (`T:99`, `H:999`)** | The parser hands them through *by design* — range policy lives in one place, not two. The state machine then fails them against `config/healthpass.php` ranges: the step falls back to **ready** with a "try again or enter it manually" nudge. Never captured, never a crash. | `captureStepFromSensor()` |
| 5 | **Absurd values that somehow reach the server** (bypassed client) | `KioskSubmitRequest` re-validates every vital against the same config bounds → **422, zero rows written** (the write is one transaction). Non-numeric garbage, SQL-ish strings, and nested arrays also 422 — never a 500. | Form Request + `SubmitKioskVisit` |
| 6 | **Half a BP pair (`BP:145/`)** | The pair is dropped whole — a lone systolic is meaningless. Likewise an incomplete BP *group* (systolic+diastolic but no heart rate) is rejected at capture: all of a step's fields must arrive together. | parser + `captureStepFromSensor()` |
| 7 | **Sensor unplugged mid-step** | `disconnect` event → non-blocking notice ("Sensor unplugged — reconnecting… Manual entry still works."). No modal, no dead end: the disguised corner triple-tap still opens the numeric pad, same validation ranges, provenance recorded as `manual`. When the same port returns, it auto-reopens silently (FR-HW-05). | `serial.js` events + `onSerialStatus()` |
| 8 | **Sensor connected but silent ≥ 10 s** | Watchdog fires a `timeout` status → "The sensor is quiet. Wait a moment, or enter it manually." Re-armed by every good line, so a healthy stream never trips it. | `serial.js` watchdog |
| 9 | **Slow measurement vs the 90 s idle reset** | Any serial line arriving while the current step is still **uncaptured** now pings the idle timer (`onSerialReading()` → `bumpIdle()`), so a slow BP cuff can't be idle-reset mid-measurement while the hub streams other keys. Once the step is **captured**, lines stop counting — an abandoned session still resets and wipes the student's data (FR-KSK-15). Off the vitals screen, serial traffic never touches the timer. | `onSerialReading()` |
| 10 | **Totally silent abandon** (no taps, no serial) | The 90 s idle reset fires as before: wholesale `freshState()` replacement + server-side identity forget. Residual: a student parked on an *uncaptured* vitals step while the hub streams indefinitely won't idle-reset (indistinguishable from a slow measurement) — acceptable because no vitals are on screen yet and the next student's scan overwrites the session identity anyway. | `bumpIdle()` / `reset()` |
| 11 | **Mixed sensor + manual session** | Each step records its own provenance; the client sends the list and the **server** rolls it up: all-sensor → `sensor`, all-manual → `manual`, any mix → `mixed` (`vital_signs.entry_method`, FR-KSK-06). Values outside `sensor|manual` are rejected (422), not coerced. | `SubmitKioskVisit::entryMethod()` |

**Test entry points**

```
npm run test:js                              # parser + state machine (26 tests)
php artisan test --filter=KioskSubmitTest    # server-side re-validation (17 tests)
```

Note for Baldo: `state-machine.js` now imports `./serial.js` **with the
extension** — Node's test runner needs it (Vite doesn't care). Keep the
extension on any new kiosk-module imports so the JS tests keep running.
