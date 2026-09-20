# HealthPass — End-to-End UAT Scenarios (E2E-1…E2E-20)

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
| Nurse | `nurse@healthpass.test` | Clinic Dashboard (encode history) + Live Queue + Encode |
| Physician (D-64) | `physician@healthpass.test` | Same pages as the nurse; name + license print on records they encode |
| Clinic Director | `director@healthpass.test` | Approvals + Analytics |
| CCS College Admin | `admin.ccs@healthpass.test` | Pattern: `admin.<code>@healthpass.test` |
| CEA College Admin | `admin.cea@healthpass.test` | For cross-college tests |
| Student — Juan Santos (CCS) | `juan.santos@psu.edu.ph` | Student no. `2021060001` |
| Student — Maria Reyes (CCS) | `maria.reyes@psu.edu.ph` | Student no. `2022060002` |
| Student — Carlo Cruz (CCS) | `carlo.cruz@psu.edu.ph` | Student no. `2023060003` |

There are 28 demo students across all 11 colleges (D-43 removed Senior High
School); any `@psu.edu.ph` student in the seeder works. A full list is in the
`StudentSeeder`.

### Two things to know about the kiosk

- **Logging in at the kiosk:** the real kiosk uses a USB QR scanner. For UAT
  on a laptop you don't have one, so use the kiosk's **"Log in with email"**
  path (FR-KSK-02) with a student's email + password. This reaches the same
  Identity Confirm screen as a QR scan.
- **Resetting the kiosk:** after a submit, the Complete screen auto-returns to
  Welcome after 12 seconds, or tap **Done**. To restart mid-flow, just reload
  `http://127.0.0.1:8080/kiosk`.

---

## E2E-1 — College-scheduled medical clearance (happy path)

**Goal:** the student's college schedules them (a batch the Director
approves), the student completes the kiosk, the nurse encodes **Fit** and
prints, and the result shows up in the student's records. Since **D-61**
students never book for themselves and never walk in.

**Account:** CCS Admin `admin.ccs@healthpass.test`, Director
`director@healthpass.test`, Student `juan.santos@psu.edu.ph`, then Nurse
`nurse@healthpass.test`.

**Steps:**

1. Log in as `admin.ccs@healthpass.test`, open **New Batch Request**. Choose
   any **Reason**, click **today** on the Requested Clinic Date calendar, pick
   a **Start time** that has not ended yet, tick **Juan Santos** (and **Maria
   Reyes** too if you will run E2E-2 today), and click **Submit Batch
   Request**. → **Expect:** the confirmation screen, "Pending Director
   Approval". Log out.
2. Log in as `director@healthpass.test`, open **Batch Approvals**, click
   **Approve** on that batch and confirm. → **Expect:** the row reads
   "✓ Approved". Log out.
3. Log in as `juan.santos@psu.edu.ph` / `password`. → **Expect:** the Student
   Dashboard. **Next Appointment** shows today's date and hour with a
   **"Booked by College of Computing Studies"** badge and **no Cancel
   button**.
4. → **Expect:** the **Clearance Status** card reads "Your college requests
   your clinic schedule." and the sidebar has **no** Book Appointment or My
   Appointments item (E2E-13 checks this in full).
5. **Record the reference number** (`APT-…`) shown on the Next Appointment
   card.
6. Log out.
7. The appointment is for **today**, so the kiosk will link it and skip the
   "No Clinic Schedule Today" screen. (A student with nothing today cannot
   use the kiosk at all — that is E2E-12.)
8. Open the kiosk: `http://127.0.0.1:8080/kiosk`. → **Expect:** the Welcome
   screen (pulsing QR target + "Log in with email").
9. Click **Lost ID? Log in with email**. Type `juan.santos@psu.edu.ph` and
   `password` using the on-screen keyboard, press **Enter**. → **Expect:** the
   Identity Confirm screen with Juan's name, college (CCS), course, year, and
   student number.
10. Click **That's me — Continue**.
11. **Schedule check:** Juan has an appointment today, so the flow goes
    straight to Privacy Consent. (If you see "No Clinic Schedule Today"
    instead, the batch from steps 1–2 is not approved for today — go back and
    fix that; there is no way forward from that screen.)
12. On **Privacy Consent**, click **I Agree — Proceed**.
13. **Vitals — Height:** click **Enter manually**, type a normal height (e.g.
    `165`), confirm, click **Next**.
14. **Weight:** enter e.g. `60`. → **Expect:** a BMI panel appears showing a
    computed value (~`22.0`) and status, "from 165 cm + 60 kg". Click **Next**.
15. **Temperature:** enter a normal temp e.g. `36.8`. → **Expect:** no fever
    badge. Click **Next**.
16. **Blood Pressure:** enter systolic `118`, diastolic `76`, heart rate `72`.
    → **Expect:** no High-BP flag. Click **Next**.
17. **Questionnaire:** → **Expect:** the heading reads "Physical Signs
    Disorder of:" and the twelve cards are the new forms' rows, reading down
    the form's three columns — SKIN, HEAD, EYES, EARS, NOSE, THROAT,
    CHEST/LUNGS, HEART, ABDOMEN, KIDNEY/BLADDER, BRAIN, MENTAL DISORDER — each
    with a short helper line (D-63). The grid may scroll, but **Review &
    Submit** stays on screen. Answer **No** to all twelve and to **Are you
    Pregnant?** → **Expect:** footer reads "13 of 13 answered" and **Review &
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
    with Juan's vitals and all twelve questionnaire answers; every one of the
    twelve **Physical Signs Disorder of** rows is pre-checked **No** (his kiosk
    answers, D-63) and
    Nurse Notes is empty.
22. Set **Result = Fit**. Leave case category/purpose blank. Optionally type a
    note. Click **Preview & Print**. → **Expect:** the official clearance form
    renders in a preview and the browser print dialog opens. (Cancel the print
    dialog if you have no printer — the layout check is SM-3.)
23. Click **Save & Close**. → **Expect:** Juan's row disappears from the Live
    Queue.
24. Log out, log back in as `juan.santos@psu.edu.ph`, open **My Records**
    (`/student/records`). → **Expect:** a record with a **Fit** result badge
    and the visit's vitals in the detail modal.

**Pass criteria:** college batch approved for today → read-only appointment on
the dashboard → kiosk submitted with no result shown → appeared in queue
≤ 5 s → encoded Fit and printed → **Fit** visible only now in My Records.

---

## E2E-2 — Flags (fever + high BP) → encode Unfit

**Goal:** a college-scheduled student completes the kiosk with feverish /
high-BP values; the flags appear in the nurse queue and the Director's Flagged
Anomalies, and the nurse encodes **Unfit**. (This used to be run as a walk-in;
since D-61 there are none.)

**Account:** Student `maria.reyes@psu.edu.ph`, then Nurse, then Director.

**Steps:**

1. Make sure **Maria has an appointment today**: tick her on the batch in
   E2E-1 steps 1–2, or run those two steps again for her.
2. Open `http://127.0.0.1:8080/kiosk`. On Welcome, click **Log in with
   email**, enter `maria.reyes@psu.edu.ph` / `password`, press **Enter**.
3. Click **That's me — Continue**. → **Expect:** straight to Privacy Consent
   (she has an appointment today).
4. Click **I Agree — Proceed** on Privacy Consent.
5. **Height:** enter `160`. **Weight:** enter `85` → **Expect:** BMI ~`33.2`
   shown with an **Abnormal BMI / Obese** status (threshold ≥ 30.0).
6. **Temperature:** enter `38.5` → **Expect:** a **Fever** badge (threshold
   > 37.2 °C).
7. **Blood Pressure:** enter systolic `150`, diastolic `95`, HR `112` →
   **Expect:** a **High Blood Pressure** badge (systolic ≥ 140 OR diastolic
   ≥ 90), and **(D-66)** the heart-rate panel below it reading **High**
   (> 100 bpm). The wording is a status only — never "tachycardia" or any
   other interpretation.
8. **Questionnaire with YES details (D-56, rows per D-63):** answer **Yes** on
   **SKIN**, tap **Add details (optional)** and type `itchy rash on left arm`
   on the on-screen keyboard. → **Expect:** a panel docked at the bottom of the
   screen with the keyboard and an "N / 120" counter. Tap **Done** → the SKIN
   card shows the detail. Scroll to **THROAT**, answer **Yes** and add
   `sore throat since monday`. Answer **Yes** on **KIDNEY/BLADDER**, add any
   detail, then switch it back to **No** → **Expect:** its detail disappears.
   Answer the rest **No**, answer **Are you Pregnant?** → footer "13 of 13
   answered"; reach Review. → **Expect:** the three flagged vitals are shown in
   **orange with a ⚑**; all twelve rows are listed; SKIN and THROAT show
   **Yes** with their details under them; KIDNEY/BLADDER shows **No** with no
   detail. Click **Submit to Clinic →**.
