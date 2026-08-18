# HealthPass adviser revisions — session prompts

Five prompts, in execution order. **Run each in its own fresh session.**
Each prompt assumes the previous one is finished and committed.

Source: adviser review by A. Viscayno. Decisions were locked with Nat on
**2026-08-18** and are baked into each prompt — do not re-litigate them
in-session.

| # | Prompt | Decision | Schema change | Skill to invoke alongside |
|---|--------|----------|---------------|---------------------------|
| 1 | Program catalog + cascading form | D-42 | none | `/laravel-tdd` |
| 2 | SHS removal, reseed, visit program snapshot | D-43 | `clinic_visits.course` | `/laravel-tdd` |
| 3 | Nurse Dashboard (encode history) | D-44 | none | `/laravel-tdd` |
| 4 | Shared analytics service + College Admin analytics | D-45 | none | `/laravel-security` |
| 5 | Printable monthly report + Director program card | D-46 | none | `/verify` |
| 6 | Director super-admin console (staff provisioning) | D-47 | none | `/laravel-security` |

**Branch for all five:** `feature/advisor-revisions` (already exists, branched
off `main`, currently level with it). Do **not** create a new branch per prompt.
One commit per prompt; merge to `main` after Prompt 5.

**Ordering rationale:** 1 before 2 because the seeders in 2 read the config file
that 1 creates. 2 before 3 because the nurse history table shows the per-visit
program snapshot that 2 adds. 4 before 5 because 5 renders the report from the
service that 4 extracts. 3 is otherwise independent and can be moved earlier if
you want a quick win first. 6 is independent of all of them — it shares no code
with 1–5 — but is placed last deliberately: it answers a question the adviser
raised after the others were scoped, and it is the safest one to cut if the
Aug 28 freeze gets tight.

**Why one decision per prompt (D-42 … D-47):** each prompt writes its own PRD
decision row, its own revision-history row, and its own FR changes, so no prompt
is blocked on another prompt's doc edits landing first.

---

## PROMPT 1 — Program catalog + cascading form (D-42)

