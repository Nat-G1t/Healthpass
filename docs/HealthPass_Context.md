# HealthPass — Full Project Context
> Use this document as the opening context in any new Claude conversation about the HealthPass capstone.
> Last updated: June 2026

---

## 0. Quick project snapshot

| Item | Detail |
|---|---|
| System name | HealthPass |
| Institution | Pampanga State University (PamSU), College of Computing Studies |
| Deadline | August 30, 2026 (full working prototype) |
| Programmers | Nat (Nathaniel C. Medina) — web app; Baldo — hardware lead |
| Non-programmer teammates | David, Dela Cruz, Fabian, Pamintuan, Sebastian (docs, UAT, clinic coordination) |
| Faculty adviser | Andrei Viscayno |
| Stack | Laravel 11/12 + Blade, MySQL (XAMPP locally → internet deployment), Laravel Breeze auth, Chart.js, `window.print()` for clearance |
| Kiosk hardware target | Raspberry Pi 4, Chromium kiosk mode, Web Serial API, Arduino/ESP32 sensor hub |
| Git repo | https://github.com/Nat-G1t/Healthpass.git |
| Dev env | Windows, XAMPP, `C:\Capstone\healthpass`, `php artisan serve --port=8080` + `npm run dev` in parallel |
| DB connection | `127.0.0.1` (not `localhost`) |

---

## 1. What HealthPass is

HealthPass is a **single Laravel application** (plus a clinic kiosk that is a Blade route inside the same app) that runs PamSU's **medical and dental clearance end-to-end**:

1. Students book appointments or are batch-enrolled by their College Admin.
2. The kiosk captures vital signs and a 9-item body-system screening.
3. A nurse reviews each capture and encodes a Fit/Unfit result.
4. The system prints the official PamSU clearance form.
5. The Clinic Director gets approvals and analytics.

**There is no AI.** Vital-sign flagging is rule-based against fixed thresholds.

---

## 2. Scope

**In scope**
- Student self-registration, profile management, and QR-ID linking
- Solo appointment booking (medical or dental) by students
- College Admin batch clearance requests (per-college, approved by Director)
- Director approval that auto-generates appointments for listed students
- Kiosk vitals capture (real sensors via Web Serial) + 9-item screening questionnaire
- Nurse live queue, encode result (Fit/Unfit), and clearance printout
- Director dashboard: KPIs, approvals, analytics, flagged anomalies

**Out of scope**
- AI / predictive risk profiling (fully dropped from earlier paper iterations)
- Doctor role / teleconsultation
- Payments, pharmacy, inventory
- Laboratory results or referrals
- Native mobile app
- Faculty and NASA (non-academic staff) clearances — analytics and clearance records cover students only; Faculty and NASA visits are explicitly out of scope and excluded from all analytics

---

## 3. Roles and permissions

Only **students** self-register. `nurse`, `college_admin`, and `director` accounts are seeded or admin-provisioned.

**Important:** Each college has its **own dedicated College Admin account**, scoped exclusively to that college. A College Admin can only see, request, and track batch requests for their own college.

| Capability | Student | College Admin | Nurse | Director |
|---|---|---|---|---|
| Self-register, edit profile, hold kiosk QR | ✓ | | | |
| Book a solo appointment (medical/dental) | ✓ | | | |
| Submit batch clearance request (own college only) | | ✓ | | |
| View own college's students and batch requests only | | ✓ | | |
| Approve / reject batch requests (all colleges) | | | | ✓ |
| Use the kiosk (vitals + screening) | ✓ | | | |
| View the live nurse queue | | | ✓ | |
| Encode Fit/Unfit + case category + purpose | | | ✓ | |
| Preview / print clearance form | | | ✓ | |
| View own clearance records | ✓ | | | |
| View analytics + flagged anomalies | | | | ✓ |
| Seed colleges / provision staff accounts | (admin seed) | | | |

**Colleges (12 total):**

| Code | Full name |
|---|---|
| COE  | College of Education |
| CEA  | College of Architecture and Engineering |
| CBS  | College of Business Studies |
| CAS  | College of Arts and Science |
| CSSP | College of Social Science and Philosophy |
| CCS  | College of Computing Studies |
| CHTM | College of Hospitality and Management |
| CIT  | College of Industrial Technology |
| LAW  | School of Law |
| GS   | Graduate Studies |
| SHS  | Senior High School |
| LHS  | Laboratory High School |

Each college has exactly one College Admin account. The admin's college is stored on their `users` record (`managed_college_id`). All screens, student lists, and batch request data are filtered by this value — it is never settable by the admin themselves.

---

## 4. Clearance lifecycle

