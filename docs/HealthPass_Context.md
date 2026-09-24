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

HealthPass is a **single Laravel application** (plus a clinic kiosk that is a Blade route inside the same app) that runs PamSU's **medical clearance end-to-end** (dental is out of scope — D-60):

1. Students are batch-enrolled by their College Admin, with the Clinic Director's approval. They never book themselves and never walk in (D-61).
2. The kiosk captures vital signs and a 9-item body-system screening.
3. A nurse reviews each capture and encodes a Fit/Unfit result.
4. The system prints the official PamSU clearance form.
5. The Clinic Director gets approvals and analytics.

**There is no AI.** Vital-sign flagging is rule-based against fixed thresholds.

---

## 2. Scope

**In scope**
- Student self-registration, profile management, and QR-ID linking
- ~~Solo appointment booking (Medical Clearance) by students~~ — removed by **D-61**: students are scheduled only through their college
- College Admin batch clearance requests (per-college, approved by Director)
- Director approval that auto-generates appointments for listed students
- Kiosk vitals capture (real sensors via Web Serial) + the new forms' twelve Physical Signs questions (D-63)
- Nurse live queue, encode result (Fit/Unfit), and clearance printout
- Director dashboard: KPIs, approvals, analytics, flagged anomalies

**Out of scope**
- AI / predictive risk profiling (fully dropped from earlier paper iterations)
- ~~Doctor role~~ / teleconsultation — *the University Physician got a `physician` account with D-64 (v1.40); teleconsultation stays out*
- Payments, pharmacy, inventory
- Laboratory results or referrals
- Student self-booking, a student appointments list, and kiosk walk-ins (D-61 — a student asks their college office, offline, to include them in a batch request)
- Native mobile app
- Faculty and NASA (non-academic staff) clearances — analytics and clearance records cover students only; Faculty and NASA visits are explicitly out of scope and excluded from all analytics

---

## 3. Roles and permissions

Only **students** self-register. `nurse`, `physician` (D-64), `college_admin`, and `director` accounts are seeded or provisioned by the Director (Staff Accounts, FR-AUTH-10).

**Five roles since D-64.** The **Nurse** and the **University Physician** share the **Clinic Dashboard** (`/nurse/*` — URLs and route names unchanged): the same pages, the same encode history, the same powers. The only difference is the printout: the physician's name and license print on records the **physician** encoded; a nurse's records print a blank block for the physician's wet signature.

**Important:** Each college has its **own dedicated College Admin account**, scoped exclusively to that college. A College Admin can only see, request, and track batch requests for their own college.

| Capability | Student | College Admin | Nurse | Physician (D-64) | Director |
|---|---|---|---|---|---|
| Self-register, edit profile, hold kiosk QR | ✓ | | | | |
| ~~Book a solo appointment (Medical Clearance)~~ — removed by D-61 | | | | | |
| Submit batch clearance request (own college only) | | ✓ | | | |
| View own college's students and batch requests only | | ✓ | | | |
| Approve / reject batch requests (all colleges) | | | | | ✓ |
| Use the kiosk (vitals + screening) | ✓ | | | | |
| View the live nurse queue | | | ✓ | ✓ | |
| Encode Fit/Unfit + case categories (purpose read-only from the batch, D-62) | | | ✓ | ✓ | |
| Preview / print clearance form | | | ✓ | ✓ | |
| Enable Kiosk Mode · kiosk staff exit | | | ✓ | ✓ | |
| View own clearance records | ✓ | | | | |
| View analytics + flagged anomalies | | | | | ✓ |
| Provision staff accounts (College Admin / Nurse / Physician) and correct a physician's license | | | | | ✓ |
| Seed colleges / the first Director | (admin seed) | | | | |

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
| LHS  | Laboratory High School |

**11 units since D-43** (was 12) — Senior High School is not on the PSU
main-campus (bicolor) program offerings, so it is not a unit this system serves.

Each college has exactly one College Admin account. The admin's college is stored on their `users` record (`managed_college_id`). All screens, student lists, and batch request data are filtered by this value — it is never settable by the admin themselves.

---

## 4. Clearance lifecycle

```
Student registers → consents → links ID QR
         │
         └── Student asks their college office (offline) to include them;
             College Admin submits batch request
                    │
                    └── Director approves → system auto-creates
                            one appointment per listed student
             (D-61: no self-booking, no walk-ins)

Student arrives at clinic (scheduled by their college)
         │
         └── Kiosk login (QR scan OR email + virtual keyboard)
                    │
                    └── Schedule check: no appointment today → "No Clinic
                    │   Schedule Today" → Back to start (no way forward, D-61)
                    │
                    └── Privacy consent (RA 10173)
                    │
                    └── Vitals: Height → Weight (+ BMI) → Temp → BP (+ HR)
                    │
                    └── Physical Signs questionnaire (the form's 9 rows + YES details — required on a Medical Clearance, D-75) + pregnancy/LMP
                    │
                    └── Review & Submit → Clinic Visit created (status: captured)
                            │ (links to today's appointment; with none the server
                            │  refuses the submit and writes nothing — D-61)
                            │
                            └── D-72: if temp / BP / HR is flagged, the button
                                │  reads "Rest & re-check" instead and the visit
                                │  is created as status: resting — no queue, no
                                │  count anywhere. Rest screen shows the SERVER's
                                │  come-back time, then auto-resets.
                                │
                                └── Student re-scans after resting_until
                                    │  → Identity Confirm → ONLY the flagged
                                    │    vitals step(s); consent, questionnaire
                                    │    and social history are already stored
                                    │
                                    └── Review → Submit → first numbers copied to
                                       vital_signs.first_reading, all flags
                                       recomputed, status: resting → captured,
                                       checked_in_at = now() (back of the queue)

Nurse sees visit in Live Queue
         │
         └── Opens Encode Result screen
                    │ (views vitals with flags, questionnaire answers)
                    │
                    └── Sets Fit/Unfit + case categories (0..n) + notes (purpose = the batch reason, D-62)
                    │
                    └── Preview & Print clearance form (official PamSU form)
                    │
                    └── Save & Close → Clinic Visit (status: encoded), Clearance Record created

Director analytics and flagged anomalies update from encoded records
```

---

## 5. Business rules

### Appointments / booking
- **D-61: students never book.** Only a Director-approved college batch creates an appointment, and every new appointment is `source` = `'batch'`. There is no Book Appointment page, no My Appointments page, and no student cancel — only the College Admin withdraws a seat (FR-ADM-07). BR-04 (one self-booking per service per date) is retired.
- Past dates are unbookable.
- Each clinic day has a configurable capacity; full days show as unavailable ("FULL").
- **D-37/BR-23: an hour that has already ended is not bookable today.** A slot dies when it **ends** — at 12:00 the 11 AM–12 PM hour is gone, 12–1 PM is still open. Only today is affected, it is decided on the server clock (never the browser's), and it binds the College Admin's batch start hour and Director approval alike (D-61 removed the student picker) — a batch requested for today whose span hours have ended can no longer be approved, only rejected.
- **D-37: the day is ten one-hour slots.** Clinic hours (7 AM–5 PM, lunch included) yield ten bookable slots of `hourly_capacity` = **12** each — `daily_capacity` **120**, up from 40. There is one counter per hour (D-60). A date is FULL when every slot is at 12 (or the daily cap is reached, which is the only counter that sees pre-D-37 appointments).
- Clinic hours: 7:00 AM–5:00 PM, daily.
- Service type: **Medical Clearance only** (D-60, superseding D-3 and D-33 — dental is not part of HealthPass). The `service_type` columns are kept for history; the server always writes `'medical'`.

### Batch requests
- A College Admin can only request for their own college (enforced server-side).
- **A clinic form is required first (D-62):** `clearance` (Medical Clearance) or `assessment` (Medical Assessment Form). It drives the kiosk questions, the encode fields and the printed document.
- A reason is required and must come from **the chosen form's** list (`BatchRequest::REASONS_BY_FORM`, the paper's exact labels) — Medical Clearance: Field Trip/Educational Tour, Outbound Activities, Others, Specify; Medical Assessment Form: Off Campus Procedure, Sports Activities, On-the-job Training, Related Learning Experience, Others, Specify. If reason = `others`, a detail textarea is required (max 120 — it prints on the "Others, Specify:" line). The batch reason IS the purpose printed on the form.
- At least one student must be selected.
- **One clinic schedule per student per day (D-54, D-77, BR-25).** A batch holds its **date** for every student on it from submission — `pending` or `approved`. A clash is the **same date** against another pending/approved batch the student is on, whatever its hours or form (D-77 widened D-54's hour overlap to the whole day; D-61 retired the self-booking clash). **First come wins:** the later booking — a batch submission or the Director's approval — is refused. Rejected/cancelled batches and a student withdrawn from an approved batch hold nothing; a pre-D-37 batch with no hour clashes on its date too.
- **Director approval generates one appointment per listed student automatically** — it appears on each student's dashboard (Next Appointment card) and in their email, and they proceed directly to the kiosk. Since D-61 this is the only way an appointment is created.
- Rejection generates no appointments; batch status flips to Rejected and the Director's written reason is stored (required, D-36).
- Batch reasons: graduation clearance, OJT/practicum, general enrollment, scholarship, sports/athletics, field trip/educational tour, others.

### Visit linkage
- ~~On kiosk submit, the visit links to the student's booked **medical** appointment for that date if one exists (`appointment_id` FK). Dental is scheduling-only (Decision D-3) and never links.~~ *Superseded — D-54 sets the rule below, and D-60 removed dental from HealthPass entirely:*
- On kiosk submit, the visit links to one of the student's `scheduled` appointments for **today**, of **any** service (`appointment_id` FK) — **the one whose hour starts closest to check-in time** (D-54). A tie goes to the earlier hour; an appointment with no hour (pre-D-37) links only when no timed one exists. A student can hold, say, a 9 AM seat on one batch and a 2 PM seat on another the same day (their hours don't overlap), which is why the closest one wins.
- ~~If no scheduled appointment exists today, it is a **walk-in** (`appointment_id` = null).~~ **D-61: there are no walk-ins.** If no `scheduled` appointment exists today, the submit is **refused (422) and nothing is written** — the server decides, never the kiosk screen. `appointment_id` is NULL only on legacy pre-D-61 rows.

### Rule-based vital flags (not diagnoses — screening signals only)