```
Work on the existing branch feature/advisor-revisions. Do not create a new
branch. Read docs/HealthPass_PRD.md sections 5 (FR-REG, FR-STU) and 14
(Decisions Log) before starting.

CONTEXT
Our faculty adviser requires monthly clinic reports broken down per academic
program for each college. Today student_profiles.course is FREE TEXT — a plain
text input on registration (resources/views/auth/register/step2.blade.php around
line 145) and on the student profile edit modal
(resources/views/student/id-profile.blade.php around line 355), validated only
with max:120. Free text cannot be grouped reliably, so per-program reporting is
impossible. This prompt makes the program a validated, college-dependent
dropdown. The reporting itself comes in later prompts — do NOT build any
analytics here.

DECISIONS ALREADY LOCKED — implement these, do not propose alternatives:
- The catalog lives in a PHP CONFIG FILE, not a database table. No new table, no
  foreign key, no migration, no backfill. This is deliberate: it keeps the PRD's
  10-table canon intact and there is no admin CRUD requirement.
- The column KEEPS the name `course`. Do not rename it. The official DHVSU form
  (D-25) prints "Course, Year & Section", so `course` is the correct domain term.
  The UI labels it "Program".
- Majors are FLAT entries in one dropdown ("Bachelor of Secondary Education major
  in English" is its own option). No second cascade level, no separate major
  column.
- Year level ALSO becomes college-dependent, from the same config file.

SCOPE — do exactly these, nothing else:

A. NEW FILE config/programs.php
   Keyed by college CODE, each entry holding a 'programs' list and a
   'year_levels' map of storage-key => display-label.

   Use EXACTLY this catalog (11 colleges — Senior High School is deliberately
   absent; it is removed in Prompt 2):

   COE — College of Education (year levels 1-4)
     Bachelor of Elementary Education
     Bachelor of Early Childhood Education
     Bachelor of Secondary Education major in English
     Bachelor of Secondary Education major in Filipino
     Bachelor of Secondary Education major in Mathematics
     Bachelor of Secondary Education major in Social Studies
     Bachelor of Secondary Education major in General Science
     Bachelor of Secondary Education major in Methods of Teaching
     Bachelor of Physical Education
     Bachelor of Culture and Arts Education
     Bachelor of Technology and Livelihood Education major in Home Economics
     Bachelor of Technology and Livelihood Education major in Industrial Arts
     Bachelor of Technical-Vocational Teacher Education major in Food and Service Management
     Bachelor of Technical-Vocational Teacher Education major in Garments Fashion and Design
     Bachelor of Technical-Vocational Teacher Education
     Bachelor of Science in Exercises and Sports Science major in Fitness and Sports Management
     Bachelor of Science in Exercises and Sports Science major in Fitness and Sports Coaching

   CEA — College of Architecture and Engineering (year levels 1-5)
     Bachelor of Science in Civil Engineering
     Bachelor of Science in Computer Engineering
     Bachelor of Science in Electrical Engineering
     Bachelor of Science in Electronics Engineering
     Bachelor of Science in Industrial Engineering
     Bachelor of Science in Mechanical Engineering
     Bachelor of Science in Architecture

   CBS — College of Business Studies (year levels 1-4)
     Bachelor of Science in Business Administration major in Marketing Management
     Bachelor of Science in Business Administration major in Business Economics
     Bachelor of Science in Entrepreneurship
     Bachelor of Science in Accountancy
     Bachelor of Science in Accounting Info Systems
     Bachelor of Public Administration
     Bachelor of Science in Real Estate Management
     Bachelor of Science in Legal Management
     Bachelor of Science in Logistics and Supply Chain Management

   CAS — College of Arts and Science (year levels 1-4)
     Bachelor of Science in Environmental Science
     Bachelor of Science in Mathematics
     Bachelor of Science in Statistics
     Bachelor of Science in Biology
     Bachelor of Science in Nursing

   CSSP — College of Social Science and Philosophy (year levels 1-4)
     Bachelor of Science in Psychology
     Bachelor of Science in Human Services
     Bachelor of Science in Sociology
     Bachelor of Science in Social Work
     Bachelor of Science in Social Work (Second Degree - Trimester)

   CCS — College of Computing Studies (year levels 1-4)
     Associate in Computer Technology
     Bachelor of Science in Information Technology
     Bachelor of Science in Information Systems
     Bachelor of Science in Computer Science

   CHTM — College of Hospitality and Management (year levels 1-4)
     Bachelor of Science in Hospitality Management
     Bachelor of Science in Tourism Management
     Bachelor of Science in Tourism Management Major in Events Management

   CIT — College of Industrial Technology (year levels 1-4)
     Bachelor of Science in Industrial Technology major in Automotive Technology
     Bachelor of Science in Industrial Technology major in Electronics Technology
     Bachelor of Science in Industrial Technology major in Electrical Technology
     Bachelor of Science in Industrial Technology major in Garments Fashion and Design
     Bachelor of Science in Industrial Technology major in Food and Service Management
     Bachelor of Science in Industrial Technology major in Mechatronics
     Bachelor of Science in Industrial Technology major in Graphics Technology
     Bachelor of Science in Industrial Technology major in Wellness and Beauty Care
     Bachelor of Science in Industrial Technology major in Instrumentation and Control
     Bachelor of Science in Industrial Technology major in Mechanical Technology
     Bachelor of Science in Industrial Technology major in Woodworking Technology
     Bachelor of Science in Industrial Technology major in Welding Technology

   LAW — School of Law (year levels 1-4)
     Juris Doctor (Law)
     Juris Doctor Bridge Program

   GS — Graduate Studies (year levels 1-2)
     Doctor of Education major in Educational Management
     Doctor of Public Administration
     Master in Information Technology
     Master of Arts in Education major in Educational Management
     Master of Arts in Education major in English
     Master of Arts in Education major in Filipino
     Master of Arts in Education major in Mathematics
     Master of Arts in Education major in Physical Education
     Master of Arts in Education major in Social Studies
     Master of Arts in Education major in General Science
     Master of Arts in Education major in Technology and Livelihood Education
     Master of Public Administration
     Master of Public Administration major in Regulatory Management Systems
     Master of Business Administration
     Master of Science in Social Work
     Master of Engineering Management
     Master of Arts in Guidance and Counseling

   LHS — Laboratory High School
     Laboratory High School (Grade 7 to 10)
     year levels: 7 => Grade 7, 8 => Grade 8, 9 => Grade 9, 10 => Grade 10

   Four editorial calls already made on the adviser's raw list — put a short
   comment in the config recording each, and FLAG them in your summary so Nat
   can confirm with the adviser:
     1. "Bachelor of Technical-Vocational Teacher Education" appeared TWICE
        under COE (once with two majors, once bare). Kept as three entries: two
        majored plus one bare general track. No literal duplicate.
     2. "Methods of Teaching" was indented under the BSEd majors, so it is
        recorded as "Bachelor of Secondary Education major in Methods of
        Teaching".
     3. GS entries carrying "(Academic and Professional Track)" keep the base
        program name only — a track is not a separate program. The one exception
        is MPA major in Regulatory Management Systems, kept because it names a
        major.
     4. Dropped the "(New!)" marker from Master in Information Technology and
        fixed the doubled "in in" in the CAS Biology entry.
   Note also: Associate in Computer Technology is a 2-year program sitting in a
   1-4 college. Year levels are per COLLEGE, not per program — leave it, and say
   so in your summary. Do not build per-program year levels.

B. NEW FILE app/Support/Programs.php
   Follow the conventions of the existing App\Support classes (Otp.php,
   VisitMonths.php, TrustedProxies.php): final class, declare(strict_types=1),
   static methods, docblocks that explain WHY.
     - forCollege(int collegeId): array           — program list for that college
     - yearLevelsForCollege(int collegeId): array — key => label map
     - all(): array                               — the whole map keyed by
                                                    college ID (not code), for
                                                    the JS payload
     - isValid(int collegeId, string course): bool
   Resolve college id -> code once per request; an unknown id returns an empty
   array and never throws — a hand-edited form must fail validation, not 500.

C. Validation — the server is the authority
   app/Http/Requests/Auth/StoreRegistrationInfoRequest.php and
   app/Http/Requests/Student/UpdateStudentProfileRequest.php: course becomes a
   Rule::in() over Programs::forCollege() for the SUBMITTED college_id, and
   year_level a Rule::in() over the keys of yearLevelsForCollege().
   The client-side cascade is UX only — a POST naming another college's program
   MUST be rejected. Add messages() entries so an empty or invalid college
   produces "Select your college first, then choose your program." rather than a
   bare "selected course is invalid".

D. The two forms — cascading selects, Alpine
   Alpine is the house framework (already registered in resources/js/app.js) —
   use it, do not hand-roll DOM swaps and do not add a package.
   - resources/views/auth/register/step2.blade.php: replace the Course text
     input with an x-hp.select labelled "Program" that is DISABLED until a
     college is chosen and repopulates when the college changes. The Year Level
     select is driven by the same map instead of the hardcoded 1-5 list
     currently at line 163.
   - resources/views/student/id-profile.blade.php: same treatment inside the
     Edit modal, pre-selected from the saved values. When the student changes
     college (they can — FR-STU-09/D-17 allows transfers), the program select
     must clear and repopulate rather than keep a now-invalid program.
   - Ship the map into the Alpine x-data with @json(Programs::all()). Keep it
     beginner-readable — Nat and Baldo are new to Laravel, so add a one-line
     comment explaining what the x-data object is doing.
   - The hardcoded ordinal map at the top of id-profile.blade.php (lines 4-8,
     '1' => '1st Year' and so on) must now read from the config, so an LHS
     student displays "Grade 8" and not a bare "8".

E. Tests (tests/Feature/)
   - registration rejects a program belonging to a different college
   - registration rejects a year level the chosen college does not offer
     (year 5 for CCS, year 3 for GS)
   - registration accepts a valid college + program + year triple
   - profile edit rejects a cross-college program
   - profile edit changing college to one that does not offer the current
     program is rejected unless the program changes too
   EXISTING SUITES THAT POST course WILL BREAK — they send arbitrary strings.
   Fix them to use real catalog values: tests/Feature/Auth/RegistrationTest.php,
   tests/Feature/AuthSecurityTest.php,
   tests/Feature/Student/IdProfilePageTest.php. Search the whole tests/ tree for
   "course" before declaring done.

OUT OF SCOPE — do not touch in this prompt:
- Seeders, factories, and the SHS college (Prompt 2 handles all of them). The
  suite still passes because the factory writes its own course strings and this
  prompt adds no DB constraint.
- Any analytics, any nurse page, any migration.

VERIFICATION (do not declare done without this):
- php artisan test — full suite green, including your new tests.
- php artisan serve --port=8080 and npm run dev, then at 127.0.0.1:8080/register:
  the Program select is disabled with no college chosen; picking CCS lists
  exactly its 4 programs; switching to CIT clears the choice and lists its 12;
  picking LHS shows Grade 7-10 in Year Level. Complete one registration.
- Log in as a student, open My ID & Profile, edit, change college, confirm the
  program select clears and repopulates, and save.
- With devtools, submit a forged program value for another college and confirm
  the server rejects it.

DOCS TO UPDATE IN THIS SAME CHANGE:
- docs/HealthPass_PRD.md
  * Amend FR-REG-03: program and year level are college-dependent dropdowns
    sourced from config/programs.php, validated server-side.
  * Amend FR-STU-09: the profile edit uses the same cascade; changing college
    re-scopes the program list.
  * Decisions Log: add D-42 — "Academic programs become a validated,
    college-dependent catalog held in config, not a table. student_profiles.course
    keeps its name and stays a string; majors are flat entries; year level becomes
    college-dependent. NO schema change." Record the four editorial calls from
    section A.
  * Revision History: add row v1.17 dated August 18, 2026.
- docs/HealthPass_Context.md — against student_profiles.course and year_level,
  note that the value set is config-backed (config/programs.php) and is NOT a
  database table, so the 10-table canon is unchanged.
- CHANGELOG.md — one entry under Unreleased.

CONSTRAINTS:
- No new packages. Tailwind + Alpine only.
- Keep raw SQL portable — the suite runs on SQLite in-memory, dev/prod is MySQL.
- If anything here conflicts with the PRD, STOP and say so before coding.

DO NOT COMMIT. Leave everything uncommitted in the working tree and print a
summary of changed files plus the flagged editorial calls when done.
```

