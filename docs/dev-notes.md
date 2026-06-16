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