| Vital | Normal range | Flag when |
|---|---|---|
| Temperature | 36.1–37.2 °C | > 37.2 °C |
| Blood pressure | < 120/80 mmHg | Systolic ≥ 140 OR diastolic ≥ 90 mmHg |
| BMI | 18.5–24.9 | < 18.5 **or** ≥ 25.0 — anything not Normal **(D-78)** |
| Heart rate **(D-66)** | 60–100 bpm | > 100 bpm — **no low-HR flag**, a resting rate under 60 is common in healthy young students |
| Respiratory rate **(D-66)** | 12–20 breaths/min | < 12 **or** > 20 breaths/min |

Flags appear in the nurse queue's "Flags" column and the Director's Flagged Anomalies screen. BMI = weight(kg) ÷ height(m)².

**When each flag is computed (D-66).** Four of the five are computed at **kiosk capture**, in `SubmitKioskVisit`, and stored as booleans. `is_rr_flagged` is the exception: the kiosk has **no sensor** for a respiratory rate (D-65), so that flag is computed at **encode**, in the same transaction that writes `vital_signs.respiratory_rate`. Until then it is `false`, meaning "not measured yet" — not "normal". The rules live as static helpers on `App\Models\VitalSigns` (`isBmiFlagged()` — D-78, `isHeartRateFlagged()`, `isRespiratoryRateFlagged()`), shared by the kiosk submit, the encode controller and the seeders, and every threshold comes from `config('healthpass.thresholds')` (BR-13). Nothing is ever derived in the browser or read from a request body.

### Clearance encoding
- ~~Only the **Nurse** encodes (4 roles total — no Doctor login).~~ **D-64:** a **nurse or the University Physician** encodes (5 roles total); `encoded_by` records who.
- The encode form is titled "Doctor's Assessment" in the UI and is operated by clinic staff (nurse or physician).
- `result` = Fit or Unfit (required to save).
- Case categories (0..n, D-23) are optional. **The purpose is not nurse-entered (D-62):** it is the batch's reason, shown read-only and copied onto the clearance record on Save (label → `purpose`, specify text → `purpose_other`; NULL with no batch). A posted purpose is ignored.
- ~~The printed form carries the **pre-printed physician signature: REYNALDO S. ALIPIO, MD, License No. 60252**.~~ **D-64:** the physician block prints the encoder's name (`UPPER(name), MD`) and license **only when a physician encoded**; a nurse's record prints a blank name line and "License No. ________" for the wet signature.
- ~~**Respiratory Rate** is intentionally blank on the print form — it is not a captured vital.~~ **D-65:** the clinic measures the respiratory rate on the encode page (`vital_signs.respiratory_rate`, 8–60) and **it prints**. The printed vitals are the clinic's confirmed copy (`clearance_records.encoded_vitals`); the kiosk's own reading is never overwritten.

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
- **Back guard (FR-UI-07):** each role's dashboard renders `<x-back-guard>`, which adds one history entry for the same URL; the browser's Back pops it and opens the footer's Log out dialog (window event `open-logout-confirm`) instead of leaving the page.

**Role nav items:**

| Role | Nav items |
|---|---|
| Student | Dashboard · My Records (+ the D-73 Clinic Record page) · My ID (D-61 removed Book Appointment and My Appointments) |
| College Admin | Dashboard · New Batch Request · Batch Tracking |
| Nurse | Live Queue · Encode Result · Enable Kiosk Mode (Kiosk Devices: enroll/revoke trusted terminals, D-27) |
| Clinic Director | Dashboard · Batch Approvals · Analytics · Flagged Anomalies |

**Unread badges (D-57, FR-UI-05):** a solid orange badge on a menu item — a dot for 1, the number for 2–9, "9+" from 10, nothing at 0 — at the top-right of the item's label (expanded sidebar, phone drawer) or of its icon (collapsed rail). Orange even on the active item. Refreshed on page load only; no polling.