---

## PROMPT 2 — SHS removal, reseed, visit program snapshot (D-43)

```
Work on the existing branch feature/advisor-revisions. Do not create a new
branch. Read docs/HealthPass_PRD.md section 6 (data dictionary) and section 14
(Decisions Log, especially D-17) before starting.

PRECONDITION: config/programs.php and app/Support/Programs.php already exist
(added by the previous session, decision D-42). If they do not, STOP — this
prompt depends on them.

CONTEXT
Two things, both consequences of making programs real data.

(1) Senior High School must be removed. It is not listed on the PSU main-campus
    (bicolor) program offerings, so it is not a real unit for us. Colleges go
    12 -> 11. SHS is wired into more than the college seeder: database/seeders/
    CollegeSeeder.php, StaffSeeder.php (an SHS administrator account, ~line 69),
    DemoClinicVisitSeeder.php (~line 63), StudentSeeder.php (2 SHS students),
    and the 12-college wording in the PRD and Context docs.

(2) Per-program reporting must be transfer-proof. clinic_visits already freezes
    college_id at kiosk submit (D-17/FR-STU-09) precisely so a student who
    transfers does not rewrite history. The program needs the same treatment, or
    a program change would silently restate every past monthly report.

DECISIONS ALREADY LOCKED — implement these, do not propose alternatives:
- Nat has APPROVED wiping and reseeding the dev database. CLAUDE.md requires
  asking before destructive DB commands; this is that approval. Still announce
  the exact command before you run it.
- The snapshot column is clinic_visits.course, VARCHAR(120) NULL, mirroring
  student_profiles.course. Nullable, and pre-existing rows are NEVER backfilled
  — they render as "—", exactly the pattern appointments.scheduled_time uses.
- No new table.

SCOPE — do exactly these, nothing else:

A. Migration: add course to clinic_visits
   string('course', 120)->nullable()->after('college_id'). Add course to the
   $fillable array on app/Models/ClinicVisit.php. Comment the column the way
   college_id is commented — it is a CAPTURE-TIME SNAPSHOT, not a live lookup.

B. app/Actions/Kiosk/SubmitKioskVisit.php
   The private studentCollegeId() helper (~line 104) currently fetches only
   college_id and throws a RuntimeException when it is missing. Widen it to
   fetch college_id AND course in ONE query and freeze both on the visit. Keep
   the existing fail-loudly behaviour for a missing college. A missing course
   must NOT throw — legacy profiles may predate the catalog; store null.
   Kiosk endpoints must never trust client-supplied identity (CLAUDE.md) — the
   course comes from the server-side bound student, never from the request body.

C. Seeders and factory
   - CollegeSeeder.php: remove the SHS row. 11 colleges remain.
   - StaffSeeder.php: remove the SHS administrator account.
   - StudentSeeder.php: remove the 2 SHS students (adjust the count comment at
     ~line 51), and give every student a REAL program from config/programs.php.
   - DemoClinicVisitSeeder.php: remove the SHS bucket and set the course
     snapshot on the visits it creates, so the later analytics prompts have
     per-program demo data to render.
   - database/factories/StudentProfileFactory.php: DELETE the private $courses
     and $yearLevels arrays (lines ~45-73) and read config/programs.php instead,
     so there is ONE source for the catalog. forCollege() keeps working the same
     way from the caller's point of view.

D. Fix a live bug while you are here
   year_level is stored as '1'-'5' by the app (register step2 line ~163,
   validated in:1,2,3,4,5) but the seeder and factory write '1st Year',
   'Grade 11' and similar. A seeded student who opens My ID & Profile, edits and
   saves currently gets "Please select a valid year level (1-5)". Make the
   seeder and factory write the config KEYS ('1', '2', ... and '7'-'10' for
   LHS). Add a regression test that a SEEDED student can save a profile edit.

E. Reseed and tests
   - Announce, then run, the reseed.
   - tests/Feature/Seeders/: colleges total 11 and no SHS code exists.
   - a kiosk submit stores the student's course on the visit
   - changing the student's course AFTER the visit does not change the visit's
     stored course (this is the whole point of the snapshot)
   - a student whose profile has no course still submits successfully, with null
   - the regression test from D above

OUT OF SCOPE:
- Any analytics, any nurse page, any new UI. Nothing reads clinic_visits.course
  yet — later prompts do. Adding the column and populating it is the whole job.

VERIFICATION (do not declare done without this):
- php artisan migrate, then the approved reseed. Confirm 11 colleges and that
  no SHS row, SHS admin account, or SHS student remains.
- php artisan test — full suite green.
- Run one kiosk session end to end and confirm the new clinic_visits row carries
  the student's program.
- Log in as a seeded student, edit the profile, save — no year-level error.

DOCS TO UPDATE IN THIS SAME CHANGE:
- docs/HealthPass_PRD.md
  * Data dictionary: add clinic_visits.course (capture-time program snapshot,
    nullable, never backfilled) and change the college list from 12 to 11 units,
    removing the SHS row.
  * Anywhere the text says "all 12 colleges" (FR-ANL-09 among them), change to
    11.
  * Decisions Log: add D-43 — "Senior High School is removed from the college
    list (12 -> 11); clinic visits snapshot the student's program at capture.
    FLAGGED SCHEMA CHANGE: clinic_visits.course VARCHAR(120) NULL, nullable and
    never backfilled, mirroring the college_id snapshot of D-17."
  * Revision History: add row v1.18 dated August 18, 2026.
- docs/HealthPass_Context.md — college table (remove SHS) and the clinic_visits
  schema block (add course).
- docs/dev-notes.md — the SHS admin account (~line 35) and the two SHS student
  logins (~lines 68-69) are now stale; remove them and refresh any account table
  affected by the reseed.
- CHANGELOG.md — one entry under Unreleased, noting the schema change.

CONSTRAINTS:
- Keep raw SQL portable — SQLite in tests, MySQL in dev/prod.
- Never backfill the new column on existing rows.
- If anything here conflicts with the PRD, STOP and say so before coding.

DO NOT COMMIT. Leave everything uncommitted and print a summary of changed
files, plus the exact reseed command you ran, when done.
```

