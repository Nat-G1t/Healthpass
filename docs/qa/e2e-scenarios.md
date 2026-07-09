# HealthPass — End-to-End UAT Scenarios (E2E-1…E2E-6)

Source: `docs/HealthPass_PRD.md` §13 (Acceptance & UAT). These are the
Week-11 end-to-end acceptance runs, written as step-by-step checklists a
non-programmer tester can follow. Each scenario lists exactly which account
to use, what to click, and what you should see.

> **Note on scope:** these describe the **finished system** as specified in
> the PRD. Some steps (nurse encode/print, batch approvals, Director
> analytics) depend on modules still under construction — run those steps
> only once the corresponding feature is merged. Mark any step you cannot
> yet reach as **Blocked (not built)** rather than Fail.

---

## Before you start (setup — do this once)

1. Make sure **XAMPP MySQL** is running (green in the XAMPP control panel).
2. In two separate terminals at `C:\Capstone\healthpass`, run:
   - Terminal 1: `php artisan serve --port=8080`
   - Terminal 2: `npm run dev`
3. If the database has no demo data yet, ask a developer to seed it. All the
   accounts below come from the seeders.
4. Open **Google Chrome** (the kiosk needs Chromium; the web app works in
   Chrome/Edge/Firefox). Use `http://127.0.0.1:8080` for every URL — never
   type `localhost`.

### Seeded accounts (all passwords are `password`)

| Role | Email | Notes |
|---|---|---|
| Nurse | `nurse@healthpass.test` | Live Queue + Encode |
| Clinic Director | `director@healthpass.test` | Approvals + Analytics |
| CCS College Admin | `admin.ccs@healthpass.test` | Pattern: `admin.<code>@healthpass.test` |
| CEA College Admin | `admin.cea@healthpass.test` | For cross-college tests |
| Student — Juan Santos (CCS) | `juan.santos@psu.edu.ph` | Student no. `2021060001` |
| Student — Maria Reyes (CCS) | `maria.reyes@psu.edu.ph` | Student no. `2022060002` |
| Student — Carlo Cruz (CCS) | `carlo.cruz@psu.edu.ph` | Student no. `2023060003` |

There are 30 demo students across all 12 colleges; any `@psu.edu.ph` student
in the seeder works. A full list is in the `StudentSeeder`.

### Two things to know about the kiosk

- **Logging in at the kiosk:** the real kiosk uses a USB QR scanner. For UAT
  on a laptop you don't have one, so use the kiosk's **"Log in with email"**
  path (FR-KSK-02) with a student's email + password. This reaches the same
  Identity Confirm screen as a QR scan.
- **Resetting the kiosk:** after a submit, the Complete screen auto-returns to
  Welcome after 12 seconds, or tap **Done**. To restart mid-flow, just reload
  `http://127.0.0.1:8080/kiosk`.

---

## E2E-1 — Self-booked medical clearance (happy path)

**Goal:** a student books a Medical Clearance, completes the kiosk, the nurse
encodes **Fit** and prints, and the result shows up in the student's records.

**Account:** Student `juan.santos@psu.edu.ph`, then Nurse `nurse@healthpass.test`.

**Steps:**

1. Go to `http://127.0.0.1:8080/login`. Log in as `juan.santos@psu.edu.ph` /
   `password`. → **Expect:** the Student Dashboard.
2. Click **Book New Appointment** (or go to `/student/appointments`).
3. Select the **Medical Clearance** service card. → **Expect:** the card
   highlights.
4. On the calendar, click a **future weekday that is not greyed "FULL"**.
   → **Expect:** the date highlights and **Confirm Booking** becomes enabled.