```
Student registers → consents → links ID QR
         │
         ├── self-books appointment (Medical or Dental)
         │
         └── College Admin submits batch request
                    │
                    └── Director approves → system auto-creates
                            one appointment per listed student
                    
Student arrives at clinic
         │
         └── Kiosk login (QR scan OR email + virtual keyboard)
                    │
                    └── Privacy consent (RA 10173)
                    │
                    └── Vitals: Height → Weight (+ BMI) → Temp → BP (+ HR)
                    │
                    └── 9-item questionnaire + pregnancy/LMP
                    │
                    └── Review & Submit → Clinic Visit created (status: captured)
                            │ (links to today's appointment if one exists, else walk-in)

Nurse sees visit in Live Queue
         │
         └── Opens Encode Result screen
                    │ (views vitals with flags, questionnaire answers)
                    │
                    └── Sets Fit/Unfit + case category + purpose + notes
                    │
                    └── Preview & Print clearance form (official PamSU form)
                    │
                    └── Save & Close → Clinic Visit (status: encoded), Clearance Record created

Director analytics and flagged anomalies update from encoded records
```

---

## 5. Business rules

### Appointments / booking
- Weekends and past dates are unbookable.
- Each clinic day has a configurable capacity; full days show as unavailable ("FULL").
- Clinic hours: Monday–Friday, 7:00 AM–5:00 PM.
- Service types: Medical Clearance, Dental Check.

### Batch requests
- A College Admin can only request for their own college (enforced server-side).
- A reason is required. If reason = `others`, a detail textarea is required.
- At least one student must be selected.
- **Director approval generates one appointment per listed student automatically** — it appears in each student's appointment list and they proceed directly to the kiosk.
- Rejection generates no appointments; batch status flips to Rejected.
- Batch reasons: graduation clearance, OJT/practicum, general enrollment, scholarship, sports/athletics, field trip/educational tour, others.

### Visit linkage
- On kiosk submit, the visit links to the student's booked appointment for that date if one exists (`appointment_id` FK).
- If no appointment exists, it is a **walk-in** (`appointment_id` = null).

### Rule-based vital flags (not diagnoses — screening signals only)

| Vital | Normal range | Flag when |
|---|---|---|
| Temperature | 36.1–37.2 °C | > 37.2 °C |
| Blood pressure | < 120/80 mmHg | Systolic ≥ 140 OR diastolic ≥ 90 mmHg |
| BMI | 18.5–24.9 | ≥ 30.0 (obese) |

Flags appear in the nurse queue's "Flags" column and the Director's Flagged Anomalies screen. BMI = weight(kg) ÷ height(m)².

### Clearance encoding
- Only the **Nurse** encodes (4 roles total — no Doctor login).
- The encode form is titled "Doctor's Assessment" in the UI but is nurse-operated.
- `result` = Fit or Unfit (required to save).
- Case category and purpose are optional (can save without them).
- The printed form carries the **pre-printed physician signature: REYNALDO S. ALIPIO, MD, License No. 60252**.
- **Respiratory Rate** is intentionally blank on the print form — it is not a captured vital.

### Reference number formats

| Entity | Format |
|---|---|
| Appointment | `APT-YYYY-####` |
| Batch request | `BR-YYYY-###` |
| Clinic visit / clearance | `HP-YYYY-####` |

---

## 6. Design system (must be implemented first — every screen depends on it)

All Blade views share the same design system, ported from the prototype.

### Color palette

| Name | Hex | Usage |
|---|---|---|
| White | `#FFFFFF` | Cards, inputs, sidebar |
| Background | `#F6F2ED` | App and kiosk backgrounds |
| Peach | `#FFCAA0` | Active nav, badge fills, selected states |
| Orange | `#FF8C2A` | Primary buttons, active text, logo accent |
| Slate | `#4B5563` | Body text, icons, borders |

### Typography
- Font: **Poppins** (weights 400, 500, 600, 700) — load from Google Fonts.
- Body: 13–14px / 400. Labels: 600. Headings: 700.
- Scrollbars: 5px, slate at ~16% opacity.

### Shared Blade components to build

| Component | Behaviour |
|---|---|
| `HPButton` | Pill (radius 999px). Variants: `primary` (orange), `ghost` (transparent, slate border), `soft` (peach), `muted` (slate-12). Sizes: sm / md / lg / xl. Disabled = 0.5 opacity. |
| `HPBadge` | Pill, 11px/600. Positive/flagged/approved/fit/cleared/live = peach bg + orange text. Neutral/pending/rejected/unfit = slate-tint bg + slate text. `live` = solid orange + white. |
| `HPCard` | White, radius 12, 1px slate-15 border, 24px padding. |
| `HPInput` | Radius 8, 1.5px slate-25 border, built-in password eye toggle (Eye / EyeOff icons). |
| `HPSelect` | Same styling as HPInput. |
| `HPTextarea` | Same styling, resize vertical. |
| `HPLogo` | Orange plus-cross SVG mark + "Health" (slate) + "Pass" (orange) in Poppins 700. Sizes: sm / md / lg. |

