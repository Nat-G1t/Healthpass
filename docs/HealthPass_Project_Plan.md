# HealthPass — Project Plan & Technical Setup

**Capstone — Pampanga State University, College of Computing Studies**
**Target system completion: August 30, 2026** · Plan written June 8, 2026 (~12 weeks)

---

## 1. What we are building (revised scope)

HealthPass is a **web-based scheduling and digital medical-clearance system** for the PamSU college clinic, paired with a **self-service kiosk** that captures vital signs. It digitalizes how students obtain a **Medical Clearance** and book a **Dental appointment** at the campus clinic.

There is **no AI / predictive risk component**. The system schedules, captures, records, and prints. All clinical judgment (Fit/Unfit, case category) is made by clinic staff.

> **Important — paper vs. system mismatch:** Your capstone document still describes an "AI-Powered Health Screening Kiosk with Predictive Risk Profiling" (Azure OpenAI GPT-4o, pseudonymization, ISO eval of an AI feature). The system you're actually building no longer has that. The paper will need a title/scope revision so the document and the defended system match. This plan covers the **build only** — flag the paper revision to the documentation team (David / Dela Cruz / Fabian) as a separate workstream.

### The four roles
| Role | What they do |
|---|---|
| **Student** | Register, link student ID (QR), book Medical Clearance / Dental appointments, view clearance history & records, manage profile. |
| **College Admin** | *Scoped to their own college.* Submit batch clearance requests (select students + reason), track batch status. |
| **Nurse** | Live queue of kiosk submissions, review flagged vitals, encode the result (Fit/Unfit + case category + purpose + notes), print the official Medical Clearance form, launch kiosk mode. |
| **Clinic Director** | Approve/reject college batch requests, view the "Summary of Medical Cases" analytics (case category × college, by sex), review flagged anomalies. |

### Core data flow
`Student books appointment` → arrives at clinic → `Kiosk: scan ID → consent → capture 5 vitals → 9-system questionnaire → submit` → `Nurse live queue` → `Nurse encodes Fit/Unfit + category` → `Print Medical Clearance` → `Director sees it in analytics`.

---

## 2. System architecture

A **single unified Laravel application** (no separate API/service). The kiosk is just another route in the same app, run full-screen in Chromium on the Raspberry Pi. This keeps everything in PHP/Blade — aligned with the team's skills — and removes any cross-system token/auth complexity.

```
                 ┌──────────────────────────────────────────────┐
                 │           HealthPass — Laravel app            │
                 │      (Blade + Controllers + MySQL/Eloquent)   │
                 │                                                │
   Browsers ───► │  /login  /student/*  /admin/*  /nurse/*       │
 (staff +        │  /director/*                                  │
  students)      │                                                │
                 │  /kiosk  ◄── runs full-screen in Chromium      │
                 │             on the Raspberry Pi (kiosk mode)   │
                 └───────────────┬────────────────────────────────┘
                                 │
                                 │  Web Serial API (in the kiosk page JS)
                                 ▼
                 ┌──────────────────────────────────────────────┐
                 │  Microcontroller (Arduino / ESP32) over USB    │
                 │  ── streams a combined vitals reading ──►      │
                 │  Sensors: height (ultrasonic), weight (load    │
                 │  cell + HX711), temp (IR), BP + heart rate     │
                 │  (BP monitor with serial output)               │
                 └──────────────────────────────────────────────┘
```

### Why a microcontroller sits between the sensors and the browser
The **Web Serial API reads USB-serial devices**, not the Pi's GPIO pins directly. So the natural pattern is: sensors wired to an Arduino/ESP32 → the MCU formats one reading line (e.g. `H:163;W:64;T:37.9;BP:145/92;HR:78`) → sends it over USB → the kiosk page reads and parses that line via Web Serial. **Baldo confirms the exact wiring and which devices expose serial** — the BP monitor is the one to validate first (many consumer units don't expose a serial port).

### Kiosk runtime + Web Serial constraint (read this carefully — it shapes deployment)
Web Serial only works in a **secure context**: `https://` **or** `http://localhost`. Practical consequence:

