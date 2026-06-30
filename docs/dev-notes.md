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