9. Log in as `nurse@healthpass.test`. On the Live Queue, find Maria's row. →
   **Expect:** the **Flags column shows badges for temp, BP, BMI and HR**
   (D-66), and the flagged values — the bpm included — are bold orange.
   There is **no RR badge**: the respiratory rate has not been measured yet.
10. Open **Encode Result**. → **Expect (D-56/D-63):** the questionnaire card
    shows each detail under its Yes; there are **twelve** Physical Signs rows;
    **SKIN** and **THROAT** are pre-checked **Yes** and the other ten **No**;
    **Nurse Notes** is pre-filled with two lines, in form order:
    `SKIN: itchy rash on left arm` then `THROAT: sore throat since monday`. Set **Result = Unfit**,
    optionally set a Case
    Category (e.g. Cardiovascular System), click **Preview & Print**. →
    **Expect:** on the printed form the **SKIN YES** bubble is shaded and the
    **REMARKS** show both lines; the Physical Signs table has twelve rows in
    three columns and the page still fits one sheet. Click **Save & Close**.
11. Log in as `director@healthpass.test`, open **Flagged Anomalies**. →
    **Expect (D-66):** **five** stat cards — High Blood Pressure, Fever,
    Abnormal BMI, High Heart Rate, Abnormal Respiratory Rate — and Maria's
    row listing four flags with their values (`150/95 mmHg`, `38.5°C`,
    `33.2`, `112 bpm`), with her college (CCS) shown.

**Pass criteria:** all four capture-time flags computed at capture, visible in
the queue **immediately** (before encoding), Unfit encoded, and the visit
surfaces in Flagged Anomalies. (Flags show from capture; case counts need
encoding.)

---

## E2E-3 — Batch cohort (College Admin → Director → students)

**Goal:** a College Admin submits a batch for a group of students with a
requested clinic date, the Director confirms the date and approves, one
appointment per student is created, and sampled students complete the loop.

**Account:** CCS Admin `admin.ccs@healthpass.test`, then Director, then two
students.

**Steps:**

1. Log in as `admin.ccs@healthpass.test`. → **Expect:** the Admin Dashboard
   with a banner naming **College of Computing Studies** and stat cards.
2. Go to **New Batch Request**. Pick the **Medical Assessment Form** tile, then
   the Reason **On-the-job Training** (D-62), and a **Requested Clinic Date**
   (defaults to today; past dates disabled — D-29).
3. In the student picker, use **Select All** (or tick several). → **Expect:**
   only **CCS** students are listed; selected rows highlight peach; a
   "(N of M selected)" counter updates. **You must not be able to find a CEA
   student here.**
4. Click **Submit**. → **Expect:** a confirmation screen with a Batch ID like
   `BR-2026-001`, status "Pending Director Approval". Note the Batch ID.
5. Log out; log in as `director@healthpass.test`. Open **Batch Approvals**. →
   **Expect:** the CCS batch row with **Approve / Reject** buttons.
6. Click **Approve**. → **Expect:** the date picker is **pre-filled with the
   admin's requested date** (falls back to today if it passed; past dates
   disabled — D-29). Confirm. → **Expect:** the row flips to "✓ Approved" and
   can no longer be re-approved.
7. Log in as one of the batch students (e.g. `carlo.cruz@psu.edu.ph`), open the
   Dashboard. → **Expect:** the generated appointment appears under Next
   Appointment on the approved date, with a **"Booked by College of Computing
   Studies"** badge and no Cancel button (D-61).
8. Take **that student** through the kiosk (as in E2E-1, email login) on the
   approved date. → **Expect:** the visit **links to the appointment**
   (there are no walk-ins since D-61 — a student with no appointment today
   is turned away, E2E-12).
9. Repeat for a second sampled student.
10. Log back in as `admin.ccs@healthpass.test` and open **Batch Tracking**. →
    **Expect:** a **Batch Results** card **above** the requests table, listing
    the approved batch with **Time of Completion = In progress** (at least one
    student has not been encoded yet). Pending, rejected and cancelled batches
    are **not** in this card (D-55).
11. Click **View** on that row. → **Expect:** a popup with the Batch ID,
    service, clinic date and hour span, and one row per student: name, student
    no., hour, **Status** and **Result**. A student taken through the kiosk but
    not yet encoded reads **At the clinic** with Result "—"; a student not at the
    kiosk yet reads **Not yet attended**. **No vital signs, questionnaire
    answers, nurse notes or HP- reference appear anywhere in the popup.**
12. Log in as `nurse@healthpass.test` and encode one sampled student (Fit or
    Unfit). Back as the CCS admin, **reload** Batch Tracking and click View
    again. → **Expect:** that student now reads **Completed** with the result
    you encoded.
13. **Absent rule (8 PM).** A student who never reaches the kiosk stays **Not
    yet attended** all clinic day and reads **Absent from 8:00 PM** on the
    clinic date. To test without waiting until evening, ask a developer to set
    `HEALTHPASS_ABSENT_CUTOFF` in `.env` to a time a couple of minutes ahead
    (e.g. `14:05`), run `php artisan config:clear`, and reload after that time.
    → **Expect:** the no-shows read **Absent**. Once every student is Completed,
    Absent or Withdrawn, Time of Completion shows the **date and time of the
    last encode** (e.g. "Sep 10, 2026 · 3:42 PM"), or **No one attended** if
    nobody was encoded. A student still **At the clinic** keeps the batch **In
    progress** even after the cutoff. Ask the developer to remove the setting
    afterwards.
14. In the requests table, click the Batch ID to open the **batch roster**. →
    **Expect:** clinic date, hour span, purpose and student count, and each
    student's appointment and hour with **Withdraw** — but **no** Status or
    Result columns and no "Clearance results" line (they live in the popup
    now). Withdraw still works for a student who has not checked in.
15. Narrow the browser to phone width (about 400 px) and repeat steps 10–11. →
    **Expect:** the card and the popup table re-flow into stacked cards, the
    popup scrolls, and nothing overflows sideways.

**Pass criteria:** N selected students → exactly N appointments created on the
chosen date, visible to those students, and sampled students complete the
kiosk loop against their generated appointment. The Batch Results card lists
only approved batches; its popup shows each student's status and Fit/Unfit and
nothing else clinical; no-shows read Absent from 8 PM on the clinic day; and
the completion time appears once every student is finished.

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
   a tool like curl, POST to a state-changing endpoint (e.g. `/admin/batches`)
   **without** a valid CSRF token. → **Expect:** **HTTP 419 / rejected**.

**Pass criteria:** every attempt above is refused. Zero cross-role or
cross-college data is ever visible (SM-5).

---

## E2E-6 — Dental is gone from the whole website (D-60)

**Goal:** confirm there is no dental option, column, legend or wording anywhere,
and that a crafted dental request cannot get one into the database.

**Accounts:** a student, a College Admin (CCS), the Clinic Director.

**Steps:**

1. Log in as the student. → **Expect:** there is no Book Appointment page to
   check any more (D-61 — `/student/appointments` is a 404), and no "Dental"
   anywhere on the dashboard.
2. Log in as the College Admin, go to **New Batch Request**. → **Expect:** the
   form asks for Reason, Requested Clinic Date, start hour and students —
   there is **no Service Type field** and no "Dental" on the page. Submit a
   small batch and open its confirmation. → **Expect:** it reads **Medical
   Clearance**.
3. Open **Analytics** as the College Admin, with a month that has visits. →
   **Expect:** *Clinic Visits by Program* is **one bar series**, its "View as
   table" toggle has a single **Visits** column, and *Visits per Month* is one
   line. No Medical/Dental legend swatches, no blue `#2563EB` series.
4. Click **Print Monthly Report**. → **Expect:** the summary line reads
   *"N total visits"* with no medical/dental split, and the program table has
   **Program | Visits** only.
5. Log in as the Clinic Director, open **Analytics** with **All colleges**, then
   filter to **one college**. → **Expect:** the same — one series, one Visits
   column, no legend, no "Dental" in either view.
6. Press **Ctrl+F** on each of those four pages and search for `Dental`. →
   **Expect:** zero matches on every one.
7. Open the kiosk and log in as a student with no appointment today. →
   **Expect:** the "No Clinic Schedule Today" screen never mentions dental.

**Pass criteria:** the word "Dental" appears nowhere in the web app or the
kiosk; the New Batch form has no Service Type field; both analytics pages and
the printed report carry a single Visits series; and a batch always stores
`service_type = 'medical'` (a posted `service_type=dental` is ignored; since
D-61 there is no student booking to refuse).

---

## E2E-7 — Kiosk submit → nurse encodes → the result appears in the encode history