- **Cleanest setup:** run the Laravel app **on the Pi itself**, so the kiosk Chromium opens `http://localhost/kiosk` (localhost = secure → Web Serial works, no TLS needed). The MCU plugs into that same Pi via USB. Other clinic staff hit the same app over the LAN at the Pi's IP for the web side (they don't need Web Serial).
- **If the server is a separate machine:** the Pi opening `http://server-ip/kiosk` will have Web Serial **blocked**. You'd need HTTPS (even a self-signed cert) on that server. Avoid this for the demo unless you have a reason.
- During XAMPP development on your laptop, everything is `localhost`, so Web Serial testing works there too (with the MCU plugged into the laptop).

### Recommended tech stack
| Concern | Choice | Note |
|---|---|---|
| Framework | **Laravel 11/12** | Needs PHP 8.2+. |
| Views | **Blade** + Blade components | Mirror the prototype's `HPButton`, `HPCard`, etc. as Blade components. |
| DB | **MySQL** (via XAMPP) | phpMyAdmin for inspection. |
| Auth | **Laravel Breeze** (Blade stack) | Gives login/register scaffolding to extend; gentle for beginners. |
| Styling | **CSS variables matching the prototype** (`--orange:#FF8C2A` etc.) | The prototype is already working HTML/CSS — lift its tokens directly. Tailwind optional. Font: **Poppins**. |
| Charts | **Chart.js** | Director's grouped bar + sex donut. |
| Clearance output | **Print-friendly Blade view + `window.print()`** first | Matches the prototype exactly, zero new dependencies. Upgrade to **dompdf** (`barryvdh/laravel-dompdf`) if you need a saved PDF file. |
| QR — kiosk ID login | **USB keyboard-wedge scanner** | "Types" the ID's multi-line QR text + Enter at the kiosk Welcome screen; far more reliable for unattended use. Kiosk normalizes the payload: extracts `IDNo:` line's value if present, otherwise uses the full string (keeps phone-QR backup working). |
| QR — generation | **`simplesoftwareio/simple-qrcode`** | Generates each student's kiosk QR from `qr_token` for the My ID screen. |
| QR — registration capture | **`html5-qrcode`** (client-side JS, npm) | In-browser camera or uploaded ID photo, decoded on the student's own device; `IDNo` extracted and matched to `student_number` before POSTing. Students register on personal devices — a USB scanner is not available there. |
| Live queue | **Polling** (JS fetch every 3–5s) | Real-time websockets are overkill for a single-clinic demo. |
| Mail (OTP) | `MAIL_MAILER=log` or **Mailtrap** locally | Real SMTP only at deployment. |
| Version control | **Git + GitHub**, feature branches + PRs | Critical with 2 programmers — see §7. |

---

## 3. Database schema

> **Superseded — do not build from this section.** The schema drafted here on June 8 was finalized on June 10–11 into the canonical **10-table ERD**. The authoritative column-level schema is `HealthPass_Context.md` §8 (including `batch_requests.scheduled_date` and `vital_signs.entry_method`), summarized in `HealthPass_PRD.md` §6. Build all migrations from there, in the FK-safe order given in PRD §6.3.

The 10 domain tables: `colleges`, `users`, `student_profiles`, `appointments`, `batch_requests`, `batch_request_students`, `clinic_visits`, `vital_signs`, `screening_responses`, `clearance_records`.

**Flagging is rule-based** (no AI). Canonical thresholds (PRD §5.4, Decision D-10): temperature > 37.2 °C → fever; **systolic ≥ 140 OR diastolic ≥ 90 → high BP**; BMI ≥ 30.0 → abnormal. All thresholds live in one place: `config/healthpass.php`.

---

## 4. The 12-week plan (Jun 8 → Aug 30)

Two parallel tracks: **Nat = Laravel application**, **Baldo = hardware + Web Serial**, meeting at the kiosk integration milestone. The hardware spike is front-loaded because it's the biggest unknown and everything kiosk-related depends on it.