5. Click **Confirm Booking** → in the modal ("Book Medical Clearance on
   {date}?") click **Yes, book it**. → **Expect:** a confirmation screen
   showing Service, Date, Clinic Hours (7:00 AM–5:00 PM), and a reference like
   `APT-2026-0001`.
6. **Record the reference number.** Log out.
7. **This scenario assumes the appointment is for today** so the kiosk links
   it. If your booked date is not today, either book for today, or expect the
   kiosk to record a walk-in (that's fine — it still flows through the queue).
8. Open the kiosk: `http://127.0.0.1:8080/kiosk`. → **Expect:** the Welcome
   screen (pulsing QR target + "Log in with email").
9. Click **Lost ID? Log in with email**. Type `juan.santos@psu.edu.ph` and
   `password` using the on-screen keyboard, press **Enter**. → **Expect:** the
   Identity Confirm screen with Juan's name, college (CCS), course, year, and
   student number.
10. Click **That's me — Continue**.
11. **Walk-in check:** if Juan has an appointment today the flow goes straight
    to Privacy Consent; if not, you'll see "No Scheduled Clearance Today" —
    click **Proceed as Walk-in**.
12. On **Privacy Consent**, click **I Agree — Proceed**.
13. **Vitals — Height:** click **Enter manually**, type a normal height (e.g.
    `165`), confirm, click **Next**.
14. **Weight:** enter e.g. `60`. → **Expect:** a BMI panel appears showing a
    computed value (~`22.0`) and status, "from 165 cm + 60 kg". Click **Next**.
15. **Temperature:** enter a normal temp e.g. `36.8`. → **Expect:** no fever
    badge. Click **Next**.
16. **Blood Pressure:** enter systolic `118`, diastolic `76`, heart rate `72`.
    → **Expect:** no High-BP flag. Click **Next**.
17. **Questionnaire:** answer **No** to all 9 system cards and the pregnancy
    question. → **Expect:** footer reads "10 of 10 answered" and **Review &
    Submit** becomes enabled. Click it.
18. **Review screen:** confirm vitals and answers look right (nothing flagged
    orange). Click **Submit to Clinic →**. → **Expect:** the Complete screen
    ("Submitted! … proceed to the nurse's station") with a 12-second countdown.
    **The kiosk must NOT show any Fit/Unfit result** (FR-KSK-14).
19. In a **new tab**, go to `http://127.0.0.1:8080/login` and log in as
    `nurse@healthpass.test` / `password`. → **Expect:** the Live Queue.
20. → **Expect:** Juan's visit appears in the queue within ~5 seconds, tagged
    **NEXT**, with his vitals summarised and **no flags** (Flags column shows
    "—").
21. Click **Encode Result**. → **Expect:** the "Doctor's Assessment" screen
    with Juan's vitals and all questionnaire answers.
22. Set **Result = Fit**. Leave case category/purpose blank. Optionally type a
    note. Click **Preview & Print**. → **Expect:** the official clearance form
    renders in a preview and the browser print dialog opens. (Cancel the print
    dialog if you have no printer — the layout check is SM-3.)
23. Click **Save & Close**. → **Expect:** Juan's row disappears from the Live
    Queue.
24. Log out, log back in as `juan.santos@psu.edu.ph`, open **My Records**
    (`/student/records`). → **Expect:** a record with a **Fit** result badge
    and the visit's vitals in the detail modal.

**Pass criteria:** booking created → kiosk submitted with no result shown →
appeared in queue ≤ 5 s → encoded Fit and printed → **Fit** visible only now
in My Records.

---

## E2E-2 — Walk-in with flags (fever + high BP) → encode Unfit

**Goal:** a student with **no booking** completes the kiosk with feverish /
high-BP values; the flags appear in the nurse queue and the Director's Flagged
Anomalies, and the nurse encodes **Unfit**.

**Account:** Student `maria.reyes@psu.edu.ph`, then Nurse, then Director.

**Steps:**

1. **Do not book anything** for Maria. (If she has an appointment today,
   pick a different student who does not.)
2. Open `http://127.0.0.1:8080/kiosk`. On Welcome, click **Log in with
   email**, enter `maria.reyes@psu.edu.ph` / `password`, press **Enter**.
3. Click **That's me — Continue**. → **Expect:** the "No Scheduled Clearance
   Today" screen. Click **Proceed as Walk-in**.
4. Click **I Agree — Proceed** on Privacy Consent.
5. **Height:** enter `160`. **Weight:** enter `85` → **Expect:** BMI ~`33.2`
   shown with an **Abnormal BMI / Obese** status (threshold ≥ 30.0).
6. **Temperature:** enter `38.5` → **Expect:** a **Fever** badge (threshold
   > 37.2 °C).
7. **Blood Pressure:** enter systolic `150`, diastolic `95`, HR `88` →
   **Expect:** a **High Blood Pressure** badge (systolic ≥ 140 OR diastolic
   ≥ 90).
8. Answer the questionnaire (any answers), reach Review. → **Expect:** the
   three flagged vitals are shown in **orange with a ⚑**. Click **Submit to
   Clinic →**.
9. Log in as `nurse@healthpass.test`. On the Live Queue, find Maria's row. →
   **Expect:** the **Flags column shows badges for temp, BP and BMI**, and the
   flagged values are bold orange.
10. Open **Encode Result**, set **Result = Unfit**, optionally set a Case
    Category (e.g. Cardiovascular System), click **Save & Close**.
11. Log in as `director@healthpass.test`, open **Flagged Anomalies**. →
    **Expect:** Maria appears under High Blood Pressure, Fever, and Abnormal
    BMI, with her college (CCS) shown.

**Pass criteria:** all three flags computed at capture, visible in the queue
**immediately** (before encoding), Unfit encoded, and the visit surfaces in
Flagged Anomalies. (Flags show from capture; case counts need encoding.)

---

## E2E-3 — Batch cohort (College Admin → Director → students)

**Goal:** a College Admin submits a batch for a group of students, the
Director approves with a date, one appointment per student is created, and
sampled students complete the loop.

**Account:** CCS Admin `admin.ccs@healthpass.test`, then Director, then two
students.

**Steps:**

1. Log in as `admin.ccs@healthpass.test`. → **Expect:** the Admin Dashboard
   with a banner naming **College of Computing Studies** and stat cards.
2. Go to **New Batch Request**. Choose a **Reason** (e.g. "OJT/practicum"),
   **Service Type = medical**.
3. In the student picker, use **Select All** (or tick several). → **Expect:**
   only **CCS** students are listed; selected rows highlight peach; a
   "(N of M selected)" counter updates. **You must not be able to find a CEA
   student here.**
4. Click **Submit**. → **Expect:** a confirmation screen with a Batch ID like
   `BR-2026-001`, status "Pending Director Approval". Note the Batch ID.
5. Log out; log in as `director@healthpass.test`. Open **Batch Approvals**. →
   **Expect:** the CCS batch row with **Approve / Reject** buttons.
6. Click **Approve**, pick an appointment **date** (default today; past dates
   disabled), confirm. → **Expect:** the row flips to "✓ Approved" and can no
   longer be re-approved.
7. Log in as one of the batch students (e.g. `carlo.cruz@psu.edu.ph`), open the
   Dashboard. → **Expect:** the generated appointment appears under Next
   Appointment on the approved date, looking like any self-booked one.
8. Take **that student** through the kiosk (as in E2E-1, email login) on the
   approved date. → **Expect:** the visit **links to the appointment**
   (appears in the queue, not as a walk-in).
9. Repeat for a second sampled student.

**Pass criteria:** N selected students → exactly N appointments created on the
chosen date, visible to those students, and sampled students complete the
kiosk loop against their generated appointment.

---

## E2E-4 — Degraded hardware (manual completion)

**Goal:** with sensors unavailable (or "unplugged" mid-vitals), the student
finishes entirely via manual entry and the submission is intact.

**Account:** any student, e.g. `carlo.cruz@psu.edu.ph`.

> On a laptop with no MCU attached, the kiosk already has no sensor — so
> "Enter manually" is the path throughout. This scenario confirms that a
> missing/failing sensor is **never a dead end**.

**Steps:**

1. Open `http://127.0.0.1:8080/kiosk`, log in with the student's email, reach
   the vitals.
2. On the **Height** step, look for the sensor/scanning state. → **Expect:** if
   no sensor connects, a **non-blocking notice** appears and an **Enter
   manually** action is available — not an error that stops you.
3. Complete **all four** vitals using **Enter manually**. Each manual value
   must still be range-checked: try an out-of-range value (e.g. height `500`)
   → **Expect:** it's rejected and asks for re-entry (valid range 50–250 cm).
   Then enter a valid one.
4. Finish the questionnaire and **Submit to Clinic →**.
5. Log in as the nurse and open the visit. → **Expect:** the visit is present
   and complete; its vitals `entry_method` records **manual** (a developer can
   confirm in the DB, or it shows on the encode screen if surfaced).

**Pass criteria:** a session with no working sensor still submits a complete,
valid visit through manual entry; out-of-range values are refused.

---

## E2E-5 — Security negatives (must all be REJECTED)

**Goal:** confirm cross-role, cross-college, and unauthenticated access are all
blocked. Every step here **passes only if the action is refused.**

**Accounts:** a student, the CCS admin, the CEA admin.

**Steps:**

1. **Student cannot reach nurse pages.** Log in as `juan.santos@psu.edu.ph`.
   In the address bar, go to `http://127.0.0.1:8080/nurse/queue`. → **Expect:**
   **HTTP 403** or a redirect back to the student dashboard — **never** the
   queue.
2. Repeat for `/director/dashboard` and `/admin/dashboard` while logged in as
   the student. → **Expect:** blocked each time.
3. **Not logged in.** Log out entirely. Try `http://127.0.0.1:8080/student/dashboard`
   and `/nurse/queue`. → **Expect:** redirected to the login page.
4. **Cross-college isolation.** Log in as `admin.ccs@healthpass.test`. In the
   batch student picker, search for a known **CEA** student (e.g. "Torres" or
   student no. `2022020008`). → **Expect:** **no CEA student appears** — the
   list is CCS-only.
5. **Tampered query.** Still as the CCS admin, if the student list has a URL
   with a college parameter, try changing it to another college's id. →
   **Expect:** the result is still **only CCS students** (scope is enforced
   server-side, not by the URL).
6. **Direct POST without CSRF** (developer-assisted). Using browser devtools or
   a tool like curl, POST to a state-changing endpoint (e.g. `/student/appointments`)
   **without** a valid CSRF token. → **Expect:** **HTTP 419 / rejected**.

**Pass criteria:** every attempt above is refused. Zero cross-role or
cross-college data is ever visible (SM-5).

---

## E2E-6 — Dental is scheduling-only (never enters the kiosk loop)

**Goal:** confirm a Dental Check can be booked but never goes through vitals →
encode → print.

**Account:** a student who has **no medical appointment today**, e.g.
`maria.reyes@psu.edu.ph`.

**Steps:**

1. Log in as the student, go to **Book Appointment**.
2. Select the **Dental Check** service card, pick a **future date today**,
   confirm the booking. → **Expect:** a confirmation with a reference; the
   appointment shows in the dashboard as a Dental Check.
3. Open the kiosk and log in as that same student. Click **That's me —
   Continue**.
4. → **Expect:** because a dental appointment exists today, the "No Scheduled
   Clearance Today" notice is **suppressed** (skipped straight to Privacy
   Consent) — but if you complete the flow it records as a **walk-in**
   (`appointment_id` is NULL), because **dental never links** (Decision D-3).
5. Do **not** expect any dental-specific vitals or a dental encode/print path.
   Dental lives only in scheduling and records.
6. Log in as the nurse. → **Expect:** the dental appointment does **not** create
   a captured visit on its own; it never appears in the encode/print loop.

**Pass criteria:** dental books and appears in records, but has **no** vitals
capture, **no** nurse encode, and **no** printed clearance. Any kiosk session
by a dental-only student is recorded as a walk-in, not linked to the dental
appointment.

---

## Recording results

For each scenario, record: **Pass / Fail / Blocked (not built)**, the tester
name, the date, and a note for any Fail. Feed FR-level pass/fail back into
`docs/qa/traceability-template.csv` (map each scenario step to its FR IDs).