---

## PROMPT 3 — Nurse Dashboard, encode history (D-44)

```
Work on the existing branch feature/advisor-revisions. Do not create a new
branch. Read docs/HealthPass_PRD.md section 5 (FR-NRS) and section 14 before
starting.

PRECONDITION: clinic_visits.course exists and is populated at kiosk submit
(added by an earlier session, decision D-43). If the column is missing, STOP.

CONTEXT
Our faculty adviser's review: every log the system produces should be visible on
the relevant role's dashboard, and he named the nurse's encode log specifically.
The nurse is the ONLY role with no history surface — students have My Records,
College Admins have Batch Tracking, the Director has Batch Approvals and Flagged
Anomalies. The nurse navigation
(resources/views/components/layout/sidebar.blade.php, the 'nurse' arm around
line 24) has only Live Queue and Enable Kiosk Mode, and the nurse's home is
/nurse/queue (App\Http\Middleware\EnsureRole, the home map around line 21).
Once a visit is encoded it leaves the Live Queue (ClinicVisit::scopeLiveQueue
filters status = captured) and is reachable only by typing its URL. After a
whole clinic day the nurse cannot see what they encoded.

DECISIONS ALREADY LOCKED — implement these, do not propose alternatives:
- ONE new page, /nurse/dashboard: stat cards on top, the filterable paginated
  encode history directly below. Not two pages, not a dashboard-plus-history
  pair.
- It becomes the nurse's HOME. Live Queue stays in the nav and is unchanged.
- The history is CLINIC-WIDE, not per-nurse — every nurse sees every encoded
  result, and the table names who encoded it. Two nurses on shift must still see
  one continuous log.

SCOPE — do exactly these, nothing else:

A. Route and navigation
   - GET /nurse/dashboard named nurse.dashboard, inside the existing
     ['auth', 'role:nurse'] group in routes/web.php.
   - App\Http\Middleware\EnsureRole: the nurse home becomes /nurse/dashboard.
   - sidebar.blade.php: Dashboard becomes the FIRST nurse nav item, above Live
     Queue. Follow the existing [label, route-name, icon-path] shape and reuse
     the dashboard icon path the student/admin/director arms already use.

B. NEW app/Http/Controllers/Nurse/DashboardController.php
   Single __invoke, thin — query building can live in private methods on the
   controller, this does not need a service.
   Four stat cards: encoded today, encoded this month, awaiting encode (the
   current captured count), flagged vitals this month.
   History query: start from App\Models\ClearanceRecord — it IS the encode log —
   eager-loading clinicVisit.student, clinicVisit.college and encoder. Order by
   encoded_at descending. Paginate 15 per page with withQueryString() so filters
   survive page changes.

C. NEW resources/views/nurse/dashboard.blade.php
   Use x-layout.sidebar like the other nurse pages, and the existing x-hp.card /
   x-hp.badge / x-hp.table components — match nurse/queue.blade.php's visual
   language, do not invent a new one.
   Columns: Reference No, Student, College, Program (clinic_visits.course, "—"
   when null), Result (Fit/Unfit as an x-hp.badge), Encoded by, Encoded at,
   Printed (yes/no from printed_at), Actions.
   Actions reuse what already exists — link to the read-only encode screen
   (nurse.visits.encode already renders read-only for an encoded visit via its
   $readOnly flag) and to the existing reprint route. Build no new detail page.
   Filters above the table: month (reuse App\Support\VisitMonths for the option
   list — it is already the month dimension for Director analytics), result
   (All / Fit / Unfit), and a search box matching student name or reference
   number. Submit by GET so the filter state is shareable and bookmarkable.
   Empty state: a friendly "No encoded results yet" panel, in the style of the
   Live Queue's empty state.

D. Tests — tests/Feature/Nurse/DashboardPageTest.php
   - a student, a college admin, and a director are all refused (role gate)
   - a nurse sees encoded visits, newest first
   - CAPTURED (not yet encoded) visits do NOT appear — they belong to the queue
   - the month filter narrows correctly
   - the result filter narrows correctly
   - the search matches by both student name and reference number
   - the Encoded by column shows the encoding nurse, and a second nurse's
     encodes are visible to the first nurse (clinic-wide, not per-nurse)
   - pagination keeps the active filters

OUT OF SCOPE:
- Do not touch the Live Queue, the encode screen, the print flow, or any
  analytics.

CRITICAL CONSTRAINT — SQLITE PORTABILITY:
The suite runs on SQLite in-memory while dev/prod is MySQL. Do NOT use MySQL-only
date functions (MONTH(), DAY(), DATE_FORMAT) in selectRaw or havingRaw. Filter
months with whereBetween on encoded_at using Carbon month bounds, the way
Director\AnalyticsController::monthBounds() already does it.

VERIFICATION (do not declare done without this):
- php artisan test — full suite green, including your new tests.
- php artisan serve --port=8080 and npm run dev. Log in as the nurse: you land
  on the dashboard, not the queue. Run a kiosk session, encode it, and confirm
  the row appears in the history with the correct program and encoder. Exercise
  every filter and page 2.
- Confirm Live Queue still works and still auto-polls.

DOCS TO UPDATE IN THIS SAME CHANGE:
- docs/HealthPass_PRD.md
  * Add FR-NRS-09: "Nurse Dashboard — the nurse's landing page shall show four
    stat tiles (encoded today, encoded this month, awaiting encode, flagged
    vitals this month) and a clinic-wide encode history of all clearance records,
    newest first, filterable by month and result and searchable by student name
    or reference number, paginated, showing the encoding nurse. Rows link to the
    existing read-only encode view and reprint action." Priority M.
  * Decisions Log: add D-44 — "The nurse gets a dashboard carrying the encode
    log; the history is clinic-wide with an Encoded-by column, and the dashboard
    replaces the Live Queue as the nurse's landing page. NO schema change."
    Record that this closes the adviser's 'logs visible on each dashboard' point,
    the nurse having been the only role without a history surface.
  * Revision History: add row v1.19 dated August 18, 2026.
- docs/qa/e2e-scenarios.md — add a scenario: kiosk submit -> nurse encodes ->
  the record appears in the dashboard history with the right program and encoder.
- CHANGELOG.md — one entry under Unreleased.

CONSTRAINTS:
- No new packages.
- Existing design tokens only (hp-orange, hp-peach, hp-slate, hp-bg) and the
  page must work in dark mode, which is site-wide for the web app since D-38.
- If anything here conflicts with the PRD, STOP and say so before coding.

DO NOT COMMIT. Leave everything uncommitted and print a summary of changed files
when done.
```