### Icon set
Lucide-style, 24px viewBox, stroke-width 2, stroke-linecap/linejoin round:
Home, Calendar, FileText, QrCode, Plus, List, Activity, Edit, BarChart, Alert, Check, ChevronRight, ChevronDown, X, Search, LogOut, Download, Monitor, Users, Eye, EyeOff.

### `SidebarLayout` (authenticated shell)
- 220px white left sidebar: HPLogo top → role nav → user footer (circular initials, name, role, logout).
- Active nav item: peach background, orange text, weight 600.
- Right: 56px white top header (current screen title) + scrollable `<main>` (28px padding) on `#F6F2ED`.

**Role nav items:**

| Role | Nav items |
|---|---|
| Student | Dashboard · Book Appointment · My Records · My ID |
| College Admin | Dashboard · New Batch Request · Batch Tracking |
| Nurse | Live Queue · Encode Result · Enable Kiosk Mode (opens kiosk in new tab) |
| Clinic Director | Dashboard · Batch Approvals · Analytics · Flagged Anomalies |

---

## 7. Page-by-page build specification

### AUTH (unauthenticated)

#### Login (`/login`)
- Centered 420px column on `#F6F2ED`; HPLogo lg + tagline "Medical Clearance — Pampanga State University".
- Fields: Email Address, Password (eye toggle).
- "Register here" link → register page.
- RA 10173 footer note.
- POST to Laravel Breeze auth.

#### Register (`/register`) — 4-step flow with top progress bar
Progress steps: Consent → Account Info → Email Verify → Link ID

**Step 1 — Data Privacy Consent**
- RA 10173 notice in a bg-tinted box.
- Required checkbox: "I consent to the collection and processing of my personal health data…"
- Continue disabled until checked. "← Back to Login" link.

**Step 2 — Personal Information (2-column grid)**
- First Name, Last Name, Student Number, College (dropdown — 12 colleges), Sex (M/F), Course & Year, Date of Birth (+ auto-computed Age badge), Place of Birth, Civil Status (Single/Married/Widowed/Separated), Address, Email, Password.

**Step 3 — Email Verify**
- 6 OTP boxes (auto-focus hidden input, visual boxes highlight as digits are entered).
- Resend link. Verify & Continue disabled until 6 digits entered.
- In production: fire a `Mail` job on step-2 submit.

**Step 4 — Link Student ID**
- QR scan dropzone (USB QR scanner acts as keyboard input).
- "Skip for now" button (links later from My ID screen).
- On complete (scan or skip): log in as the new student.

---

### STUDENT

#### Student Dashboard (`student-dashboard`)
- 3 stat cards across:
  - **Clearance Status** (with status badge + orange left border; "Book New Appointment" button).
  - **Next Appointment** (date + service + time).
  - **Past Clearances** (large count, "View all →" link).
- **Recent Activity** timeline card below (bullet list of timestamped events).

#### Book Appointment (`student-book`)
- **Service picker**: two selectable cards (Medical Clearance 🏥 / Dental Check 🦷). Selected card gets orange border + peach background.
- **Month calendar**: 7-column grid. Weekends and past dates disabled (greyed/transparent). Full days greyed with "FULL" micro-label. Selected day orange fill + white text. Available days: white with slate-14 border.
- **Clinic hours note**: "7:00 AM – 5:00 PM · Monday to Friday · Campus Clinic, Main Building".
- "Confirm Booking" disabled until a date is selected. On confirm: creates appointment record, routes to confirmation screen.

#### Booking Confirmed (`student-book-confirm`)
- Centered success: orange circle with check icon.
- Summary box: Service, Date, Clinic Hours, Reference No.
- "Back to Dashboard" button.

#### My Records (`student-records`)
- Table: Date, Service, Result (Fit/Unfit badge), Reference No., View.
- "View" opens a **Record modal** (fixed overlay, max-width 700px):
  - Left column: "Kiosk Vital Signs" (height, weight, BMI, temp, HR, BP key-value list) + Medical Case Category badge if present.
  - Right column: 9-item questionnaire (Yes/No badges — Yes = flagged variant).

#### My ID & Profile (`student-profile`)
- Two-column layout (240px left + 1fr right):
  - **Left card**: "My Kiosk QR Code" heading, SVG QR code graphic, "Scan at the kiosk for fast login" note, Active badge.
  - **Right card**: read-only profile fields (Full Name, Student Number, College, Course & Year, DOB, Age [computed], Place of Birth, Address, Civil Status, Email, Account Status). "Edit Profile" button (ghost, sm).
- **Edit Profile modal**: editable fields (name, email, course, year, address, DOB, place of birth, civil status). Save Changes / Cancel.

