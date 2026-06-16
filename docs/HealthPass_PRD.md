# HealthPass — Product Requirements Document (PRD)

**Web-Based Scheduling and Digital Medical Clearance System with Self-Service Vitals Kiosk**
Pampanga State University · College of Computing Studies · Capstone Project

---

## Document Control

| Field | Value |
|---|---|
| Document title | HealthPass Product Requirements Document |
| Version | 1.0 (Baseline for build) |
| Date | June 11, 2026 |
| Status | Approved for development — Week 1 of 12 |
| Prepared by | Nathaniel C. Medina (Lead Developer — Web Application) |
| Hardware lead | Baldo (Kiosk hardware & Web Serial integration) |
| Documentation / QA / Coordination | David, Dela Cruz, Fabian, Pamintuan, Sebastian |
| Faculty adviser | Andrei Viscayno |
| Target completion | August 30, 2026 (full working prototype; internal freeze August 28, 2026) |
| Source artifacts | `HealthPass_Context.md` (system spec), `HealthPass_Project_Plan.md` (build plan), Claude Design HTML prototypes (web app + kiosk), finalized 10-table ERD |

### Revision History

| Version | Date | Author | Changes |
|---|---|---|---|
| 0.x | Pre-June 2026 | Team | Earlier AI-powered kiosk concept (superseded — see §1.3) |
| 1.0 | June 11, 2026 | N. Medina | Baseline PRD for the no-AI HealthPass system. Incorporates locked schema decisions: BP flag threshold 140/90, `vital_signs.entry_method`, `batch_requests.scheduled_date`, clinic capacity as config value. |
| 1.1 | June 16, 2026 | N. Medina | Added optional middle_name to student_profiles and the registration Step 2 form, aligning capture with the Middle Name field on official form DHVSU-QSP-OSS-004-FO002-R03. |

---

## 1. Overview

### 1.1 Product summary

HealthPass is a **single, unified Laravel web application** that runs Pampanga State University's campus-clinic **medical and dental clearance process end-to-end**, paired with a **self-service kiosk** (a Blade route within the same application, displayed full-screen on a Raspberry Pi) that captures student vital signs and a nine-item body-system screening questionnaire.

The system covers the complete lifecycle: students register and link a QR-coded ID; they book appointments themselves or are batch-enrolled by their College Admin (subject to Clinic Director approval); on arrival they use the kiosk to record vitals and screening answers; a nurse reviews each submission in a live queue, encodes the clinical result (Fit/Unfit plus case category), and prints the official PamSU Medical Clearance form; and the Clinic Director monitors approvals, analytics, and flagged anomalies.

**There is no artificial intelligence in this system.** All vital-sign flagging is rule-based against fixed, documented thresholds (§7.4). All clinical judgment is made by clinic staff. The system schedules, captures, records, flags by rule, and prints.

### 1.2 Problem statement

The campus clinic's medical and dental clearance process is currently manual: students queue physically without scheduled slots, personal and clinical information is handwritten and re-transcribed across forms and logbooks, colleges requesting clearances for entire groups (e.g., OJT batches, graduating classes) coordinate by paper lists, and the clinic has no consolidated view of case categories, volumes, or anomalous readings across colleges. This produces long waits during peak clearance seasons, transcription errors, lost or duplicated records, and zero analytical visibility for clinic management.

HealthPass replaces this with scheduled appointments, one-time digital registration, self-service vitals capture at a kiosk, a structured nurse workflow that ends in a printed official clearance form identical to the existing paper form, and a Director dashboard summarizing medical cases by category, college, and sex.

### 1.3 Background and prior scope (what changed)

Earlier iterations of this capstone proposed an *AI-Powered Health Screening Kiosk with Predictive Risk Profiling* (Azure OpenAI GPT-4o, pseudonymization, predictive analytics). That scope has been **fully dropped**. The current system is purely scheduling + digital clearance with rule-based flagging.

> **Open documentation action:** the capstone paper still carries the AI-era title and scope. The documentation team must revise the title, objectives, and chapter scope so the defended paper matches this PRD. This is a parallel workstream with its own deadline (§15, Risk R-6).

### 1.4 Goals

1. **G1 — Digitize the clearance loop end-to-end:** from booking to printed official form, with no paper handoffs except the final printed clearance itself.
2. **G2 — Reduce per-student processing effort:** self-service kiosk capture replaces manual measurement transcription; the nurse encodes from a pre-populated screen.
3. **G3 — Give the Clinic Director operational visibility:** approvals, case-category analytics by college and sex, and rule-flagged anomalies in one dashboard.
4. **G4 — Enforce data privacy by design:** RA 10173-compliant consent capture at registration and at every kiosk session, role-based access control, server-side college scoping, no health data leaving the server.
5. **G5 — Deliver a defensible working prototype by August 30, 2026** that passes the team's ISO/IEC 25010:2023 evaluation instrument.

### 1.5 Success metrics (measurable)

| ID | Metric | Target | Verification |
|---|---|---|---|
| SM-1 | Complete kiosk session (QR login → submit) | ≤ 5 minutes per student | Timed UAT runs (W11) |
| SM-2 | New kiosk submission appears in nurse Live Queue | ≤ 5 seconds (one polling cycle) | Stopwatch test during integration |
| SM-3 | Printed clearance vs. official form DHVSU-QSP-OSS-004-FO002-R03 | Field-for-field identical layout | Side-by-side print comparison signed off by clinic staff |
| SM-4 | Captured visits retained if encoding is delayed | 100% (zero loss) | Submit N visits, restart services, verify all N remain `captured` |
| SM-5 | Role isolation | 0 cross-role or cross-college data leaks | Negative-path test cases (College Admin A cannot see College B; student cannot reach `/nurse/*`) |
| SM-6 | Rule flags correctness | 100% agreement with §7.4 thresholds on a seeded test set | Automated/manual test against boundary values |
| SM-7 | End-to-end happy path | Book → kiosk → encode → print completes without developer intervention | Full dress rehearsal (W11–W12) |
| SM-8 | ISO/IEC 25010 evaluation | Acceptable rating per the team's instrument | Evaluation runs in W11 |

### 1.6 Non-goals

Explicitly **out of scope** for this release:

