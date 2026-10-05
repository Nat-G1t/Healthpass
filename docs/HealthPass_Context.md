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
| Download own college's Yearly Clearance Report (PDF — FR-ADM-13, D-81; beside Print Monthly Report on the admin's Data Summary page — "Analytics" before D-103; both reports open with the PSU letterhead, D-104) | | ✓ | | | |
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
             College Admin submits batch request (form, purpose,
             students — no date or hour since D-108)
                    │
                    └── Director picks the clinic date + start hour
                        and approves (D-108) → system auto-creates
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

8:00 PM on the clinic day — the healthpass:second-chances job (D-110)
         │  (scheduler, every 5 min; cron must run schedule:run)
         │
         └── Students with NO visit, or only a RESTING one → booked
             TOGETHER on the nearest day from tomorrow that fits them all,
             earliest free hours + free arrival times, SAME batch; roster
             row re-pointed to the new appointment; "2nd chance" email.
             One chance only — missing it is final (Unattended). A captured
             student the clinic hasn't encoded is NOT missed.

Director analytics and flagged anomalies update from encoded records
```

---

## 5. Business rules

### Appointments / booking
- **D-61: students never book.** Only a Director-approved college batch creates an appointment, and every new appointment is `source` = `'batch'`. There is no Book Appointment page, no My Appointments page, and no student cancel. ~~Only the College Admin withdraws a seat (FR-ADM-07).~~ **(D-87)** Nobody cancels an approved batch appointment — the College Admin's per-student withdrawal is removed too. BR-04 (one self-booking per service per date) is retired.
- Past dates are unbookable.
- Each clinic day has a configurable capacity; full days show as unavailable ("FULL").
- **D-37/BR-23: an hour that has already ended is not bookable today.** A slot dies when it **ends** — at 12:00 the 11 AM–12 PM hour is gone, 12–1 PM is still open. Only today is affected, it is decided on the server clock (never the browser's), and since **D-108** it binds **the Director's chosen hours**: the Review page's hour picker greys out a start whose span contains an ended hour, and approval refuses it. Same-day scheduling is allowed with the hours still to come.
- **D-37: the day is ten one-hour slots.** Clinic hours (7 AM–5 PM, lunch included) yield ten bookable slots of `hourly_capacity` = **20** each since D-95 (was 12) — `daily_capacity` **200** (was 120, up from 40). **D-95:** the kiosk averages 2:20 from QR scan to submit; each student is budgeted 3 minutes, so the one kiosk is busy ~47 minutes an hour. There is one counter per hour (D-60). A date is FULL when every slot is at the hourly cap (or the daily cap is reached, which is the only counter that sees pre-D-37 appointments).
- Clinic hours: 7:00 AM–5:00 PM, daily.
- Service type: **Medical Clearance only** (D-60, superseding D-3 and D-33 — dental is not part of HealthPass). The `service_type` columns are kept for history; the server always writes `'medical'`.

### Batch requests
- A College Admin can only request for their own college (enforced server-side).
- **A clinic form is required first (D-62):** `clearance` (Medical Clearance) or `assessment` (Medical Assessment Form). It drives the kiosk questions, the encode fields and the printed document.
- A reason — **shown as "Purpose" on every screen and email since D-105** (the column stays `reason`) — is required and must come from **the chosen form's** list (`BatchRequest::REASONS_BY_FORM`, the paper's exact labels) — Medical Clearance: Field Trip/Educational Tour, Outbound Activities, Others, Specify; Medical Assessment Form: Off Campus Procedure, Sports Activities, On-the-job Training, Related Learning Experience, Others, Specify. If reason = `others`, a detail textarea is required (max 120 — it prints on the "Others, Specify:" line). The batch reason IS the purpose printed on the form.
- At least ~~one student~~ **2 students** must be selected (**D-105**, BR-07) — checked in the browser and in `StoreBatchRequestRequest` from one constant, `MIN_STUDENTS`. Batches submitted earlier with one student are left as they are.
- **The Director schedules every batch (D-108).** The College Admin sends the form, the purpose and the students — **no date and no start hour**. The Director picks both on the batch's Review page; the batch then fills `ceil(students ÷ 20)` consecutive hours, 20 per hour (D-37/D-95), with each student's arrival time (D-96). A roster larger than one clinic day (200) is still refused at submission.
- **One clinic schedule per student per day (D-54, D-77, BR-25).** Since **D-108** only an **approved** batch holds a date, on its `scheduled_date` — a pending batch has no date yet, so it holds nothing. A clash is the **same date** against another approved batch the student is on, whatever its hours or form (D-77 widened D-54's hour overlap to the whole day; D-61 retired the self-booking clash). It is checked only when the Director approves (and shown on the Review page first), which is refused while any student clashes. Rejected/cancelled batches and a student withdrawn from an approved batch hold nothing; a pre-D-37 batch with no hour clashes on its date too.
- **Director approval generates one appointment per listed student automatically** — it appears on each student's dashboard (Next Appointment card) and in their email, and they proceed directly to the kiosk. Since D-61 this is the only way an appointment is created — **except the one automatic 2nd chance (D-110, BR-27)**: at the 8:00 PM cutoff the `healthpass:second-chances` job books each approved batch's missed students (no visit, or only a resting one) together on the nearest day that fits all of them, at the earliest free hours, on the same batch (`appointments.rescheduled_from_id` points back at the missed one; the roster row is re-pointed), and emails them. One chance only; only cutoffs from the last 24 hours; never for a cancelled batch or an inactive student.
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
| College Admin | Dashboard · New Batch Request · Batch Tracking · Data Summary (FR-ADM-08; "Analytics" before D-103) |
| Nurse | Live Queue · Upcoming Batches (D-114) · Encode Result · Enable Kiosk Mode (Kiosk Devices: enroll/revoke trusted terminals, D-27) |
| Clinic Director | Dashboard · Batch Approvals · Batch Tracking (D-112) · Data Summary (D-103; was "Analytics") · Flagged Anomalies |

**Unread badges (D-57, FR-UI-05):** a solid orange badge on a menu item — a dot for 1, the number for 2–9, "9+" from 10, nothing at 0 — at the top-right of the item's label (expanded sidebar, phone drawer) or of its icon (collapsed rail). Orange even on the active item. Refreshed on page load only; no polling.

| Role | Badged item → what it counts |
|---|---|
| Student | My Records → results encoded since last seen · Kiosk Tutorial → a dot until the walkthrough reaches its last step (opening the page doesn't clear it) |
| College Admin | Batch Tracking → Director decisions + results encoded for the college's batch students, since last seen · Activity Log → entries since last seen, minus the viewer's own submissions and cancellations |
| Nurse | Live Queue → visits waiting right now (a work count — opening the page doesn't clear it, encoding does) |
| Clinic Director | Batch Approvals → pending requests (a work count) · Batch Tracking → document release requests waiting for review (a work count, D-113) · Flagged Anomalies → flagged visits captured since last seen |

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
  - **Clearance Status** (status badge + orange left border) with the line **"Your college requests your clinic schedule."** where the "Book New Appointment" button used to be (D-61). **(D-107)** On a **Fit** result a line under "Last updated" reads **"Valid for 6 months — until Apr 4, 2027"** — the encoded date + `healthpass.clearance_validity_months`, no month overflow (Aug 31 → Feb 28), valid through that date (BR-26). From the next day the badge reads **Expired** (red `danger` variant) instead of Fit and the line reads **"Expired — was valid until …"**. Both forms; an Unfit card is unchanged. Server clock, display-only — nothing is blocked. `ClearanceRecord::validUntil()` / `isExpired()`.
  - **Next Appointment** — **read-only since D-61**: the nearest upcoming non-cancelled appointment (date + service + time) with its **"Booked by <College>"** badge (`Appointment::scheduledByLabel()`, batch case only). No cancel action; a future one also says "Booked by your college. Contact your college administrator if you need this cancelled." A student on two future batches sees the nearest; their emails cover the rest. Empty state: "No upcoming appointment", with no Book link. **(D-101)** It shows **"Batch ID: BR-YYYY-###"** — the number the scheduling email gives — not the APT reference; Recent Activity's appointment events name the batch the same way. **(D-110)** A missed appointment that has a 2nd chance is never shown as next — even that evening; the 2nd chance is, with a red **"2nd chance"** badge and the line **"You missed {date}. This is your last chance."** Recent Activity calls it **"2nd chance scheduled"**.
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
- **Result card**: the Fit/Unfit result large plus its badge, Purpose (the batch reason, with the admin's specify text), "Encoded by <name> (<role>)" (D-64), encode date, the student's own name and number. One column of content: the result above a two-column details grid (stacked at phone width) — no button beside it since D-106.
- **Vital Signs card**: the clinic's confirmed copy (`clearance_records.encoded_vitals`, D-65) as seven tiles — Height, Weight, BMI, Temperature, Blood Pressure, Heart Rate, Respiratory Rate — each with the D-66 "⚑ Flagged" chip where the stored flag is set, and a **"re-checked"** line under any reading re-taken after a D-72 rest, above a peach note giving the pre-rest reading and its time. A closing line says a flag is a reading outside the usual range, not a result.
- **Physical Signs card**: the twelve rows (D-63) with the student's Yes/No pill (orange = reported, green = clear, "—" = unanswered) and the detail they typed under a Yes; on a **Clearance** visit each row also shows the clinic's own `ps_*` finding under a "Clinic" label, because that column is what prints. Then pregnancy / LMP.
- **Student remarks card** ("Clinic Notes" before D-76): `nurse_notes` verbatim — what prints under REMARKS. **Medical Clearance only**; an Assessment record has no such card (D-76).
- **Assessment visits only** (D-69/D-70), each its own card in the paper's order: I. Personal / Social History · Past Medical History & Family History (the eighteen rows, Patient / Family columns, with the typed specify text) · II. Immunization Profile (given vaccines highlighted, the rest greyed, plus "Others") · III. Family Planning Access · Past Surgical History / Procedures · **V. Menstrual History and VI. OB/Pregnancy History — female students only**, as the paper is (`ClinicVisit::studentIsFemale()`, server-side) · Pertinent Physical Examination (only the findings the clinic ticked, plus each group's "Others"; a group with none reads "No findings recorded").
- **No download (D-106, supersedes FR-STU-15).** The student reads the record on this page only; the official document stays with the clinic (its Print / Save as PDF is unchanged) and the Director, and a college gets it only through a Director-approved release (D-113). `GET /student/records/{visit}/pdf` (`student.records.pdf`) is **removed**, so typing that URL gives the 404 page — for every visit, not just someone else's.
  - *Removed Save as PDF, for the record (D-73 → D-106): an orange button on the Result card with a one-line note (Letter, one page / Letter, both pages), served by that route under `throttle:10,1,record-pdf` through `App\Support\VisitDocument` — the same template the clinic prints, one file, no `printed_at` stamp.*

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
- **4 stat cards**: ~~Registered Students (college headcount)~~ **Active Students (D-98 — active accounts only, the same set the batch picker lists; seeded graduates are inactive and not counted)**, Total Batches, Pending Approval, Approved.
- **Batch Requests table**: Batch ID (orange), Students, Date Submitted, Status (badge).
- "+ New Batch Request" button (sm, top-right of table).

#### New Batch Request (`admin-new-batch`)
- College banner (auto, read-only).
- **"Choose the form the clinic will use" card** (D-62), on top of the page: two radio tiles styled as cards — **Medical Assessment Form** (left) and **Medical Clearance** (right) — each with a live, blank render of that official form's front page (the printed template, served by `admin.batches.form-preview` into an iframe) and a short guidance text. Keyboard-accessible; the tiles stack at phone width.
- **Purpose dropdown** (required; ~~Reason~~ — renamed on screen by **D-105**, still the `reason` field; first option "— Select a purpose —", specify placeholder "Describe the purpose of this batch request") — **disabled until a form is chosen**; its options are the chosen form's purposes, and changing the form clears the reason and the detail. If `others` selected: textarea "Please specify" (max 120) appears below. `old()` restores form, reason and detail after a validation error.
- **No date and no start hour (D-108).** The Director picks the clinic date and start hour. The page drops the D-54 mini calendar (and its `GET /admin/batches/availability` feed), D-37's start-hour select and span preview, and the D-54/D-77 clash popup and "Already scheduled" badges — there is no date to clash on. Under Submit a note reads "The Clinic Director picks the clinic date and hours, and each student is emailed their schedule once the batch is approved." A selection over one clinic day (200) shows a red note under the student counter and keeps Submit disabled.
- **"Attach signed memo" card (D-109, required)** — in the left column under Request Details, where the date card was. Hint: "A photo or PDF of the signed request memo. The Director reads it before scheduling." The reusable `<x-hp.file-capture name="memo">`: **Take photo** (`accept="image/*" capture="environment"` — camera on phones/tablets, file picker on laptops) and **Upload file** (`.jpg,.jpeg,.png,.pdf`); **one file**, JPG / PNG / PDF up to **5 MB** (`healthpass.uploads.max_kb` / `mimes`) — choosing again replaces it. Below: an image thumbnail (`URL.createObjectURL`) or a PDF tile, the file name and size, and **Remove**. The browser checks type and size with the server's words ("The memo must be a JPG, PNG or PDF.", "The memo must be 5 MB or smaller."); the server re-checks by the file's real content ("Please attach the signed memo." when missing). The form is `multipart/form-data`. A file input can't be refilled, so after a failed submit the card says "Please attach the memo again."
- **Student multi-select** (scoped to admin's college):
  - **Program dropdown** left of the search bar (2026-09-23, FR-ADM-03): All programs + the college's catalog programs from `config/programs.php`, even ones with no students yet; styled like the Clinic Dashboard's filters. Browser-only — never posted. A program with no students reads "No students registered in <program> yet."
  - Search bar (by name or student number) — the list shows students matching **both** the program and the search.
  - Select All / Clear links. Select All acts on the filtered list; the selection is kept across filter changes, and M in the counter stays the whole roster.
  - Scrollable checkbox list (max-height 260px): each row = name (600) + student number + course/year (muted). Selected rows = peach background.
  - Counter: "(N of M selected)". **(D-105)** While exactly 1 student is selected, a muted hint under it reads "Select at least 2 students."
- "Submit Request (N)" — disabled until form + purpose + **signed memo (D-109)** + ~~≥ 1 student~~ **≥ 2 students (D-105)** selected; the page gets the 2 from `StoreBatchRequestRequest::MIN_STUDENTS`.
- **On submit (D-109):** the memo is saved to the private `local` disk (`storage/app/private/memos/`, Laravel's random name) **before** the batch transaction and deleted again if the transaction fails; the stored type is the server-detected one.

#### Request Submitted (`admin-new-batch-confirm`)
- Success check icon.
- Summary: Batch ID, Status = "Pending Director Approval", Form, ~~Reason~~ **Purpose (D-105)**, Students, **Clinic date** — **"To be scheduled by the Director"** while pending (D-108; the date plus an **Hours** row once approved, "—" if never scheduled) — Submitted date. (D-88 removed the "Service" row, which always read "Medical Clearance"; D-108 removed Requested Date / Requested Time and D-90's "another request is waiting for these hours" heads-up — a pending batch holds no hours.)
- **Signed memo (D-109):** "**View**" opens the file inline in a new tab (`admin.batches.memo`, own college only); a batch from before D-109 reads "None — submitted before signed memos were required". The batch roster page's summary shows the same.
- **Students in this batch (D-88):** the roster table, ten per page — the same partial as the batch roster.
- "What happens next": the Director reviews the request, picks the clinic date and hours and approves it — or rejects it with a reason (D-108, D-36); on approval each student is emailed.
- Buttons: "View Tracking" + "Dashboard", and **"Cancel Request"** while pending (D-88 — the D-52 dialog and endpoint).

#### Batch Tracking (`admin-batch-tracking`)
- **Batch Results card** (D-55, FR-ADM-12; columns and statuses since **D-111**), **above** the requests table and rendered only when the college has at least one approved batch: every **approved** batch, newest clinic date first, ten per page, as **Batch ID · Purpose · Date requested · Status · View** (blank header). **Purpose** is cut to 60 characters; **Date requested** is the day the college submitted the request (`created_at`). **Status** (`BatchRequest::resultsStatus()`, server clock, first match wins): **Completed** the moment every student is encoded · **Scheduled** before the batch's first clinic hour · **In progress** from the batch's earliest hour until 8:00 PM, on day 1 and again on its 2nd-chance day · **Still in progress** while any student is still Scheduled, Rescheduled, Rest / Retake or At the clinic between or after those days · **No one attended** (everyone Unattended) · **Finished incomplete** (some Unattended). A **Batch ID search** box on the card filters it server-side, across all pages. **View** opens the batch's own results page (below). Rebuilt on every page load (no polling). D-111 removed the D-55 popup and the D-86 Time of Completion column.
- **Requests card header (D-103):** the card holding the table is labelled **"Batch requests"** — the Batch Results card's 11px uppercase label style, no caption — above the table and above the "No batch requests yet" empty state.
- Table: Batch ID, **Form Type (D-62)**, ~~Reason~~ **Purpose (D-105)** (truncated with ellipsis), Students, Submitted, **Clinic Date (D-108** — the Director's date with its hours beneath it, "To be scheduled by the Director" while pending, "—" when never scheduled**)**, Status (Pending shows as "Pending Director Approval"; `cancelled` shows as "Cancelled" — D-52).
- **Rejection Reason** column (D-36) appears only when the list holds at least one rejected row.
- **Cancel column** (D-52, FR-ADM-11): a trailing column with a **blank header**, rendered only when at least one row is cancellable, holding a Cancel control on **pending rows only** (every other row gets an em dash). Clicking it opens a confirmation dialog naming the reference, the student count and — for an approved batch — its scheduled clinic date. Blank-headed because it holds an action rather than a value — the same shape as the Withdraw column on the batch roster.
- Batch ID links to the **batch roster** (D-40): the batch's **Clinic date** and **Hours** (the Director's schedule; "To be scheduled by the Director" while pending — D-108), form (D-62), purpose and student count, then each student's appointment and hour. ~~and the **Withdraw** action~~ **(D-87)** The Withdraw action is removed, and students withdrawn before D-87 are not listed. The per-student Status / Result columns and results roll-up D-53 added here **moved to the Batch Results popup in D-55**, and to the batch's results page in D-111.
- "+ New Request" button (top-right).

#### Batch Results page (`admin-batch-results`, D-111)
- `GET /admin/batches/{batch}/results` — the Batch Results card's **View**. Found through the admin's own college and **approved batches only**: another college's batch, or a pending / rejected / cancelled one, is a 404.
- **Header:** "← Batch Tracking", **Batch BR-2026-012**, one line of form · purpose · clinic date and hours (plus **"2nd chance: Oct 7, 2026, 7:00 AM"** when the batch has one), and the batch status badge.
- **Three summary cards** (side by side; stacked on a phone): **Completed** "21 out of 40 students completed" · **Fit** "14 out of 21 completed students are fit" · **Rescheduled** "3 out of 40 students were rescheduled" — everyone ever given a 2nd chance, still counted after they complete (`resultsSummary()`).
- **Main card:** a **Result** filter (All results / Fit / Unfit), a **Completion status** filter (All / Scheduled / At the clinic / Rest / Retake / Rescheduled / Completed / Unattended) and a **search** by name or student ID — all three together, in the browser (the whole roster is on the page). Below them a table of **Name · Student ID · Completion status · Result**, in last-name order, **ten rows tall, then it scrolls inside the card** with its header pinned — no pager (FR-UI-06's exception). No match → "No students match these filters." Student statuses as D-110: **Scheduled** / **Rescheduled** (red — on their 2nd-chance day, not yet seen) / **Rest / Retake** / **At the clinic** (captured, not yet encoded — also after the cutoff) / **Completed** / **Unattended**. Each row reads the student's CURRENT appointment. Students withdrawn before D-87 are not listed or counted. Below `md` each student re-flows into a small card.
- **Outcome only** — name, student number, status and Fit / Unfit; no vitals, screening answers, clinic notes, physician details or visit reference. (D-113: an encoded row also carries its visit's database id — not its HP- reference — which the document download posts.)
- Built from shared partials (`resources/views/batches/results/` — header, status badge, summary, table) and one payload builder (`App\Support\BatchResults`), so the other roles' results pages can show the same body (the Director's, since D-112). The table's filters and selection are one Alpine component, `batchResults` (`resources/js/shared/batch-results.js`), shared by every role's page.
- **Document release (D-113, FR-ADM-15)** — under the main card, full width, a large button: **Request release of documents** — disabled with "Available when the batch is completed." until the batch is **Completed** or **Finished incomplete**; enabled → a popup with a **reason** (10–500 characters, live counter) and **one attachment** (Take photo / Upload file — JPG, PNG or PDF up to 5 MB, the D-109 `<x-hp.file-capture>`). While pending: disabled, "Release requested on {date} — waiting for the Director." Rejected: the Director's message ("Not released: …") and **Request again**. Released: "Documents released by the Director on {date}." One pending request per batch; an approval never expires; no emails.
- **Select & download (D-113)** — the filters row gains **Select students** (select mode: only encoded students stay, each with a checkbox at the left of its row; the filters keep working), **Clear** (once something is ticked) and **Select all** (ticks every student shown), and the card's lower right **Download documents (N)** — one ZIP `{Batch ID}-documents.zip`, one PDF per student `{Batch ID}-{Surname}-{Student ID}.pdf` (D-114; was `{Batch ID}-{Student No}-{Last name}.pdf`), each the official form exactly as the clinic's PDF; at most **20** per download ("Select up to 20 students per download."). Until the Director releases the batch every tool is drawn **greyed out** with "Available after the Director releases the documents." (the server refuses the download with a 403 too).

---

### NURSE + PHYSICIAN — the Clinic Dashboard (D-64)

Every page in this section is shared by the nurse and the University Physician. The sidebar's first item is **Clinic Dashboard** (the stat tiles + the clinic-wide encode history, D-44), and the sidebar role label reads "Nurse" or "Physician". The history's **Encoded by** column shows the encoder's name plus a **Nurse / Physician** badge; both roles see the same rows. (A separate clinic logs page was considered and dropped — this history is the log.) Opening a record with View shows '← Back to Clinic Dashboard', returning to the dashboard with the same year / month / result / search filters and page; opened from the Live Queue, the page links back to the Live Queue (2026-09-23). **(D-100)** The history filters by **Year** (All years, 2021 → now) then **Month** (All months, January–December — usable once a year is picked, months with no encoded results greyed out) and Result, applied with Apply; the search box matches the **reference number or student ID** (not the name), and a **Student ID** column sits beside the student's name.

#### Live Queue (`nurse-dashboard`)
- **Header**: blinking LIVE dot + "LIVE QUEUE" pill (peach bg, orange text) + "{n} students waiting · updated just now".
- **Queue table** (full-width card, no outer padding): Student (avatar initials + name; first row tagged "NEXT" badge + highlighted peach-35 row — the longest-waiting student), College, **Vitals Summary** (all values inline, flagged values bold orange), **Flags** (flagged-variant badges for temp/bp/bmi **and HR since D-66**, or "—" — there is no RR badge here: that vital is only measured at encode), Time (waiting since), Action ("Encode Result" button — primary for row 1, ghost for others).
- Queue = clinic visits with status `captured`, ordered by `checked_in_at` asc (oldest first = top row — **first come, first served**). The top row is the next student to serve; new kiosk submissions append at the bottom.
- **A `resting` visit is never here (D-72)** — the `captured` filter excludes it on its own, and there is deliberately **no Resting list** for the clinic: a student re-taking a high reading has not submitted anything yet, so nobody is waiting on them. When their re-check releases the visit, `checked_in_at` is re-stamped, so they appear at the **bottom** of the queue rather than jumping it with their original arrival time.
- Refresh by **polling** (every 3–5 seconds via `setInterval` + `fetch`) — meets SM-2 (queue reflects a submission within ≤ 5 s).
- Sidebar "Enable Kiosk Mode" opens the nurse **Kiosk Devices** page (D-27): enroll the current browser/terminal as a trusted kiosk device, list enrolled devices, and revoke each. Reaching `/kiosk` requires a device-enrolled token **OR** an active nurse **OR** config-allowed loopback; everyone else sees a friendly branded restricted-access page (see the KioskAccess middleware).

#### Upcoming Batches (`nurse-batches`, `/nurse/batches`) — D-114
- In the sidebar right after **Live Queue**, with a calendar icon, for the nurse and the physician alike. No badge. Every college — no college scope. Another signed-in role is sent back to its own dashboard, like every `/nurse` page.
- **Approved batches only** (pending, rejected and cancelled never show), in two cards, ten per page each under its own page parameter. **Each card has its own filter bar** at the right of its heading: a **College** dropdown ("All colleges" + every college's code — picking one applies it at once) and a **Batch ID search** (Search / Enter), both server-side over all pages. Filtering one card never changes the other; **Clear** resets that card only; no match → "No batch here matches these filters." (Same bar as the Director's Batch Tracking.)
- **Card 1 — Upcoming:** every batch with a clinic day **today or later** — its first day **or** a 2nd-chance day (D-110) — **soonest clinic hour first** (`upcoming_page`). Empty: "No upcoming batches."
- **Card 2 — Past:** every other approved batch, **newest clinic date first** (`past_page`). Empty: "No past batches yet."
- Columns, as the Director's Batch Results card: **Batch ID / College** (code) **/ Form Type / Purpose** (cut to 60 characters) **/ Date scheduled** (date, hours beneath, and "2nd chance: Sep 12, 2026, 7:00 AM" beneath that when the batch has one — that is why a batch first seen last week can be in Upcoming) **/ Status** (D-111) **/ [View]**. Both tables re-flow into stacked cards below `md`.

#### Batch Results page — clinic (`nurse-batch-results`, `/nurse/batches/{batch}`) — D-114
- An approved batch's **View** (anything else → 404). The **same page body** as the College Admin's and the Director's (header naming the college, back link "← Upcoming Batches", three summary cards, filters, ten-row scrolling table) from the same partials and `App\Support\BatchResults`. Still **outcome only** — no vitals on this page.
- **No release button** — the clinic neither requests nor reviews releases.
- A fifth column, **Document**, on this page only, with the **encode screen's print buttons** on every **encoded** row: **Print** on a Medical Clearance; **Print front** and **Print back** on a Medical Assessment Form. Each is the clinic's Reprint posted into a hidden frame — the print dialog opens right on this page (no new tab) and `printed_at` is re-stamped, as any Reprint. After a Print front the card reads "Put {name}'s printed sheet back in the tray, then click Print back." Then **PDF** (`nurse.visits.pdf`, downloads as `{Batch ID}-{Surname}-{Student ID}.pdf`; the page stays put). Other rows show "—".
- **Select students / Clear / Select all / Download documents (N)** — D-113's tools, **always enabled** (a college may ask the clinic to print its copies). The ZIP is `{Batch ID}-documents.zip`, one PDF per ticked student named `{Batch ID}-{Surname}-{Student ID}.pdf`, through the same `App\Services\BatchDocumentZip` rule (encoded, held row, this batch, at most 20 — anything else is refused with a 422).

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
    - **Student remarks** textarea (D-76; "Clinic Notes" under D-64, "Nurse Notes" before that — the column is still `nurse_notes`) — **Medical Clearance only**, prints under REMARKS. No placeholder; a helper line says it comes from what the student typed at the kiosk and prints as Remarks. It opens with a **server-built default**, `ClinicVisit::studentRemarks()`: the student's YES details, one `SKIN: <detail>` line each in the form's order (D-56; the twelve-row order since D-63), or **empty** when there are none (D-84 — it used to be the literal `No student remarks`; a record saved with exactly that text before D-84 still prints a blank REMARKS). It stays **editable**. Precedence: `old()` input → the saved record's value → the default, so a saved edit is never replaced by a regenerated default. **A Medical Assessment visit shows no such box** — FO010-R00 has no Remarks line — and saves `nurse_notes` NULL (a posted one is dropped, like `ps_*`).
    - "Preview & Print Medical Clearance" button (ghost style, full width, Download icon).
    - "← Back" (ghost) + "Save & Close Appointment" (primary, flex-2) — Save disabled until Fit/Unfit chosen.

- **On Save**: update clinic visit `status` → `encoded`, create `clearance_records` row, return to queue. **D-69:** on an `assessment` visit the same transaction also creates the one `medical_assessments` row; a Medical Clearance visit creates none. **D-70:** that row also carries `menstrual_history` and `ob_history` (**NULL unless the student is female**) and `physical_exam` (all eight groups, always). **D-65:** the same transaction stores the confirmed vitals in `clearance_records.encoded_vitals` (BMI recomputed) and the measured `vital_signs.respiratory_rate`; **no other `vital_signs` column or flag is ever touched** — the kiosk's reading is what the flags and the analytics describe (BR-14). **D-64:** the physician block is stamped from the encoder — a physician's own name (`UPPER(name), MD`) and license, NULL for a nurse. An encoded visit's read-only notice reads "encoded by <name> (<role>)".

#### Print Medical Clearance (modal)
- Fixed overlay (z-index 2000), modal 92% wide / 92vh.
- Modal header: "Medical Clearance — Document Preview" + "Kiosk data pre-filled. Review before printing." + Close + Print buttons.
- Body: `<iframe>` renders the **official PamSU form** as an HTML document, pre-filled from student + the clinic's confirmed vitals (`encoded_vitals`, D-65) + the nurse-encoded assessment (result, purpose, notes, physical-signs exam findings) + the questionnaire's pregnancy/LMP answer (D-22).
- **D-67:** the read-only (encoded) screen also offers **Save as PDF** beside Reprint — `GET /nurse/visits/{visit}/pdf`, the same document rendered by dompdf at Letter and returned as `{reference_no}-medical-clearance.pdf` — **since D-114 a batch student's file is `{Batch ID}-{Surname}-{Student ID}.pdf`** (the name it has in every ZIP); only a visit with no batch keeps the reference-number name. It deliberately does **not** stamp `printed_at`.

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
  no JavaScript; base64 logos; Times/Arial). **US Letter portrait (8.5 × 11in "short
  bond", D-83 — Legal under D-71), `@page` margins 0 / 0.7in / 0 / 0.7in plus a 0.2in
  top padding on the sheet, TWO pages** — front and back, printed back-to-back on ONE
  sheet, each fitting one page — each carrying the form code at its foot. Zero top and
  bottom page margins mean Chrome offers no Headers and footers, so its default print
  settings give the right result.
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
- **Save as PDF** returns **one file with BOTH pages** at Letter (D-83), named
  `{reference_no}-medical-assessment.pdf` (a batch student's: `{Batch ID}-{Surname}-{Student ID}.pdf`
  since D-114) — a duplex-capable printer prints that
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

#### Batch Approvals (`director-approvals`) — a queue since D-108
- **Pending requests only, oldest first** (`created_at`, then `id`), ten per page, the order carried across pages. Columns: **Batch ID / College / Form Type / Purpose** (in quotes) **/ [Review]** — the Students, Requested Date, Clinic Hours, Submitted and Action columns are gone (D-108).
- The oldest pending request in the whole queue is highlighted as **Next**, styled like the clinic's Live Queue (peach band, orange "Next" tag, primary Review button) — only ever the first row of page 1. **Any** row can be reviewed; the highlight is a guide.
- **Phones (below `md`, 2026-10-05 fix):** each request is a stacked card — Batch ID (+ Next tag), College, Form Type, Purpose, full-width Review button; the Next card is peach. The table shows from `md` up. The page never scrolls sideways.
- **Batch ID search (D-90)** at the top of the card — server-side, across all pages. Empty state: "No batch requests are waiting."
- Decided and college-cancelled batches **leave** the queue — the Director's **Batch Tracking** page lists them (D-112). The row Approve/Reject buttons and modals, D-90's conflict lines and Schedule-conflict modal, and D-92's cancel-reason popup are removed.

#### Review page (`director-review`, `/director/batches/{batch}/review`) — D-108
- Opened by a queue row's **Review** button. A request that is no longer pending redirects to the queue with "BR-… has already been decided — nothing was changed." or "BR-… was cancelled by the college — nothing was changed."
- **Card 1 — Batch details** (the admin's Request Submitted look): Batch ID, College (name + code), Form, Purpose, Students, **Hours needed** ("2 hours — 40 students, 20 per hour"), Submitted (date · time), Submitted by; for a batch submitted before D-108 only, the information line "Originally requested: Wed, Oct 7, 2026 · 9:00 AM (before D-108)".
- **Card 2 — Students in this batch:** Name, Student No., Program, Year — ten rows tall, then it scrolls inside the card under a sticky header (no pager).
- **Card 3 — Signed memo (D-109):** an image memo as a **thumbnail**, a PDF as a **tile** (icon, file name, "PDF · size"). A click opens a **full-screen viewer**: dark backdrop, **X button top-right**, Esc closes it too. Image: **Zoom in / Zoom out / Reset** (25 %–400 %; "100 %" = the whole memo fitted to the screen; zoomed images scroll inside the viewer). PDF: the browser's own PDF viewer in an `<iframe>` filling the viewer (its own zoom). The file comes from `GET /director/batches/{batch}/memo` (`director.batches.memo`, inline, stored Content-Type, `nosniff`). A batch from before D-109: "No memo attached — submitted before signed memos were required."
- **Card 4 — Schedule:**
  - **Calendar** (the old admin calendar, bigger — ~3.5 rem cells, `text-xl` numbers, shrinking to fit a 390 px phone). Data from `GET /director/batches/{batch}/calendar?year=&month=`: per day `{date, booked, capacity, fits, why}`. Each day number sits inside an SVG **ring** = booked ÷ 200, drawn clockwise from 12 o'clock (full at 200, none at 0) — peach track, orange arc. A **selected** day turns solid orange with a white number and a white arc over a lighter track. **Disabled** (greyed, tooltip = why): past days, today after closing ("Closed for today"), non-booking weekdays, and days where **no start hour can take the whole batch** ("No hour can fit 40 students"). Legend: "Ring = how full the day is (out of 200 students)". Month arrows; never before the current month.
  - **Hour picker** (after a day is picked) — a **native `<select>`** of start hours from `GET /director/batches/{batch}/hours?date=`. Each option names who is already in the hour — "9:00 AM – 10:00 AM ~ scheduled for CCS (8/20), CEA (5/20)", or "· open" — and an hour **this batch can't start at** (its span would lack room, include an ended hour, or run past 5 PM) is a `disabled` option with the reason ("— no room at 10:00 AM", "— already passed", "— runs past 5:00 PM"). An hour other colleges use but that still fits stays selectable. Below it: "40 students → 9:00 AM – 11:00 AM (2 hours)".
  - **Clash note** (BR-25): "2 students already have a schedule that day: A, B. Pick another day." — Approve stays disabled.
  - **Approve** (disabled until a fitting day + hour and no clash) opens "Approve BR-2026-014 for Tue, Oct 6, 2026, 9:00 AM – 11:00 AM?" → **Confirm & Approve** posts `scheduled_date` + `start_time` (double-click safe). **Reject** opens the reason dialog (10–500 chars, `RejectBatchRequest`).
- **On Approve** (D-108): one transaction on the **locked** row, refusing a row no longer pending. In order: the span `span(start_time, ceil(students ÷ 20))` ends by closing; no hour of it has ended (BR-23); every hour has room for the seats this batch puts there (D-91) and the day stays within 200 (BR-02); no student already has a schedule that day (BR-25). A refusal returns to the Review page naming the hour or day to change (the picked day and hour are restored). Success sets `status` → `approved`, stamps `reviewed_by`/`reviewed_at` and `scheduled_date` / `scheduled_time` / `scheduled_blocks`, creates **one appointment per student** (`medical`, `source` = `batch`, 20 per hour in pivot order, each with its D-96 **arrival time**, 3 minutes apart), back-writes each `batch_request_students.appointment_id`, and queues one email per student **after the commit**. The span length is never posted — it comes from the roster.
- **On Reject** (D-36): a **written reason is required** (10–500 chars). Status → `rejected`, `rejection_reason` stored, no appointments. The College Admin reads it on Batch Tracking.

#### Batch Tracking (`director-tracking`, `/director/batch-tracking`) — D-112
- In the sidebar right after **Batch Approvals**, with the College Admin's Batch Tracking icon. **Badge (D-113):** a work count of the document release requests waiting for review. Every college — no college scope.
- **Card 1 — Batch Results:** every **approved** batch, **newest clinic date first**, ten per page (`results_page`). Columns: **Batch ID / College** (code) **/ Form Type / Purpose** (cut to 60 characters) **/ Date scheduled** (date, hours beneath) **/ Status / [View]**. Status is D-111's (Scheduled / In progress / Still in progress / Completed / Finished incomplete / No one attended), worded by the same partial as the college's card. **Filter bar** at the right of the heading (D-114 — was a Batch ID search only): a **College** dropdown (applies at once) and a **Batch ID search** — server-side, all pages, this card only; no match → "No approved batch matches these filters.". Empty: "No approved batches yet." A row whose college has a pending document release request carries a peach **Release requested** tag under its Batch ID (D-113, Q18).
- **Card 2 — Rejected batches:** batches the Director **rejected** plus batches a **college cancelled** (Q20), **newest decision first** (the cancellation, else the rejection), ten per page under its own `rejected_page`. Same columns. Date scheduled is "—" unless the batch was cancelled after approval. Status: **Rejected**, or **Cancelled by college** (never "Rejected") with "after approval" beneath when the college cancelled an approved batch (D-92). Its own **filter bar** too (D-114): College dropdown + Batch ID search for this card only; no match → "No rejected or cancelled batch matches these filters.". Empty: "No rejected or cancelled batches."
- Both tables re-flow into stacked cards below `md`; the page never scrolls sideways.

#### Batch Results page — Director (`director-tracking-results`, `/director/batch-tracking/{batch}/results`) — D-112
- An approved batch's **View**. The **same page body** as the College Admin's Batch Results page (header, three summary cards, filters, ten-row scrolling table) from the same partials and `App\Support\BatchResults`, so both roles see the same numbers. The header's back link reads "← Batch Tracking" and the header also **names the college**. Outcome only, as for the admin. A pending, rejected or cancelled batch → 404.
- **Document tools (D-113, FR-DIRA-11):** the same **Select students / Clear / Select all / Download documents (N)** as the College Admin's page, **always enabled** — the Director needs no release request. Under the main card, full width, **Review request of documents** — enabled only while the college has a **pending** release request (otherwise greyed, "No release request from the college."). It opens a popup: who asked and when, the reason, the attachment (an image thumbnail or a PDF tile opening the shared full-screen viewer `<x-hp.file-viewer>` — zoom for images, the browser's PDF viewer for PDFs; only the Director's route serves the file), and **Approve** / **Reject** — Reject reveals a required message (10–500 characters, live counter) the college reads on its page. Both decisions are locked and terminal. Under the button, small: every request this batch has had, with its date and outcome.

#### Rejected / cancelled batch (`director-tracking-show`, `/director/batch-tracking/{batch}`) — D-112
- A rejected or cancelled batch's **View**. An approved or pending batch → 404.
- **Card 1 — Batch details** (as on the Review page): Batch ID, College (name + code), Form, Purpose, Students, Submitted (date · time), Submitted by; **Date scheduled** + hours only for a batch cancelled after approval; **Signed memo → View** (new tab) only when the batch has one (D-109); the "Originally requested" line for a pre-D-108 batch.
- **Card 2 — Students in this batch:** the Review page's card (a shared partial) — Name, Student No., Program, Year, ten rows tall then scrolling. A cancelled batch keeps its students.
- **Card 3 — the reason:** **Rejection reason** — the Director's written reason, "Rejected by *name* on *date · time*"; or **Cancelled by college** — the college's written reason, "Cancelled by *name* (*code* College Admin) on *date · time*", plus "This was after approval — *name* approved it on *date · time*." when it was. Rejections before D-36 and cancellations before D-92 read "No reason was recorded — … before written reasons were required."

#### Data Summary (`director-analytics`) — called "Analytics" before D-103; rescoped by D-32 (v1.11)
> The Medical Cases analytics (Medical Cases by College, Summary of Medical
> Cases matrix, Cases by Medical System) and the case-category concept were
> **dropped** — HealthPass never sees clinic-wide caseload, only its own
> kiosk/clearance encounters. Every card below reads only data the system
> itself collects: appointments booked in the web app + vitals captured at
> the kiosk. **No Export** (FR-ANL-06 removed). **Two printouts since
> D-94/D-97:** the Yearly Report PDF and a **Print Monthly Report** (D-97,
> FR-ANL-16) — the College Admin's report template with visits by college
> plus a College Summary table (visits, flagged, abnormal BMI, male, female
> per college); with one college picked it prints that college only.
> **Both printouts — and the College Admin's two — open with the official
> PSU letterhead (D-104):** the Medical Assessment form's (Republic of the
> Philippines / PAMPANGA STATE UNIVERSITY / (former Don Honorio Ventura State
> University), the PSU seal and Bagong Pilipinas, a thin black rule) at the
> top of page 1, above the report's own title block, which no longer repeats
> the university's name (`reports/partials/letterhead.blade.php`). The approved layout is `docs/prototypes/web/director-analytics-rescope.html`.
- **Filters** (FR-ANL-13): a **year picker** (2021 → the current year) + a **month picker** listing January–December of that year, months with no visits in scope greyed out (D-99), + a **college dropdown** (default "All colleges"). All three scope every card; the Visits-per-Month trend takes the year and the college but not the month.
- **Clinic Visits by College** (FR-ANL-09) — horizontal bar chart, one row per college (all 11 since D-43, zero-visit rows included), **a single Visits series in `#FF8C2A` — D-60 dropped the Medical / Dental split and its legend** — sorted by visits descending, total-visits headline, "View as table" toggle (college × visits). Visits = kiosk check-ins (`clinic_visits`, capture-time `college_id` snapshot). Includes a **Visits by Purpose** mini bar chart from the linked appointment's `purpose`/`purpose_other`; visits with no linked appointment or purpose fall into a "Not specified" bucket ("Walk-in / not specified" until D-61).
- **Vital-Sign Flags** (FR-ANL-10) — **five** stat tiles since D-66 (High BP, Fever, Abnormal BMI, **High Heart Rate**, **Abnormal Respiratory Rate**), each showing count **and rate** (% of the month's captured screenings — the same denominator for all five). Recomputed server-side from `vital_signs` flags; every caption is built from `config('healthpass.thresholds')`, and the respiratory-rate tile's caption says "measured by the clinic at encode" because only encoded visits can contribute to its count. The College Admin's page and the printed monthly report carry the same five.
- **Visits per Month** (FR-ANL-11) — line chart of the **selected year's months** (January–December with zeros; the current year stops at the current month), **one series**: clinic visits (D-60 dropped the dental series and its legend). Follows the year and college filters, not the month (D-99).
- **Students Screened by Sex** (FR-ANL-04, retitled from "By-Sex donut") — Chart.js donut, 160px, Male (orange) + Female (peach), centre total, legend with count + %. Counts students screened (captured kiosk visits), so it already fits the system-collected scope; now obeys the college filter too.
- **Flagged Vitals by Sex** (FR-ANL-14) — beside the donut: one Chart.js 100%-stacked bar per flag (BMI · Temp · BP · PR · RR) — every bar full height, split by each sex's share, Male (orange, on top) over Female (peach) — the flag's total count drawn above each bar, and hovering a segment shows that sex's count and share. Obeys both filters and counts only submitted visits (D-72). On the College Admin page too, and printed in the monthly report as a Flag / Male / Female / Total table.
- **BMI Distribution** (FR-ANL-12, optional) — four rule-based buckets of captured screenings: Underweight (< 18.5), Normal (18.5–24.9), Overweight (25–29.9), Obese (≥ 30). Descriptive only, no profiling (no-AI lock).

#### Flagged Anomalies (`director-flagged`)
- **Year picker (D-102)** above the cards: 2021 → the current year, default the current year, reloads on change. Cards and table count visits checked in that year; View carries `?year=` so the record page's back link returns to it.
- **5 stat cards** (orange left border, D-66): High Blood Pressure, Fever, Abnormal BMI, **High Heart Rate**, **Abnormal Respiratory Rate** — each subtitle quoting its threshold from config.
- **Table**: Student (600), College (muted — capture-time `clinic_visits.college_id` snapshot, not the student's current college), Flag (flagged badge), Value (orange 700), View (link). **The Category column is dropped (D-32).**
- Source: `ClinicVisit::scopeFlagged()` — clinic visits where `vital_signs.is_bp_flagged OR is_temp_flagged OR is_bmi_flagged OR is_hr_flagged OR is_rr_flagged` = true (D-66), joined to student name and the visit's snapshot college. That one scope is also what the Director dashboard preview and the D-57 sidebar badge count, so all three moved together.
- No Export button (FR-ANL-06 removed by D-32).
- **Phones (below `md`, 2026-10-05 fix):** each flagged visit is a stacked card — student, college, each flag badge beside its value, and View. The table shows from `md` up, in a `relative` scroll box so its screen-reader-only header label can't widen the page.

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

**Cancel (D-89):** Identity, Consent, Vitals, Questionnaire, Social History and Review carry a top-left **✕ Cancel** → "Start over?" (Keep going / Start over). Start over resets exactly like the idle timeout. The hidden staff exit is at the **top-centre** (5 taps), not the corner.

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
- **"Confirm?"** (lg; D-103 — was "That's me — Continue") → schedule check (Screen 3a).
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
- **Five-sample capture (D-74, count by D-80)** — height, weight and temperature do not take the first sensor reading. The step shows its "Measuring…" scan card (no sample counter) while it collects **5** readings — or, once **6 s** have passed since the first, at least **3** (fewer keeps waiting; the "sensor is quiet" nudge and the 90 s idle reset cover a sensor that stopped) — then records the average of the **steadiest cluster**: the longest run of readings within the field's tolerance (height ±2 cm, weight ±1 kg, temperature ±0.3 °C, on the `VITALS` field metadata), ties to the tighter run, then the more recent. E.g. weight 50.4 · 52.9 · 52.2 · 52.8 · 52.9 → **52.7 kg**. The average goes through the ordinary sensor capture (range check, `entry_method = sensor`); the server still recomputes BMI and every flag. The manual pad still opens mid-sampling and discards the buffer; Retry, Previous and a D-72 re-check all start a fresh one. Blood pressure is not sampled — it arrives as one finished reading (D-58).

| Step | Vital | Icon | Key detail |
|---|---|---|---|
| 1/4 | Height | 📏 | Ultrasonic sensor. Captured: e.g. 163 cm, "Normal" badge. **D-74 / FR-KSK-17:** the result also shows feet and inches as secondary text (175 cm · 5 ft 8.9 in) — display only; cm is stored and printed. |
| 2/4 | Weight | ⚖️ | Load cell scale. Captured: e.g. 64 kg + computed BMI panel (peach bg, shows BMI + status badge + "from Xcm + Ykg"). |
| 3/4 | Temperature | 🌡️ | IR forehead thermometer. Captured: e.g. 37.9°C, "Slightly Elevated" (flagged badge), normal range note. |
| — | *(BP step, heart-rate panel)* | ❤️ | **D-66:** the heart-rate sub-panel carries a status badge reading **"Normal" or "High"** (> 100 bpm, from the injected `thresholds.hrMax`) — a status, never an interpretation, and never Fit/Unfit. |
| 4/4 | Blood Pressure | 💪 | Cuff BP monitor. Has its own instruction step ("Place your arm in the cuff") before measuring. Pulsing arm emoji during scan. Captured: e.g. 145/92 mmHg "Elevated — Flagged" + Heart Rate in peach panel (78 bpm, Normal badge). **(D-82)** A reading from the Bluetooth monitor also lists **Monitor checks** between the badges and the heart-rate panel — Body movement, Cuff too loose, Irregular pulse, Pulse out of range, Improper position — each ✓ OK or ⚠ Detected, captioned "As reported by the blood-pressure monitor."; hidden for a typed BP or a reading with no status. Information only — no flag, no re-check, never Fit/Unfit. |

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

## 8. Database schema (12 tables)

> Was locked at 10 tables; **D-69 (September 20, 2026) adds `medical_assessments`**
> as table #11 — the Medical Assessment Form's own sections, written only for
> visits that use that form. `clearance_case_categories` (added as table #11 on
> July 9, 2026 by D-23) was **removed by D-32** (July 18, 2026) when the
> Medical Cases analytics and the case-category concept were dropped.
> **D-113 (October 5, 2026) adds `document_release_requests`** as table #12 —
> a College Admin's request that the Director release a finished batch's
> official documents (FLAGGED SCHEMA CHANGE).
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
arrival_time          time NULL             -- SCHEMA ADD (D-96): the student's own time to arrive inside the slot, canonical 'H:i:s', one kiosk budget apart (3600 s ÷ hourly_capacity); written at approval, never changed (it is emailed); NULL on pre-D-96 rows, never backfilled
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
memo_path             varchar(255) NULL     -- D-109 (FLAGGED SCHEMA CHANGE): the signed memo on the PRIVATE `local` disk — memos/<random name> under storage/app/private, never public/. Required on every batch since D-109; NULL on earlier ones, never backfilled; kept on reject/cancel
memo_original_name    varchar(255) NULL     -- D-109: the admin's file name (control characters dropped), for display and the browser's save-as name
memo_mime             varchar(255) NULL     -- D-109: the MIME type the SERVER detected at upload (image/jpeg, image/png, application/pdf), sent back as Content-Type
service_type          enum('medical','dental')  -- always 'medical' since D-60; the server never reads it from the request
requested_date        date NULL             -- LEGACY since D-108: the admin-proposed clinic date of a batch submitted between D-29 and D-108. New batches leave it NULL; only the Review page's "Originally requested" line reads it
requested_time        time NULL             -- D-37, LEGACY since D-108: the admin-chosen START hour of a pre-D-108 batch (canonical 'H:i:s')
requested_blocks      tinyint unsigned NULL -- D-37: span LENGTH in whole hours = ceil(students / hourly_capacity), always computed server-side. Start + block count, NEVER an end time and never both: the count is what every consumer loops over, an end time is ambiguous about the last hour, and re-deriving it later could silently shrink an approved span if a student left the roster. LEGACY since D-108, like requested_time
scheduled_date        date NULL             -- FINAL appointment date, stamped at approval; since D-108 the date the DIRECTOR picks (D-5, amended by D-29/D-36/D-108)
scheduled_time        time NULL             -- D-108: START hour of the span the Director scheduled (canonical 'H:i:s'); NULL until approval
scheduled_blocks      tinyint unsigned NULL -- D-108: span LENGTH in whole hours = ceil(students / hourly_capacity), computed server-side at approval (start + count, D-37's representation). The D-108 migration copied requested_time/requested_blocks into these two ONLY for batches that were approved (approved, or cancelled with reviewed_at set — D-92), whose requested span was their scheduled span under D-36; nothing else is backfilled. Every "which hours does this batch hold" question reads these (BatchRequest::scheduledSpan())
status                enum('pending','approved','rejected','cancelled') DEFAULT 'pending'  -- D-52 added 'cancelled'; the transition is pending → {approved, rejected, cancelled}, plus approved → cancelled until the first clinic hour (D-92); rejected and cancelled are terminal (BR-24)
rejection_reason      text NULL             -- Director's written reason, required on reject (10-500 chars, D-36); NULL on pending/approved and on pre-D-36 rejections
reviewed_by           bigint NULL FK → users.id   -- director who acted
reviewed_at           timestamp NULL
cancelled_at          timestamp NULL        -- D-52: when the College Admin cancelled the request (FR-ADM-11) — pending, or since D-92 approved before its first clinic hour
cancelled_by          bigint NULL FK → users.id   -- D-52: the admin who ACTED, not requested_by — a college may have two admins since D-47. Both cancellation columns are NULL on every non-cancelled row and are NEVER backfilled. They exist because the College Activity Log (D-49) is DERIVED from this table and needs an actor + a timestamp to place the event; reviewed_by/reviewed_at were deliberately NOT reused, since they mean "the Director decided" in three other places and a cancellation is not a decision
cancellation_reason   text NULL             -- D-92: the College Admin's written reason (10-500 chars), required on every cancel; read by the Director, emailed to an approved batch's students. NULL on pre-D-92 cancellations, never backfilled
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

### `document_release_requests`
```sql
-- D-113 — table #12. A College Admin asks the Director to release a finished
-- (Completed / Finished incomplete) batch's official documents. Many per
-- batch: a rejected request stays as history and the college may ask again,
-- but at most ONE is pending at a time (checked under a lock on the batch
-- row, not by the schema). Released = any request approved; never expires.
id                        bigint PK
batch_request_id          bigint FK → batch_requests.id   -- cascade on delete
requested_by              bigint FK → users.id            -- the College Admin who asked
reason                    text                            -- 10–500 chars (Admin\StoreReleaseRequest)
attachment_path           varchar(255)                    -- PRIVATE `local` disk, release-requests/<random name>
attachment_original_name  varchar(255)                    -- shown; the browser's save-as name
attachment_mime           varchar(255)                    -- detected by the SERVER at upload; the Director's route alone serves it
status                    enum('pending','approved','rejected') DEFAULT 'pending'
reviewed_by               bigint NULL FK → users.id       -- the Director
reviewed_at               timestamp NULL
rejection_message         text NULL                       -- 10–500 chars (Director\RejectReleaseRequest); the college reads it
created_at, updated_at
INDEX (batch_request_id, status)
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
batch_requests ──< document_release_requests (D-113 — requested_by / reviewed_by → users)

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
4. **Decided (PRD D-5, amended by D-29, D-36, then D-108)** — ~~the College Admin proposes the clinic date at batch submission (`batch_requests.requested_date`); the Director **confirms it** at approval~~ since **D-108** the **Director picks the clinic date and start hour** at approval, from the batch's Review page, and they are stamped into `batch_requests.scheduled_date` / `scheduled_time` / `scheduled_blocks`. The pushback path is still rejecting with a written reason.
---

*End of HealthPass context document.*