**Goal:** confirm the Nurse Dashboard (FR-NRS-09, D-44) shows what the nurse
just encoded, attributed to the right nurse and carrying the right **program**.

**Accounts:** the nurse `nurse@healthpass.test`, and any student with a program
on their profile, e.g. `juan.santos@psu.edu.ph` (CCS).

**Steps:**

1. Log in as the **nurse**. → **Expect:** you land on **`/nurse/dashboard`**,
   not the Live Queue — the dashboard is the nurse's home. Note the
   **Encoded Today** and **Awaiting Encode** tiles.
2. Open the kiosk in another tab, log in as the student (email path), and
   complete a full session through **Submit**.
3. Back on the dashboard, reload. → **Expect:** **Awaiting Encode** has gone up
   by one; the new visit is **not** in the history yet — it is still `captured`,
   so it belongs to the Live Queue.
4. Open **Live Queue**, click **Encode Result** on that visit, choose **Fit**,
   and **Save & Close**. → **Expect:** you return to the Live Queue and the row
   animates away (unchanged behaviour).
5. Open **Dashboard**. → **Expect:** the visit is now the **top row** of the
   encode history (newest first), showing its reference number, the student's
   name, their college **code**, the **program the student had at capture**,
   a **Fit** badge, **Encoded by = the nurse you are logged in as**, the encode
   timestamp, and **Printed = No**. **Encoded Today** has gone up by one and
   **Awaiting Encode** back down by one.
6. Click **Reprint** on that row. → **Expect:** the **print dialog opens on
   the dashboard itself** — **no new tab** — with the official clearance form
   in its preview pane. Print or cancel, then reload the dashboard. →
   **Expect:** that row's **Printed** now reads **Yes** (the reprint stamps
   `printed_at` when the form is fetched, so cancelling the dialog still
   counts as a reprint).
7. Exercise the filters: set **Result = Unfit** → the Fit row disappears; set it
   back. Type part of the student's **name** in Search, then their **reference
   number** — each finds the row. Pick a different **Month** → the row
   disappears. Click **Clear** → everything comes back.
8. If the clinic has more than 15 encoded results, go to **page 2** with a
   filter still applied. → **Expect:** the filter is **still applied** on page 2
   (the URL keeps `result=`/`q=`/`month=`), and no row from page 1 repeats.
9. **Clinic-wide check:** log in as a *different* nurse (or have a teammate
   encode a visit) and reload the dashboard as the first nurse. → **Expect:**
   the other nurse's encode is **visible**, with **their** name in Encoded by —
   the log is the clinic's, not one nurse's.
10. Confirm **Live Queue** still works and still auto-polls (open it in two
    windows and submit a kiosk session — both update within one poll cycle).

**Pass criteria:** the encoded result appears in the history with the correct
program snapshot and encoder; captured (un-encoded) visits never appear; every
filter narrows and survives paging; both nurses see one continuous log; the
Live Queue is unaffected. A visit captured before D-43 (no program snapshot)
renders "—" in the Program column and is **never** backfilled.

---

## E2E-8 — College Admin prints the monthly report (figures must match the screen)

**Goal:** confirm the printable Monthly Clinic Report (FR-ADM-09, D-46) carries
the College Admin's filters and quotes exactly the figures the analytics page
shows for the same scope.

**Accounts:** a College Admin with data, e.g. `admin.ccs@healthpass.test`.

**Steps:**

1. Log in as the **CCS admin** and open **Analytics**. Pick a **month that has
   data**. → **Expect:** the scope banner names your college, and Clinic Visits
   by Program lists **every CCS program**, zero-visit ones included.
2. Write down, from the screen: the **total visits** headline, each
   program row's three numbers, the **Visits by Purpose** counts, the three
   **Vital-Sign Flag** counts and rates, the four **BMI** bucket counts, and the
   **Male / Female** counts and percentages.
3. Click **Print Monthly Report**. → **Expect:** the browser's **print dialog
   opens straight away, on the Analytics page itself** — **no new tab**. The
   dialog's preview pane is the report. Cancel it: you are still on Analytics
   with your filters and scroll position untouched.
4. Compare the preview against step 2. → **Expect:** **every number matches.**
   The header carries the university, **College of Computing Studies (CCS)**,
   "Monthly Clinic Report", the month written in full, and a generated-at
   timestamp with **your name**. The footer states the report covers data
   captured by HealthPass only.
5. Confirm it is a **report, not a screenshot of the page**: there are **no
   charts** — every section is a table — and no app sidebar or navigation.
6. In the print dialog switch the paper between **A4** and **Letter**. →
   **Expect:** clean on both — nothing clipped at the right edge, no row split
   across a page break, and table headers repeat on any second sheet.
7. Go back to the analytics tab, set the **program filter** to one program, and
   click **Print Monthly Report** again. → **Expect:** the report is narrowed to
   that program and **states its filter** ("Filtered to: …") under the month.
8. **Security negative:** with the report open, hand-edit its URL to append
   `&college=<another college's id>` and reload. → **Expect:** the figures and
   the college name are **unchanged** — still your own college.

**Pass criteria:** every printed figure equals the on-screen figure for the same
month and program; zero-visit programs appear on the printout; the header names
the college, month, generator and time; the page prints clean at A4 and Letter;
and `?college=` cannot move the scope.

---

## E2E-9 — A student can't be double-booked by two batches (D-54, D-61)

**Goal:** confirm a College Admin cannot put a student into a batch during an
hour another batch already holds for them, and that the New Batch page's clash
popup, its **Remove** button and the mini calendar all work. (Before D-61 this
scenario also covered a student's own self-booking; self-booking is gone.)

**Accounts:** students `juan.santos@psu.edu.ph` and `maria.reyes@psu.edu.ph`
(both CCS), and the CCS Admin `admin.ccs@healthpass.test`.

**Steps:**

1. Log in as the **CCS admin** and open **New Batch Request**. → **Expect:**
   "Requested clinic date" is a **small month calendar**, not a date box. Past
   days are faded and cannot be clicked, the left arrow is disabled on the
   current month, the right arrow moves forward and back again, and any FULL
   day is greyed with a "Full" label.
2. Click a date **at least two days ahead**. → **Expect:** "Selected: <that
   date>" appears under it. Choose a **Reason**, **Start time 9:00 AM –
   10:00 AM**, tick **Juan Santos only**, and submit. → **Expect:** the
   confirmation screen with a `BR-` number (call it batch A). Write down the
   date.
3. Start another **New Batch Request** for the **same date**, **Start time
   9:00 AM – 10:00 AM**, and tick **Juan Santos** and **Maria Reyes**. Submit.
4. → **Expect:** the page comes back with a popup titled **"Some students are
   already scheduled at this time"**. It lists **Juan Santos**, his student
   number and "on batch <batch A's BR- number>, 9:00 AM – 10:00 AM". **Maria is
   not listed.** Behind the popup the reason, date, start hour and both ticks
   are still filled in. Batch Tracking shows **no** new batch.
5. Click **Close**. → **Expect:** the popup closes and **both** students are
   still ticked.
6. Change **Start time** to **10:00 AM – 11:00 AM** (the batch no longer covers
   9 AM) and submit. → **Expect:** the confirmation screen with a new `BR-`
   number. Two students need one hour, so this batch holds 10–11 AM.
7. Start another **New Batch Request** for the **same date**, **Start time
   10:00 AM – 11:00 AM**, ticking Juan and **Carlo Cruz**. Submit. →
   **Expect:** the popup lists **Juan only** (step 6's batch holds him at
   10–11). Click **Remove these students from the batch**. → **Expect:** the
   popup closes, **Juan is unticked**, Carlo is still ticked, and the "(N of M
   selected)" counter drops by one. Submit again. → **Expect:** the
   confirmation screen.

**Pass criteria:** a batch is refused while any ticked student is already held
by another pending or approved batch during its span; the popup lists exactly
those students and the batch they clash with; **Close** keeps the selection
and **Remove** deselects only the listed students; a span that misses the held
hour submits; the mini calendar disables past and FULL days and its month
arrows work.

---

## E2E-10 — Sidebar unread badges (D-57)

**Goal:** confirm each role's menu badges count the right things, clear when
their page is opened (or stay, for the two work counts), and sit in the right
place in the expanded sidebar, the collapsed rail and the phone drawer.

**Accounts:** student `carlo.cruz@psu.edu.ph` (CCS), the CCS Admin
`admin.ccs@healthpass.test`, the CEA Admin `admin.cea@healthpass.test`,
`nurse@healthpass.test` and `director@healthpass.test`.

**What a badge looks like:** a solid orange circle at the top-right corner of
a menu item's name. **One** new thing is a small dot with no number; **2–9**
shows the number; **10 or more** shows **9+**; nothing new shows no badge at
all. Badges only change when a page loads — reload if you are waiting for one.