| Week | Dates | Application track (Nat) | Hardware track (Baldo) |
|---|---|---|---|
| **W1** | Jun 8–14 | Env setup (XAMPP, Laravel, Git repo, Breeze). Finalize DB schema + migrations. Build 1 vertical slice end-to-end (login → student dashboard) to *learn the stack*. | **Web Serial spike:** get **one** sensor reading from an Arduino/ESP32 into a Chromium page on the Pi via Web Serial. Confirm BP monitor serial output is possible. **Buy hardware only after this works.** |
| **W2** | Jun 15–21 | Auth + roles + middleware (role-scoped routes). Student **registration** flow (consent → personal info → email OTP → QR link). | Wire + read **height + weight** sensors; compute BMI; settle the combined serial message format. |
| **W3** | Jun 22–28 | Student **booking** (medical/dental, calendar, clinic hours) + **records** + **profile** (with generated QR). | Add **temperature** sensor; reliability testing of readings (repeatability, noise). |
| **W4** | Jun 29–Jul 5 | Build the **kiosk route UI** (port the prototype screens to Blade: welcome → identity → consent → vitals → questionnaire → review → submit). | Add **BP + heart rate**; finalize full combined reading. Hand off message format to Nat. |
| **W5** | Jul 6–12 | **Kiosk ↔ Web Serial integration** (parse the live reading into the vital steps) + **QR/USB-scanner login** + submit-to-queue writes an encounter. | On-Pi integration: full sensor rig + MCU running against the real kiosk page in **Chromium kiosk mode**. |
| **W6** | Jul 13–19 | **Nurse live queue** (polling) + **flagging** of out-of-range vitals. | Kiosk-mode reliability: does Web Serial reconnect cleanly, survive idle/reset, work unattended? Document quirks. |
| **W7** | Jul 20–26 | **Nurse encode** (Fit/Unfit + category + purpose + notes) + **print Medical Clearance** (Blade print view matching the official form). | Build the physical kiosk enclosure / mounting; cable management; power. |
| **W8** | Jul 27–Aug 2 | **College Admin** batch workflow (new request + select students + tracking, college-scoped). | Joint hardware + software dry-run #1; fix integration issues. |
| **W9** | Aug 3–9 | **Director** batch approvals + start **analytics** (Summary of Medical Cases matrix + by-sex donut). | Support analytics data needs; continue reliability testing. |
| **W10** | Aug 10–16 | Finish **analytics** (grouped bar chart, flagged anomalies, export) + seed realistic test data. | Final hardware hardening; spare parts on hand for demo. |
| **W11** | Aug 17–23 | **Feature freeze.** Full integration testing, ISO/IEC 25010 evaluation runs, bug fixing. | Full clinic-floor rehearsal of the kiosk under realistic conditions. |
| **W12** | Aug 24–30 | Polish, finalize documentation, **deployment dry-run**, defense rehearsal. System done by **Fri Aug 28**, buffer to Aug 30. | Defense-day hardware checklist + backup plan. |

**Non-programmer team (David, Dela Cruz, Fabian, Pamintuan, Sebastian):** run documentation (incl. the paper title/scope revision), test-case writing + UAT from ~W6, ISO 25010 evaluation instrument prep, and clinic coordination/consent. Adviser check-ins (Andrei Viscayno) at the end of W2 / W5 / W8 / W11.

> This can be framed as Agile sprints to match your paper: W1–3, W4–6, W7–9, W10–12 = four sprints.

---

## 5. Local technical setup (XAMPP — do this in Week 1)

1. **Install XAMPP** (Apache + MySQL + PHP **8.2+**). You'll mainly use its **MySQL + phpMyAdmin**; Laravel's own dev server is easier than configuring Apache vhosts.
2. **Install Composer** (PHP package manager) and **Node.js LTS** (for Vite assets).
3. **Create the project:**
   ```bash
   composer create-project laravel/laravel healthpass
   cd healthpass
   composer require laravel/breeze --dev
   php artisan breeze:install blade
   npm install && npm run build
   ```