- AI / machine learning / predictive risk profiling of any kind
- A Doctor role, doctor login, or teleconsultation (the University Physician's name and license number are pre-printed on the form only)
- Payments, pharmacy, medicine inventory
- Laboratory results, imaging, or referral management
- Native mobile applications (the web app is responsive desktop-first; the kiosk is fixed 800×480)
- Faculty and NASA (non-academic staff) clearances — analytics and clearance records cover students only; Faculty and NASA visits are explicitly out of scope and excluded from all analytics
- SMS notifications
- Multi-clinic or multi-campus support (single PamSU campus clinic, one kiosk)
- Real-time WebSockets (queue refresh is polling-based by design)

---

## 2. Users and Personas

Only **students self-register**. `nurse`, `college_admin`, and `director` accounts are seeded or provisioned by the team — there is no public staff registration.

### 2.1 Student

A PamSU student (any of the 12 colleges) who needs a Medical Clearance (graduation, OJT, sports, field trip, etc.) or a Dental Check. Uses the web app from a personal device to register, book, and view records; uses the kiosk on-site with a QR-coded ID. Technical skill: basic smartphone/web literacy. Key needs: book without queuing blind, finish the kiosk quickly, see their own history.

### 2.2 College Admin

A designated staff member of **exactly one college** (e.g., the CCS admin). Submits batch clearance requests on behalf of groups of students (a graduating block, an OJT cohort) and tracks their status. **Hard constraint:** every screen, list, and query is scoped to their `managed_college_id`, enforced server-side; the admin can never change or escape this scope. Key needs: select many students fast, know batch status at a glance.

### 2.3 Nurse

The clinic nurse operating the day-to-day flow. Watches the Live Queue of kiosk submissions, opens each visit, reviews vitals (with rule flags) and questionnaire answers, encodes the result (Fit/Unfit + case category + purpose + notes), and prints the official clearance. Also launches kiosk mode on the clinic terminal. The encode screen is titled **"Doctor's Assessment"** in the UI but is nurse-operated (Decision D-2). Key needs: see new arrivals immediately, encode in under a minute, print that matches the official form exactly.

### 2.4 Clinic Director

Head of the Health Services Unit. Approves or rejects college batch requests, monitors KPIs, reviews the **Summary of Medical Cases** (case category × college, by sex) and the **Flagged Anomalies** list. Key needs: act on pending batches quickly, get a defensible statistical picture for reporting.

### 2.5 Colleges (reference data)

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

Each college has **exactly one** College Admin account.

---

## 3. End-to-End Workflow (Clearance Lifecycle)

```
Student registers → consents (RA 10173) → links ID QR
         │
         ├── Path A: self-books an appointment (Medical or Dental)
         │
         └── Path B: College Admin submits a batch request
                        └── Director approves → system auto-creates one
                            appointment per listed student on the
                            Director-selected date (Decision D-5)

Student arrives at clinic (booked or walk-in)
         │
         └── Kiosk login (QR scan via USB scanner OR email + virtual keyboard)
                │
                └── Privacy consent (RA 10173) — decline resets to Welcome
                │
                └── Vitals: Height → Weight (+ auto BMI) → Temperature → BP (+ Heart Rate)
                │     (sensor capture with manual-entry override on every step — Decision D-7)
                │
                └── 9-item body-system questionnaire + pregnancy/LMP
                │
                └── Review & Submit → Clinic Visit created (status: captured)
                        (links to today's appointment if one exists, else walk-in)

Nurse sees the visit in the Live Queue (polling, ≤ 5 s)
         │
         └── Opens Encode Result ("Doctor's Assessment")
                │  reviews vitals with flags + questionnaire answers
                └── Sets Fit/Unfit + case category + purpose + notes
                └── Preview & Print clearance (official PamSU form)
                └── Save & Close → visit status: encoded; Clearance Record created

Director analytics and Flagged Anomalies update from encoded records
```

**Dental appointments are scheduling-only** (Decision D-3): a dental booking creates an appointment and appears in records, but does not go through the kiosk vitals → encode → print loop.

---
## 4. Functional Requirements

Notation: each requirement has an ID, a MoSCoW priority — **M**ust (defense-critical), **S**hould (expected, slip candidate only under pressure), **C**ould (nice-to-have) — and acceptance criteria (AC). "The system" = the unified Laravel app.

### 4.1 Module AUTH — Authentication, Roles & Access Control

| ID | Requirement | Priority |
|---|---|---|
| FR-AUTH-01 | The system shall authenticate users by email + password via Laravel Breeze (Blade stack), with hashed (bcrypt) password storage. | M |
| FR-AUTH-02 | The system shall support exactly four roles: `student`, `college_admin`, `nurse`, `director`, stored on `users.role`. | M |
| FR-AUTH-03 | Role middleware shall scope all route groups: `/student/*`, `/admin/*`, `/nurse/*`, `/director/*`. A user requesting a route outside their role receives HTTP 403 (or redirect to their own dashboard). | M |
| FR-AUTH-04 | After login, the system shall redirect each role to its own dashboard (Student Dashboard, Admin Dashboard, Live Queue, Director Dashboard respectively). | M |
| FR-AUTH-05 | Staff accounts (`nurse`, `college_admin`, `director`) shall be created only via database seeders / provisioning — no public staff registration path shall exist. | M |
| FR-AUTH-06 | College Admin accounts shall carry a non-null `managed_college_id`; **all** Admin queries shall be filtered by this value server-side. The value shall never be settable through any Admin-facing request. | M |
| FR-AUTH-07 | The system shall support an `active`/`inactive` user status; inactive users cannot log in. | S |
| FR-AUTH-08 | The login screen shall follow the prototype: centered 420px column, HPLogo + tagline, email + password (with eye toggle), "Register here" link, RA 10173 footer note. | S |
| FR-AUTH-09 | Session handling, CSRF protection, and password reset shall use Laravel/Breeze defaults. | M |

**AC (module):** logging in as each seeded role lands on the correct dashboard; a student manually entering `/nurse/queue` is rejected; the CCS admin's student list returns only CCS students even when query parameters are tampered with; an inactive account is refused with a clear message.

### 4.2 Module REG — Student Self-Registration (4-step wizard)

| ID | Requirement | Priority |
|---|---|---|
| FR-REG-01 | Registration shall be a 4-step wizard with a top progress bar: **Consent → Account Info → Email Verify → Link ID**. | M |
| FR-REG-02 | **Step 1 (Consent):** display the RA 10173 data-privacy notice; the Continue button shall remain disabled until the consent checkbox is ticked. Consent timestamp is stored in `student_profiles.privacy_consent_at`. | M |
| FR-REG-03 | **Step 2 (Personal Information):** capture First Name, Middle Name (optional), Last Name, Student Number (unique), College (dropdown of the 12 colleges), Sex (M/F), Course & Year, Date of Birth (with auto-computed Age badge), Place of Birth, Civil Status (Single/Married/Widowed/Separated), Address, Email (unique), Password. All fields validated server-side. | M |
| FR-REG-04 | **Step 3 (Email Verify):** the system shall verify email ownership via a 6-digit OTP entered in six auto-advancing boxes, with a Resend link; Verify & Continue is disabled until 6 digits are entered (Decision D-8: OTP is generated server-side, stored **hashed in cache with a TTL of 10 minutes** — no database table; mail driver is `log`/Mailtrap in dev, SMTP in production). | M |
| FR-REG-05 | OTP attempts shall be rate-limited (max 5 verify attempts per code; resend invalidates the previous code). | S |
| FR-REG-06 | **Step 4 (Link ID):** the student may link their physical ID by scanning its QR (USB scanner acting as keyboard input into a focused field) which binds `qr_token`, **or** press "Skip for now" and link later from the My ID screen. | M |
| FR-REG-07 | On completing (or skipping) Step 4 the system shall log the student in and route to the Student Dashboard. | M |
| FR-REG-08 | A registration abandoned before Step 3 completion shall not create a verified, login-capable account. | M |

**AC:** a new student can complete all 4 steps in one sitting; the OTP in `storage/logs/laravel.log` (dev) verifies successfully; a wrong OTP 5× invalidates the code; skipping QR still produces a working account; duplicate student number or email is rejected with a field-level error.

### 4.3 Module STU — Student Portal

| ID | Requirement | Priority |
|---|---|---|
| FR-STU-01 | **Dashboard** shall show three stat cards — Clearance Status (latest result badge + "Book New Appointment" button), Next Appointment (date + service + clinic hours), Past Clearances (count + "View all") — plus a Recent Activity timeline of the student's own events. | M |
| FR-STU-02 | **Book Appointment** shall present a service picker (Medical Clearance / Dental Check as selectable cards) and a month calendar grid in which weekends and past dates are disabled and full days are greyed with a "FULL" label. | M |
| FR-STU-03 | Day capacity shall be evaluated as: count of non-cancelled appointments on that date ≥ configured capacity (`config/healthpass.php`, Decision D-4) → day is FULL. | M |
| FR-STU-04 | Confirm Booking shall be disabled until both a service and a date are selected; on confirm the system creates an `appointments` row (`source = self`, status `scheduled`, reference `APT-YYYY-####`) and routes to a confirmation screen showing Service, Date, Clinic Hours, and Reference No. | M |
| FR-STU-05 | The system shall reject (validation error, not DB constraint) a booking when the student already has a non-cancelled appointment for the same service on the same date. | M |
| FR-STU-06 | Students may cancel their own `scheduled` appointments before the appointment date. | S |
| FR-STU-07 | **My Records** shall list the student's clinic visits/clearances (Date, Service, Result badge, Reference No.) with a View action opening a record modal: left column = kiosk vitals (height, weight, BMI, temp, HR, BP) + case category badge if present; right column = the 9 questionnaire answers as Yes/No badges (Yes = flagged style). | M |
| FR-STU-08 | The student shall **never** see the Fit/Unfit determination on the kiosk; results become visible in My Records only after nurse encoding. | M |
| FR-STU-09 | **My ID & Profile** shall show the student's kiosk QR code (generated via `simplesoftwareio/simple-qrcode` from `qr_token`) with an Active badge, plus read-only profile fields, and an Edit Profile modal for: name, email, course, year, address, DOB, place of birth, civil status. Student number, college, and sex are not self-editable. | M |
| FR-STU-10 | If the student skipped QR linking at registration, the My ID screen shall offer the linking flow. | M |

**AC:** booking a full day is impossible from the UI and rejected server-side if forced; the calendar never allows a Saturday/Sunday or yesterday; a fresh student sees an empty-state dashboard without errors; the QR rendered on My ID scans back to the same `qr_token` at the kiosk.

### 4.4 Module ADM — College Admin Batch Workflow

| ID | Requirement | Priority |
|---|---|---|
| FR-ADM-01 | **Admin Dashboard** shall display a college-scope banner ("{College Full Name} — you can only manage students and batch requests for your assigned college"), four stat cards (Registered Students in college, Total Batches, Pending Approval, Approved), and a table of the college's batch requests. | S |
| FR-ADM-02 | **New Batch Request** shall require: a Reason (dropdown: graduation clearance, OJT/practicum, general enrollment, scholarship, sports/athletics, field trip/educational tour, others), a "Please specify" textarea **required iff** reason = others, a Service Type (medical/dental), and ≥ 1 student selected. | M |
| FR-ADM-03 | The student multi-select shall list **only** students of the admin's college, searchable by name or student number, with Select All / Clear, peach-highlighted selected rows, and a "(N of M selected)" counter. Submit is disabled until valid. | M |
| FR-ADM-04 | On submit the system creates a `batch_requests` row (status `pending`, reference `BR-YYYY-###`) plus one `batch_request_students` row per selected student, then shows a confirmation screen (Batch ID, "Pending Director Approval", date) with View Tracking / Dashboard actions. | M |
| FR-ADM-05 | **Batch Tracking** shall list the college's requests (Batch ID, Reason truncated, student count, Submitted date, Status — pending displayed as "Pending Director Approval"). | M |
| FR-ADM-06 | Server-side enforcement: any attempt to include a student from another college, or to read another college's batch, shall fail regardless of client-side state. | M |

**AC:** the CCS admin's search can never surface a CEA student; reason `others` without detail text is rejected; submitting 30 selected students creates exactly 30 pivot rows; batch status changes made by the Director appear in Tracking without admin action.

### 4.5 Module DIR-A — Director Batch Approvals

| ID | Requirement | Priority |
|---|---|---|
| FR-DIRA-01 | The Director shall see all colleges' batch requests as rows (Batch ID + status badge, college, reason in quotes, student count, submitted date), with Approve / Reject buttons on pending rows only. | M |
| FR-DIRA-02 | **On Approve** the Director shall select an appointment date (date picker, defaulting to today; weekends/past dates disallowed — Decision D-5). The system then atomically (DB transaction): sets batch status `approved`, stamps `reviewed_by`/`reviewed_at` and `batch_requests.scheduled_date`, creates **one appointment per listed student** (service = batch's service type, date = selected date, `source = batch`, status `scheduled`, own `APT` reference), and writes each new `appointment_id` back to its `batch_request_students` row. | M |
| FR-DIRA-03 | Generated appointments shall immediately appear in each listed student's appointment list and dashboard, indistinguishable in downstream flow from self-booked ones. | M |
| FR-DIRA-04 | **On Reject** the system sets status `rejected`, stamps reviewer fields, and creates no appointments. | M |
| FR-DIRA-05 | Approved/rejected rows display static "✓ Approved" / "✕ Rejected" text; re-approval or re-rejection of a decided batch shall be impossible. | M |
| FR-DIRA-06 | Batch approval shall bypass the per-day capacity check (a Director-approved cohort may exceed the self-booking capacity) — but the UI shall warn the Director when the selected date is already at/over capacity. | S |

**AC:** approving a 25-student batch creates exactly 25 appointments inside one transaction (kill the process mid-way in a test → zero partial state); rejected batches generate nothing; double-clicking Approve does not duplicate appointments.

### 4.6 Module KSK — Kiosk (800×480, touch-first)

The kiosk is the route `/kiosk` rendered full-screen in Chromium kiosk mode on the Raspberry Pi at `http://localhost` (Web Serial secure-context requirement, §11.3). Dark `#1c1917` letterbox around a `#F6F2ED` 800×480 panel, scaled to fit.

| ID | Requirement | Priority |
|---|---|---|
| FR-KSK-01 | **Welcome screen:** pulsing QR target + "Tap to Scan Your ID" on the left, divider, "Lost ID? Log in with email" on the right. The page shall keep an invisible focused input so a USB QR scanner (keyboard-wedge) can type the `qr_token` + Enter at any time. | M |
| FR-KSK-02 | **Email login screen:** email + password fields with an on-screen QWERTY virtual keyboard (4 rows incl. digits and `@ . _ -`, Delete/Space/Enter; Enter orange, Delete peach); typing routes to the focused field; password has an eye toggle; "← Cancel" returns to Welcome. | M |
| FR-KSK-03 | **Identity Confirm:** large initials avatar, "Identity Verified ✓", greeting with first name, college/course/year/student number, "That's me — Continue" and "Not you?" (resets to Welcome). | M |
| FR-KSK-04 | **Privacy Consent (per session):** RA 10173 text with "I Agree — Proceed" and "Decline" (Decline resets to Welcome and stores nothing). Agreement timestamps `clinic_visits.privacy_consent_at`. | M |
| FR-KSK-05 | **Vitals sequence** of four steps with progress dots — Height (cm) → Weight (kg, with computed BMI panel showing value + status + "from X cm + Y kg") → Temperature (°C) → Blood Pressure (systolic/diastolic mmHg + Heart Rate bpm in a peach sub-panel). Each step has a 3-phase UI: ready (instructions) → scanning (animation) → captured (large value + unit + status badge), with Retry and Next actions. | M |
| FR-KSK-06 | **Manual entry shall exist on every vital step as a first-class path** (Decision D-7): a "Enter manually" action opens a numeric on-screen pad; manual values pass the same validation ranges. The visit's `vital_signs.entry_method` records `sensor`, `manual`, or `mixed`. | M |
| FR-KSK-07 | **Sensor capture** (progressive enhancement over FR-KSK-06): the page connects to the microcontroller via the **Web Serial API**, reads the combined reading line (format §11.2), parses it, and fills the current step's value. Sensor unavailability (no port, parse failure, timeout) shall degrade gracefully to manual entry with a non-blocking notice — never a dead end. | M |
| FR-KSK-08 | Client- and server-side plausibility validation of vitals: height 50–250 cm; weight 10–300 kg; temperature 30.0–45.0 °C; systolic 60–260; diastolic 30–160; heart rate 30–220 bpm. Out-of-range input prompts re-entry. | M |
| FR-KSK-09 | BMI shall be computed (not entered) as weight(kg) ÷ height(m)², rounded to 1 decimal. | M |
| FR-KSK-10 | **Questionnaire:** a 3-column grid of 9 system cards — Vision/Eyes, Hearing/Ears, Nose & Throat, Skin, Respiratory/Breathing, Heart/Circulation, Digestive/Stomach, Bones & Joints, Nervous/Neurological — each answered Yes/No; plus a full-width pregnancy question (Yes reveals an inline month calendar for Last Menstrual Period; future dates disabled). Footer shows "{N} of 10 answered"; Review & Submit is disabled until all 10 are answered. | M |
| FR-KSK-11 | **Review screen:** two cards (Vital Signs with flagged items in orange + ⚑; Questionnaire as Yes/No badges) and "Submit to Clinic →". | M |
| FR-KSK-12 | **Submit** shall create, in one transaction: a `clinic_visits` row (status `captured`, reference `HP-YYYY-####`, `login_method`, consent + check-in timestamps, `appointment_id` = today's booked appointment for that student if one exists else NULL = walk-in) + its 1:1 `vital_signs` row (with flag booleans computed per §7.4) + its 1:1 `screening_responses` row. | M |
| FR-KSK-13 | **Complete screen:** success check, "Submitted! … proceed to the nurse's station", and a countdown pill auto-resetting the kiosk to Welcome after **12 seconds** (or instantly via a Done tap). All session state is cleared on reset. | M |
| FR-KSK-14 | The kiosk shall never display Fit/Unfit, case categories, or any clinical interpretation beyond the per-vital status badge (e.g., "Slightly Elevated"). | M |
| FR-KSK-15 | An idle timeout (no interaction for 90 s mid-flow) shall discard the session and reset to Welcome, to protect privacy on an abandoned kiosk. | S |
| FR-KSK-16 | A discreet staff exit (e.g., 5 taps on the logo corner + nurse password) shall close kiosk mode; students cannot navigate out of `/kiosk` otherwise. | S |

**AC:** scanning a valid QR lands on Identity in < 2 s; declining consent stores zero rows; pulling the MCU's USB cable mid-flow still allows finishing via manual entry; a submitted visit with no appointment shows as walk-in in the queue; after the 12 s countdown the next student sees a clean Welcome with no residue of the previous session.

### 4.7 Module NRS — Nurse Live Queue & Encoding

| ID | Requirement | Priority |
|---|---|---|
| FR-NRS-01 | **Live Queue** shall list visits with status `captured`, **oldest first (first come, first served)** — the top row is the longest-waiting student, tagged "NEXT" with a peach highlight; new submissions append at the bottom. Columns: Student (initials avatar + name), College, Vitals Summary inline (flagged values bold orange), Flags column (badges for temp/bp/bmi or "—"), capture Time, and an "Encode Result" action (primary button on the top row). | M |
| FR-NRS-02 | The queue shall refresh by **polling** (JS `fetch` every 3–5 s) updating rows in place; the header shows a blinking LIVE pill and "{n} students waiting · updated just now". | M |
| FR-NRS-03 | **Encode Result ("Doctor's Assessment"):** shall display the visit's full vitals (with flags), all questionnaire answers, and student identity; the nurse sets Result (**Fit/Unfit — required**), Medical Case Category (optional; one of Alimentary System, Respiratory System, Musculo-Skeletal System, Integumentary System, Urinary System, Metabolic Endocrine System, Cardiovascular System, Eyes, Ears, Nose & Throat Disorders), Purpose (optional; one of Off Campus Procedure, On-the-job Training, Field Trip/Educational Tour, Sports Activities), and free-text Nurse Notes. | M |
| FR-NRS-04 | **Save & Close** shall create the 1:1 `clearance_records` row (with `encoded_by`, `encoded_at`, pre-filled physician fields per §7.5) and flip the visit to `encoded`, removing it from the Live Queue. Encoding shall be idempotent — a visit can be encoded exactly once; subsequent opens are read/reprint-only. | M |
| FR-NRS-05 | **Preview & Print** shall render the official clearance form (Module PRT) in an iframe inside the encode screen and trigger `window.print()` on the iframe's content window; printing stamps `printed_at`. Reprint is allowed and re-stamps `printed_at`. | M |
| FR-NRS-06 | The nurse navigation shall include **Enable Kiosk Mode**, opening `/kiosk` in a new tab/window for the clinic terminal. | M |
| FR-NRS-07 | If a captured visit's linked appointment exists, encoding shall also mark that appointment `completed`. | S |
| FR-NRS-08 | Captured visits shall persist indefinitely until encoded — a backlog at day's end is preserved across restarts (ties to SM-4). | M |

**AC:** two browser windows on the queue both show a new kiosk submission within one poll cycle; saving without a Result is blocked with a field error; after Save & Close the row disappears from both windows; the printed page matches the official form (SM-3); killing the server with 5 un-encoded visits and restarting shows all 5 still queued.

### 4.8 Module PRT — Clearance Printing

| ID | Requirement | Priority |
|---|---|---|
| FR-PRT-01 | The print view shall be a dedicated Blade template reproducing form **DHVSU-QSP-OSS-004-FO002-R03 — MEDICAL CLEARANCE**, issuing office "Office of Student Welfare and Formation — Health Services Unit", matching the official layout field-for-field. | M |
| FR-PRT-02 | The form shall populate: student identity fields (name, student number, college, course/year, age, sex, civil status, address, birth details), vitals (height, weight, BMI, temperature, BP, heart rate), the encoded Result, Case Category and Purpose where set, encode date, and nurse notes where the form provides space. | M |
| FR-PRT-03 | **Respiratory Rate shall be intentionally left blank** — it is not a captured vital (Decision D-6). | M |
| FR-PRT-04 | The physician block shall be pre-printed: **REYNALDO S. ALIPIO, MD — University Physician — License No. 60252** (stored per record in `clearance_records.physician_name` / `physician_license_no` defaults), with a blank signature line for wet signing. | M |
| FR-PRT-05 | Print styling shall use a print CSS that fits one page (A4/Letter as the clinic stocks), suppressing app chrome. Implementation is HTML + `window.print()`; dompdf (`barryvdh/laravel-dompdf`) is a Could-have upgrade if a saved PDF artifact is later required. | M / C |

**AC:** physical print compared side-by-side with a blank official form by clinic staff — all labels, ordering, and the physician block match; respiratory rate is blank; long nurse notes do not break the one-page layout.

### 4.9 Module ANL — Director Dashboard, Analytics & Flags

| ID | Requirement | Priority |
|---|---|---|
| FR-ANL-01 | **Director Dashboard** shall show KPI cards plus two preview panels — Pending Batch Approvals (count + preview rows → Batch Approvals) and Flagged Anomalies (count + preview rows → Flagged Anomalies) — each with "View all →". | M |
| FR-ANL-02 | **Analytics — Medical Cases by College:** a horizontal stacked bar chart (Chart.js), one row per college/unit (all 12 units), each bar segmented by the 8 medical-system categories, sorted by total case volume descending, with a total-cases headline (e.g. '235 total cases'). Subtitle: 'Total cases per college, broken down by medical system — sorted by volume.' Students only — Faculty and NASA excluded. Series colors follow the prototype legend. Source: encoded `clearance_records` with non-null `case_category`, grouped by `student_profiles.college_id` × `case_category`. | M |
| FR-ANL-03 | **Summary of Medical Cases matrix:** 8 medical-system rows × 12 college-code columns + a TOTAL column and a totals row. Fixed column order: COE, CEA, CBS, CAS, CSSP, CCS, CHTM, CIT, LAW, GS, SHS, LHS. Subtitle: 'Rows = medical system · Columns = college · Faculty & NASA excluded.' Source: encoded `clearance_records` with non-null `case_category`, grouped by `student_profiles.college_id` × `case_category`; NULL-category records excluded. | M |
| FR-ANL-04 | **By-Sex donut** (Chart.js): Male vs Female counts of encoded records, center total, legend with count + %. | M |
| FR-ANL-05 | **Flagged Anomalies screen:** three stat cards (High Blood Pressure, Fever, Abnormal BMI counts) and a table (Student, College, Flag badge, Value, Category, View), sourced from visits where any of `is_bp_flagged / is_temp_flagged / is_bmi_flagged` is true. | M |
| FR-ANL-06 | **Export:** an Export action on Analytics and Flagged Anomalies producing at minimum a CSV of the underlying rows (print-friendly view acceptable as fallback). | S |
| FR-ANL-07 | All analytics shall compute from encoded records only — `captured` (un-encoded) visits never enter case statistics; flags however surface immediately from capture. | M |
| FR-ANL-08 | **Cases by Medical System:** a horizontal bar chart (Chart.js), one bar per medical-system category (the 8 above), showing the overall total per system across all units, sorted descending. Same source/scope as the matrix (FR-ANL-03). | M |

**AC:** seeding a known dataset (e.g., 3 Respiratory System cases in CCS, 2 in CEA) produces exactly those cells in the matrix and bars; a freshly captured (not yet encoded) flagged visit appears in Flagged Anomalies but not in case counts. The By-Sex donut counts students by sex across all encoded clinic visits in scope (one count per student/visit), so its total intentionally exceeds the cases total — visits with no assigned case category are still counted as people screened.

### 4.10 Module HW — Kiosk Hardware Integration

| ID | Requirement | Priority |
|---|---|---|
| FR-HW-01 | The sensor rig shall be wired to a single microcontroller (Arduino or ESP32) which streams a **combined reading line over USB serial** in the agreed format (§11.2) at a fixed cadence and/or on request. | M |
| FR-HW-02 | The kiosk page shall read the MCU via the **Web Serial API**, available only in a secure context — satisfied by serving the app at `http://localhost` on the Pi (Decision D-9). | M |
| FR-HW-03 | Sensors: ultrasonic distance (height), HX711 load cell (weight), MLX90614 IR thermometer (temperature), and a digital BP monitor **with verified serial output** (also yields heart rate). | M |
| FR-HW-04 | The **Week-1 spike** shall validate, before any bulk hardware purchase: (a) one sensor reading reaching a Chromium page on the Pi via Web Serial, and (b) the candidate BP monitor actually exposing readable serial output. | M |
| FR-HW-05 | The kiosk shall handle serial disconnect/reconnect (cable jiggle, MCU reset) without page reload where possible, and document Chromium kiosk-mode permission persistence behaviour for unattended operation. | S |
| FR-HW-06 | A physical enclosure shall mount the Pi, screen, MCU, sensors, and QR scanner with managed cabling and a single power feed. | S |
| FR-HW-07 | Defense-day kit: spare MCU, spare cables, and the documented manual-entry fallback drill. | S |

**AC:** with the full rig on the Pi in kiosk mode, a student completes all four vitals from live sensors; yanking the BP monitor mid-step degrades to manual entry (FR-KSK-07) and the session still submits.

---
## 5. Business Rules (normative)

### 5.1 Scheduling
- **BR-01** Clinic hours: Monday–Friday, 7:00 AM–5:00 PM, Campus Clinic, Main Building. Weekends and past dates are never bookable.
- **BR-02** Each clinic day has one global capacity (config value, Decision D-4). A day at capacity displays "FULL" and rejects self-bookings server-side.
- **BR-03** Service types: `medical` (full clearance loop) and `dental` (scheduling only, Decision D-3).
- **BR-04** One active (non-cancelled) appointment per student per service per date — enforced by validation, **not** a DB unique constraint (cancelled rows would collide).

### 5.2 Batch requests
- **BR-05** A College Admin requests only for their own college (server-enforced via `managed_college_id`).
- **BR-06** Reason is required; `others` additionally requires `reason_detail`. Valid reasons: graduation clearance, OJT/practicum, general enrollment, scholarship, sports/athletics, field trip/educational tour, others.
- **BR-07** ≥ 1 student per batch.
- **BR-08** Director approval **auto-generates one appointment per listed student** on the Director-selected date, transactionally, and links each via `batch_request_students.appointment_id`.
- **BR-09** Rejection generates no appointments and is terminal; decided batches are immutable.

### 5.3 Visits & linkage
- **BR-10** A kiosk submission links to the student's appointment **for that date** if one exists; otherwise it is a walk-in (`appointment_id` NULL). Walk-ins are first-class — they flow through queue → encode → print identically.
- **BR-11** A clinic visit is `captured` until the nurse saves an encode, after which it is `encoded` forever.
- **BR-12** Captured visits are never auto-deleted or expired (reliability, SM-4).

### 5.4 Rule-based vital flags — screening signals, not diagnoses

| Vital | Normal reference | Flag condition (locked) |
|---|---|---|
| Temperature | 36.1–37.2 °C | **> 37.2 °C** → `is_temp_flagged` ("Fever") |
| Blood pressure | < 120/80 mmHg | **Systolic ≥ 140 OR Diastolic ≥ 90** → `is_bp_flagged` ("High Blood Pressure") |
| BMI | 18.5–24.9 | **≥ 30.0** → `is_bmi_flagged` ("Abnormal BMI / Obese") |

- **BR-13** Thresholds live in **one place** — `config/healthpass.php` — consumed by the kiosk badges, queue flags, and Director anomaly screen alike. (This resolves the earlier 130/85-vs-140/90 discrepancy between planning documents: **140/90 is canonical**, Decision D-10.)
- **BR-14** Flags are computed at capture time and stored as booleans (queryable), and are advisory only — they never block submission or pre-determine Fit/Unfit.

### 5.5 Encoding & clearance
- **BR-15** Only the Nurse encodes; the screen is titled "Doctor's Assessment" but no Doctor login exists (Decision D-2).
- **BR-16** `result` (Fit/Unfit) is required to save; case category and purpose are optional.
- **BR-17** The printed form carries the pre-printed physician identity (REYNALDO S. ALIPIO, MD, License No. 60252) and a blank line for wet signature; respiratory rate is intentionally blank.
- **BR-18** Students see results only in My Records after encoding — never on the kiosk.

### 5.6 Reference numbers
- **BR-19** `APT-YYYY-####` (appointments), `BR-YYYY-###` (batch requests), `HP-YYYY-####` (clinic visits / clearances). Sequences are per-year, zero-padded, generated server-side, unique-indexed.

---

## 6. Data Requirements

### 6.1 Entity overview (10 domain tables)

The finalized ERD (rendered June 10, 2026 in the project workspace) is the schema of record. It is the `HealthPass_Context.md` §8 schema plus **exactly two locked deltas**:

1. `batch_requests.scheduled_date` `DATE NULL` — the Director-selected appointment date stamped at approval (Decision D-5).
2. `vital_signs.entry_method` `ENUM('sensor','manual','mixed') DEFAULT 'sensor'` — provenance of the captured vitals (Decision D-7).

### 6.2 Data dictionary (condensed)

| Table | Purpose | Key columns (PK bold, FK →) |
|---|---|---|
| `colleges` | Reference: the 12 colleges | **id**, code UQ, name |
| `users` | All accounts, 4 roles | **id**, role, name, email UQ, email_verified_at, password, managed_college_id →colleges (admins only), status |
| `student_profiles` | 1:1 student detail | **id**, user_id UQ →users, college_id →colleges, student_number UQ, first/middle (opt.)/last name, sex, course, year_level, date_of_birth, place_of_birth, civil_status, address, qr_token UQ, privacy_consent_at |
| `appointments` | Solo + batch-generated bookings | **id**, reference_no UQ, student_id →users, service_type, scheduled_date, status (scheduled/checked_in/completed/cancelled), source (self/batch), batch_request_id →batch_requests NULL, created_by →users NULL |
| `batch_requests` | College Admin cohort requests | **id**, reference_no UQ, college_id →colleges, requested_by →users, reason, reason_detail, service_type, **scheduled_date NULL (new)**, status (pending/approved/rejected), reviewed_by →users NULL, reviewed_at |
| `batch_request_students` | Batch ↔ student pivot | **id**, batch_request_id →, student_id →users, appointment_id →appointments NULL (set on approval); UQ(batch_request_id, student_id) |
| `clinic_visits` | One kiosk session | **id**, reference_no UQ, student_id →users, appointment_id →appointments NULL (=walk-in), login_method (qr/email), status (captured/encoded), privacy_consent_at, checked_in_at |
| `vital_signs` | 1:1 vitals per visit | **id**, clinic_visit_id UQ →, height_cm, weight_kg, bmi, temperature_c, heart_rate_bpm, bp_systolic, bp_diastolic, is_temp_flagged, is_bp_flagged, is_bmi_flagged, **entry_method (new)** |
| `screening_responses` | 1:1 questionnaire per visit | **id**, clinic_visit_id UQ →, vision, hearing, nose, skin, respiratory, heart, digestive, bones, nervous (booleans), is_pregnant, last_menstrual_period NULL |
| `clearance_records` | 1:0..1 encoded result per visit | **id**, clinic_visit_id UQ →, encoded_by →users, result (Fit/Unfit), case_category NULL, purpose NULL, nurse_notes, physician_name (default Alipio), physician_license_no (default 60252), encoded_at, printed_at |

Framework tables created by Laravel/Breeze (`password_reset_tokens`, `sessions`, `cache`, `jobs`) exist but are **not** counted among the 10 domain tables.

### 6.3 Migration order (FK-safe)

`colleges` → `users` → `student_profiles` → `batch_requests` → `appointments` → `batch_request_students` → `clinic_visits` → `vital_signs` → `screening_responses` → `clearance_records`

### 6.4 Indexes (beyond PK/UQ)

- `appointments (scheduled_date, status)` — calendar FULL computation and daily lists.
- `clinic_visits (status, created_at)` — Live Queue polling every 3–5 s.
- FK columns indexed per Laravel convention.

### 6.5 Seed data

Seeders shall provide: the 12 colleges; 1 director, 1 nurse, 12 college admins (one per college); a realistic demo cohort of students across colleges with profiles + QR tokens; and (for W10 demo prep) a seeded history of encoded visits spanning all 8 case categories and both sexes so analytics render meaningfully.

### 6.6 Data retention & privacy posture

Student health data never leaves the server (no third-party APIs). Access is least-privilege by role; students see only their own records; admins see only their college's *roster and batch* data (never clinical results); the nurse and director see clinical data as required by their function. Deletion/retention policy beyond the capstone demo is institution-defined and out of scope, but the schema supports it (cascading rules to be conservative: restrict deletes on users with clinical records).

---

## 7. Non-Functional Requirements (ISO/IEC 25010:2023 mapping)

| # | Quality characteristic | Requirement | Target / verification |
|---|---|---|---|
| NFR-1 | Functional suitability | The clearance workflow produces a correct, printable result per student matching the official PamSU form | SM-3, SM-7 |
| NFR-2 | Performance efficiency | Kiosk step transitions feel immediate (< 300 ms UI response); queue reflects submissions within one poll cycle | SM-2; manual timing |
| NFR-3 | Compatibility | Kiosk: Chromium kiosk mode on Raspberry Pi OS. Web app: current Chrome/Edge/Firefox on desktop. **Web Serial is Chromium-only and only promised on the kiosk.** | Browser matrix smoke test |
| NFR-4 | Interaction capability | Kiosk is touch-first at 800×480: ≥ 48 px touch targets, on-screen keyboards for all text/number input, no hover-dependent UI | UAT with non-team users |
| NFR-5 | Reliability | Captured visits survive crashes/restarts until encoded; kiosk auto-resets between students; sensor failure never blocks a session (manual fallback) | SM-4; pull-the-plug tests |
| NFR-6 | Security | RA 10173 consent at registration and per kiosk session; RBAC middleware; bcrypt password hashing; server-side college scoping; CSRF protection; Web Serial confined to localhost; no public exposure of health data | SM-5; negative-path test suite |
| NFR-7 | Maintainability | One Laravel codebase; shared Blade components (HPButton, HPCard, HPBadge, HPInput, HPSelect, HPTextarea, HPLogo, SidebarLayout); thresholds and capacity centralized in `config/healthpass.php`; Git feature-branch + PR workflow | Code review checklist |
| NFR-8 | Portability | Runs on XAMPP (Windows dev), on the Pi (demo), and on a PHP 8.2+/MySQL host (deployment) with `.env`-only changes | Deployment dry-run (W12) |
| NFR-9 | Flexibility (sub-char.) | Capacity, thresholds, clinic hours adjustable without code edits to views/controllers (config-driven) | Change-one-value test |

---

## 8. UI / UX Requirements

### 8.1 Design system (source of truth: Claude Design HTML prototypes, June 2026)

| Token | Value | Usage |
|---|---|---|
| White | `#FFFFFF` | Cards, inputs, sidebar |
| Background | `#F6F2ED` | App and kiosk backgrounds |
| Peach | `#FFCAA0` | Active nav, badge fills, selected states |
| Orange | `#FF8C2A` | Primary buttons, active text, logo accent |
| Slate | `#4B5563` | Body text, icons, borders |

Typography: **Poppins** 400/500/600/700 (Google Fonts). Body 13–14 px/400, labels 600, headings 700. Scrollbars 5 px slate at ~16% opacity. Implementation: CSS variables (`--orange: #FF8C2A` etc.) lifted directly from the prototype; Tailwind utilities may complement but never override the tokens.

### 8.2 Shared Blade components (build first — every screen depends on them)

`HPButton` (pill r999; variants primary/ghost/soft/muted; sizes sm–xl; disabled 0.5 opacity) · `HPBadge` (pill 11px/600; peach+orange for positive/flagged/approved/fit/live, slate-tint for neutral/pending/rejected/unfit; `live` solid orange) · `HPCard` (white, r12, 1px slate-15 border, 24px padding) · `HPInput`/`HPSelect`/`HPTextarea` (r8, 1.5px slate-25 border; password eye toggle) · `HPLogo` (orange plus-cross + "Health"/"Pass" wordmark) · `SidebarLayout` (220px sidebar, role-specific nav, 56px header, scrollable main on `#F6F2ED`).

Icons: Lucide-style outline set (24px viewBox, stroke 2) — Home, Calendar, FileText, QrCode, Plus, List, Activity, Edit, BarChart, Alert, Check, Chevrons, X, Search, LogOut, Download, Monitor, Users, Eye/EyeOff.

### 8.3 Screen inventory & navigation

| Role | Nav / screens |
|---|---|
| Public | Login · Register (4-step wizard) |
| Student | Dashboard · Book Appointment (+ confirmation) · My Records (+ record modal) · My ID & Profile (+ edit modal) |
| College Admin | Dashboard · New Batch Request (+ confirmation) · Batch Tracking |
| Nurse | Live Queue · Encode Result (+ print preview iframe) · Enable Kiosk Mode |
| Director | Dashboard · Batch Approvals · Analytics · Flagged Anomalies |
| Kiosk (`/kiosk`) | Welcome · Email Login · Identity Confirm · Privacy Consent · Vitals ×4 · Questionnaire · Review · Complete |

The page-by-page behavioural spec in `HealthPass_Context.md` §7 (field lists, button states, empty/disabled rules, exact copy) is **incorporated by reference** and is binding for implementation.

### 8.4 Kiosk-specific UX rules

800×480 fixed canvas scaled to the display, dark letterbox; 3-phase vital capture (ready → scanning → captured) with progress dots; virtual QWERTY + numeric pads for all input; large hit areas; "Not you?" and "Decline" always escape to Welcome; 12 s completion auto-reset; 90 s idle reset (FR-KSK-15); no browser chrome, no navigation out of the flow.

---
## 9. Technical Architecture

### 9.1 Topology

A **single unified Laravel application** — no separate API service, no microservices, no cross-system tokens. The kiosk is the route `/kiosk` in the same app, opened full-screen by Chromium on the Raspberry Pi.

```
                 ┌──────────────────────────────────────────────┐
                 │           HealthPass — Laravel app            │
                 │      (Blade + Controllers + MySQL/Eloquent)   │
   Browsers ───► │  /login /register /student/* /admin/*         │
 (staff +        │  /nurse/* /director/*                         │
  students)      │                                                │
                 │  /kiosk ◄── full-screen Chromium on the Pi     │
                 └───────────────┬────────────────────────────────┘
                                 │ Web Serial API (kiosk page JS)
                                 ▼
                 ┌──────────────────────────────────────────────┐
                 │ Arduino / ESP32 over USB — combined reading   │
                 │ Sensors: ultrasonic (height), HX711 (weight), │
                 │ MLX90614 (temp), serial BP monitor (BP + HR)  │
                 └──────────────────────────────────────────────┘
```

### 9.2 Stack (locked)

| Concern | Choice |
|---|---|
| Framework | Laravel 11/12, PHP 8.2+ |
| Views | Blade + shared Blade components (§8.2) |
| Database | MySQL (XAMPP dev; hosted MySQL at deployment) |
| Auth | Laravel Breeze (Blade stack), role middleware on top |
| Styling | Prototype CSS variables + Poppins; Tailwind as utility layer |
| Charts | Chart.js (Director analytics) |
| QR | `simplesoftwareio/simple-qrcode` (generation); USB keyboard-wedge scanner (reading) |
| Printing | Blade print view in iframe + `window.print()`; dompdf optional later |
| Queue refresh | Polling (`setInterval` + `fetch`, 3–5 s) — WebSockets deliberately excluded |
| Mail | `MAIL_MAILER=log` / Mailtrap in dev; real SMTP at deployment |
| VCS | Git + GitHub (`Nat-G1t/Healthpass`), feature branches + PRs |

### 9.3 Application conventions

Role-prefixed route groups with middleware; controllers per module mirroring §4 (Auth, Registration, Student, Admin, DirectorApprovals, Kiosk, NurseQueue, Encode, Print, Analytics); Eloquent models matching §6; all thresholds/capacity/hours in `config/healthpass.php`; reference-number generation in one service class; DB transactions around batch approval (FR-DIRA-02) and kiosk submit (FR-KSK-12).

---

## 10. Environments & Deployment

| Environment | Where | Notes |
|---|---|---|
| Development | Windows + XAMPP, `C:\Capstone\healthpass` | MySQL via `127.0.0.1` (never `localhost` in `.env`); run `php artisan serve --port=8080` and `npm run dev` in parallel terminals; kiosk + Web Serial testable in Chrome at `http://127.0.0.1:8080/kiosk` with the MCU on the laptop (`127.0.0.1` is also a secure context) |
| Demo / defense | **Laravel runs on the Raspberry Pi itself**; Chromium kiosk opens `http://localhost/kiosk`; staff browsers reach the same app over the campus LAN via the Pi's IP | localhost = secure context → Web Serial works with **no TLS**; this is Decision D-9 and the primary deployment shape |
| Internet deployment (optional, post-stability) | Shared hosting / VPS / PaaS with PHP 8.2+ & MySQL | **Must be HTTPS** if the kiosk ever points at it (Web Serial requirement). Avoid pointing the kiosk at a remote server for the defense. |

Even without AI, HealthPass processes student health data and remains an **RA 10173 (Data Privacy Act of 2012)** system: consent capture stays mandatory, access is role-restricted, and no health data is exposed publicly or sent to third parties.

---

## 11. Hardware Specification (Kiosk)

### 11.1 Bill of components

| Component | Item | Role |
|---|---|---|
| Host | Raspberry Pi 4 + 800×480 touch display | Runs Chromium in `--kiosk` mode pointed at `http://localhost/kiosk` |
| Sensor hub | Arduino or ESP32 | Aggregates all sensors; one USB serial line to the Pi |
| Height | Ultrasonic distance sensor | Mounted overhead; distance → height |
| Weight | Load cell + HX711 amplifier | Floor platform |
| Temperature | MLX90614 IR thermometer | Non-contact forehead read |
| BP + HR | Digital BP monitor **with serial output** | The single riskiest part — must be validated in the Week-1 spike **before purchase** (FR-HW-04) |
| ID login | USB QR/barcode scanner (keyboard-wedge) | Types `qr_token` + Enter into the focused field |

### 11.2 Serial message contract (agreed Week 1, owned jointly by both developers)

One line, ASCII, newline-terminated, e.g.:

```
H:163;W:64;T:37.9;BP:145/92;HR:78
```

Keys: `H` height cm (int), `W` weight kg (int or 1-dec), `T` temperature °C (1-dec), `BP` systolic/diastolic mmHg (ints), `HR` bpm (int). Partial lines (e.g., only `H:` during the height step) are valid; the kiosk parser consumes whichever keys are present and ignores unknown keys (forward compatibility). Malformed lines are dropped silently with a retry, never crash the step.

### 11.3 Web Serial constraint (architecture-shaping)

Web Serial requires a **secure context**: `https://` or `http://localhost`. Hence the app runs **on the Pi** and the kiosk opens localhost (D-9). If the server were a separate machine, the kiosk would need HTTPS (self-signed at minimum) — avoided for the defense. Web Serial is **Chromium-only**; the kiosk officially supports Chromium and nothing else.

---

## 12. Release Plan & Milestones (12 weeks, 4 sprints)

| Sprint | Weeks | Application track (Nat) | Hardware track (Baldo) | Exit criteria |
|---|---|---|---|---|
| **S1 — Foundation** | W1 (Jun 8–14) | Env setup, Git, Breeze; all 10 migrations from §6; vertical slice login → student dashboard | **Web Serial spike**: one sensor reading in Chromium on the Pi; BP monitor serial confirmed; *purchases gated on this* | Slice demo + spike verdict at W2 adviser check-in |
| | W2 (Jun 15–21) | Roles/middleware; 4-step registration (consent → info → OTP → QR) | Height + weight wired; BMI computed; serial format settled | Register + login as all roles |
| | W3 (Jun 22–28) | Student booking + records + profile/My ID | Temperature sensor; reading repeatability tests | Student can book against capacity rules |
| **S2 — Kiosk** | W4 (Jun 29–Jul 5) | Kiosk UI ported to Blade (all screens) **with manual entry on every vital step** | BP + HR added; full combined reading handed off | Kiosk completable end-to-end on manual entry alone |
| | W5 (Jul 6–12) | Web Serial parsing into vital steps; QR/USB login; submit creates visit + 1:1 rows | On-Pi integration in true kiosk mode | Sensor-driven kiosk session submits to DB |
| | W6 (Jul 13–19) | Nurse Live Queue (polling) + flag computation/badges | Kiosk-mode reliability: reconnects, idle, unattended quirks documented | Submission visible in queue ≤ 5 s |
| **S3 — Clinical loop + cohorts** | W7 (Jul 20–26) | Encode Result + official print view | Physical enclosure, mounting, cabling, power | Printed form matches official (SM-3 sign-off) |
| | W8 (Jul 27–Aug 2) | College Admin batch workflow (college-scoped) | Joint dry-run #1; integration fixes | Batch submit → pending visible to Director |
| | W9 (Aug 3–9) | Director approvals (transactional appointment generation) + analytics start | Analytics data support; reliability testing | Approve → N appointments atomically |
| **S4 — Analytics, hardening, freeze** | W10 (Aug 10–16) | Analytics finished (matrix, charts, flags, export) + realistic seed data | Final hardware hardening; spares procured | Director suite demo-ready |
| | W11 (Aug 17–23) | **Feature freeze.** Full integration testing, ISO 25010 evaluation runs, bug fixes | Clinic-floor rehearsal under realistic conditions | All SM-1…SM-8 measured |
| | W12 (Aug 24–30) | Polish, docs, deployment dry-run, defense rehearsal; **system done Fri Aug 28**, 2-day buffer | Defense-day hardware checklist + backup plan | Dress rehearsal passes |

Non-programmer team across all sprints: paper title/scope revision (R-6), test cases + UAT from ~W6, ISO 25010 instrument prep, clinic coordination & consent logistics. Adviser check-ins (A. Viscayno): end of W2 / W5 / W8 / W11.

**Slip policy:** if scope must be cut under pressure, the **batch workflow (Modules ADM + DIR-A)** is the designated slip candidate — it is the least essential to the core clearance loop. The kiosk, queue, encode, and print loop is never cut.

---

## 13. Acceptance & UAT

1. **Requirement-level:** every Must FR in §4 verified against its module AC by the QA sub-team, tracked in a traceability sheet (FR ID → test case ID → pass/fail → tester → date). Test-case authoring starts W6.
2. **End-to-end scenarios (W11):**
   - E2E-1 Self-booked medical clearance: register → book → kiosk (sensor) → queue → encode Fit → print → record visible to student.
   - E2E-2 Walk-in with flags: no booking → kiosk with feverish/high-BP seeded values → flags in queue and Director anomalies → encode Unfit.
   - E2E-3 Batch cohort: admin selects 20 students → Director approves with date → 20 appointments exist → 3 sampled students complete the loop.
   - E2E-4 Degraded hardware: MCU unplugged mid-vitals → manual completion → submission integrity intact.
   - E2E-5 Security negatives: cross-role URLs, cross-college tampering, direct POSTs without CSRF — all rejected.
   - E2E-6 Dental: booking only; verify it never enters the kiosk/encode loop.
3. **Form fidelity sign-off:** clinic staff compare a printed system clearance with the blank official form (SM-3).
4. **ISO/IEC 25010 evaluation:** instrument administered per the team's methodology chapter; results feed the capstone paper.

---

## 14. Decisions Log (locked — change requires team + adviser sign-off)

| ID | Decision | Rationale |
|---|---|---|
| D-1 | **No AI / predictive component** | Scope realism; clinical judgment stays human; removes Azure dependency and pseudonymization burden |
| D-2 | **No Doctor role** — nurse encodes; physician pre-printed on form | Matches actual clinic operation; 4 roles keep RBAC simple |
| D-3 | **Dental = scheduling only** | Only Medical Clearance needs the kiosk → encode → print loop |
| D-4 | **Clinic day capacity = config value** (`config/healthpass.php`), counted against non-cancelled appointments | Zero extra tables; an admin-editable settings table later is additive, not breaking |
| D-5 | **Director picks the appointment date at batch approval** (default today); stored in `batch_requests.scheduled_date` and stamped on generated appointments | Single decision point; simplest defensible flow; one nullable column |
| D-6 | **Respiratory rate not captured**; blank on the printed form | Sensor placement constraints (decided in prior iteration); form allows blank |
| D-7 | **Manual entry is a first-class path on every vital step**; sensors are progressive enhancement; provenance in `vital_signs.entry_method` | De-risks defense day; LLM-council recommendation; cheap insurance the panel reads as good engineering |
| D-8 | **Registration OTP via hashed cache entry (10-min TTL)** — no OTP table | Matches the prototype's 6-digit UI without schema impact; Breeze's link-based verification kept as documented fallback if OTP proves troublesome |
| D-9 | **Laravel runs on the Pi; kiosk at `http://localhost`** | Satisfies Web Serial's secure-context rule with no TLS; staff use LAN |
| D-10 | **BP flag threshold = systolic ≥ 140 OR diastolic ≥ 90** | Resolves the 130/85 vs 140/90 document discrepancy; aligned with stage-2 hypertension screening convention; canonical in config |
| D-11 | **Polling, not WebSockets**, for the live queue | Single-clinic scale; massive complexity saving for two Laravel beginners |
| D-12 | **USB keyboard-wedge QR scanner** (camera decode = fallback only) | Far more reliable for kiosk conditions |
| D-13 | **Live Queue is FIFO (oldest first)** — top row = next to serve ("NEXT" badge); new arrivals append at the bottom | Matches real clinic fairness (first come, first served); the prototype's emphasized row-1 styling maps cleanly to "next to serve" |

---

## 15. Risks & Mitigations

| ID | Risk | Likelihood / Impact | Mitigation |
|---|---|---|---|
| R-1 | **BP monitor lacks usable serial output / Web Serial misbehaves in unattended kiosk mode** — the #1 project risk | Med / High | Week-1 spike gates all purchases (FR-HW-04); manual entry path (D-7) guarantees a demo regardless; document Chromium permission-persistence quirks in W6 |
| R-2 | Aggressive scope: 2 Laravel beginners + hardware track in 12 weeks | High / High | W1 vertical slice to learn the lifecycle; weekly working checkpoints; hard W11 freeze; designated slip candidate = batch workflow |
| R-3 | Sensor reliability (noise, drift, repeatability) | Med / Med | W3/W6 reliability testing; plausibility validation (FR-KSK-08); retry affordance per step; manual override |
| R-4 | Web Serial is Chromium-only | Certain / Low | Kiosk officially supports Chromium only; never promised elsewhere (NFR-3) |
| R-5 | Print fidelity to the official form drifts | Med / Med | Dedicated print CSS; SM-3 sign-off with clinic staff in W7, re-verified at freeze |
| R-6 | **Capstone paper still describes the AI system** — defense mismatch | Certain if unaddressed / High | Documentation team owns title/scope revision as a parallel workstream with its own deadline; adviser review at W5 check-in |
| R-7 | Two-developer bus factor / merge chaos | Med / Med | Feature branches + PRs from day one; pairing on kiosk integration; no artifact passing outside Git |
| R-8 | Demo-day hardware failure | Med / High | Spares kit (FR-HW-07); manual-entry drill rehearsed; W12 backup plan |
| R-9 | OTP email deliverability in production | Low / Med | Dev uses log/Mailtrap; D-8 fallback to Breeze link verification |

---

## 16. Open Items (tracked, not blocking the build)

1. **W1 spike verdict** (due before any hardware purchase): Web Serial reading on the Pi + BP monitor serial confirmation — owner: Baldo.
2. **Paper title/scope revision** to the no-AI system — owner: documentation team; adviser-reviewed by W5.
3. **Capacity initial value** for `config/healthpass.php` — set with clinic staff input (recommendation: start at the clinic's realistic daily throughput; adjust in config only).
4. **Internet deployment target** (shared host vs VPS vs PaaS) — decide after W8 once the local build is stable; not needed for the defense if the Pi-local shape is used.
5. **Export format polish** (FR-ANL-06 CSV columns) — finalize with the Director's reporting needs in W9–W10.

---

## 17. Traceability Snapshot (module → primary tables → primary screens)

| Module | Tables written | Screens |
|---|---|---|
| AUTH | users | Login |
| REG | users, student_profiles | Register wizard |
| STU | appointments | Student Dashboard, Book, Records, My ID |
| ADM | batch_requests, batch_request_students | Admin Dashboard, New Batch, Tracking |
| DIR-A | batch_requests, batch_request_students, appointments | Batch Approvals |
| KSK | clinic_visits, vital_signs, screening_responses | Kiosk flow (10 screens) |
| NRS | clearance_records, clinic_visits, appointments | Live Queue, Encode Result |
| PRT | clearance_records (printed_at) | Print view (iframe) |
| ANL | (reads) clearance_records, vital_signs, student_profiles, colleges | Director Dashboard, Analytics, Flagged Anomalies |
| HW | (feeds) vital_signs | Kiosk vitals steps |

---

## 18. Glossary

| Term | Meaning |
|---|---|
| Batch request | A College Admin's request for clearances covering a list of students, requiring Director approval |
| Captured / Encoded | Clinic-visit states: kiosk-submitted vs nurse-finalized |
| Clearance record | The nurse-encoded outcome (Fit/Unfit + metadata) attached 1:0..1 to a visit |
| Flag | A rule-based boolean signal on a vital (fever / high BP / abnormal BMI) — never a diagnosis |
| Keyboard-wedge | A scanner that presents as a USB keyboard, "typing" the scanned code |
| Kiosk mode | Chromium's full-screen, chrome-less `--kiosk` launch mode |
| MCU | Microcontroller (Arduino/ESP32) aggregating the sensors |
| RA 10173 | The Philippine Data Privacy Act of 2012 |
| Walk-in | A kiosk visit with no same-day appointment (`appointment_id` NULL) |
| Web Serial API | Browser API for reading USB-serial devices; secure-context only; Chromium-only |

---

*End of HealthPass PRD v1.0 — June 11, 2026. The page-by-page UI spec and full column-level schema in `HealthPass_Context.md` are incorporated by reference; where documents conflict, this PRD and the finalized ERD govern.*