---

## PROMPT 4 — Shared analytics service + College Admin analytics (D-45)

```
Work on the existing branch feature/advisor-revisions. Do not create a new
branch. Read docs/HealthPass_PRD.md section 5 (FR-ADM, FR-ANL) and section 14
(D-32, D-37) before starting.

PRECONDITION: clinic_visits.course exists and carries the per-visit program
snapshot (decision D-43), and config/programs.php holds the catalog (D-42). If
either is missing, STOP.

CONTEXT
At our university a college can request a monthly report of its own students'
clinic results. Our adviser asked for that as analytics on the College Admin
dashboard, behaving like the Director's analytics but scoped to the admin's own
college and broken down PER PROGRAM (CCS, for example, has 4: IT, CS, IS, and
Associate in Computer Technology).

The Director already has exactly this machinery:
app/Http/Controllers/Director/AnalyticsController.php, ~390 lines, six cards —
Clinic Visits by College (FR-ANL-09), Visits by Purpose, Vital-Sign Flags
(FR-ANL-10), Visits per Month trend (FR-ANL-11), BMI Distribution (FR-ANL-12),
Students Screened by Sex (FR-ANL-04) — filtered by ?month= and ?college=
(FR-ANL-13). Duplicating those six queries into an admin controller would
guarantee drift.

DECISIONS ALREADY LOCKED — implement these, do not propose alternatives:
- EXTRACT a shared service and have both roles call it. Do not copy-paste the
  queries into a second controller.
- The College Admin page carries the SAME six cards, with Clinic Visits by
  College replaced by Clinic Visits by PROGRAM.
- The printable report and the Director's program card are the NEXT prompt. Do
  not build them here.

SCOPE — do exactly these, nothing else:

A. NEW app/Services/ClinicAnalytics.php
   Move the six card builders out of Director/AnalyticsController into this
   service, parameterized by (CarbonImmutable month, ?College college,
   ?string course). Move the colour constants (MEDICAL_COLOR, DENTAL_COLOR,
   SEX_COLORS, WALK_IN_LABEL) with them. Preserve the existing behaviour
   EXACTLY — same shapes, same keys, same sort and tie-break rules, same
   Chart.js payloads. This is a refactor, not a rewrite.
   Add one new builder: visitsByProgram(month, college, course) — one row per
   program of that college, split Medical / Dental, sorted by total descending
   with a stable alphabetical tie-break, and ZERO-VISIT PROGRAMS INCLUDED (the
   same rule FR-ANL-09 applies to colleges). Medical reads the clinic_visits.course
   snapshot; dental has no clinic_visits row, so it attributes through the
   student profile's current course, the same stated limitation dental already
   carries for college.
   The new course parameter narrows medical visits by the snapshot column and
   dental by the profile course. Null means all programs.

B. Director/AnalyticsController becomes thin
   Resolve the filters, call the service, pass to the view. Nothing else.
   ACCEPTANCE BAR FOR THE REFACTOR: tests/Feature/Director/AnalyticsPageTest.php
   is 401 lines and must pass COMPLETELY UNCHANGED. If you find yourself editing
   that file to make it pass, you have changed behaviour — revert and fix the
   service instead. It builds its own two colleges in setUp(), so Prompt 2's
   college removal does not affect it: there is NO legitimate reason to edit
   this file in this prompt.

C. NEW app/Http/Controllers/Admin/AnalyticsController.php
   Use the ScopedToManagedCollege trait
   (app/Http/Controllers/Admin/Concerns/) like every other admin controller.
   SECURITY, NON-NEGOTIABLE: the college is derived from
   $this->managedCollege(), NEVER from the request. There must be nothing for a
   ?college= parameter to override — FR-AUTH-06 / FR-ADM-06. If a ?college=
   appears in the query string it is ignored outright, not validated.
   Filters: ?month= (reuse App\Support\VisitMonths::resolve) and ?program=,
   validated against Programs::forCollege() for the MANAGED college; an unknown
   program degrades to "All programs" rather than erroring, matching how
   Director resolveCollege() degrades today.
   Route GET /admin/analytics named admin.analytics, inside the existing
   ['auth', 'role:college_admin', 'college.scope'] group.

D. NEW resources/views/admin/analytics.blade.php
   Mirror resources/views/director/analytics.blade.php's structure and
   components so the two pages feel like one system. Replace the by-College card
   with by-Program. No college dropdown — show the college-scope banner instead,
   the way admin/dashboard.blade.php does. Add the program dropdown.
   Add Analytics to the college_admin arm of
   resources/views/components/layout/sidebar.blade.php.

E. Tests
   - tests/Feature/Director/AnalyticsPageTest.php passes unchanged (see B)
   - NEW tests/Feature/Admin/AnalyticsPageTest.php:
     * a student, a nurse, and a director are refused
     * an admin with no managed college is refused (college.scope)
     * the page shows only the admin's own college's numbers
     * SECURITY: admin of college A requesting ?college=<B's id> still sees
       ONLY college A's numbers
     * per-program rows are correct, and a program with zero visits still
       renders as a zero row
     * the program filter narrows every card, not just the program card
     * the month filter behaves like the Director's
     * a visit whose snapshot program differs from the student's CURRENT program
       counts under the SNAPSHOT (the D-43 guarantee)

OUT OF SCOPE:
- The printable monthly report and the Director's program card — next prompt.
- Any schema change. Any CSV export (FR-ANL-06 stays struck by D-32).

CRITICAL CONSTRAINT — SQLITE PORTABILITY:
No MySQL-only date functions in raw SQL. The existing controller derives months
in PHP from Carbon-cast dates for exactly this reason — preserve that.

VERIFICATION (do not declare done without this):
- php artisan test — full suite green, with AnalyticsPageTest unchanged.
- php artisan serve --port=8080 and npm run dev. Log in as the CCS admin: the
  Analytics page shows CCS only, broken out across its 4 programs, with
  zero-visit programs present. Change the month and the program and confirm
  every card responds.
- Manually append ?college=<another college id> to the admin analytics URL and
  confirm the numbers do not change.
- Log in as the Director and confirm that page is visually and numerically
  identical to before the refactor.

DOCS TO UPDATE IN THIS SAME CHANGE:
- docs/HealthPass_PRD.md
  * Add FR-ADM-08: "College Admin Analytics — a college-scoped analytics page
    carrying the same six cards as Director Analytics, with Clinic Visits by
    College replaced by Clinic Visits by Program (zero-visit programs included).
    Filterable by month and by program. The college is derived from the admin's
    managed college and is never read from the request." Priority M.
  * Note against FR-ANL-09..13 that the card builders now live in
    App\Services\ClinicAnalytics and are shared by the Director and College Admin
    pages.
  * Decisions Log: add D-45 — "College Admins get analytics for their own
    college, broken down per program, built on a ClinicAnalytics service
    extracted from the Director controller so the two roles cannot drift. NO
    schema change." Record that the acceptance bar was the existing Director
    analytics test passing unchanged.
  * Revision History: add row v1.20 dated August 18, 2026.
- CHANGELOG.md — one entry under Unreleased.

CONSTRAINTS:
- No new packages. Chart.js is already in use.
- Dark mode must work (D-38, web app is site-wide dark).
- If anything here conflicts with the PRD, STOP and say so before coding.

DO NOT COMMIT. Leave everything uncommitted and print a summary of changed files
when done, explicitly confirming AnalyticsPageTest was not edited.
```