4. **Create the database:** start MySQL in XAMPP → open phpMyAdmin → create a database `healthpass`.
5. **Configure `.env`** (XAMPP MySQL defaults):
   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=healthpass
   DB_USERNAME=root
   DB_PASSWORD=
   MAIL_MAILER=log     # OTP emails land in storage/logs during dev
   ```
6. **Migrate & run:**
   ```bash
   php artisan migrate
   php artisan serve --port=8080      # http://127.0.0.1:8080
   npm run dev                        # in a second terminal
   ```
7. **Git from day one:** create a GitHub repo, both programmers clone it, work on **feature branches**, merge via **pull requests**. (No more passing `.docx`/laptop files around — that's how work gets lost.)
8. **Kiosk testing on the laptop:** open `http://127.0.0.1:8080/kiosk` in Chrome with the MCU plugged in — Web Serial works on localhost.

---

## 6. Deployment (later — after the local build is stable)

Keep this for after the system works on XAMPP. Options, simplest first: **shared hosting** with PHP 8.2+/MySQL, a **VPS** (DigitalOcean/Linode), or a **PaaS** (Railway/Render). Whatever you pick:

- **Production must be HTTPS** if the kiosk points at it (Web Serial requirement). The simplest reliable path remains **running the app on the Pi at `localhost`** so the kiosk needs no TLS, with the web side served over the campus LAN.
- Still a **data-privacy / RA 10173** system even without AI — you handle student health data. Keep the consent screens (already in the prototype), restrict access by role, and don't expose health data publicly. No API pseudonymization needed anymore (nothing leaves your server).

---

## 7. Honest risk assessment

- **Hardware + Web Serial is the #1 risk.** Unattended kiosk-mode permission persistence, BP-monitor serial output, and sensor reliability are all unproven. The Week-1 spike exists to de-risk this *before* you buy parts or build dependent features. **Strong recommendation:** even though you chose real sensors, build a small **manual-entry override on each vital step** as a safety net so a flaky sensor on defense day can't kill the demo. It's cheap insurance and the panel will see it as good engineering.
- **Full scope + 2 Laravel-beginners + a hardware track in 12 weeks is aggressive.** There's little slack. Mitigations baked into the plan: a learning vertical-slice in W1, weekly working checkpoints, and a hard **feature freeze in W11**. If something must slip, let it be the **batch workflow** (admin → director approvals) — it's the least essential to the core clearance loop.
- **Browser support:** Web Serial is **Chromium-only** (Chrome/Edge). Fine for the kiosk; just don't promise Firefox/Safari support.
- **Learning curve:** build one feature fully end-to-end first (migration → model → controller → Blade) before parallelizing, so both of you understand the Laravel request lifecycle. Pair on the kiosk integration.

---

## 8. Assumptions (all since confirmed and locked — see PRD §14 Decisions Log)

1. **No separate Doctor login** — the nurse encodes the "Doctor's Assessment"; the University Physician's name appears on the printed form only. (Locked as Decision D-2; adding a Doctor role requires team + adviser sign-off.)
2. **Sensors connect via a microcontroller over USB**, read by the kiosk through Web Serial (not direct Pi GPIO). Baldo confirms.
3. **Dental = scheduling only** — book a dental appointment; no kiosk vitals or special result screen for dental (only Medical Clearance goes through the full kiosk → encode → print loop).
4. **USB QR scanner** for kiosk ID login (camera decode is the fallback).
5. **Local mail uses the log/Mailtrap driver**; real SMTP only at deployment.
6. **Single clinic** (PamSU campus clinic), one kiosk.

---

## 9. Concrete first steps this week (W1)

1. Nat: install XAMPP + Composer + Node, scaffold the Laravel project with Breeze, create the GitHub repo, write the migrations from §3.
2. Baldo: run the **Web Serial spike** — one sensor → Arduino/ESP32 → Chromium on the Pi. Do **not** buy the full sensor set until a reading shows up in the browser and the BP monitor's serial output is confirmed.
3. Both: agree on the serial message format (e.g. `H:163;W:64;T:37.9;BP:145/92;HR:78`) so the application and hardware tracks can move independently.
4. Documentation team: start the paper title/scope revision (remove AI/predictive-risk framing).