**Run this before 3:00 PM** — the batch below is for today, and an hour that
has already ended cannot be booked.

**Before you start — clear everyone's badges:** log in once as each account
above and open every menu item that shows a badge (except **Live Queue** and
**Batch Approvals**, which don't clear by opening). Write down the Nurse's
Live Queue number and the Director's Batch Approvals number.

**Steps — College Admin:**

1. Log in as the **CCS admin**. New Batch Request → today's date → any
   reason → **Medical** → a start hour that has not ended yet → tick **Carlo
   Cruz** → Submit. (If a clash popup lists Carlo, Close it and pick another
   hour.) → **Expect:** the confirmation screen. **Activity Log shows no
   badge** — your own submission is not news to you. Log out.

**Steps — Director:**

2. Log in as the **Director**. → **Expect:** Batch Approvals is **one higher**
   than the number you wrote down (a dot if it went from 0 to 1).
3. Open **Batch Approvals**. → **Expect:** its badge is **still there** on this
   page. Approve the new CCS batch. → **Expect:** on the page that loads,
   Batch Approvals is back to the number you wrote down. Log out.

**Steps — College Admin again:**

4. Log in as the **CCS admin**. → **Expect:** **Batch Tracking** and **Activity
   Log** each show a **dot** (the Director's approval).
5. Open **Batch Tracking**. → **Expect:** on this page Batch Tracking has no
   badge; Activity Log still has its dot. Open **Activity Log**. → **Expect:**
   its badge is gone too. Log out.
6. Log in as the **CEA admin**. → **Expect:** no new badge — another college's
   batch never counts. Log out.

**Steps — Student:**

7. Log in as **Carlo Cruz**. → **Expect:** there is **no My Appointments**
   item (D-61 removed it with its badge); the appointment the college booked
   is on the dashboard's Next Appointment card.
8. **Kiosk Tutorial** shows a dot. Open it but do **not** click Get Started;
   click Dashboard. → **Expect:** the dot is **still there**.
9. Open Kiosk Tutorial → **Get Started** → **Next** until the card reads
   **"Step 6 of 6"** (Finishing up). Reload the page. → **Expect:** the dot is
   **gone**, and it stays gone after logging out and back in.

**Steps — Nurse:**

10. On the kiosk (`http://127.0.0.1:8080/kiosk`), log in with email as Carlo
    Cruz and complete a screening with **manual** vitals, entering blood
    pressure **150 / 95**. Submit to Clinic.
11. Log in as the **Nurse**. → **Expect:** Live Queue is **one higher** than
    the number you wrote down. Open **Live Queue**. → **Expect:** the badge
    **stays**. Encode Carlo's visit as **Fit** → Save & Close. → **Expect:** on
    the page that loads, Live Queue is back to your number. Log out.

**Steps — the results reach everyone:**

12. Log in as the **Director**. → **Expect:** **Flagged Anomalies** shows a dot
    (Carlo's high blood pressure). Open it. → **Expect:** the badge is gone.
13. Log in as the **CCS admin**. → **Expect:** **Batch Tracking** shows a dot
    (Carlo's result was encoded). **Activity Log** has no badge — an encode is
    not an activity entry.
14. Log in as **Carlo Cruz**. → **Expect:** **My Records** shows a dot. Open
    it. → **Expect:** the badge is gone.

**Steps — where the badge sits (any account with a badge showing):**

15. On a desktop-width window, click the **☰** button at the top of the
    sidebar to collapse it to icons. → **Expect:** the badge is at the
    **top-right corner of the icon**. Expand it again → the badge is back at
    the top-right of the **name**.
16. Narrow the window to phone width (or Chrome DevTools → device toolbar,
    e.g. iPhone 12). Open the menu with ☰. → **Expect:** the badge is at the
    top-right of the item's **name** in the drawer.
17. *(Optional — needs 10 or more pending batch requests, e.g. submitted by
    several college admins.)* Log in as the Director. → **Expect:** Batch
    Approvals shows **9+**.

**Pass criteria:** every badge in steps 1–14 appears, clears or stays exactly
as described; your own actions never badge your own Activity Log; another
college's events never reach an admin; the tutorial dot survives just opening
the page but not reaching Step 6 of 6; the badge moves to the icon when the
rail is collapsed and stays on the name in the phone drawer; badges are orange
even on the item you are currently on.

---

## E2E-11 — Blood pressure from the Bluetooth monitor (D-58)

**Goal:** confirm a blood-pressure reading sent by the Pi's Bluetooth daemon
fills the kiosk's blood-pressure step, that manual entry still works, and that
the nurse sees the monitor's irregular-pulse indicator.

**Accounts:** student `carlo.cruz@psu.edu.ph` and `nurse@healthpass.test`.

**What stands in for the cuff:** a developer runs the command below in a
terminal on the machine running the app — the real daemon sends exactly this
message. `<key>` is `HEALTHPASS_KIOSK_KEY` from `.env`; the developer types it
and it never goes into a chat or the QA sheet.

```bash
curl -i -X POST http://127.0.0.1:8080/api/kiosk/bp-reading \
  -H "Content-Type: application/json" -H "X-Kiosk-Key: <key>" \
  -d '{"systolic":128,"diastolic":82,"pulse":72,"unit":"mmHg","taken_at":"2026-09-15T14:30:05","entry_method":"device_ble","device_model":"A&D UA-651BLE","suspect":false,"flags":{"irregular_pulse":true}}'
```

**Steps — a reading fills the step:**

1. On the kiosk (`http://127.0.0.1:8080/kiosk`), log in with email as Carlo
   Cruz, agree to the privacy notice, and complete height, weight and
   temperature any way you like.
2. On **Blood Pressure · Step 4 of 4**, tap **▶ Start**. → **Expect:** the card
   changes to a pulsing animation, **"Waiting for the blood pressure monitor…"**
   and a **Cancel** button. Now have the developer run the command.
   → **Expect:** within about a second the scanning animation plays and the
   card shows **128/82 mmHg**, heart rate **72 bpm** and **From sensor**.
   Nothing about an irregular pulse appears anywhere on the kiosk.
3. Continue, answer the questionnaire and **Submit to Clinic**.
4. Log in as the **Nurse** and open Carlo's visit from the Live Queue.
   → **Expect:** Vital Signs shows **128/82 mmHg**, **Entry: Sensor** (or
   Mixed if you typed an earlier step) and an orange **⚑ Irregular pulse**
   badge reading "Detected by the blood-pressure monitor during this reading."

**Steps — nothing carries over to the next student:**

5. While the kiosk is on **Welcome**, have the developer run the command
   again. Log in as Carlo, go through to Step 4 and tap **▶ Start**.
   → **Expect:** it keeps waiting — a reading sent before you tapped Start is
   **not** used. Triple-tap the logo and type the blood pressure by hand
   instead (opening the pad stops the waiting).

**Steps — manual entry and the retake suggestion:**

6. Start another session to Step 4 and tap **▶ Start**. Triple-tap the logo
   so the number pad opens, then have the developer run the command.
   → **Expect:** nothing changes behind the pad. Type **120**, **80**, **70**, confirming each.
   → **Expect:** the card shows **120/80** and **Entered manually** — the
   reading did not replace it.
7. Tap **↻ Retry** and **▶ Start**, then have the developer run the command
   with `"suspect":true`. → **Expect:** the reading is captured, with a peach
   note: **"The monitor noticed movement or a loose cuff. You can tap Retry to
   measure again, or continue."** Both **Retry** and **Continue** still work.

**Steps — Cancel and the 2-minute limit (D-59):**

8. Tap **↻ Retry**, **▶ Start**, then **Cancel**. → **Expect:** **▶ Start** is
   back, and running the command now changes nothing.
9. Tap **▶ Start** and touch nothing for 2 minutes. → **Expect:** the kiosk
   does **not** reset to Welcome while it waits; after 2 minutes the step shows
   **▶ Start** again with "No reading came from the blood pressure monitor.
   Tap Start to try again."

**Steps — the endpoint refuses bad messages (developer):**

10. Run the command with a wrong key. → **Expect:** `HTTP/1.1 403`.
11. Run it with `"systolic":300`. → **Expect:** `HTTP/1.1 422` with a JSON body
    naming `systolic`.

**Pass criteria:** a reading sent after **▶ Start** fills Step 4 as a sensor
reading; a reading sent before Start is never used; Cancel and the 2-minute
limit bring Start back without resetting the session; numbers being typed or
already typed are never replaced; the retake note appears only for a flagged
reading and never blocks; the kiosk never mentions an irregular pulse while the
nurse's encode page does; a wrong key gets 403 and impossible numbers get 422.

---

## E2E-12 — No clinic schedule today: the kiosk turns the student away (D-61)

**Goal:** a student with no appointment today cannot get through the kiosk —
the screen offers no way forward, and nothing reaches the nurse.

**Account:** a student with **no appointment today** — e.g.
`angel.garcia@psu.edu.ph` (check her dashboard: Next Appointment must not show
today). Nurse `nurse@healthpass.test`.

**Steps:**

1. Open `http://127.0.0.1:8080/kiosk`, click **Lost ID? Log in with email**,
   and log in as the student. → **Expect:** Identity Confirm with her name.
2. Click **That's me — Continue**. → **Expect:** a screen titled **"No Clinic
   Schedule Today"** reading "You don't have a clinic schedule today.
   Clearances are scheduled through your college, so please ask your college
   office to include you in a batch request."
3. → **Expect:** exactly **one** button, **Back to start**. There is **no**
   "Proceed", "Continue" or "Walk-in" button anywhere on the screen.
4. Click **Back to start**. → **Expect:** the Welcome screen, with no trace of
   the student.
5. Log in as the nurse and open the **Live Queue**. → **Expect:** no new visit
   for that student.
6. *(Developer-assisted, optional.)* Force a submit for her from the browser
   devtools. → **Expect:** HTTP **422** with the same message, and no new
   visit anywhere.

**Pass criteria:** the no-schedule screen appears for a student with nothing
today, offers only Back to start, and the server stores nothing even when the
screen is bypassed. A student with an appointment at a **different hour
today** still gets through (E2E-1).

---

## E2E-13 — The student dashboard has no booking (D-61)

**Goal:** confirm a student can see their schedule but can neither book nor
cancel anything.

**Account:** a student with an upcoming batch appointment (e.g. Juan after
E2E-1, or `juan.santos@psu.edu.ph` on the seeded upcoming batch BR-2026-904),
and a student with none (e.g. `angel.garcia@psu.edu.ph`).

**Steps:**

1. Log in as the student **with** an appointment. → **Expect:** the sidebar
   lists Dashboard, My Records, My ID & Profile and Kiosk Tutorial — **no Book
   Appointment, no My Appointments**.
2. → **Expect:** the **Clearance Status** card has no button; it reads "Your
   college requests your clinic schedule."
3. → **Expect:** the **Next Appointment** card shows the date, hour and a
   **"Booked by College of Computing Studies"** badge. There is **no Cancel
   appointment** button; a future appointment says "Booked by your college.
   Contact your college administrator if you need this cancelled."
4. Type `http://127.0.0.1:8080/student/appointments` into the address bar. →
   **Expect:** a **404** page. Do the same for `/student/my-appointments`. →
   **Expect:** **404**.
5. Log in as the student **without** an appointment. → **Expect:** "No
   upcoming appointment / Your schedule is clear" with **no Book button**.
6. Repeat step 1–3 at phone width (browser devtools, ~390 px wide). →
   **Expect:** the cards stack, nothing is cut off, and still no Book or
   Cancel control.

**Pass criteria:** no page, button or link lets a student book or cancel; the
removed URLs are 404; the Next Appointment card stays visible and read-only.

---

## E2E-14 — The College Admin chooses the clinic form (D-62)

**Goal:** the New Batch Request page makes the admin pick one of the two
official forms first, and the Reason list follows that choice.

**Account:** CCS Admin `admin.ccs@healthpass.test`.

**Steps:**

1. Open **New Batch Request**. → **Expect:** a card on top titled **"Choose
   the form the clinic will use"** with two tiles — **Medical Assessment Form**
   on the left, **Medical Clearance** on the right — each with a picture of the
   form and a short description. The **Reason** dropdown is greyed out and
   reads "— Choose a form first —".
2. Press **Tab** until a tile is focused, then use the **arrow keys**. →
   **Expect:** a visible orange focus ring; the arrow keys move the selection
   between the two tiles (they are radio buttons).
3. Click **Medical Clearance**. → **Expect:** the tile gets an orange border
   and a check mark; the Reason dropdown turns on and lists exactly **Field
   Trip/Educational Tour, Outbound Activities, Others, Specify**.
4. Pick **Others, Specify** and type an event in "Please specify". → **Expect:**
   the box stops accepting text at 120 characters.
5. Click **Medical Assessment Form**. → **Expect:** the Reason resets to
   "— Select a reason —", the specify box disappears (and is empty if you pick
   Others again), and the list is exactly **Off Campus Procedure, Sports
   Activities, On-the-job Training, Related Learning Experience, Others,
   Specify**.
6. *(Developer-assisted, optional.)* Pick **On-the-job Training**, a date and
   a start hour but **no** student, remove the Submit button's `disabled`
   attribute in devtools and click it. → **Expect:** the page comes back with
   an error, **Medical Assessment Form** still chosen and **On-the-job
   Training** still selected.
7. Select a student and **Submit**. → **Expect:** the confirmation screen shows
   **Form: Medical Assessment Form** and **Reason: On-the-job Training**. Batch
   Tracking shows a **Form Type** column just before **Reason**.
8. Repeat step 1–3 at phone width (~390 px). → **Expect:** the two tiles stack
   one above the other, nothing is cut off.

**Pass criteria:** no reason can be picked before a form; each form offers only
its own reasons, worded exactly as on the paper; switching forms clears the
reason; the choice survives a failed submit and appears on the confirmation and
tracking pages.

---

## E2E-15 — The Director sees each batch's form (D-62)

**Goal:** Batch Approvals tells the Director which form a batch uses before
they approve or reject it.

**Account:** Director `director@healthpass.test` (after `migrate:fresh --seed`,
which seeds pending batches on both forms).

**Steps:**

1. Open **Batch Approvals**. → **Expect:** a **Form Type** column placed
   **immediately before Reason**; the seeded pending batches show **Medical
   Assessment Form** (BR-2026-901, "On-the-job Training") and **Medical
   Clearance** (BR-2026-902, "Field Trip/Educational Tour").
2. Click **Approve** on BR-2026-902. → **Expect:** the modal carries a line
   **Form: Medical Clearance**. Cancel.
3. Click **Reject** on BR-2026-901. → **Expect:** the modal carries **Form:
   Medical Assessment Form**. Cancel.
4. Narrow the window to phone width. → **Expect:** the table scrolls sideways
   and the Form Type column is still there, before Reason.
5. Log in as the nurse and open the **Live Queue** with a visit captured on
   each form. → **Expect:** each row shows a small **Clearance** or
   **Assessment** badge next to the student's name, and the encode page header
   shows the form name and the purpose read-only (no purpose dropdown).

**Pass criteria:** the Form Type column sits right before Reason, both modals
name the form, and the nurse sees the same form on the queue and encode page.

---

## E2E-16 — Physician and nurse encodes print different physician blocks (D-64)

**Goal:** the physician's name and license print only on records the physician
encoded; a nurse's record prints a blank block for the wet signature, and the
shared history shows who encoded each one.

**Accounts:** Physician `physician@healthpass.test`, Nurse
`nurse@healthpass.test` (after `migrate:fresh --seed`). You need **two
captured visits** in the Live Queue — run the kiosk twice (E2E-1 steps) with
two students who have an appointment today.

**Steps:**

1. Log in as the **physician**. → **Expect:** you land on **Clinic Dashboard**;
   the sidebar's first item reads **Clinic Dashboard** and the role under your
   name reads **Physician**. The sidebar also shows Live Queue and Enable Kiosk
   Mode.
2. Open **Live Queue** → **Encode Result** on the first visit. → **Expect:** the
   notes box is labelled **Clinic Notes**.
3. Choose **Fit**, click **Preview & Print**. → **Expect:** the preview's
   physician block reads **REYNALDO S. ALIPIO, MD**, "University Physician",
   **License No. 60252**. Close the print dialog, then **Save & Close**.
4. Log out and log in as the **nurse**. Encode the second visit (Fit), **Preview
   & Print**. → **Expect:** the physician block has a **blank name line**,
   "University Physician" and **License No. ________**. Save & Close.
5. Still as the nurse, open **Clinic Dashboard**. → **Expect:** the two new rows
   at the top; **Encoded by** shows "Reynaldo S. Alipio" with a **Physician**
   badge on one and "Head Nurse" with a **Nurse** badge on the other.
6. Click the physician's row. → **Expect:** the read-only notice reads
   "encoded by **Reynaldo S. Alipio** (Physician)"; **Reprint** shows the
   physician's name on the form.
7. Log in as the physician again and open **Clinic Dashboard**. → **Expect:**
   the same rows and badges as step 5.

**Pass criteria:** the name and license print only on the physician's record;
the nurse's record prints blank lines; both roles see the same history with a
role badge per row.

---

## E2E-17 — The Director creates a physician and corrects the license (D-64)

**Goal:** the Director can provision a physician with a license number, the
rules on that number hold, and a typo can be corrected afterwards.

**Account:** Director `director@healthpass.test`.

**Steps:**

1. Open **Staff Accounts**. Set **Role** to **Physician**. → **Expect:** a
   **License No.** field appears, plus the hint "Enter the name without 'Dr.' or
   'MD'; the form adds ', MD'." The College picker is hidden.
2. Enter name "Ana B. Cruz", an unused email, and License No. **PRC123**.
   Click **Create account**. → **Expect:** refused — "The license number must be
   4 to 10 digits, numbers only."
3. Clear the license and submit. → **Expect:** refused — "A physician needs a
   license number…".
4. Enter License No. **1234567** and submit. → **Expect:** the one-time password
   panel; the list shows "Ana B. Cruz", role **Physician**, and **License No.
   1234567** under it.
5. Set **Role** to **Nurse**. → **Expect:** the License No. field disappears
   (a nurse can't be given one).
6. On Ana's row click **Correct**. → **Expect:** a dialog "Correct the license
   number?" with the current number filled in. Press **Esc**. → **Expect:** the
   dialog closes and nothing changes.
7. Click **Correct** again, type **7654321**, click **Save license**. →
   **Expect:** the green message "Ana B. Cruz's license number is now 7654321…";
   the row shows **License No. 7654321**.
8. Click **Correct**, type **76A** and save. → **Expect:** a red banner "License
   not changed: …4 to 10 digits…"; the row still shows 7654321, and the New
   staff account form shows no error.
9. Nurse and College Admin rows have **no Correct** link.

**Pass criteria:** a physician can't be created without a valid 4–10 digit
license, only physicians carry one, and the correction updates the list.

---

## E2E-18 — The clinic corrects a kiosk vital and records the respiratory rate (D-65)

**Goal:** what the clinic confirms is what prints; what the kiosk measured is
what the analytics keep counting.

**Accounts:** Nurse `nurse@healthpass.test`, Director
`director@healthpass.test`, Student `maria.reyes@psu.edu.ph` (after
`migrate:fresh --seed`). Use seeded captured visit **HP-2026-9005** (Maria
Reyes) — the kiosk recorded a **fever, 38.1 °C**, and a BP of 125/82.

**Steps:**

1. Log in as the **nurse**, open **Live Queue** → **Encode Result** on that
   visit. → **Expect:** the **Vital Signs** card on the left is now a set of
   **input boxes**, pre-filled with the kiosk's numbers, with an **empty
   Respiratory Rate (breaths/min)** box and a **BMI** tile that is not
   editable. The ⚑ Flagged badge sits next to the flagged vital.
2. Change **Height** to **175** and **Weight** to **80**. → **Expect:** the BMI
   tile updates to **26.1** as you type, without reloading the page.
3. Set the vitals back: height **158.5**, weight **52.5**. Now correct the two
   the clinic re-took: **Temperature 37.0** and **BP 118 / 76**. Leave
   Respiratory Rate **empty**, choose **Fit**, click **Save & Close**. →
   **Expect:** refused, with the message **"Measure and enter the respiratory
   rate before saving."** Nothing was saved.
4. Type **4** in Respiratory Rate and save. → **Expect:** refused — "The
   respiratory rate field must be at least 8."
5. Type **18**, click **Preview & Print**. → **Expect:** the printed form's
   vitals read **37.0 °C** and **118/76 mmHg** (not the kiosk's 38.1 and
   125/82) and **Respiratory Rate: 18 breaths/min**. Close the print dialog.
6. Click **Save & Close**. → **Expect:** back at the Live Queue, the visit gone.
7. Re-open the same visit from **Clinic Dashboard**. → **Expect:** read-only;
   the Vital Signs card shows **37.0 °C** with a small grey **"Kiosk: 38.1 °C"**
   under it and **118/76 mmHg** with **"Kiosk: 125/82 mmHg"** under it, and
   **no** such line under Height, Weight or Heart Rate (they were not
   corrected). The **Respiratory Rate** tile reads **18 breaths/min**.
8. Click **Reprint**. → **Expect:** the same 37.0 °C, 118/76 and
   18 breaths/min.
9. Log in as the **Director** and open **Flagged Anomalies**. → **Expect:** the
   visit is still listed as **Fever — 38.1°C**, and **37.0 appears nowhere on
   the page**. The clinic's correction did **not** rewrite what the screening
   measured.
10. Log in as the **student** whose visit it was, open **My Records** and click
    **View** on that record. → **Expect:** a **Respiratory Rate** row reading
    **18 breaths/min**. Open a record that has not been encoded — it reads
    **—**.

**Pass criteria:** the respiratory rate is required and range-checked; the
printed form and the read-only card show the clinic's confirmed values with a
"Kiosk:" hint only where they differ; Flagged Anomalies still shows the kiosk's
reading.

**Note for the tester:** the ⚑ Flagged badge stays beside the temperature even
after the clinic's correction — it describes the **kiosk's** screening (BR-14),
which is what the "Kiosk: 38.1 °C" line underneath it names.

---

## E2E-19 — Heart-rate and respiratory-rate flag boundaries (D-66)

**Goal:** prove the two new thresholds are exact on both sides, that the
respiratory-rate flag is raised at **encode** and not at capture, and that both
reach the Director's screens. Run it after E2E-2, which already covers the
"clearly flagged" path.

**Accounts:** a student with an appointment today, then Nurse, then Director.

**Steps:**

1. Put a student through the kiosk with **HR = 100** at the Blood Pressure
   step, everything else normal. → **Expect:** the heart-rate panel reads
   **Normal** (green), and Review shows the bpm in plain slate with no ⚑.
2. As the Nurse, check the Live Queue row. → **Expect:** **no HR badge**, and
   the bpm is **not** orange.
3. Put a second student through with **HR = 101**, everything else normal.
   → **Expect:** the heart-rate panel reads **High** (orange) and Review
   shows the bpm orange with a ⚑.
4. As the Nurse, check that row. → **Expect:** an **HR** badge in the Flags
   column and an orange bpm. Open **Encode Result** → the Heart Rate input
   carries a **⚑ Flagged** badge, and the Respiratory Rate input carries
   none (its flag does not exist yet).
5. In that encode form, type **Respiratory Rate = 20** and Save & Close.
   Reopen the visit read-only. → **Expect:** the Respiratory Rate tile reads
   `20 breaths/min` with **no** Flagged badge — 20 is in range.
6. Encode another visit with **Respiratory Rate = 21**. Reopen it read-only.
   → **Expect:** `21 breaths/min` **with** a ⚑ Flagged badge.
7. Repeat step 6 with **Respiratory Rate = 11** → flagged; and **12** →
   not flagged.
8. Log in as the **Director**, open **Flagged Anomalies**. → **Expect:**
   five stat cards; the **High Heart Rate** count includes the 101-bpm student
   and **not** the 100-bpm one; the **Abnormal Respiratory Rate** count
   includes the 21 and the 11 and **not** the 20 or the 12. Each flagged row
   lists the label with its value (`101 bpm`, `21 breaths/min`).
9. Open **Analytics** for the same month. → **Expect:** the Vital-Sign Flags
   card shows **five** tiles; the High Heart Rate and Abnormal Respiratory Rate
   counts match step 8, and their rates are a % of the month's captured
   screenings. The respiratory tile's caption reads **"measured by the clinic
   at encode"**.
10. Log in as a **College Admin** for that college, open **Analytics** → the
    same five tiles; click **Print monthly report** → the Vital-Sign Flags
    table has **five** rows with the same counts and rates.
11. As the 101-bpm student, open **My Records** and view the encoded visit.
    → **Expect:** the Heart Rate row carries a **Flagged** chip, and so does
    the Respiratory Rate row on the visit encoded with 21.

**Pass criteria:** 100 is not flagged and 101 is; 12 and 20 are not flagged and
11 and 21 are; the respiratory-rate flag appears only after encoding; and every
screen — queue, Flagged Anomalies, both analytics pages, the printed report
and My Records — agrees with the stored booleans.

**Note for the tester:** a respiratory rate that is blank is **not** a flag. A
visit the clinic has not encoded shows `—` and counts toward nothing; that is
"not measured yet", not "normal".

---

## E2E-20 — The Medical Clearance matches form R04, and saves as a PDF (D-67)

**Who:** a clinic tester (nurse or physician) with a **blank printed copy of
official form PSU-QSP-OSS-004-FO002-R04** in hand. This is the **SM-3
sign-off** run.

**Why:** since D-67 the printout and the downloadable PDF come from the *same*
template, so they must match the official blank **and each other**.

1. Log in as `nurse@healthpass.test` / `password` and open the **Clinic
   Dashboard**. Pick any **encoded** visit and click **Encode Result** to open
   it read-only.
2. Click **Reprint**. → **Expect:** the browser print dialog opens on the
   dashboard itself (no new tab) with the clearance in its preview pane.
   Set the paper to **Letter** and the margins to **Default**.
3. **Print it** (or save the preview), then lay the printout **beside the blank
   R04 form** and check, top to bottom:
   - the letterhead — "Republic of the Philippines", **PAMPANGA STATE
     UNIVERSITY**, "(former Don Honorio Ventura State University)", the PSU
     seal and Bagong Pilipinas on the right, then the OSWF logo with "Office of
     Student Welfare and Formation / Health Services Unit" and the rule;
   - the title **MEDICAL CLEARANCE**, underlined and letter-spaced;
   - Name over **SURNAME / FIRST NAME / MIDDLE NAME**; Course, Year & Section
     (**no college**); Address; Age / Sex / Civil Status; Date of Birth /
     Place of Birth;
   - the vitals in three column pairs — Height / Weight, Heart Rate /
     Blood Pressure, Temperature / **Respiratory Rate**. **There is no BMI
     box** on R04, and none should print;
   - **Physical Signs Disorder of:** as a bordered grid of **three column
     groups of four** — SKIN, HEAD, EYES, EARS | NOSE, THROAT, CHEST/LUNGS,
     HEART | ABDOMEN, KIDNEY/BLADDER, BRAIN, MENTAL DISORDER — each with
     YES and NO boxes, then "*If YES, give details under Remarks.*";
   - **REMARKS** on **two ruled lines**;
   - "Are you Pregnant ○ YES ○ NO   *If YES, when is the last
     menstrual period?*";
   - "He/She is physically / mentally ○ FIT ○ UNFIT **to participate
     in:**" with **three** options — Field Trip/Educational Tour, Outbound
     Activities, "Others, Specify: ___";
   - the physician block — signature line, name (only on a record a
     **physician** encoded), "University Physician", "License No.";
   - **Date:** (the encode date) and the code **PSU-QSP-OSS-004-FO002-R04**
     bottom-left.
   → **Expect:** every label, its wording and its position match the blank
   form, and the whole document is **one page**. Every answered bubble and
   box is a **solid** filled circle or square — never a small dot, and
   never an empty outline — **even though "Background graphics" is left
   unchecked**. Record any difference as a Fail with a photo.
4. Back on the same read-only screen, click **Save as PDF**. → **Expect:**
   the browser downloads a file named
   **`HP-…-medical-clearance.pdf`** (the visit's reference number). No new tab
   opens and the page does not navigate.
5. Open the downloaded PDF. → **Expect:** it is **exactly one page**, it is
   **Letter** sized, and it is the **same document** as the printout from step
   3 — same values, same wording, same layout, same REMARKS text at the
   same size.
6. Reload the Clinic Dashboard and find that visit's row. → **Expect:** the
   **Printed** column is unchanged by step 4 — downloading the PDF is not
   printing, so it must **not** move Printed to Yes or change the printed
   timestamp. (Step 2's Reprint is what sets it.)
7. Find a visit that is still **captured** (in the Live Queue, not yet
   encoded). Its encode screen has **no** Save as PDF button — only
   **Preview & Print**. → **Expect:** Preview & Print still opens the print
   dialog with the same R04 document, filled from what is on screen.
8. Log in as a **student**, a **College Admin** and the **Director** in turn
   and paste the PDF address from step 4 into the address bar. → **Expect:**
   each one is bounced to their own dashboard — the clearance PDF is
   clinic-staff only.

**Pass criteria:** the printout matches the blank R04 form field for field; the
saved PDF matches the printout; both are one page; Save as PDF does not stamp
Printed; and no other role can reach the PDF.

**Note for the tester:** a long Clinic Note is **clipped** to the two ruled
lines on purpose — the type shrinks first (down to a floor) and the rest is
cut. That is correct behaviour, not a bug: the clearance must never run to a
second page. The printout and the PDF must clip at the *same* point.

## E2E-21 — The kiosk follows the batch's form (D-68)

**Goal:** the same student, walked through the kiosk twice on two batches —
one **Medical Clearance**, one **Medical Assessment Form** — sees a *different*
set of screens, and only the Assessment visit records a Personal / Social
History. Since D-68 the form type is decided on the **server**, so the kiosk
cannot be talked out of it.

**Accounts:** CCS Admin `admin.ccs@healthpass.test`, Director
`director@healthpass.test`, Student `juan.santos@psu.edu.ph`, Nurse
`nurse@healthpass.test`. All passwords `password`.

### Part A — a Medical Clearance batch: nothing changes

1. Log in as `admin.ccs@healthpass.test` → **New Batch Request**. Pick the
   **Medical Clearance** tile, Reason **Field Trip/Educational Tour**, click
   **today** on the calendar, pick a **Start time** that has not ended yet,
   tick **Juan Santos**, and submit. Log out.
2. Log in as `director@healthpass.test` → **Batch Approvals** → **Approve**
   that batch. Log out.
3. Open the kiosk at `http://127.0.0.1:8080/kiosk`, click **Lost ID? Log in
   with email**, sign in as `juan.santos@psu.edu.ph` / `password`, click
   **That's me — Continue**, then **I Agree — Proceed**.
4. Enter the four vitals manually (e.g. `165`, `60`, `36.8`, `118`/`76`/`72`).
5. **Questionnaire.** → **Expect:** the heading reads exactly **"Physical Signs
   Disorder of:"** — **no "(Self Assessment)"**. Answer **No** to all twelve
   and to **Are you Pregnant?**, then click **Review & Submit →**.
6. → **Expect:** you land **straight on Review**. There is **no** Personal /
   Social History screen and **no** Personal / Social History card — only
   **Vital Signs** and **Questionnaire**. The Back button reads **"← Back to
   questionnaire"**.
7. Click **Submit to Clinic →**. → **Expect:** the Complete screen, and still
   **no Fit/Unfit anywhere** (FR-KSK-14).
8. In another tab log in as `nurse@healthpass.test`, open the Live Queue and
   click **Encode Result** on Juan's visit. → **Expect:** the left column has
   the Vital Signs and Health Questionnaire cards and **no** "Personal / Social
   History (from the kiosk)" card. Encode **Fit** and **Save & Close**.

### Part B — a Medical Assessment Form batch: four extra questions

9. Log back in as `admin.ccs@healthpass.test` → **New Batch Request**. This
   time pick the **Medical Assessment Form** tile, Reason **On-the-job
   Training**, **today**, a **Start time in a later hour that has not ended**,
   tick **Juan Santos**, submit. Log out, approve it as the Director, log out.
10. Open the kiosk again and take Juan through email login → **That's me** →
    **I Agree** → the four vitals, exactly as before.
11. **Questionnaire.** → **Expect:** the heading now reads **"Physical Signs
    Disorder of: (Self Assessment)"**. The twelve cards, their helper lines and
    the pregnancy question are **unchanged**. Answer all thirteen and click
    **Review & Submit →**.
12. → **Expect:** a **new screen**, headed **"Personal / Social History"**,
    with the line **"Your answers are confidential and are seen only by the
    clinic staff."** and four rows:
    - **Smoking** — Yes / No / **Quit**
    - **Alcohol** — Yes / No / **Quit**
    - **Illicit Drugs** — Yes / No / **Quit**
    - **Sexually Active** — **Yes / No only** (no Quit)
    **Review & Submit →** is **disabled** while any row is unanswered.
13. Answer **three** of the four and confirm the button is **still disabled**.
    Then answer the fourth. → **Expect:** it enables.
14. Click **← Back**. → **Expect:** you are on the questionnaire, answers
    intact. Click **Review & Submit →** again. → **Expect:** you return to the
    Personal / Social History with **your four answers still selected**.
15. Set Smoking = **Quit**, Alcohol = **Yes**, Illicit Drugs = **No**,
    Sexually Active = **Yes**, then **Review & Submit →**.
16. **Review.** → **Expect:** a **third card**, **Personal / Social History**,
    reading Quit / Yes / No / Yes. The Back button now reads **"← Back"** and
    returns to the Personal / Social History screen, not the questionnaire.
17. Click **Submit to Clinic →**. → **Expect:** the Complete screen, still with
    **no Fit/Unfit and no interpretation of any answer**.
18. As the nurse, open **Encode Result** on this second visit. → **Expect:** a
    read-only card **"Personal / Social History (from the kiosk)"** in the left
    column, **under** the Health Questionnaire, showing **Smoking Quit**,
    **Alcohol Yes**, **Illicit Drugs No**, **Sexually Active Yes**. There is no
    way to edit them here. Encode **Fit** and **Save & Close**.
19. Log in as `juan.santos@psu.edu.ph` → **My Records** → **View** on the
    **second** visit. → **Expect:** the modal's right column lists the twelve
    Physical Signs rows **and then** a **Personal / Social History** section
    with the same four answers. Open the **first** visit. → **Expect:** the
    twelve rows and **no** Personal / Social History section at all.

### Part C — the idle reset still clears it

20. Start a third kiosk session as Juan (he has no third appointment today, so
    use a student who does, or re-run Part B's steps up to the Personal /
    Social History screen). Answer two rows, then **walk away for 90 seconds**
    without touching the screen. → **Expect:** the kiosk returns to Welcome on
    its own. Log in again and reach that screen: **all four rows are blank** —
    nothing of the previous student survives (FR-KSK-13/15).

**Pass criteria:** the Clearance walk never shows the new screen, the new card
or the "(Self Assessment)" heading; the Assessment walk shows all three,
requires all four answers, and carries them to the encode page and My Records;
neither walk ever shows Fit/Unfit; and an abandoned Assessment session leaves
no answers behind.

**Note for the tester:** which screens you see is decided by the **server**
from today's appointment, not by the browser — and the server decides again
when you press Submit. There is nothing to click on the kiosk that can change
the form, and a Clearance visit stores these four fields as blank on purpose,
because the Medical Clearance form has no Personal / Social History section.

---

## E2E-22 — The Medical Assessment encode records the form's histories (D-69)

**Covers:** FR-NRS-10, FR-NRS-03, FR-NRS-04, BR-16.
**Roles:** Nurse (or Physician).
**Setup:** one **captured** visit from a **Medical Assessment Form** batch and
one from a **Medical Clearance** batch. The demo seed has both; the Live Queue
tags every row with its form.

### Part A — an Assessment encode

1. Open the Live Queue and click **Encode Result** on a row badged
   **Medical Assessment Form**. → **Expect:** under the identity and Vital
   Signs cards, the questionnaire card is headed **"Physical Signs Disorder of
   (Self Assessment)"**, followed by the Personal / Social History card and
   then four new cards: **Past Medical History & Family History**, **II.
   Immunization Profile**, **III. Family Planning Access** and **Past Surgical
   History / Procedures**. The right-hand Assessment card shows Result, the
   read-only Purpose and Clinic Notes — and **no** twelve Yes/No exam rows.
2. In the history table, tick **Allergy** under *Past Medical History
   (Patient)*. → **Expect:** the specify box on that row becomes typable (it
   was greyed out). Type `Peanuts`.
3. Tick **Hypertension (Highest BP)** under *Family History (Lineal)* and type
   `160/100`. Tick **Asthma** under Patient (it has no specify box).
4. Tick **BCG**, **HepB1** and **HPV** in the Immunization Profile, and type
   `Typhoid (2024)` in **Others**.
5. Choose **Yes** for family planning access. Type `Appendectomy` as the
   procedure and `Grade 5` as the Date Done.
6. Pick **Fit**, enter a respiratory rate, and press **Save & Close**. →
   **Expect:** back on the Live Queue with the confirmation, and the row is
   gone.
7. Reopen that visit from the Clinic Dashboard's encode history. → **Expect:**
   the same four cards, now **read-only**: Allergy and Asthma still ticked
   under Patient, Hypertension under Family, `Peanuts` and `160/100` still in
   their boxes, the three vaccines and `Typhoid (2024)` still there, **Yes**
   still chosen, and `Appendectomy` / `Grade 5` still filled in. Nothing can be
   edited, and there is no second Save button.

### Part B — a Clearance encode is unchanged

8. Go back to the Live Queue and encode a row badged **Medical Clearance**. →
   **Expect:** the questionnaire card is headed **"Health Questionnaire"**, the
   right-hand card still has the **twelve Yes/No Physical Signs rows**
   pre-filled from the kiosk, and **none** of the four new cards appear.
9. Save it as usual. → **Expect:** it saves exactly as it always did.

### Part C — nothing is required

10. Encode a third **Assessment** visit and touch none of the four new
    sections — set only the Result and the respiratory rate. → **Expect:** it
    saves. Reopening it shows the four cards with nothing ticked and empty
    boxes; the family planning question shows **neither** Yes nor No, because
    "not answered" is not the same as "No".

**Pass criteria:** the four sections appear on Assessment visits only, the
specify boxes unlock per row, everything typed comes back on the read-only
view, a Clearance encode is untouched, and an Assessment encode saves with
every section left blank.

**Note for the tester:** which form a visit uses is decided by the **server**
from the batch the appointment came from — there is nothing on the page that
can change it. Printing the Assessment form itself is a later change: for now
**Preview & Print** on an Assessment visit still renders the Medical
Clearance, with its Physical Signs boxes blank.

## E2E-23 — Menstrual / OB history and the physical examination (D-70)

**Covers:** FR-NRS-10, FR-NRS-03, FR-NRS-04.
**Roles:** Nurse (or Physician).
**Setup:** two **captured** visits from a **Medical Assessment Form** batch —
one **female** student and one **male** student. The demo seed has both; the
student's sex is on the identity card at the top of the encode screen.

### Part A — a female Assessment encode

1. Open the Live Queue and click **Encode Result** on the female student's row
   (badged **Medical Assessment Form**). → **Expect:** below the four D-69
   cards, three more: **V. Menstrual History**, **VI. OB/Pregnancy History**
   and **Pertinent Physical Examination**. V and VI are at full opacity, every
   box is usable, and neither shows a "For female students only" note.
2. Look at **Last Menstrual Period**. → **Expect:** if the student answered the
   LMP question at the kiosk, the box already holds that date; otherwise it is
   empty. Either way you can change it.
3. Type `13` in Menarche, `5` in Period Duration, `0` in No. of Pads per Day,
   `28` in Interval Cycle and `None` as the Contraceptive Method. Choose **No**
   for Menopause.
4. Try to break the ranges: type `2` in Menarche and `200` in Interval Cycle,
   and set the LMP to **tomorrow**. Press **Save & Close**. → **Expect:** the
   page comes back with an error under each of those three boxes and **nothing
   is saved**. Put the good values back.
5. In **VI**, enter Gravida `1`, Para `1`, T `1`, P `0`, A `0`, L `1`, type
   `Normal spontaneous delivery` and choose **Yes** for Pregnancy Induced
   Hypertension.
6. In the **Pertinent Physical Examination**, tick **Essentially Normal** under
   **A. HEENT**, tick **Not Applicable** under **F. DIGITAL RECTAL EXAMINATION
   (DRE)** and type `Deferred` in that group's **Others** line. Tick both
   *Essentially Normal* **and** a finding under **G. SKIN & EXTREMITIES**. →
   **Expect:** both stay ticked — the form allows it, so the screen does too.
7. Pick **Fit**, enter a respiratory rate and press **Save & Close**. →
   **Expect:** back on the Live Queue, row gone.
8. Reopen that visit from the encode history. → **Expect:** all three cards are
   **read-only** and still show every value: `13`, `5`, `0`, `28`, `None`, the
   LMP, Menopause **No**, the six OB counts, `Normal spontaneous delivery`,
   PIH **Yes**, and the exam ticks including `Deferred`. Nothing is editable.

### Part B — a male Assessment encode

9. Encode the **male** student's visit. → **Expect:** **V. Menstrual History**
   and **VI. OB/Pregnancy History** are visibly **greyed out**, each headed
   with the note **"For female students only"**, and every box inside them
   refuses to be clicked or typed in. **Past Surgical History** above them is
   **not** greyed — it is open to everyone.
10. The **Pertinent Physical Examination** is at full opacity: tick
    **Essentially Normal** under **H. NEUROLOGICAL EXAMINATION** and type a
    note in **F. DRE**'s Others line. → **Expect:** both work normally.
11. Save it as **Fit**, then reopen it. → **Expect:** the examination comes
    back filled in and read-only, while V and VI are still greyed and empty —
    the server stores nothing there for a male student.

### Part C — phone width

12. Narrow the browser to a phone width (or open the encode screen on a phone)
    on the female visit. → **Expect:** the eight examination groups stack into
    a single column, V and VI stack to one field per row, nothing overflows
    sideways and no text is cut off.

**Pass criteria:** V and VI are usable for a female student and greyed for a
male one; out-of-range values and a future LMP are refused with the record
unsaved; the LMP pre-fills from the kiosk; the examination works for every
student, allows Normal *and* a finding together, and everything saved comes
back on the read-only view.

**Note for the tester:** the grey-out is only what you can see — the rule is on
the server. Even if the male student's boxes were forced open, the saved record
would still hold nothing for sections V and VI. Printing the Medical Assessment
Form itself is a later change (D-71).

---

---

---

## Recording results

For each scenario, record: **Pass / Fail / Blocked (not built)**, the tester
name, the date, and a note for any Fail. Feed FR-level pass/fail back into
`docs/qa/traceability-template.csv` (map each scenario step to its FR IDs).