---

## PROMPT 5 — Printable monthly report + Director program card (D-46)

```
Work on the existing branch feature/advisor-revisions. Do not create a new
branch. Read docs/HealthPass_PRD.md section 5 (FR-ADM, FR-ANL), section 9
(Module PRT) and section 14 before starting.

PRECONDITION: app/Services/ClinicAnalytics.php exists and
/admin/analytics renders the six college-scoped cards including Visits by
Program (decision D-45). If not, STOP.

CONTEXT
Our adviser's framing was that a college REQUESTS a monthly report of its
students' clinic results — something the College Admin hands over, not just a
screen they look at. The previous prompt built the on-screen analytics; this one
produces the printable artifact. It also finishes the Director side: when the
Director filters to a single college, the by-College card has nothing to say, so
it should show that college's programs instead.

DECISIONS ALREADY LOCKED — implement these, do not propose alternatives:
- The deliverable is a PRINT VIEW, a Blade page plus window.print(), exactly the
  pattern the clearance form already uses (App\Http\Controllers\Nurse\
  PrintClearanceController and resources/views/nurse/print.blade.php). Not a PDF
  package, not a CSV. FR-ANL-06 export stays struck by D-32.
- The report renders TABLES, not Chart.js canvases. Charts do not print
  reliably, and the report has to be readable on paper.
- Print views are permanently LIGHT — never include partials/theme-init, and
  respect the @media print block already in resources/css/app.css (CLAUDE.md).

SCOPE — do exactly these, nothing else:

A. Route and controller
   GET /admin/analytics/print named admin.analytics.print, inside the existing
   ['auth', 'role:college_admin', 'college.scope'] group. Either a print method
   on Admin/AnalyticsController or a small Admin/MonthlyReportController —
   choose one and say which.
   SAME SECURITY RULE as the analytics page: the college comes from
   $this->managedCollege(), never from the request. The month and program
   filters carry over from the analytics page's query string.

B. NEW resources/views/admin/monthly-report.blade.php
   A standalone Blade document (no app sidebar/nav), like nurse/print.blade.php.
   Contents, in order:
     - Header: university name, college full name and code, "Monthly Clinic
       Report", the month in full ("August 2026"), and a generated-at timestamp
       with the name of the admin who generated it.
     - Summary line: total visits, medical, dental.
     - Clinic Visits by Program: a table — Program, Medical, Dental, Total —
       including zero-visit programs, with a totals row.
     - Visits by Purpose: a table.
     - Vital-Sign Flags: a table of flag, count and rate, carrying the same
       threshold captions the analytics card uses (they come from
       config('healthpass.thresholds') — do not hardcode 140/90).
     - BMI Distribution: a table of the four buckets with counts.
     - Students Screened by Sex: counts and percentages.
     - A footer line stating the report covers data captured by HealthPass only.
   Trigger window.print() the way the clearance print flow does, and make sure
   the page is clean at A4 and Letter.
   Add a "Print Monthly Report" button to resources/views/admin/analytics.blade.php
   that carries the current month and program filters into the print URL.

C. Director analytics gains the program card
   In resources/views/director/analytics.blade.php: when a single college is
   selected (selectedCollegeId is not null), the Clinic Visits by College card
   becomes Clinic Visits by Program for that college. With "All colleges"
   selected it stays exactly as it is today. Reuse
   ClinicAnalytics::visitsByProgram() — no new query logic.

D. Tests
   - NEW tests/Feature/Admin/MonthlyReportTest.php:
     * a student, a nurse, and a director are refused
     * the report renders for the admin's college with correct totals
     * SECURITY: ?college=<other college id> does not change the numbers
     * the month and program filters carry through from the query string
     * zero-visit programs appear in the table
   - Extend tests/Feature/Director/AnalyticsPageTest.php ONLY to cover the card
     swap: with no college selected the by-College card renders; with one college
     selected the by-Program card renders instead. Do not modify the existing
     assertions.

OUT OF SCOPE:
- Any schema change, any new package, any CSV.
- Do not add a print view for the Director — he was not asked for one.

VERIFICATION (do not declare done without this):
- php artisan test — full suite green.
- php artisan serve --port=8080 and npm run dev. As the CCS admin: open
  Analytics, set a month with data, click Print Monthly Report, and check the
  browser print preview at both A4 and Letter. Every number on the printout must
  match the on-screen page for the same filters. Confirm the printout is light
  even with dark mode enabled.
- As the Director: with All colleges the by-College card is unchanged; selecting
  CCS swaps it to that college's programs.

DOCS TO UPDATE IN THIS SAME CHANGE:
- docs/HealthPass_PRD.md
  * Add FR-ADM-09: "Printable Monthly Clinic Report — the College Admin shall be
    able to print a month's report for their college, rendered as tables (not
    charts), covering visits by program, visits by purpose, vital-sign flags with
    rates, BMI distribution and sex split, headed by the college, month and
    generation timestamp. Scoped to the managed college server-side." Priority M.
  * Amend FR-ANL-09 and FR-ANL-13: when the Director's college filter selects a
    single college, the by-College chart is replaced by a by-Program chart for
    that college.
  * Decisions Log: add D-46 — "The College Admin's monthly report is a print
    view (Blade + window.print()) rendering tables rather than charts; the
    Director's by-College card becomes a by-Program card when one college is
    selected. NO schema change. FR-ANL-06 CSV export remains struck by D-32."
  * Revision History: add row v1.21 dated August 18, 2026.
- docs/qa/e2e-scenarios.md — add a scenario: College Admin opens Analytics for a
  month with data, prints the monthly report, and the printed figures match the
  screen.
- CHANGELOG.md — one entry under Unreleased.

CONSTRAINTS:
- No new packages.
- The print view must never include partials/theme-init.
- Read thresholds from config, never hardcode 140/90 or 37.2.
- If anything here conflicts with the PRD, STOP and say so before coding.

DO NOT COMMIT. Leave everything uncommitted and print a summary of changed files
when done.
```