| Role | Badged item → what it counts |
|---|---|
| Student | My Records → results encoded since last seen · Kiosk Tutorial → a dot until the walkthrough reaches its last step (opening the page doesn't clear it) |
| College Admin | Batch Tracking → Director decisions + results encoded for the college's batch students, since last seen · Activity Log → entries since last seen, minus the viewer's own submissions and cancellations |
| Nurse | Live Queue → visits waiting right now (a work count — opening the page doesn't clear it, encoding does) |
| Clinic Director | Batch Approvals → pending requests (a work count) · Flagged Anomalies → flagged visits captured since last seen |

"Last seen" = when the user last opened that page: stamped in `users.nav_seen_at` before the page renders (so the page you're on shows no badge); a page never opened counts from the account's `created_at`. Every other item has no badge. Counts come from `App\Support\NavBadges`, handed to the sidebar by a view composer.

---

## 7. Page-by-page build specification

### AUTH (unauthenticated)

#### Login (`/login`)
**Two-panel layout** (visual source of truth: the login mockup in `docs/prototypes/web/`):
- **Left panel — login card** (internals unchanged): 420px column on `#F6F2ED`; HPLogo lg + tagline "Medical Clearance — Pampanga State University"; fields Email Address + Password (eye toggle); "Register here" link → register page; RA 10173 footer note. POST to Laravel Breeze auth.
- **Right panel — decorative illustration**, shown **whole and centered** (contained, never cropped) on an off-white panel matching the image's backdrop.
- **Responsive collapse:** on narrow screens the illustration panel is hidden and the page falls back to the centered single-column card.

#### Register (`/register`) — 4-step flow with top progress bar
Progress steps: Consent → Account Info → Email Verify → Link ID

**Step 1 — Data Privacy Consent**
- RA 10173 notice in a bg-tinted box.
- Required checkbox: "I consent to the collection and processing of my personal health data…"
- Continue disabled until checked. "← Back to Login" link.

**Step 2 — Personal Information (2-column grid)**
- First Name, Middle Name (optional), Last Name, Student Number, College (dropdown — 11 colleges, D-43), Sex (M/F), Course & Year, Date of Birth (+ auto-computed Age badge), Place of Birth, Civil Status (Single/Married/Widowed/Separated), Address, Email, Password.

**Step 3 — Email Verify**
- 6 OTP boxes (auto-focus hidden input, visual boxes highlight as digits are entered).
- Resend link. Each box holds exactly one character; a paste or autofill of the whole code fills all six; Verify & Continue is enabled once all six boxes are filled, and a code containing a non-digit is refused with "Letters aren't allowed — the code is 6 numbers." before any attempt is counted (2026-09-23). The same boxes (`<x-otp.boxes>`) serve every OTP screen.
- Revisiting Step 3 after a successful verification (e.g. with the browser's Back button) shows 'Your email is already verified' and a Continue button to Step 4 instead of the code boxes; the wizard's pages are never served from the browser's back/forward cache (2026-09-23).
- In production: fire a `Mail` job on step-2 submit.

**Step 4 — Link Student ID**
- In-browser ID capture: student points their device camera at the back of the physical DHVSU ID **or** uploads a photo of it. `html5-qrcode` decodes the QR client-side; the `IDNo` line is extracted from the multi-line payload (format: `IDNo: XXXXXXXXXX\nFull Name: …\nProgram: …`). Non-digit characters are stripped from both the extracted IDNo and the `student_number` from Step 2 and compared — mismatch shows a clear error. Only the IDNo value is POSTed to the server and stored as `qr_token` (replacing the provisional token from Step 3). No USB scanner is involved at registration.
- "Skip for now" button (links later from My ID screen).
- On complete (scan or skip): log in as the new student.

---

### STUDENT

#### Student Dashboard (`student-dashboard`)
- 3 stat cards across:
  - **Clearance Status** (status badge + orange left border) with the line **"Your college requests your clinic schedule."** where the "Book New Appointment" button used to be (D-61).
  - **Next Appointment** — **read-only since D-61**: the nearest upcoming non-cancelled appointment (date + service + time) with its **"Booked by <College>"** badge (`Appointment::scheduledByLabel()`, batch case only). No cancel action; a future one also says "Booked by your college. Contact your college administrator if you need this cancelled." A student on two future batches sees the nearest; their emails cover the rest. Empty state: "No upcoming appointment", with no Book link.
  - **Past Clearances** (large count, "View all →" link).
- **Recent Activity** timeline card below (bullet list of timestamped events).

#### ~~Book Appointment · Booking Confirmed · My Appointments~~ — removed by D-61
Students are scheduled only through their college (a batch request the Clinic Director approves), so the booking page, its confirmation screen (and the first-booking tutorial modal on it), the availability JSON, the cancel action and the My Appointments page (FR-STU-14, D-51) are gone. Every old URL (`/student/appointments…`, `/student/my-appointments`) returns 404. The month roll-up they used lives on in `ClinicScheduleService` for the College Admin's batch calendar.

#### My Records (`student-records`)
- Table: Date, **Service — the visit's official form (D-62): "Medical Clearance" or "Medical Assessment Form", from `ClinicVisit::formType()`; it used to read `service_type` and so said "Medical Clearance" on every row**, Result (Fit/Unfit badge), Reference No., View.
- **(D-73)** "View" is a **link to a page**, `GET /student/records/{visit}` — the old record modal is **removed**, and with it the record JSON the list used to embed, so nothing clinical beyond the result badge reaches the browser until a record is opened. A pending (un-encoded) row still shows a "Pending" badge and no View.
  - *Superseded modal, for the record: a fixed overlay, max-width 700px, with kiosk vitals on the left and the twelve Physical Signs rows on the right.*

#### Clinic Record page (`student-record`) — D-73
- Reached from My Records' View; **encoded visits only** (FR-STU-08). Another student's id, a `captured` visit or a `resting` one (D-72) is a **404** — the lookup runs through the student's own `clinicVisits()` relation, so a 403 never leaks that the id exists.
- Header: back link to My Records, "Clinic Record", the **form-type** badge (D-62), reference no. and visit date.
- **Result card**: the Fit/Unfit result large plus its badge, Purpose (the batch reason, with the admin's specify text), "Encoded by <name> (<role>)" (D-64), encode date, the student's own name and number — and the **Save as PDF** button (FR-STU-15) with a one-line note on what comes down (Letter, one page / Legal, both pages).
- **Vital Signs card**: the clinic's confirmed copy (`clearance_records.encoded_vitals`, D-65) as seven tiles — Height, Weight, BMI, Temperature, Blood Pressure, Heart Rate, Respiratory Rate — each with the D-66 "⚑ Flagged" chip where the stored flag is set, and a **"re-checked"** line under any reading re-taken after a D-72 rest, above a peach note giving the pre-rest reading and its time. A closing line says a flag is a reading outside the usual range, not a result.
- **Physical Signs card**: the twelve rows (D-63) with the student's Yes/No pill (orange = reported, green = clear, "—" = unanswered) and the detail they typed under a Yes; on a **Clearance** visit each row also shows the clinic's own `ps_*` finding under a "Clinic" label, because that column is what prints. Then pregnancy / LMP.
- **Student remarks card** ("Clinic Notes" before D-76): `nurse_notes` verbatim — what prints under REMARKS. **Medical Clearance only**; an Assessment record has no such card (D-76).
- **Assessment visits only** (D-69/D-70), each its own card in the paper's order: I. Personal / Social History · Past Medical History & Family History (the eighteen rows, Patient / Family columns, with the typed specify text) · II. Immunization Profile (given vaccines highlighted, the rest greyed, plus "Others") · III. Family Planning Access · Past Surgical History / Procedures · **V. Menstrual History and VI. OB/Pregnancy History — female students only**, as the paper is (`ClinicVisit::studentIsFemale()`, server-side) · Pertinent Physical Examination (only the findings the clinic ticked, plus each group's "Others"; a group with none reads "No findings recorded").
- **Save as PDF** (`GET /student/records/{visit}/pdf`, `throttle:10,1,record-pdf`): the SAME template the clinic prints, via `App\Support\VisitDocument` — one Letter page for a Medical Clearance, both Legal pages in ONE file for a Medical Assessment Form. No front/back buttons for the student, and no `printed_at` stamp.

#### My ID & Profile (`student-profile`)
- Two-column layout (240px left + 1fr right):
  - **Left card (linked)**: "My Kiosk QR Code" heading, SVG QR code graphic generated by `simplesoftwareio/simple-qrcode` from `qr_token`, "Scan at the kiosk for fast login" note, Active badge.
  - **Left card (unlinked — student skipped Step 4)**: shows the same in-browser ID capture flow as Step 4 — camera or uploaded ID photo via `html5-qrcode`, `IDNo` extracted and matched to `student_number` — with a "Skip again" link below.
  - **Right card**: read-only profile fields (Full Name, Student Number, College, Course & Year, DOB, Age [computed], Place of Birth, Address, Civil Status, Email, Account Status). "Edit Profile" button (ghost, sm).
- **Edit Profile modal**: editable fields (name, email, **program** [the `course` field — a college-dependent dropdown from `config/programs.php` since D-42, disabled until a college is chosen and cleared when the college changes], year, **college** [students transfer; changing it re-scopes the student to the new College Admin — past clearances keep their capture-time college via the `clinic_visits.college_id` snapshot], address, DOB, place of birth, civil status). Student number and sex remain read-only. Save Changes / Cancel.

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
- **"Choose the form the clinic will use" card** (D-62), on top of the page: two radio tiles styled as cards — **Medical Assessment Form** (left) and **Medical Clearance** (right) — each with a live, blank render of that official form's front page (the printed template, served by `admin.batches.form-preview` into an iframe) and a short guidance text. Keyboard-accessible; the tiles stack at phone width.
- **Reason dropdown** (required) — **disabled until a form is chosen**; its options are the chosen form's purposes, and changing the form clears the reason and the detail. If `others` selected: textarea "Please specify" (max 120) appears below. `old()` restores form, reason and detail after a validation error or the clash popup.
- **Requested clinic date** (D-29) — a compact **month calendar** since **D-54**, with the (D-61-removed) student booking calendar's look and rules: month header with prev/next, weekday row, past days disabled, FULL days greyed and unselectable, today unavailable after closing (BR-20) or once no hour is left (BR-23), non-booking weekdays disabled, same legend. "Today" is the server's date. It still submits `requested_date` through a hidden input; its month data comes from `GET /admin/batches/availability` (`full_days`, `cutoff_days`), built by `ClinicScheduleService` (the student calendar that shared them went with D-61).
- **Clash popup** (D-54, D-77, BR-25): if any selected student already has a clinic schedule that day — another pending/approved batch on the same date, any hours, any form (D-61 retired the self-booking case) — the submit comes back with a teleported popup, **"Some students already have a clinic schedule that day"**, listing every clashing student (name, student no., and what they clash with, e.g. "Already scheduled for a Medical Clearance that day, on BR-2026-004 (9:00 AM – 11:00 AM)"). **Remove these students from the batch** deselects exactly those students and closes; **Choose students again** keeps the selection, scrolls to Select Students and badges the clashing rows **Already scheduled** so they can be unticked one by one or the date changed. Reason, service, date, start hour and selection are all restored.
- **Student multi-select** (scoped to admin's college):
  - **Program dropdown** left of the search bar (2026-09-23, FR-ADM-03): All programs + the college's catalog programs from `config/programs.php`, even ones with no students yet; styled like the Clinic Dashboard's filters. Browser-only — never posted. A program with no students reads "No students registered in <program> yet."
  - Search bar (by name or student number) — the list shows students matching **both** the program and the search.
  - Select All / Clear links. Select All acts on the filtered list; the selection is kept across filter changes, and M in the counter stays the whole roster.
  - Scrollable checkbox list (max-height 260px): each row = name (600) + student number + course/year (muted). Selected rows = peach background.
  - Counter: "(N of M selected)".
- "Submit Request (N)" — disabled until form + reason + ≥ 1 student selected.

#### Request Submitted (`admin-new-batch-confirm`)
- Success check icon.
- Summary: Batch ID, Status = "Pending Director Approval", Submitted date.
- Two buttons: "View Tracking" + "Dashboard".

#### Batch Tracking (`admin-batch-tracking`)
- **Batch Results card** (D-55, FR-ADM-12), **above** the requests table and rendered only when the college has at least one approved batch: every **approved** batch, newest clinic date first, as **Batch ID · Time of Completion · View** (blank header). Time of Completion reads **In progress** until every non-withdrawn student is Completed or Absent, then the date and time of the batch's **last encode** (e.g. "Sep 10, 2026 · 3:42 PM"), or **No one attended** when nobody was completed. **View** opens a teleported popup — reference, service, clinic date, hour span, and per student: name, student no., hour, **Status** (Not yet attended / **Re-check** / At the clinic / Completed / Absent / Withdrawn — **Re-check** is D-72: the student reached the kiosk, a reading was high, and they are resting before re-taking it; at the 8 PM cutoff a still-resting student becomes **Absent**, because their result never reached the queue) and **Result** (Fit / Unfit / "—"). A student with no clinic visit is **Absent from 8:00 PM on the clinic date** (`healthpass.absent_cutoff`, server clock). **Outcome only** — no vitals, screening answers, nurse notes, physician details or visit reference. Rebuilt on every page load (no polling).
- Table: Batch ID, **Form Type (D-62)**, Reason (truncated with ellipsis), Students, Submitted, Status (Pending shows as "Pending Director Approval"; `cancelled` shows as "Cancelled" — D-52).
- **Rejection Reason** column (D-36) appears only when the list holds at least one rejected row.
- **Cancel column** (D-52, FR-ADM-11): a trailing column with a **blank header**, rendered only when at least one row is cancellable, holding a Cancel control on **pending rows only** (every other row gets an em dash). Clicking it opens a confirmation dialog naming the reference, the student count and the requested clinic date. Blank-headed because it holds an action rather than a value — the same shape as the Withdraw column on the batch roster.
- Batch ID links to the **batch roster** (D-40): the batch's clinic date, hour span, form (D-62), purpose and student count, then each student's appointment, hour and the **Withdraw** action. The per-student Status / Result columns and results roll-up D-53 added here **moved to the Batch Results popup in D-55**.
- "+ New Request" button (top-right).

---

### NURSE + PHYSICIAN — the Clinic Dashboard (D-64)

Every page in this section is shared by the nurse and the University Physician. The sidebar's first item is **Clinic Dashboard** (the stat tiles + the clinic-wide encode history, D-44), and the sidebar role label reads "Nurse" or "Physician". The history's **Encoded by** column shows the encoder's name plus a **Nurse / Physician** badge; both roles see the same rows. (A separate clinic logs page was considered and dropped — this history is the log.) Opening a record with View shows '← Back to Clinic Dashboard', returning to the dashboard with the same month / result / search filters and page; opened from the Live Queue, the page links back to the Live Queue (2026-09-23).

#### Live Queue (`nurse-dashboard`)
- **Header**: blinking LIVE dot + "LIVE QUEUE" pill (peach bg, orange text) + "{n} students waiting · updated just now".
- **Queue table** (full-width card, no outer padding): Student (avatar initials + name; first row tagged "NEXT" badge + highlighted peach-35 row — the longest-waiting student), College, **Vitals Summary** (all values inline, flagged values bold orange), **Flags** (flagged-variant badges for temp/bp/bmi **and HR since D-66**, or "—" — there is no RR badge here: that vital is only measured at encode), Time (waiting since), Action ("Encode Result" button — primary for row 1, ghost for others).
- Queue = clinic visits with status `captured`, ordered by `checked_in_at` asc (oldest first = top row — **first come, first served**). The top row is the next student to serve; new kiosk submissions append at the bottom.
- **A `resting` visit is never here (D-72)** — the `captured` filter excludes it on its own, and there is deliberately **no Resting list** for the clinic: a student re-taking a high reading has not submitted anything yet, so nobody is waiting on them. When their re-check releases the visit, `checked_in_at` is re-stamped, so they appear at the **bottom** of the queue rather than jumping it with their original arrival time.
- Refresh by **polling** (every 3–5 seconds via `setInterval` + `fetch`) — meets SM-2 (queue reflects a submission within ≤ 5 s).
- Sidebar "Enable Kiosk Mode" opens the nurse **Kiosk Devices** page (D-27): enroll the current browser/terminal as a trusted kiosk device, list enrolled devices, and revoke each. Reaching `/kiosk` requires a device-enrolled token **OR** an active nurse **OR** config-allowed loopback; everyone else sees a friendly branded restricted-access page (see the KioskAccess middleware).

#### Encode Result (`nurse-encode`)
- **Two-column layout** (1fr + 1fr):
  - **Left column**:
    - Student header card (avatar, name, college · student number · time, Flagged badge if applicable).
    - **Vital Signs card — EDITABLE since D-65, and part of the assessment form** (one `<form>` wraps both columns). Height, Weight, Temperature, BP systolic, BP diastolic and Heart Rate are number inputs pre-filled from the kiosk's reading; **Respiratory Rate (breaths/min) opens empty** — no kiosk sensor measures it, and the clinic measures it here. All seven required, bounded by `config('healthpass.validation')` (systolic > diastolic). **BMI is display only**: live from height × weight in the browser, always recomputed server-side; a posted `bmi` is ignored. The kiosk's flag badges and the D-58 irregular-pulse note stay and still describe the **kiosk's** reading. On a Medical Assessment Form visit this card **is** the paper's "IV. Pertinent Physical Exam". Read-only (encoded) shows `clearance_records.encoded_vitals` with a grey "Kiosk: 150/95 mmHg" under any corrected value.
    - Questionnaire answers card: the form's twelve rows (D-63; Yes/No badges; Yes = flagged variant), with the student's typed detail under each Yes (D-56).
    - **The Medical Assessment Form's own sections — `assessment` visits only (D-69)**, in the paper's order, under the Personal / Social History card and each in its own partial (`resources/views/nurse/encode/assessment/`): **Past Medical History & Family History** (the eighteen conditions, a ☐ Present box in each of the two columns — Past Medical History (Patient) and Family History (Lineal) — and, on the rows the paper gives a blank line, an inline specify box that enables once either box on the row is ticked, ≤ 60 chars); **II. Immunization Profile** (For Children / For Adults / For Elderly & Immunocompromised + a free-text Others; the two "None" boxes are separate keys); **III. Family Planning Access** (Yes / No — neither means not answered); **Past Surgical History / Procedures** + **Date Done** (free text, and open to every student regardless of sex). All optional. Saved to the new `medical_assessments` table; the read-only view shows them disabled. The printed form comes with D-71.
    - **V. Menstrual History and VI. OB/Pregnancy History — female students only (D-70)**, the next two cards. The gate is `student_profiles.sex === 'F'`, read on the server (`ClinicVisit::studentIsFemale()`): for anyone else both cards render at reduced opacity with the note **"For female students only"** and every input disabled — and the server **ignores the posted values and stores both columns NULL**, because a disabled attribute is a courtesy, never the rule. **V** holds Menarche (5–25 yrs), Onset of sexual intercourse (8–60 yrs), **Last Menstrual Period** (a date, never in the future, **pre-filled from the kiosk's `screening_responses.last_menstrual_period`** when the student gave one and still editable), Period Duration (1–15 days), No. of Pads per Day (0–20), Interval Cycle (10–90 days), Contraceptive Method Used (≤ 60 chars) and Menopause Yes/No + Age (30–70). **VI** holds Gravida, Para, T, P, A, L (each 0–20), Type of Delivery (≤ 60 chars) and Pregnancy Induced Hypertension Yes/No. All optional; the ranges come from `MedicalAssessment::MENSTRUAL_RANGES` / `OB_RANGES`, so the inputs' min/max and the validation read one list. **Past Surgical History sits under VI on paper but is not female-only** and stays open to everyone.
    - **Pertinent Physical Examination (D-70)**, the last card: the paper's eight groups A–H (HEENT; Chest / Breast / Lungs; Cardiovascular System; Abdominal Region; Genitourinary System; Digital Rectal Examination; Skin & Extremities; Neurological Examination) from `MedicalAssessment::PHYSICAL_EXAM`, each a checkbox list plus its own **Others** text (≤ 60 chars), laid out 2–3 columns on desktop and stacked on a phone. **No sex gating** — every student gets all eight, which is why the DRE group keeps the form's own "Not Applicable" box — and **"Essentially Normal" is not exclusive** with the findings under it. An unknown group, or a finding key borrowed from another group, is **dropped** when the row is built; a group nobody ticked is recorded empty, not missing.
    - **Personal / Social History (from the kiosk)** card — **`assessment` visits only (D-68)**, under the questionnaire: Smoking, Alcohol, Illicit Drugs (Yes / No / Quit) and Sexually Active (Yes / No), read-only, "—" for an unanswered row. A `clearance` visit has no such card.
  - **Right column** — "Doctor's Assessment" card:
    - **Fit / Unfit** selector (two cards; selected = peach bg + orange border).
    - **Medical Case Categories** multi-select checkboxes (D-23 — a case can span several systems; each persists as a `clearance_case_categories` row): Alimentary System, Respiratory System, Musculo-Skeletal System, Integumentary System, Urinary System, Metabolic Endocrine System, Cardiovascular System, Eyes, Ears, Nose & Throat Disorders. The kiosk's **vision/hearing answers are decision support** for the "Eyes, Ears, Nose & Throat Disorders" pick — they have no physical-sign row of their own. *(Those two kiosk questions were removed by D-56.)*
    - **Purpose / Cleared For** — **read-only since D-62**: the batch reason's label (plus its specify text for Others), copied onto the clearance record on Save. The page header shows the form-type badge (Medical Clearance / Medical Assessment Form). The nurse picker and the `<x-hp.purpose-fieldset>` component are gone (D-24/D-28 superseded).
    - **Physical Signs Disorder of** — **Medical Clearance visits only since D-69.** On an `assessment` visit these rows are **not rendered and not saved**: that paper prints the student's own answers, so the left column's questionnaire card is titled "Physical Signs Disorder of (Self Assessment)" and is the section. What follows describes the Clearance fieldset. (D-22, rows per D-63): twelve Yes/No rows — SKIN, HEAD, EYES, EARS, NOSE, THROAT, CHEST/LUNGS, HEART, ABDOMEN, KIDNEY/BLADDER, BRAIN, MENTAL DISORDER. The physician examines the student at the clinic; the nurse records the findings. Each row optional — an unanswered row prints as blank bubbles on the form. Stored in `clearance_records.ps_*`. **Kiosk pre-fill (D-56/D-63):** the kiosk asks these same twelve rows, so every row opens pre-checked with the student's answer, **YES and NO alike** (`ps_<key>` ← `<key>`), for the nurse to confirm or correct after the exam; a NULL kiosk answer leaves the row blank. *(Before D-56 the kiosk asked different self-report systems, mapped skin→SKIN, digestive→ABDOMEN (GIT), nose→HEENT, respiratory→CHEST/LUNGS, bones→EXTREMITIES, heart→HEART/CVS, nervous→NEUROLOGICAL, and GUT/BREAST always opened blank. From D-56 to D-63 the rows were the old form's nine — SKIN, ABDOMEN (GIT), HEENT, GUT, CHEST/LUNGS, EXTREMITIES, HEART/CVS, NEUROLOGICAL, BREAST.)*
    - **Student remarks** textarea (D-76; "Clinic Notes" under D-64, "Nurse Notes" before that — the column is still `nurse_notes`) — **Medical Clearance only**, prints under REMARKS. No placeholder; a helper line says it comes from what the student typed at the kiosk and prints as Remarks. It opens with a **server-built default**, `ClinicVisit::studentRemarks()`: the student's YES details, one `SKIN: <detail>` line each in the form's order (D-56; the twelve-row order since D-63), or **`No student remarks`** when there are none. It stays **editable**. Precedence: `old()` input → the saved record's value → the default, so a saved edit is never replaced by a regenerated default. **A Medical Assessment visit shows no such box** — FO010-R00 has no Remarks line — and saves `nurse_notes` NULL (a posted one is dropped, like `ps_*`).
    - "Preview & Print Medical Clearance" button (ghost style, full width, Download icon).
    - "← Back" (ghost) + "Save & Close Appointment" (primary, flex-2) — Save disabled until Fit/Unfit chosen.

- **On Save**: update clinic visit `status` → `encoded`, create `clearance_records` row, return to queue. **D-69:** on an `assessment` visit the same transaction also creates the one `medical_assessments` row; a Medical Clearance visit creates none. **D-70:** that row also carries `menstrual_history` and `ob_history` (**NULL unless the student is female**) and `physical_exam` (all eight groups, always). **D-65:** the same transaction stores the confirmed vitals in `clearance_records.encoded_vitals` (BMI recomputed) and the measured `vital_signs.respiratory_rate`; **no other `vital_signs` column or flag is ever touched** — the kiosk's reading is what the flags and the analytics describe (BR-14). **D-64:** the physician block is stamped from the encoder — a physician's own name (`UPPER(name), MD`) and license, NULL for a nurse. An encoded visit's read-only notice reads "encoded by <name> (<role>)".

#### Print Medical Clearance (modal)
- Fixed overlay (z-index 2000), modal 92% wide / 92vh.
- Modal header: "Medical Clearance — Document Preview" + "Kiosk data pre-filled. Review before printing." + Close + Print buttons.
- Body: `<iframe>` renders the **official PamSU form** as an HTML document, pre-filled from student + the clinic's confirmed vitals (`encoded_vitals`, D-65) + the nurse-encoded assessment (result, purpose, notes, physical-signs exam findings) + the questionnaire's pregnancy/LMP answer (D-22).
- **D-67:** the read-only (encoded) screen also offers **Save as PDF** beside Reprint — `GET /nurse/visits/{visit}/pdf`, the same document rendered by dompdf at Letter and returned as `{reference_no}-medical-clearance.pdf`. It deliberately does **not** stamp `printed_at`.

**Official form details (PSU-QSP-OSS-004-FO002-R04, D-67 — replaces …-R03):**
- **One template, two renderers:** `resources/views/forms/medical-clearance.blade.php` is rendered BOTH by the browser print (iframe + `window.print()`) and by **dompdf** for the PDF. It is a standalone HTML document (no app layout, no Vite, no Tailwind) written in dompdf-safe CSS only — tables, block and inline-block, fixed widths, borders, `page-break-*`; **no flexbox, no grid, no JavaScript**. Letter portrait, `@page` margins 0.6in / 0.9in / 0.3in / 0.9in, one page. The logos are base64 `data:` URIs from print-sized copies in `public/images/form/print/`. All the view data is built once in `App\Support\ClearanceDocument`.
- Header: "Republic of the Philippines / PAMPANGA STATE UNIVERSITY / (former Don Honorio Ventura State University) / Office of Student Welfare and Formation / Health Services Unit / MEDICAL CLEARANCE"
- Font: **"Times New Roman", Times, serif** for the body and **Arial/Helvetica** for the letterhead — both are core PDF fonts, so the browser and dompdf render the same type. Black on white.
- Student fields: Surname / First Name / Middle Name (3-column underlines), Course/Year/Section, Address, Age, Sex (radio), Civil Status (radio), Date of Birth, Place of Birth.
- Vitals in **three column pairs**: Height / Weight · Heart Rate / Blood Pressure · Temperature / **Respiratory Rate** — printed since D-65 (the clinic measures it at encode; FR-PRT-03 struck). **(D-67) There is no BMI row** — R04 has no BMI box, so the old flagged scan divergence is gone; BMI stays on the student's My Records. Every value is the clinic's confirmed copy from `clearance_records.encoded_vitals`, **not** the kiosk's `vital_signs` reading; a record encoded before D-65 has none and falls back to its kiosk reading.
- Physical signs table (D-63; rebuilt as R04's bordered grid by D-67): YES/NO boxes for the twelve rows in the form's three column groups of four — a marked box prints **solid black**, and (like every bubble on the form) the fill is drawn with **border, not background colour**, because Chrome leaves "Background graphics" unchecked by default — SKIN, HEAD, EYES, EARS | NOSE, THROAT, CHEST/LUNGS, HEART | ABDOMEN, KIDNEY/BLADDER, BRAIN, MENTAL DISORDER — shaded from the nurse-encoded exam findings (`clearance_records.ps_*`, D-22); unanswered rows print blank.
- Remarks — two ruled lines of Student remarks only (D-76); case details are the physician's hand-written annotation (D-22). **(D-67)** dompdf runs no JavaScript, so the old in-page shrink-to-fit script is replaced by a **server-side** rule in `ClearanceDocument::remarks()`: the note is wrapped to the two lines at one size chosen from 10pt down to a **7pt floor**, and anything still over is clipped. Print and PDF get the identical lines and size. **(D-76)** At the 7pt floor the two lines hold ~200 characters, so a long multi-Yes pre-fill is clipped on paper (the full text stays in the record); the page never grows.
- Pregnancy question (YES/NO radio + LMP line) — pre-filled from the kiosk questionnaire (`screening_responses`).
- Fitness declaration (D-67 wording, from the R04 scan): "He/She is physically / mentally ○ FIT ○ UNFIT **to participate in:**" + purpose bubbles — **the visit's form type's purposes (D-62)**, the saved batch-reason label shaded — incl. "Others, Specify: ___" — an Others purpose shades that bubble and prints the specified event on the line, clipped to fit (D-24). The college is NOT printed anywhere on the form (D-25).
- Physician block (D-64, conditional): when a **physician** encoded — their name, "University Physician", "License No. {license}"; when a **nurse** encoded — a blank name line, "University Physician", "License No. ________". Always a blank signature line above for the wet signature. *(Was the pre-printed "REYNALDO S. ALIPIO, MD · License No. 60252".)*
- **Date** line — the encode date (today on a pre-save preview), never an input — and the form code **PSU-QSP-OSS-004-FO002-R04** bottom-**left**.
- Print via `window.print()`; the same template is saved as a PDF via dompdf (FR-PRT-06).

**Official form details (PSU-QSP-OSS-004-FO010-R00 — the Medical Assessment Form, D-71):**
- **Which document prints is the SERVER's call.** `PrintClearanceController` picks the
  template, the paper and the PDF filename from `ClinicVisit::formType()` (D-62) —
  `clearance` → the R04 above, `assessment` → this one. Nothing in the request body
  can change it.
- **One template, two renderers**, exactly as D-67:
  `resources/views/forms/medical-assessment.blade.php`, a standalone dompdf-safe
  HTML document (tables, fixed widths, borders, `page-break-*`; no flexbox, no grid,
  no JavaScript; base64 logos; Times/Arial). **US Legal portrait (8.5 × 14in "long
  bond"), `@page` margins 0.5in / 0.7in / 0.35in / 0.7in, TWO pages** — front and
  back, printed back-to-back on ONE sheet — each carrying the form code at its foot.
  All view data is built once in `App\Support\AssessmentDocument`, which **calls
  `ClearanceDocument`** for the halves both papers share.
- **Front page:** letterhead **without the OSWF block** (this form has no OSWF line) ·
  title MEDICAL ASSESSMENT FORM · Name (Surname / First / Middle), Course Year &
  Section, Address, Age / Sex / Civil Status, Date & Place of Birth ·
  **"Physical Signs Disorder of: (Self Assessment)"** — the same twelve-row grid as
  R04, but shaded from the **student's kiosk answers** (`screening_responses`, D-63),
  **not** from `ps_*`: the student self-assesses and certifies it · the pregnancy /
  LMP line · "I certify that the above informations are true and correct." with the
  Patient's Signature line · the **eighteen-row PAST MEDICAL HISTORY & FAMILY
  HISTORY** table (Medical Condition / Disease · Past Medical History (Patient)
  ☐ Present · Family History (Lineal) ☐ Present) from
  `medical_assessments.medical_history`, each specify text printed **inside its own
  blank** — "Allergy (Specify: seafood)", "Hypertension (Highest BP: 150/100 mmHg)".
- **Back page:** left column **I. Personal / Social History** (the kiosk's Yes/No/Quit
  answers, D-68), **II. Immunization Profile** (the four groups + Others),
  **III. Family Planning Access**; right column **IV. Pertinent Physical Exam** —
  the clinic's confirmed vitals (`encoded_vitals`, D-65) in **this paper's units,
  height in METRES** (cm ÷ 100, 2 dp), weight kg, BP mmHg, Temp °C, HR /min,
  RR /min — **V. Menstrual History**, **VI. OB/Pregnancy History** and
  **Past Surgical History / Procedures + Date Done**. V and VI print **blank — never
  "N/A" — for a student who is not female**. Then the full-width
  **PERTINENT PHYSICAL EXAMINATION** (the eight groups A–H of D-70 in three columns,
  ☐/☑ + each group's Others) · "He/She is physically / mentally ○FIT ○UNFIT
  **to undergo in:**" with the five Assessment purposes, the batch's own shaded
  (D-62) · **"Interviewed/Assessed by:" = the ENCODER** (nurse or physician) ·
  the **University Physician** block, name and License No. only on a physician's
  encode (D-64) · **"Date:" = the encode date**, never an input.
- **Print front / Print back (manual duplex).** Clinic printers rarely duplex, so
  the encode screen shows two buttons instead of one; each posts the encode form to
  the existing preview (unsaved) or reprint (saved) endpoint with `side=front|back`,
  and the template renders just that page. After a front print the screen hints
  "Put the printed sheet back in the tray, then click Print back." — **which way up
  the sheet goes back depends on the printer** (see `docs/qa/e2e-scenarios.md`).
  A `side` outside `front|back` is ignored and the whole document prints.
- **Save as PDF** returns **one file with BOTH pages** at Legal, named
  `{reference_no}-medical-assessment.pdf` — a duplex-capable printer prints that
  with "Two-sided" on, which is why there is no third print button. As with the
  clearance, the download never stamps `printed_at`.

**Physical-signs source (D-22, supersedes the earlier questionnaire → form mapping):**
the form's Physical Signs rows (twelve since D-63) shade from the nurse-encoded exam findings
(`clearance_records.ps_*` — the physician examines, the nurse records), never
from the kiosk questionnaire. Since D-56 the questionnaire asks the form's own
rows (twelve since D-63), and each answer **pre-fills** its matching exam row on the encode
screen for the nurse to confirm — it still never shades the print directly. Its
pregnancy/LMP answer is the one questionnaire item that prints as-is; its YES
details reach the printout only through Student remarks under REMARKS (pre-filled,
then nurse-edited — D-76).

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
- Each batch as a row: Batch ID (orange, 700) + status badge, college name (600), **Form Type (D-62, immediately before the reason)**, reason (italic, in quotes), count + submitted date. The approve and reject modals each show a "Form:" line.
- **Pending rows only** show: "Reject" (ghost sm) + "Approve" (primary sm) buttons.
- **On Approve** (confirm-only since **Decision D-36**, superseding D-29's adjust clause): the modal shows the College Admin's `requested_date` **read-only** — there is no date picker. In one DB transaction: set `batch_requests.status` → `Approved`, stamp `reviewed_by`/`reviewed_at` and `batch_requests.scheduled_date` **= the `requested_date` read from the LOCKED row** (never from the request body), **auto-create one `appointments` record per student listed in `batch_request_students`** (service = batch request's service type, date = the requested date, `source` = `batch`), and update each `batch_request_students.appointment_id`. Two kinds of batch cannot be approved at all — one whose `requested_date` is NULL (pre-D-29), and one whose `requested_date` has already passed (confirm-only can't move it, and a cohort must never be scheduled into the past). For both, Approve is disabled with a one-line notice **and** the endpoint refuses the POST; the Director rejects with a reason instead. A batch requested for today is still confirmable. **D-37 adds two things:** the modal also shows the batch's **clinic hour span read-only** (e.g. 7:00 AM – 10:00 AM (3 slots)), and capacity becomes a **hard block** — if any hour in that span has reached the hourly cap of 12 — **or has already ended, for a batch requested for today (BR-23)** — approval is refused in the UI *and* at the endpoint (capacity re-checked under lock), with the Director directed to reject-and-resubmit. A batch with no hour span (`requested_time` NULL, pre-D-37) is a further un-approvable case. The fan-out writes `scheduled_time` onto every generated appointment, 12 per hour in pivot-row id order. **D-54 adds one more un-approvable case:** a batch any of whose students is already scheduled during its span (BR-25 — another pending/approved batch overlapping it; D-61 retired the self-booking case). Submission already refuses such a batch, so this only catches a pre-D-54 batch or a race; it is re-checked under the row lock and refused with a flash naming up to three of the students ("… N student(s) are already scheduled during its hours: A, B, C and N more. Reject it with a reason so the college can resubmit."), creating nothing.
- **On Reject** (D-36): a **written reason is required** (10–500 chars, `RejectBatchRequest`). Update `batch_requests.status` → `Rejected` and store `batch_requests.rejection_reason`. No appointments created. This is also the escape hatch when the Director can't take the requested date — "date unavailable, please resubmit for &lt;X&gt;" — since the date can no longer be adjusted at approval. The College Admin reads the reason on Batch Tracking.
- Approved/rejected rows show "✓ Approved" or "✕ Rejected" static text (no action buttons).

#### Analytics (`director-analytics`) — rescoped by D-32 (v1.11)
> The Medical Cases analytics (Medical Cases by College, Summary of Medical
> Cases matrix, Cases by Medical System) and the case-category concept were
> **dropped** — HealthPass never sees clinic-wide caseload, only its own
> kiosk/clearance encounters. Every card below reads only data the system
> itself collects: appointments booked in the web app + vitals captured at
> the kiosk. **No Export/print** — analytics is on-screen only (FR-ANL-06
> removed). The approved layout is `docs/prototypes/web/director-analytics-rescope.html`.
- **Filters** (FR-ANL-13): the existing month picker + a new **college dropdown** (default "All colleges"). Both scope every card except the Visits-per-Month trend.
- **Clinic Visits by College** (FR-ANL-09) — horizontal bar chart, one row per college (all 11 since D-43, zero-visit rows included), **a single Visits series in `#FF8C2A` — D-60 dropped the Medical / Dental split and its legend** — sorted by visits descending, total-visits headline, "View as table" toggle (college × visits). Visits = kiosk check-ins (`clinic_visits`, capture-time `college_id` snapshot). Includes a **Visits by Purpose** mini bar chart from the linked appointment's `purpose`/`purpose_other`; visits with no linked appointment or purpose fall into a "Not specified" bucket ("Walk-in / not specified" until D-61).
- **Vital-Sign Flags** (FR-ANL-10) — **five** stat tiles since D-66 (High BP, Fever, Abnormal BMI, **High Heart Rate**, **Abnormal Respiratory Rate**), each showing count **and rate** (% of the month's captured screenings — the same denominator for all five). Recomputed server-side from `vital_signs` flags; every caption is built from `config('healthpass.thresholds')`, and the respiratory-rate tile's caption says "measured by the clinic at encode" because only encoded visits can contribute to its count. The College Admin's page and the printed monthly report carry the same five.
- **Visits per Month** (FR-ANL-11) — line chart across all months with data, **one series**: clinic visits (D-60 dropped the dental series and its legend). Ignores the page filters by design (whole-year, all-college).
- **Students Screened by Sex** (FR-ANL-04, retitled from "By-Sex donut") — Chart.js donut, 160px, Male (orange) + Female (peach), centre total, legend with count + %. Counts students screened (captured kiosk visits), so it already fits the system-collected scope; now obeys the college filter too.
- **Flagged Vitals by Sex** (FR-ANL-14) — beside the donut: one Chart.js 100%-stacked bar per flag (BMI · Temp · BP · PR · RR) — every bar full height, split by each sex's share, Male (orange, on top) over Female (peach) — the flag's total count drawn above each bar, and hovering a segment shows that sex's count and share. Obeys both filters and counts only submitted visits (D-72). On the College Admin page too, and printed in the monthly report as a Flag / Male / Female / Total table.
- **BMI Distribution** (FR-ANL-12, optional) — four rule-based buckets of captured screenings: Underweight (< 18.5), Normal (18.5–24.9), Overweight (25–29.9), Obese (≥ 30). Descriptive only, no profiling (no-AI lock).

#### Flagged Anomalies (`director-flagged`)
- **5 stat cards** (orange left border, D-66): High Blood Pressure, Fever, Abnormal BMI, **High Heart Rate**, **Abnormal Respiratory Rate** — each subtitle quoting its threshold from config.
- **Table**: Student (600), College (muted — capture-time `clinic_visits.college_id` snapshot, not the student's current college), Flag (flagged badge), Value (orange 700), View (link). **The Category column is dropped (D-32).**
- Source: `ClinicVisit::scopeFlagged()` — clinic visits where `vital_signs.is_bp_flagged OR is_temp_flagged OR is_bmi_flagged OR is_hr_flagged OR is_rr_flagged` = true (D-66), joined to student name and the visit's snapshot college. That one scope is also what the Director dashboard preview and the D-57 sidebar badge count, so all three moved together.
- No Export button (FR-ANL-06 removed by D-32).

---

### KIOSK (separate Blade route — 1080×1920 portrait, panel fills the viewport with `--k-zoom` scaling; D-26 supersedes the original 800×480 letterbox)

The kiosk is a **touch-first fullscreen app**. It auto-resets to Welcome 12 seconds after a student submits. In production the simulated sensor readings are replaced by **Web Serial** reads from the microcontroller, and **every vital step also offers first-class manual entry** (an "Enter manually" action opening a numeric on-screen pad; same validation ranges; provenance recorded in `vital_signs.entry_method` — Decision D-7 / FR-KSK-06). The kiosk runs at `localhost` on the Pi so Web Serial has a secure context. Blood pressure can also come from an A&D UA-651BLE **Bluetooth** monitor: a daemon on the Pi posts each reading to the server, and the kiosk picks it up while the BP step waits, keeping the monitor's irregular-pulse indicator for the nurse (D-58).

**Screen flow:**
```
Welcome
  ├── QR scan (USB scanner as keyboard input; multi-line payload normalized to IDNo) → Identity
  └── "Lost ID?" → Email Login (virtual keyboard) → Identity

Identity → already screened? (today's appointment already has a submitted visit → You're all done for today, with the HP reference, never Fit/Unfit → Back to start, FR-KSK-03b) → schedule check (no appointment today → No Clinic Schedule Today → Back to start, D-61) → Privacy Consent → vital-height → vital-weight → vital-temp → vital-bp → Questionnaire
                                                                              │
                          the batch's FORM decides what follows (D-68, resolved on the SERVER at scan/login AND again at submit)
                              ├── clearance  → Review
                              └── assessment → Personal / Social History → Review

Review → Complete (12s auto-reset → Welcome)
```

#### Screen 1 — Welcome
- `#F6F2ED` panel fills the screen (no letterbox — D-18/D-26).
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
- "That's me — Continue" (lg) → schedule check (Screen 3a).
- "Not you?" (ghost lg) → resets to Welcome.

#### Screen 3a — No Clinic Schedule Today (FR-KSK-03a, rewritten by D-61)
- Shown only when the identified student has **no `scheduled` appointment dated today** (server-decided `hasAppointmentToday`). Screen key `no-schedule` (was `walkin`); partial `kiosk/screens/no-schedule.blade.php`.
- Calendar icon; **"No Clinic Schedule Today"**; body: *"You don't have a clinic schedule today. Clearances are scheduled through your college, so please ask your college office to include you in a batch request."*
- **One** button: **"Back to start"** (lg) → resets to Welcome. **No way forward** — there are no walk-ins ("Proceed as Walk-in" is gone).
- An appointment at **any hour** today skips this screen and goes straight to Privacy Consent. The screen is a courtesy, not the gate: submit refuses a student with no `scheduled` appointment today on the server (422, nothing written).

#### Screen 3a′ — You're all done for today (FR-KSK-03b)
- Shown instead of Screen 3a when today's appointment already has a **submitted** visit (`captured` or `encoded`) — server-decided `alreadyScreenedToday`; a `resting` visit (D-72) does not count and the re-check branch still comes first. Key `already-screened`; partial `kiosk/screens/already-screened.blade.php`, same layout as 3a. Shows the visit reference (`screenedReference`) and one line by `screenedStatus` — captured: *"Please proceed to the clinic and wait to be called."*, encoded: *"The clinic has already seen you today."* **Never Fit/Unfit.** One **Back to start** button. Submit refuses a second visit on that appointment on the server, under a row lock.

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
- **Seven-sample capture (D-74)** — height, weight and temperature do not take the first sensor reading. The step shows its "Measuring…" scan card (no sample counter) while it collects **7** readings — or, once **6 s** have passed since the first, at least **3** (fewer keeps waiting; the "sensor is quiet" nudge and the 90 s idle reset cover a sensor that stopped) — then records the average of the **steadiest cluster**: the longest run of readings within the field's tolerance (height ±2 cm, weight ±1 kg, temperature ±0.3 °C, on the `VITALS` field metadata), ties to the tighter run, then the more recent. E.g. weight 48.7 · 49.3 · 50.4 · 52.9 · 52.2 · 52.8 · 52.9 → **52.7 kg**. The average goes through the ordinary sensor capture (range check, `entry_method = sensor`); the server still recomputes BMI and every flag. The manual pad still opens mid-sampling and discards the buffer; Retry, Previous and a D-72 re-check all start a fresh one. Blood pressure is not sampled — it arrives as one finished reading (D-58).

| Step | Vital | Icon | Key detail |
|---|---|---|---|
| 1/4 | Height | 📏 | Ultrasonic sensor. Captured: e.g. 163 cm, "Normal" badge. **D-74 / FR-KSK-17:** the result also shows feet and inches as secondary text (175 cm · 5 ft 8.9 in) — display only; cm is stored and printed. |
| 2/4 | Weight | ⚖️ | Load cell scale. Captured: e.g. 64 kg + computed BMI panel (peach bg, shows BMI + status badge + "from Xcm + Ykg"). |
| 3/4 | Temperature | 🌡️ | IR forehead thermometer. Captured: e.g. 37.9°C, "Slightly Elevated" (flagged badge), normal range note. |
| — | *(BP step, heart-rate panel)* | ❤️ | **D-66:** the heart-rate sub-panel carries a status badge reading **"Normal" or "High"** (> 100 bpm, from the injected `thresholds.hrMax`) — a status, never an interpretation, and never Fit/Unfit. |
| 4/4 | Blood Pressure | 💪 | Cuff BP monitor. Has its own instruction step ("Place your arm in the cuff") before measuring. Pulsing arm emoji during scan. Captured: e.g. 145/92 mmHg "Elevated — Flagged" + Heart Rate in peach panel (78 bpm, Normal badge). |

#### Screen 8 — Questionnaire (rewritten by D-56; rows replaced by D-63)
- Heading **"Physical Signs Disorder of:"**, sub-line "Answer YES or NO for each."
  **D-68:** on an `assessment` visit the heading reads **"Physical Signs Disorder of: (Self Assessment)"** — the Medical Assessment Form's own label for that table.
- **2-column grid** of the new official forms' twelve rows, reading down the form's three columns:
  SKIN · HEAD · EYES · EARS · NOSE · THROAT · CHEST/LUNGS · HEART · ABDOMEN · KIDNEY/BLADDER · BRAIN · MENTAL DISORDER.
  Each card: the form's label verbatim + one plain-language helper line (e.g. SKIN — "Rashes, wounds, itching or other skin problems"; KIDNEY/BLADDER — "Kidney, bladder or urination problems"; MENTAL DISORDER — "Anxiety, depression or other mental health concerns"; the full list is FR-KSK-10) + Yes (orange when selected) / No (green when selected) buttons. One list: `ScreeningResponse::QUESTIONS`, mirrored by the kiosk's JS `SYSTEMS`.
- **YES details:** a Yes card offers "Add details" → a full-width panel docked at the bottom of the screen with the question label, the typed text, an "N / 120" counter, **Done**, and the shared on-screen keyboard. Max 120 characters; switching to No clears it; the card then shows the detail truncated. **Medical Assessment Form:** optional, never blocks Review (D-56). **Medical Clearance (D-75):** required — the trigger reads "+ Add details (required)", each Yes needs at least 3 characters after trimming, Review & Submit stays disabled and a red line names the question until it has one, and `KioskSubmitRequest` refuses the same thing server-side (on `/kiosk/submit` and `/kiosk/rest`) using the form type from `Appointment::todayFor()`, never the body. The official form says "If YES, give details under Remarks".
- **Pregnancy question** below the grid (full width), in the form's wording: **Are you Pregnant?** Yes/No — "If YES, when is the last menstrual period?" If Yes: inline calendar (full month, tap to pick date — future dates disabled) for Last Menstrual Period. **Female students only (D-79):** a male student never sees it, and the server stores `is_pregnant = false` with no LMP for him, deciding sex from the session student's profile (`StudentProfile::isFemale()`), never from the request.
- Footer: "{N} of {total} answered" (13 for a female student, 12 for a male) + "Review & Submit →" (disabled until all of them are answered). On the 1080×1920 panel the grid area scrolls when the twelve cards overflow; the footer stays on screen. **D-68:** on an `assessment` visit that button leads to Screen 8a, not to Review.

#### Screen 8a — Personal / Social History (FR-KSK-10a, D-68 — `assessment` batches only)
- Shown **only** when the identity payload's server-decided `formType` is `assessment`; screen key `social-history`, partial `kiosk/screens/social-history.blade.php`. A `clearance` student goes from Screen 8 straight to Review and never sees this.
- Heading **"Personal / Social History"** — section **I** of the Medical Assessment Form's back page — with the confidentiality line **"Your answers are confidential and are seen only by the clinic staff."**
- **Four full-width rows**, one column, each with the form's label verbatim and the paper's own boxes as large touch buttons:
  **Smoking** · **Alcohol** · **Illicit Drugs** — **Yes** (orange when selected) / **No** (green) / **Quit** (slate);
  **Sexually Active** — **Yes** / **No** only.
- One list: `ScreeningResponse::SOCIAL_HISTORY`, mirrored by the kiosk's JS `SOCIAL_HISTORY`. The three habits store the paper's own word (`yes`|`no`|`quit`); Sexually Active stores a boolean.
- Footer: **"← Back"** (to Screen 8) + **"Review & Submit →"**, disabled until all four are answered. The 90 s idle reset applies as usual, and every answer lives in the single Alpine `state` object, so reset-to-Welcome clears them wholesale (FR-KSK-13).
- At the 1080×1920 target the four rows plus header and footer fit without scrolling at `--k-zoom`.

#### Screen 9 — Review
- "Review Your Submission" in header.
- Two-column cards: **Vital Signs** (key-value; flagged items in orange + ⚑ — since D-66 that includes Heart Rate and Respiratory Rate) + **Health Questionnaire** (Yes/No badges for the form's twelve rows — D-63 — each YES detail shown under its badge — D-56).
- **D-68:** on an `assessment` visit a third card, **Personal / Social History**, lists the four answers. Back steps to Screen 8a on an `assessment` visit and to Screen 8 on a `clearance` one — one step through the flow the student actually walked.
- "Submit to Clinic →" (xl, center).

#### Screen 10 — Complete
- Success circle with check.
- "Submitted!" heading.
- "Your vitals have been recorded. Please proceed to the nurse's station."
- Countdown pill: "Returning to home screen in {N}s…" → auto-resets at 0.

---

## 8. Database schema (11 tables)

> Was locked at 10 tables; **D-69 (September 20, 2026) adds `medical_assessments`**
> as table #11 — the Medical Assessment Form's own sections, written only for
> visits that use that form. `clearance_case_categories` (added as table #11 on
> July 9, 2026 by D-23) was **removed by D-32** (July 18, 2026) when the
> Medical Cases analytics and the case-category concept were dropped.
> (The `kiosk_devices` device-auth table, D-27, is documented in the PRD
> data dictionary as a flagged extension and is not shown in this section.)

### `colleges`
```sql
id              bigint PK
code            varchar(10) UNIQUE          -- COE, CEA, CBS, CAS, CSSP, CCS, CHTM, CIT, LAW, GS, LHS (11 since D-43 removed SHS; each code is a key of config/programs.php)
name            varchar(120)
created_at, updated_at
```

### `users`
```sql
id                    bigint PK
role                  enum('student','college_admin','nurse','physician','director')  -- physician added by D-64
license_number        varchar(20) NULL   -- D-64: PRC license, digits only (4–10); required for physician, NULL otherwise
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
middle_name           varchar(80) NULL
last_name             varchar(80)
sex                   enum('M','F')
course                varchar(120)          -- academic program; value set is CONFIG-backed (config/programs.php, D-42), NOT a table — no FK, 10-table canon unchanged. Labelled "Program" in the UI; the column keeps the name `course` because the official form prints "Course, Year & Section" (D-25). Validated server-side against the SUBMITTED college's catalog entry.
year_level            varchar(20)           -- storage key from the same per-college catalog entry ('1'..'5', or '7'..'10' for Laboratory High School); the display label ("3rd Year", "Grade 8") lives in config, not the database
date_of_birth         date
place_of_birth        varchar(120)
civil_status          enum('Single','Married','Widowed','Separated')
address               text
qr_token              varchar(64) UNIQUE       -- IDNo parsed from the physical ID QR (= student_number); provisional until linked
privacy_consent_at    timestamp NULL        -- registration consent
created_at, updated_at
```

### `appointments`
```sql
id                    bigint PK
reference_no          varchar(20) UNIQUE    -- APT-YYYY-####
student_id            bigint FK → users.id
service_type          enum('medical','dental')  -- always 'medical' since D-60; the value is kept so pre-D-60 rows read back
-- purpose / purpose_other were DROPPED by D-62 (D-28's student-chosen purpose; the purpose now comes from the batch)
scheduled_date        date
scheduled_time        time NULL             -- D-37: the one-hour clinic slot ('07:00:00' .. '16:00:00', canonical 'H:i:s'); REQUIRED on every booking from D-37 onwards, NULL on pre-D-37 rows which were deliberately NOT backfilled (they belong to no slot, render as "—", and are counted only by the daily cap)
status                enum('scheduled','checked_in','completed','cancelled') DEFAULT 'scheduled'
source                enum('self','batch')  -- how the appointment was created; always 'batch' since D-61 ('self' = pre-D-61 history)
batch_request_id      bigint NULL FK → batch_requests.id
created_by            bigint NULL FK → users.id
created_at, updated_at
-- index(scheduled_date, status)                  -- daily capacity checks + daily appointment lists
-- index(scheduled_date, scheduled_time, status)  -- D-37: per-slot counts; also serves the daily count as a leftmost prefix
```

### `batch_requests`
```sql
id                    bigint PK
reference_no          varchar(20) UNIQUE    -- BR-YYYY-###
college_id            bigint FK → colleges.id
requested_by          bigint FK → users.id  -- the college_admin
form_type             enum('clearance','assessment') DEFAULT 'clearance'  -- D-62: the official clinic form; drives kiosk, encode and print. Pre-D-62 batches all used the clearance, so no backfill
reason                varchar(30)           -- D-62 (was an enum): a key of BatchRequest::REASONS_BY_FORM[form_type]; validation is the gate
reason_detail         text NULL             -- used when reason = 'others'; max 120 since D-62 (prints on the "Others, Specify:" line)
service_type          enum('medical','dental')  -- always 'medical' since D-60; the server never reads it from the request
requested_date        date NULL             -- admin-proposed clinic date, set at submission (D-29); NULL only on pre-D-29 batches. Picked on a mini calendar since D-54
requested_time        time NULL             -- D-37: admin-chosen START hour of the batch's span (canonical 'H:i:s')
requested_blocks      tinyint unsigned NULL -- D-37: span LENGTH in whole hours = ceil(students / hourly_capacity), always computed server-side. Start + block count, NEVER an end time and never both: the count is what every consumer loops over, an end time is ambiguous about the last hour, and re-deriving it later could silently shrink an approved span if a student left the roster. Both columns NULL only on pre-D-37 batches, which (like a NULL requested_date under D-36) cannot be approved at all
scheduled_date        date NULL             -- FINAL appointment date, stamped at approval; = requested_date since D-36 (D-5, amended by D-29/D-36)
status                enum('pending','approved','rejected','cancelled') DEFAULT 'pending'  -- D-52 added 'cancelled'; the transition is pending → {approved, rejected, cancelled} and all three are terminal (BR-24)
rejection_reason      text NULL             -- Director's written reason, required on reject (10-500 chars, D-36); NULL on pending/approved and on pre-D-36 rejections
reviewed_by           bigint NULL FK → users.id   -- director who acted
reviewed_at           timestamp NULL
cancelled_at          timestamp NULL        -- D-52: when the College Admin cancelled a PENDING request (FR-ADM-11)
cancelled_by          bigint NULL FK → users.id   -- D-52: the admin who ACTED, not requested_by — a college may have two admins since D-47. Both cancellation columns are NULL on every non-cancelled row and are NEVER backfilled. They exist because the College Activity Log (D-49) is DERIVED from this table and needs an actor + a timestamp to place the event; reviewed_by/reviewed_at were deliberately NOT reused, since they mean "the Director decided" in three other places and a cancellation is not a decision
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
college_id            bigint FK → colleges.id   -- SCHEMA ADD: snapshot of the student's college at capture time, so analytics stay transfer-proof (FR-STU-09)
course                varchar(120) NULL     -- SCHEMA ADD (D-43): snapshot of the student's PROGRAM at capture time, frozen beside college_id above. student_profiles.course stays live, so without this a program shift would restate every past per-program report. NULLABLE and NEVER BACKFILLED — a pre-D-43 visit has no honest answer and renders "—" (the pattern appointments.scheduled_time uses for pre-D-37 rows); a profile with no program also stores NULL rather than blocking the kiosk.
appointment_id        bigint NULL FK → appointments.id   -- always set since D-61 (no walk-ins); NULL only on legacy walk-in rows
login_method          enum('qr','email')
status                enum('resting','captured','encoded') DEFAULT 'captured'  -- SCHEMA ADD (D-72): 'resting' = a first pass whose temp/BP/HR was flagged, parked while the student rests. Not a submitted visit — ClinicVisit::scopeSubmitted() (captured|encoded) is the one definition every count uses. resting → captured → encoded, at most once, never in reverse.
privacy_consent_at    timestamp NULL
checked_in_at         timestamp NULL        -- re-stamped by the D-72 re-check, so a returning student joins the BACK of the FCFS queue
resting_until         timestamp NULL        -- SCHEMA ADD (D-72): when a resting student may come back, now() + healthpass.kiosk.recheck_rest_minutes (10). Written once by POST /kiosk/rest, cleared by the re-check, NULL on every visit that never rested; never backfilled. The Rest screen shows THIS time — never a browser clock.
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
is_hr_flagged         boolean DEFAULT false -- heart rate > thresholds.heart_rate_max (100 bpm); computed at kiosk capture (D-66)
is_rr_flagged         boolean DEFAULT false -- respiratory rate outside 12-20; computed at ENCODE, with respiratory_rate (D-66)
first_reading         json NULL             -- SCHEMA ADD (D-72): the PRE-REST reading + its flags — {temperature_c, bp_systolic, bp_diastolic, heart_rate_bpm, is_temp_flagged, is_bp_flagged, is_hr_flagged, taken_at}. Written once by POST /kiosk/recheck from the STORED row, never from the body. NULL on every visit that never rested. One JSON column because nothing queries inside it: the clinic only READS it, on the encode page, as "First reading 150/95 mmHg at 9:05 AM → re-checked at 9:17 AM". The columns above always hold the RE-CHECKED numbers, which is what the flags, analytics and print describe.
bp_device_reading     json NULL             -- Bluetooth BP monitor's record of the reading (irregular_pulse flag, raw hex, device_model …); NULL if typed/serial, never backfilled (D-58)
created_at, updated_at
```

### `screening_responses`
```sql
id                    bigint PK
clinic_visit_id       bigint UNIQUE FK → clinic_visits.id
-- The new official forms' twelve "Physical Signs Disorder of" rows (D-63
-- replaced D-56's nine — skin/abdomen_git/heent/gut/chest_lungs/extremities/
-- heart_cvs/neurological/breast — which had replaced the old self-report
-- columns; old answers discarded both times). Names match the
-- clearance_records ps_* suffixes. NULL at DB level; validation requires all
-- twelve on every new visit.
skin                  boolean NULL
head                  boolean NULL
eyes                  boolean NULL
ears                  boolean NULL
nose                  boolean NULL
throat                boolean NULL
chest_lungs           boolean NULL
heart                 boolean NULL
abdomen               boolean NULL
kidney_bladder        boolean NULL
brain                 boolean NULL
mental_disorder       boolean NULL
details               json NULL             -- question key → text typed under a YES (≤120 chars); NULL when none
-- Personal / Social History (D-68): section I of the Medical Assessment
-- Form's back page. ALL FOUR ARE NULL on a `clearance` visit — that form has
-- no such section, so the questions are never asked — and on every visit
-- captured before D-68. The value list is gated by validation, not by the
-- column type, so MySQL and the SQLite suite behave identically.
smoking               varchar(4) NULL       -- 'yes' | 'no' | 'quit'
alcohol               varchar(4) NULL       -- 'yes' | 'no' | 'quit'
illicit_drugs         varchar(4) NULL       -- 'yes' | 'no' | 'quit'
sexually_active       boolean NULL          -- the paper offers only Yes / No here
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
-- No case_category: the case-category concept was dropped by D-32 (the
-- nurse encodes Fit/Unfit only). The clearance_case_categories child table
-- added by D-23 was removed with it.
purpose               varchar(50) NULL  -- D-62: the batch reason's printed label, copied at encode (NULL with no batch)
purpose_other         varchar(120) NULL -- D-62: the batch's "Others, Specify" text, copied at encode
nurse_notes           text NULL         -- D-76: the "Student remarks" — the clearance's REMARKS, pre-filled from the kiosk YES details, nurse-editable; always NULL on an assessment (column name unchanged)
-- "Physical Signs Disorder of" exam findings (D-22): physician examines,
-- nurse records on the encode screen; NULL = not examined (prints blank)
-- The twelve rows per D-63 (replaced D-56's nine ps_skin…ps_breast, not mapped).
ps_skin               boolean NULL
ps_head               boolean NULL
ps_eyes               boolean NULL
ps_ears               boolean NULL
ps_nose               boolean NULL
ps_throat             boolean NULL
ps_chest_lungs        boolean NULL
ps_heart              boolean NULL
ps_abdomen            boolean NULL
ps_kidney_bladder     boolean NULL
ps_brain              boolean NULL
ps_mental_disorder    boolean NULL
physician_name        varchar(120) NULL  -- D-64: no default; UPPER(encoder name) + ', MD' when a physician encoded, NULL = nurse encoded
physician_license_no  varchar(20)  NULL  -- D-64: no default; the encoding physician's license_number, NULL = nurse encoded
encoded_at            timestamp NULL
printed_at            timestamp NULL
created_at, updated_at
```

### `medical_assessments`
```sql
-- D-69 — the Medical Assessment Form's (PSU-QSP-OSS-004-FO010-R00) own
-- sections. ONE row per ENCODED `assessment` visit; a Medical Clearance
-- encode creates none, and that absence is the honest record.
-- One JSON column per section: the sections are recorded and printed whole,
-- nothing filters or aggregates on a single condition, and no analytics read
-- them. The keys are validated against MedicalAssessment's constants.
id                     bigint PK
clearance_record_id    bigint UNIQUE FK → clearance_records.id  -- restrict on delete
medical_history        json NULL   -- {"patient": [condition keys], "family": [condition keys], "specify": {key: text ≤ 60}}
immunizations          json NULL   -- {"given": [keys], "others": text|null ≤ 120}; child_none / adult_none are separate keys
family_planning_access boolean NULL -- NULL = not answered (NOT a No)
surgical_history       json NULL   -- {"procedures": text|null ≤ 200, "date_done": text|null ≤ 40}; date_done is free text ("2019", "Grade 5")
-- Created empty by the D-69 migration, written by D-70 (sections V-VI and
-- the Pertinent Physical Examination) — one migration for one table.
menstrual_history      json NULL
ob_history             json NULL
physical_exam          json NULL
created_at, updated_at
```

---

## 9. Key relationships summary

```
colleges ────┬──< users (managed_college_id)          one college → many admin accounts
             ├──< student_profiles (college_id)        one college → many students
             ├──< batch_requests (college_id)
             └──< clinic_visits (college_id)            capture-time snapshot (FR-STU-09) — frozen, ≠ student's current college

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
              ──|  clearance_records    (1:0..1)  ──|  medical_assessments (1:0..1 — `assessment` visits only, D-69)
```

---

## 10. Non-functional requirements (ISO/IEC 25010:2023)

| Quality | Requirement |
|---|---|
| Functional suitability | Clearance workflow produces a correct, printable result per student matching the official PamSU form |
| Performance efficiency | Kiosk feels immediate; nurse queue refreshes via polling |
| Compatibility | Kiosk: Chromium kiosk mode on Pi; web app: standard desktop browsers |
| Interaction capability | Kiosk is touch-first at 1080×1920 portrait (D-26) with large targets and on-screen keyboard |
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
- **Login**: USB QR code scanner (acts as keyboard — sends the physical ID's multi-line QR text + Enter; the kiosk normalizes by extracting the `IDNo:` line's value if present, using the full string otherwise — this also handles the phone-QR backup which contains only the IDNo)
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

**Form code**: PSU-QSP-OSS-004-FO002-R04 (D-67; replaces DHVSU-QSP-OSS-004-FO002-R03)  
**Title**: MEDICAL CLEARANCE  
**Issuing office**: Office of Student Welfare and Formation — Health Services Unit  
**Physician block** (D-64): the encoding physician's name + license when a physician encoded; blank name line and "License No. ________" when a nurse encoded (was pre-printed REYNALDO S. ALIPIO, MD · License No. 60252)  
**Respiratory Rate**: printed since D-65 — the clinic measures it on the encode page  
**Template**: `resources/views/forms/medical-clearance.blade.php` — ONE template per form, shared by the print and the PDF, in dompdf-safe CSS (D-67). View data from `App\Support\ClearanceDocument`.  
**Printing**: the Blade document is rendered in an `<iframe>` inside the Encode Result screen; `window.print()` is triggered from the iframe's content window.  
**PDF**: `barryvdh/laravel-dompdf` renders the same template at Letter for `GET /nurse/visits/{visit}/pdf` (FR-PRT-06). Needs PHP's **gd** extension for the letterhead images.

---

## 13. Open action items (as of June 2026)

1. **Week-1 hardware spike**: validate Web Serial + BP monitor serial output before buying hardware.
2. **Paper/scope mismatch**: the capstone paper still frames the system as AI-powered. The documentation team must revise the title and chapter scope to match the no-AI, scheduling + digital clearance system. This is a parallel workstream with its own deadline.
3. **Decided (PRD D-4, amended by D-37)** — clinic capacity is **two** config values in `config/healthpass.php`: `hourly_capacity` (12) and `daily_capacity` (120, raised from 40). Both are config, never controller constants.
4. **Decided (PRD D-5, amended by D-29, then D-36)** — the College Admin proposes the clinic date at batch submission (`batch_requests.requested_date`); the Director **confirms it** at approval, which copies it into `batch_requests.scheduled_date`. Since **D-36** the Director cannot adjust it: the pushback path is rejecting with a written reason so the college resubmits.
---

*End of HealthPass context document.*