---

### COLLEGE ADMIN
> Each admin account is scoped to exactly one college via `managed_college_id`. All data is filtered by this automatically. The admin cannot change their college.

#### Admin Dashboard (`admin-dashboard`)
- **College scope banner** (peach background, 🏛️ icon): "{College Full Name} — You can only manage students and batch requests for your assigned college."
- **4 stat cards**: Registered Students (college headcount), Total Batches, Pending Approval, Approved.
- **Batch Requests table**: Batch ID (orange), Students, Date Submitted, Status (badge).
- "+ New Batch Request" button (sm, top-right of table).

#### New Batch Request (`admin-new-batch`)
- College banner (auto, read-only).
- **Reason dropdown** (required). If `others` selected: textarea "Please specify" appears below.
- **Student multi-select** (scoped to admin's college):
  - Search bar (by name or student number).
  - Select All / Clear links.
  - Scrollable checkbox list (max-height 260px): each row = name (600) + student number + course/year (muted). Selected rows = peach background.
  - Counter: "(N of M selected)".
- "Submit Request (N)" — disabled until reason + ≥ 1 student selected.

#### Request Submitted (`admin-new-batch-confirm`)
- Success check icon.
- Summary: Batch ID, Status = "Pending Director Approval", Submitted date.
- Two buttons: "View Tracking" + "Dashboard".

#### Batch Tracking (`admin-batch-tracking`)
- Table: Batch ID, Reason (truncated with ellipsis), Students, Submitted, Status (Pending shows as "Pending Director Approval").
- "+ New Request" button (top-right).

---

### NURSE

#### Live Queue (`nurse-dashboard`)
- **Header**: blinking LIVE dot + "LIVE QUEUE" pill (peach bg, orange text) + "{n} students waiting · updated just now".
- **Queue table** (full-width card, no outer padding): Student (avatar initials + name; first row tagged "NEXT" badge + highlighted peach-35 row — the longest-waiting student), College, **Vitals Summary** (all values inline, flagged values bold orange), **Flags** (flagged-variant badges for temp/bp/bmi, or "—"), Time (waiting since), Action ("Encode Result" button — primary for row 1, ghost for others).
- Queue = clinic visits with status `captured`, ordered by `checked_in_at` asc (oldest first = top row — **first come, first served**). The top row is the next student to serve; new kiosk submissions append at the bottom.
- Refresh by **polling** (every 3–5 seconds via `setInterval` + `fetch`) — meets SM-2 (queue reflects a submission within ≤ 5 s).
- Sidebar "Enable Kiosk Mode" opens the kiosk page in a new browser tab.

#### Encode Result (`nurse-encode`)
- **Two-column layout** (1fr + 1fr):
  - **Left column**:
    - Student header card (avatar, name, college · student number · time, Flagged badge if applicable).
    - Vital Signs grid (3-col, flagged cells highlighted with peach bg + orange-40 border + orange value).
    - Questionnaire answers card (Yes/No badges; Yes = flagged variant).
  - **Right column** — "Doctor's Assessment" card:
    - **Fit / Unfit** selector (two cards; selected = peach bg + orange border).
    - **Medical Case Category** dropdown: Alimentary System, Respiratory System, Musculo-Skeletal System, Integumentary System, Urinary System, Metabolic Endocrine System, Cardiovascular System, Eyes, Ears, Nose & Throat Disorders.
    - **Purpose / Cleared For** dropdown: Off Campus Procedure, On-the-job Training, Field Trip/Educational Tour, Sports Activities.
    - **Nurse Notes** textarea (optional).
    - "Preview & Print Medical Clearance" button (ghost style, full width, Download icon).
    - "← Back" (ghost) + "Save & Close Appointment" (primary, flex-2) — Save disabled until Fit/Unfit chosen.

- **On Save**: update clinic visit `status` → `encoded`, create `clearance_records` row, return to queue.

#### Print Medical Clearance (modal)
- Fixed overlay (z-index 2000), modal 92% wide / 92vh.
- Modal header: "Medical Clearance — Document Preview" + "Kiosk data pre-filled. Review before printing." + Close + Print buttons.
- Body: `<iframe>` renders the **official PamSU form** as an HTML document, pre-filled from student + vitals + questionnaire + result + purpose + notes.

**Official form details (DHVSU-QSP-OSS-004-FO002-R03):**
- Header: "Republic of the Philippines / PAMPANGA STATE UNIVERSITY / (former Don Honorio Ventura State University) / Office of Student Welfare and Formation / Health Services Unit / MEDICAL CLEARANCE"
- Font: Times New Roman, 11.5px, black on white, print margins 12–16mm.
- Student fields: Surname / First Name / Middle Name (3-column underlines), Course/Year/Section, Address, Age, Sex (radio), Civil Status (radio), Date of Birth, Place of Birth.
- Vitals grid (3 columns): Height, Heart Rate, Temperature, Weight, Blood Pressure, Respiratory Rate (**left blank — not captured**).
- Physical signs table: YES/NO radio columns for SKIN, ABDOMEN(GIT), HEENT, GUT, CHEST/LUNGS (left col) + EXTREMITIES, HEART/CVS, NEUROLOGICAL, BREAST (right col).
- Remarks / notes line.
- Pregnancy question (YES/NO radio + LMP line).
- Fitness declaration: "He/She is physically/mentally ☐ FIT ☐ UNFIT to undergo in:" + purpose radio options.
- Pre-printed physician: **REYNALDO S. ALIPIO, MD · University Physician · License No. 60252**
- Date line + form code bottom-right.
- Print via `window.print()`.

**Questionnaire → form body system mapping:**

| Questionnaire key | Form label |
|---|---|
| skin | * SKIN |
| digestive | * ABDOMEN (GIT) |
| nose | * HEENT |
| respiratory | * CHEST/LUNGS |
| bones | * EXTREMITIES |
| heart | * HEART/CVS |
| nervous | * NEUROLOGICAL |

---

### CLINIC DIRECTOR

#### Director Dashboard (`director-dashboard`)
- **4 KPI cards**: Total Cleared (orange accent, left border), Pending Approvals, Flagged Anomalies, Avg. Daily Visits.
- **Two clickable preview cards** (side by side):
  - **Pending Batch Approvals** → navigates to `director-approvals`. Shows count badge + preview rows (college, count, date).
  - **Flagged Anomalies** → navigates to `director-flagged`. Shows count badge + preview rows (student name, flag + value, college).
- "View all →" link at bottom of each.

#### Batch Approvals (`director-approvals`)
- Full-width card with header "College Batch Requests" + description.
- Each batch as a row: Batch ID (orange, 700) + status badge, college name (600), reason (italic, in quotes), count + submitted date.
- **Pending rows only** show: "Reject" (ghost sm) + "Approve" (primary sm) buttons.
- **On Approve**: the Director selects an appointment date (date picker, default today; weekends/past dates disallowed — Decision D-5). In one DB transaction: set `batch_requests.status` → `Approved`, stamp `reviewed_by`/`reviewed_at` and `batch_requests.scheduled_date`, **auto-create one `appointments` record per student listed in `batch_request_students`** (service = batch request's service type, date = the selected date, `source` = `batch`), and update each `batch_request_students.appointment_id`.
- **On Reject**: update `batch_requests.status` → `Rejected`. No appointments created.
- Approved/rejected rows show "✓ Approved" or "✕ Rejected" static text (no action buttons).

#### Analytics (`director-analytics`)
- "Export Report" button (ghost sm, top-right, Download icon).
- **Medical Cases by College** — horizontal stacked bar chart (Chart.js). One row per college/unit (all 12 units), each bar segmented by the 8 medical-system categories, sorted by total case volume descending. Total-cases headline (e.g. '235 total cases'). Subtitle: 'Total cases per college, broken down by medical system — sorted by volume.' Students only — Faculty and NASA excluded. Series colors follow the prototype legend. Source: encoded `clearance_records` with non-null `case_category`, grouped by `student_profiles.college_id` × `case_category`.
- **Summary of Medical Cases matrix** (table): 8 medical-system rows × 12 college-code columns + TOTAL column and totals row. Fixed column order: COE, CEA, CBS, CAS, CSSP, CCS, CHTM, CIT, LAW, GS, SHS, LHS. Subtitle: 'Rows = medical system · Columns = college · Faculty & NASA excluded.' Total row at bottom in orange. Alternating row backgrounds. Source: encoded `clearance_records` with non-null `case_category`; NULL-category records excluded.
- **Cases by Medical System** — horizontal bar chart (Chart.js). One bar per medical-system category (the 8 above), overall total per system across all units, sorted descending. Same source/scope as the matrix.
- **By-Sex donut** (Chart.js) — 160px, Male (orange) + Female (peach), centre shows total count. Legend below with count + %. The donut counts students by sex across all encoded clinic visits in scope (one count per student/visit), so its total intentionally exceeds the cases total — visits with no assigned case category are still counted as people screened.

#### Flagged Anomalies (`director-flagged`)
- **3 stat cards** (orange left border): High Blood Pressure count, Fever count, Abnormal BMI count.
- **Table**: Student (600), College (muted), Flag (flagged badge), Value (orange 700), Category, View (link).
- Source: clinic visits where `vital_signs.is_bp_flagged OR is_temp_flagged OR is_bmi_flagged` = true, joined to student name and clearance category.
- "Export" button (ghost sm, top-right).

---

### KIOSK (separate Blade route — 800×480, scaled to fit window, dark `#1c1917` letterbox)

The kiosk is a **touch-first fullscreen app**. It auto-resets to Welcome 12 seconds after a student submits. In production the simulated sensor readings are replaced by **Web Serial** reads from the microcontroller, and **every vital step also offers first-class manual entry** (an "Enter manually" action opening a numeric on-screen pad; same validation ranges; provenance recorded in `vital_signs.entry_method` — Decision D-7 / FR-KSK-06). The kiosk runs at `localhost` on the Pi so Web Serial has a secure context.

**Screen flow:**
```
Welcome
  ├── QR scan (USB scanner as keyboard input) → Identity
  └── "Lost ID?" → Email Login (virtual keyboard) → Identity

Identity → Privacy Consent → vital-height → vital-weight → vital-temp → vital-bp → Questionnaire → Review → Complete (12s auto-reset → Welcome)
```

#### Screen 1 — Welcome
- Dark letterbox, `#F6F2ED` inner panel.
- Left: pulsing orange QR target SVG + "Tap to Scan Your ID" (xl button).
- Vertical divider with "or".
- Right: "Welcome to HealthPass" heading, tagline, "Lost ID? Log in with email" (ghost sm).

#### Screen 2 — Email Login
- HPLogo + "← Cancel" in header.
- Email and Password fields (tap to focus, active = orange 2px border).
- Password eye toggle inside the field.
- **QWERTY virtual keyboard** (4 rows: qwerty + digits, with `@ . _ -` keys; Delete / Space / Enter at bottom). Enter in orange, Delete in peach.
- Typing routes to the active field.

#### Screen 3 — Identity Confirm
- Large avatar (circular, initials), "Identity Verified ✓", "Hello, {first name}!", college/course/year/student number.
- "That's me — Continue" (lg) → Privacy Consent.
- "Not you?" (ghost lg) → resets to Welcome.

#### Screen 3b — Privacy Consent
- Shield icon (orange stroke on peach bg).
- RA 10173 text (two paragraphs).
- "I Agree — Proceed" (lg) → vitals flow.
- "Decline" (ghost lg) → resets to Welcome.

#### Screen 4–7 — Vitals (4 progress steps, 3-phase each: ready → scanning → captured)
Each vital screen has:
- **KioskHeader** with step indicator (animated pill dots, current = wide orange).
- Left panel: icon in peach rounded square + vital name.
- Right panel: instruction text → scanning animation (spinner + blinking dots) → captured result (large value + unit + status badge + sub-note).
- Footer: when captured, "↺ Retry" (ghost lg) + "Next →" (primary lg).

| Step | Vital | Icon | Key detail |
|---|---|---|---|
| 1/4 | Height | 📏 | Ultrasonic sensor. Captured: e.g. 163 cm, "Normal" badge. |
| 2/4 | Weight | ⚖️ | Load cell scale. Captured: e.g. 64 kg + computed BMI panel (peach bg, shows BMI + status badge + "from Xcm + Ykg"). |
| 3/4 | Temperature | 🌡️ | IR forehead thermometer. Captured: e.g. 37.9°C, "Slightly Elevated" (flagged badge), normal range note. |
| 4/4 | Blood Pressure | 💪 | Cuff BP monitor. Has its own instruction step ("Place your arm in the cuff") before measuring. Pulsing arm emoji during scan. Captured: e.g. 145/92 mmHg "Elevated — Flagged" + Heart Rate in peach panel (78 bpm, Normal badge). |

#### Screen 8 — Questionnaire
- "Any concern with the following?" heading.
- **3-column grid** of 9 system cards (white, orange border when answered):
  Vision/Eyes · Hearing/Ears · Nose & Throat · Skin · Respiratory/Breathing · Heart/Circulation · Digestive/Stomach · Bones & Joints · Nervous/Neurological.
  Each card: system name + Yes (orange when selected) / No (slate when selected) buttons.
- **Pregnancy question** below the grid (full width): Are you pregnant? Yes/No. If Yes: inline calendar (full month, tap to pick date — future dates disabled) for Last Menstrual Period.
- Footer: "{N} of 10 answered" + "Review & Submit →" (disabled until all 10 answered including pregnancy).

#### Screen 9 — Review
- "Review Your Submission" in header.
- Two-column cards: **Vital Signs** (key-value; flagged items in orange + ⚑) + **Health Questionnaire** (Yes/No badges).
- "Submit to Clinic →" (xl, center).

#### Screen 10 — Complete
- Success circle with check.
- "Submitted!" heading.
- "Your vitals have been recorded. Please proceed to the nurse's station."
- Countdown pill: "Returning to home screen in {N}s…" → auto-resets at 0.

---

## 8. Database schema (10 tables)

### `colleges`
```sql
id              bigint PK
code            varchar(10) UNIQUE          -- COE, CEA, CBS, CAS, CSSP, CCS, CHTM, CIT, LAW, GS, SHS, LHS
name            varchar(120)
created_at, updated_at
```

### `users`
```sql
id                    bigint PK
role                  enum('student','college_admin','nurse','director')
name                  varchar(120)
email                 varchar(191) UNIQUE
email_verified_at     timestamp NULL
password              varchar(255)
managed_college_id    bigint NULL FK → colleges.id   -- set for college_admin only
status                enum('active','inactive') DEFAULT 'active'
remember_token        varchar(100) NULL
created_at, updated_at
```

### `student_profiles`
```sql
id                    bigint PK
user_id               bigint UNIQUE FK → users.id
college_id            bigint FK → colleges.id
student_number        varchar(20) UNIQUE
first_name            varchar(80)
last_name             varchar(80)
sex                   enum('M','F')
course                varchar(120)
year_level            varchar(20)
date_of_birth         date
place_of_birth        varchar(120)
civil_status          enum('Single','Married','Widowed','Separated')
address               text
qr_token              varchar(64) UNIQUE
privacy_consent_at    timestamp NULL        -- registration consent
created_at, updated_at
```

### `appointments`
```sql
id                    bigint PK
reference_no          varchar(20) UNIQUE    -- APT-YYYY-####
student_id            bigint FK → users.id
service_type          enum('medical','dental')
scheduled_date        date
status                enum('scheduled','checked_in','completed','cancelled') DEFAULT 'scheduled'
source                enum('self','batch')  -- how the appointment was created
batch_request_id      bigint NULL FK → batch_requests.id
created_by            bigint NULL FK → users.id
created_at, updated_at
```

### `batch_requests`
```sql
id                    bigint PK
reference_no          varchar(20) UNIQUE    -- BR-YYYY-###
college_id            bigint FK → colleges.id
requested_by          bigint FK → users.id  -- the college_admin
reason                enum('graduation','ojt','enrollment','scholarship','sports','fieldtrip','others')
reason_detail         text NULL             -- used when reason = 'others'
service_type          enum('medical','dental')
scheduled_date        date NULL             -- Director-selected appointment date, stamped at approval (D-5)
status                enum('pending','approved','rejected') DEFAULT 'pending'
reviewed_by           bigint NULL FK → users.id   -- director who acted
reviewed_at           timestamp NULL
created_at, updated_at
```

### `batch_request_students`
```sql
id                    bigint PK
batch_request_id      bigint FK → batch_requests.id
student_id            bigint FK → users.id
appointment_id        bigint NULL FK → appointments.id   -- set when batch is approved
created_at, updated_at
UNIQUE(batch_request_id, student_id)
```

### `clinic_visits`
```sql
id                    bigint PK
reference_no          varchar(20) UNIQUE    -- HP-YYYY-####
student_id            bigint FK → users.id
appointment_id        bigint NULL FK → appointments.id   -- NULL = walk-in
login_method          enum('qr','email')
status                enum('captured','encoded') DEFAULT 'captured'
privacy_consent_at    timestamp NULL
checked_in_at         timestamp NULL
created_at, updated_at
```

### `vital_signs`
```sql
id                    bigint PK
clinic_visit_id       bigint UNIQUE FK → clinic_visits.id
height_cm             decimal(5,1)
weight_kg             decimal(5,1)
bmi                   decimal(4,1)          -- computed: weight / (height_m^2)
temperature_c         decimal(4,1)
heart_rate_bpm        smallint
bp_systolic           smallint
bp_diastolic          smallint
entry_method          enum('sensor','manual','mixed') DEFAULT 'sensor'  -- provenance of the readings (D-7)
is_temp_flagged       boolean DEFAULT false
is_bp_flagged         boolean DEFAULT false
is_bmi_flagged        boolean DEFAULT false
created_at, updated_at
```

### `screening_responses`
```sql
id                    bigint PK
clinic_visit_id       bigint UNIQUE FK → clinic_visits.id
vision                boolean
hearing               boolean
nose                  boolean
skin                  boolean
respiratory           boolean
heart                 boolean
digestive             boolean
bones                 boolean
nervous               boolean
is_pregnant           boolean
last_menstrual_period date NULL             -- required if is_pregnant = true
created_at, updated_at
```

### `clearance_records`
```sql
id                    bigint PK
clinic_visit_id       bigint UNIQUE FK → clinic_visits.id
encoded_by            bigint FK → users.id  -- the nurse
result                enum('Fit','Unfit')
case_category         enum('Alimentary System','Respiratory System','Musculo-Skeletal System','Integumentary System','Urinary System','Metabolic Endocrine System','Cardiovascular System','Eyes, Ears, Nose & Throat Disorders') NULL
purpose               enum('Off Campus Procedure','On-the-job Training','Field Trip/Educational Tour','Sports Activities') NULL
nurse_notes           text NULL
physician_name        varchar(120) DEFAULT 'REYNALDO S. ALIPIO, MD'
physician_license_no  varchar(20)  DEFAULT '60252'
encoded_at            timestamp NULL
printed_at            timestamp NULL
created_at, updated_at
```

---

## 9. Key relationships summary

```
colleges ────┬──< users (managed_college_id)          one college → many admin accounts
             ├──< student_profiles (college_id)        one college → many students
             └──< batch_requests (college_id)

users ────────┬──| student_profiles (user_id)          one student user → one profile
              ├──< appointments (student_id)
              ├──< clinic_visits (student_id)
              ├──< batch_requests (requested_by)
              ├──< batch_request_students (student_id)
              └──< clearance_records (encoded_by)

batch_requests ──< batch_request_students ──|── appointments (generated on approval)

appointments ──|── clinic_visits (appointment_id, nullable)

clinic_visits ──|| vital_signs          (1:1)
              ──|| screening_responses  (1:1)
              ──|  clearance_records    (1:0..1)
```

---

## 10. Non-functional requirements (ISO/IEC 25010:2023)

| Quality | Requirement |
|---|---|
| Functional suitability | Clearance workflow produces a correct, printable result per student matching the official PamSU form |
| Performance efficiency | Kiosk feels immediate; nurse queue refreshes via polling |
| Compatibility | Kiosk: Chromium kiosk mode on Pi; web app: standard desktop browsers |
| Interaction capability | Kiosk is touch-first at 800×480 with large targets and on-screen keyboard |
| Reliability | Captured visits are never lost if encoding is delayed; kiosk auto-resets between students |
| Security | RA 10173 compliance (consent capture), RBAC, hashed passwords, least-privilege, Web Serial on `localhost` |
| Maintainability | One Laravel codebase with shared Blade components; Git feature-branch workflow |
| Portability | XAMPP locally → internet deployment |

---

## 11. Architecture and hardware

### Software
- **Framework**: Laravel 11/12, Blade templating
- **Auth**: Laravel Breeze (Blade stack)
- **CSS**: Tailwind CSS + custom design-system tokens
- **Charts**: Chart.js (Director analytics)
- **Printing**: Blade print view + `window.print()`
- **Queue refresh**: polling via `setInterval` + `fetch` (no WebSockets required for MVP)
- **Database**: MySQL (XAMPP locally, internet-deployed instance for defense)

### Hardware (kiosk)
- **Host**: Raspberry Pi 4, running Chromium in `--kiosk` mode
- **Sensor hub**: Arduino or ESP32, wired to sensors, streams a combined reading over USB serial
- **Sensors**: ultrasonic height detector, HX711 load-cell (weight), MLX90614 IR thermometer, digital BP monitor
- **Login**: USB QR code scanner (acts as keyboard — sends QR token string + Enter)
- **Web Serial**: used on the kiosk Blade page to read the sensor hub's USB output; secure context satisfied by `localhost`
- **Biggest risk**: the BP monitor's serial output format must be validated in the Week-1 spike before hardware is purchased

### Dev environment
- OS: Windows, XAMPP (Apache + MySQL)
- Project root: `C:\Capstone\healthpass`
- DB: connect via `127.0.0.1` (not `localhost`)
- Run: `php artisan serve --port=8080` in Terminal 1, `npm run dev` in Terminal 2
- Git: feature-branch flow, push to `https://github.com/Nat-G1t/Healthpass.git`

---

## 12. Print form reference

**Form code**: DHVSU-QSP-OSS-004-FO002-R03  
**Title**: MEDICAL CLEARANCE  
**Issuing office**: Office of Student Welfare and Formation — Health Services Unit  
**Pre-printed physician**: REYNALDO S. ALIPIO, MD · University Physician · License No. 60252  
**Respiratory Rate**: intentionally blank (not a captured vital in HealthPass)  
**Printing**: Blade view generates an HTML document rendered in an `<iframe>` inside the Encode Result screen; `window.print()` is triggered from the iframe's content window.

---

## 13. Open action items (as of June 2026)

1. **Week-1 hardware spike**: validate Web Serial + BP monitor serial output before buying hardware.
2. **Paper/scope mismatch**: the capstone paper still frames the system as AI-powered. The documentation team must revise the title and chapter scope to match the no-AI, scheduling + digital clearance system. This is a parallel workstream with its own deadline.
3. **Decided (PRD D-4)** — clinic day capacity is a config value in `config/healthpass.php`; only the initial number remains to be set with clinic staff input.
4. **Decided (PRD D-5)** — the Director picks the appointment date at batch approval (default today), stored in `batch_requests.scheduled_date`.
---

*End of HealthPass context document.*