---

## PROMPT 6 — Director super-admin console, staff provisioning (D-47)

```
Work on the existing branch feature/advisor-revisions. Do not create a new
branch. Read docs/HealthPass_PRD.md section 5 (FR-AUTH) and section 14
(D-2, D-35) before starting.

PRECONDITION: prompts 1-5 of this file are finished and committed. This prompt
shares no code with them, but its decision number (D-47) and revision row
assume D-42..D-46 already exist in the PRD.

CONTEXT
Our faculty adviser asked whether the system has a super admin, and assumed it
was the Clinic Director. It does not have one today, and the gap is
operational, not cosmetic: staff accounts (College Admin, Nurse) can ONLY be
created by running database/seeders/StaffSeeder.php. On the hosted deployment
that is now primary (D-34), adding one College Admin therefore requires a
developer with server and database access. There is no in-app way to add a
staff member, deactivate one who has left, or reissue a forgotten password.

DECISIONS ALREADY LOCKED — implement these, do not propose alternatives:
- The Director gains super-admin CAPABILITIES. Do NOT add a fifth role.
  users.role is a hard enum of exactly four values, and FR-AUTH-02 mandates
  "exactly four roles" — a super_admin role would need an enum migration and
  would overturn a Mandatory requirement plus D-2. A capability layer on the
  existing director role needs NO schema change at all.
- Accounts are PROVISIONED by the Director. There is NO staff self-registration
  and NO approval queue. FR-AUTH-05 (Mandatory) already reads "created only via
  database seeders / provisioning — no public staff registration path shall
  exist", so in-app provisioning satisfies it as written, while a
  self-registration flow would have contradicted it. Add no public route.
- The Director may provision college_admin and nurse ONLY. Never another
  director, never a student. This is the anti-privilege-escalation rule and it
  MUST be enforced server-side, not merely absent from the dropdown.
- Credentials reuse D-35 exactly: Str::password(16, symbols: false), Hash::make,
  must_change_password = true, and the existing RequirePasswordChange
  middleware forces the change at first login. Build no second credential path.
- The generated password is SHOWN ONCE on screen for the Director to hand over
  through official channels. Do NOT email it, and do NOT persist it in readable
  form — StaffSeeder::reportOneTimePasswords() already documents that rule.

SCOPE — do exactly these, nothing else:

A. Routes and navigation
   New routes inside the existing ['auth', 'role:director'] group in
   routes/web.php:
     GET    /director/staff                    director.staff.index
     POST   /director/staff                    director.staff.store
     POST   /director/staff/{user}/password    director.staff.password
     PATCH  /director/staff/{user}/status      director.staff.status
     PATCH  /director/staff/{user}/college     director.staff.college
   Give EACH write endpoint its own throttle prefix (the third argument). For an
   authenticated user Laravel's inline throttle keys on the user id with no
   path, so without distinct prefixes these would share one counter with each
   other and with the batch endpoints — the exact bug documented at
   routes/web.php around line 129 for batch-appt-cancel.
   Add "Staff Accounts" to the 'director' arm of
   resources/views/components/layout/sidebar.blade.php (around line 30).

B. NEW app/Http/Controllers/Director/StaffAccountController.php
   index    — list college_admin and nurse accounts: name, email, role, managed
              college, status, and whether a password change is still pending
              (must_change_password). Order by role then name. Students are not
              listed, and neither is the Director's own account.
   store    — create a staff account. Generate the one-time password, hash it,
              set must_change_password = true, status = 'active', and
              email_verified_at = now() (staff do not verify by email; the
              seeder already does this). Flash the plaintext to the session
              ONCE so index can show it in a dismissible panel — after that it
              is gone.
   password — reissue a one-time password for an existing staff account under
              the same rules, setting must_change_password = true again.
   status   — flip active/inactive. NEVER delete a user: FKs are
              restrictOnDelete and clearance_records.encoded_by /
              batch_requests.reviewed_by point at staff rows. Deactivation is
              the only form of removal this system has.
   college  — reassign a college_admin's managed_college_id.

C. Form Requests (CLAUDE.md: non-trivial forms use Form Request classes)
   app/Http/Requests/Director/StoreStaffAccountRequest.php:
     role  => required, in:college_admin,nurse     <-- the escalation guard
     name  => required, string, max:120
     email => required, email, max:191, unique:users,email
     managed_college_id => required and must exist in colleges when role is
                           college_admin; must be null when role is nurse
   The role whitelist lives in the REQUEST, not only in the view: a POST naming
   role=director must be rejected by validation.
   Add a second request class for the college reassignment.

D. Safety rules to implement explicitly
   - The Director cannot deactivate, or reissue a password for, their OWN
     account through this screen. Guard it server-side and 403.
   - A college_admin with a null managed_college_id is unusable, because
     college.scope 403s them on every /admin route. store must refuse to create
     one, and the reassign endpoint must refuse to null it.
   - Deactivating a user must never touch their historical records.

E. Views
   resources/views/director/staff.blade.php using x-layout.sidebar and the
   existing x-hp.card / x-hp.table / x-hp.badge components — match how
   admin/batches renders its form-plus-table pages rather than inventing a new
   layout. A "New staff account" form, the account table with per-row actions,
   and the show-once credential panel. That panel must be visually
   unmistakable and must state plainly that the password will not be shown
   again. Must work in dark mode (D-38).

F. Tests — tests/Feature/Director/StaffAccountTest.php
   - a student, a nurse, and a college admin are each refused on EVERY endpoint
   - the Director can create a college_admin with a college, and a nurse without
   - SECURITY: POSTing role=director is rejected
   - SECURITY: POSTing role=student is rejected
   - creating a college_admin WITHOUT managed_college_id is rejected
   - the created account has must_change_password = true and is forced to the
     change-password screen at first login — assert against the existing
     behaviour covered by tests/Feature/Auth/RequirePasswordChangeTest.php
   - the plaintext password is never persisted in readable form
   - deactivating a user blocks their login (LoginRequest already enforces
     status !== 'active', around line 54)
   - the Director cannot deactivate their own account
   - reassigning a college_admin's college changes what their /admin pages see
   - a deactivated nurse's past clearance records still render

OUT OF SCOPE — do not build any of these:
- A system activity log or audit trail. Separate, larger, and may be cut.
- College or program CRUD. Programs are config-backed by D-42 and a UI to edit
  them would contradict that decision.
- Any UI for capacity or clinical thresholds. D-37 keeps capacity in config and
  the BP threshold 140/90 is LOCKED — a screen that edits it is actively wrong.
- Impersonation / "log in as". It would let the Director read a student's
  medical records as that student. Hard no for a health system.
- Creating or deleting students. They self-register (FR-REG), and deletion is
  blocked by restrictOnDelete regardless.
- Any change to StaffSeeder. Seeding stays exactly as it is; this adds an
  in-app path alongside it, it does not replace it.

VERIFICATION (do not declare done without this):
- php artisan test — full suite green, including your new tests.
- php artisan serve --port=8080 and npm run dev. As the Director: create a new
  College Admin, copy the one-time password, log out, log in as that admin, and
  confirm you are forced to change the password before reaching any dashboard.
  Confirm the new admin then sees only their assigned college.
- Deactivate that admin and confirm the login is refused.
- With devtools, POST role=director to the store endpoint and confirm you get a
  validation failure, not a created account.

DOCS TO UPDATE IN THIS SAME CHANGE:
- docs/HealthPass_PRD.md
  * Add FR-AUTH-10 — this belongs in the FR-AUTH block, not FR-DIRA, because it
    is an account-lifecycle requirement extending FR-AUTH-05 and FR-AUTH-07
    (FR-DIRA-01..06 are all taken and are about batch approvals). Text:
    "Staff Account Provisioning — the Director shall be able to create
    college_admin and nurse accounts in-app, issuing a one-time password shown
    once and requiring a change at first login; to reissue a password; to
    activate or deactivate an account; and to reassign a College Admin's
    managed college. The Director shall not be able to provision a director or
    student account, nor deactivate their own account." Priority S.
  * Note against FR-AUTH-05 that in-app provisioning by the Director satisfies
    its "provisioning" clause, and that no public staff registration path is
    added.
  * Decisions Log: add D-47 — "The Clinic Director gains super-admin
    capabilities as a CAPABILITY LAYER on the existing role, NOT a fifth role;
    users.role stays a four-value enum and FR-AUTH-02 is unchanged. Staff
    accounts are PROVISIONED by the Director, reusing the D-35
    one-time-password flow. Self-registration with an approval queue was
    considered and REJECTED: it would contradict FR-AUTH-05, add a public
    unauthenticated endpoint where none exists today, and verify identity only
    by self-assertion. NO schema change." Record the separation-of-duties note:
    the Director both approves clinical batches and controls access, accepted
    because the Director is the clinic's data controller and provisioning is
    limited to clinic-facing staff, never to another Director.
  * Revision History: add row v1.22, dated the day you run this.
- docs/dev-notes.md — staff accounts can now be created in-app; the seeder
  remains the bootstrap path for the very first Director account.
- docs/deployment-hosted.md — adding staff after go-live no longer needs server
  or database access.
- CHANGELOG.md — one entry under Unreleased.

CONSTRAINTS:
- No new packages, no new tables, no migration.
- Reuse Str::password / Hash::make / must_change_password exactly as
  StaffSeeder::createStaff() does — do not invent a second credential path.
- If anything here conflicts with the PRD, STOP and say so before coding.

DO NOT COMMIT. Leave everything uncommitted and print a summary of changed
files when done.
```

---

## Cross-cutting reminders for every session

- **Never commit directly to `main`.** All five prompts run on
  `feature/advisor-revisions`; merge once at the end.
- **The suite runs on SQLite in-memory, dev/prod on MySQL.** No `MONTH()`,
  `DAY()`, or `DATE_FORMAT` in `selectRaw`/`havingRaw`. Any raw SQL needs a
  feature test so SQLite catches drift.
- **Never run `migrate:fresh` or any destructive DB command without asking** —
  the one exception is the reseed in Prompt 2, which Nat has already approved.
- **Kiosk endpoints never trust client-supplied identity.** Prompt 2 touches the
  kiosk submit path; the program snapshot binds from the server-side session
  student, never from the request body.
- **`/admin/*` derives its college from `managedCollege()`, never the request.**
  Prompts 4 and 5 both depend on this holding.
- **Dark mode is web-app only** (D-38). The kiosk and every print view stay
  permanently light — that includes the new monthly report.
- **No AI features, four roles only, kiosk never shows Fit/Unfit.** Unchanged.
- **Explain Laravel concepts on first use** — Nat and Baldo came from plain PHP
  CRUD, and the defense panel reads this code.
