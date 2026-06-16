# HealthPass — Day-by-Day Vibe-Coding Plan (Claude Code Edition)

**For:** Nat (Lead Dev — Web App) · **Timeline:** Day 3 (Thu, Jun 11) → Day 83 (Sun, Aug 30, 2026) · **Cadence:** 7 days/week, hybrid pacing
**Source of truth:** `docs/HealthPass_PRD.md ` (HealthPass PRD v1.0) — every prompt below references it by section so you never paste requirements again.

---

## 0. How This Plan Works (basahin mo muna 'to)

### 0.1 Daily session rhythm (Pro plan, max 3 sessions/day)

| Session | Purpose |
|---|---|
| **Session 1** | Main build of the day. Biggest, most focused prompt. |
| **Session 2** | Second build task, OR continuation of Session 1 kung hindi natapos. |
| **Session 3** | **Always reserved as buffer.** Testing, bug fixes, polish. **Never start a new feature here.** |

Sa **Light** days, expect na 1 session lang gagamitin mo. Sa **Heavy** days, budget the full 2 build sessions and start early.

### 0.2 Plan limits reality check

- Pro gives you a **5-hour rolling session window** PLUS a **weekly usage cap shared across Claude chat and Claude Code**. Check `Settings > Usage` sa claude.ai or run `/usage` inside Claude Code. (Ref: https://support.claude.com/en/articles/8325606-what-is-the-pro-plan)
- Usage per prompt scales with **how much code Claude Code reads/writes** — kaya the rules below exist.
- Dahil 7 days/week ka, **the weekly cap is your real enemy**, hindi yung 5-hour window. This plan deliberately caps each week at ~2 Heavy days and keeps flex days light. **Huwag mong i-max ang 3 sessions every day "just because" — mauubos ang weekly allowance mo bago matapos ang linggo.**
- If you hit a limit mid-task: tell Claude Code to **commit progress with a WIP message**, then resume next window with the RESUME template (§0.6).

### 0.3 Token-saving rules (sundin 'to religiously)

1. `CLAUDE.md` + `docs/HealthPass_PRD.md ` live **in the repo** — Claude Code reads them itself. Never paste requirements into prompts.
2. `/clear` between unrelated tasks. Long conversations silently burn your window.
3. **One feature per prompt.** Never "and also...".
4. On Heavy days: prompts say **"Plan first, wait for my GO."** Review the plan (cheap), then approve (expensive part starts).
5. Paste only the **error message**, not the whole log/file.
6. Don't ask it to "review the whole codebase" casually — that's a whole session gone.

### 0.4 Supervision rules (the "vibe code WITH my supervision" part)

- **Never mark a day done without running the day's ✅ Verify list yourself** in the browser. Claude Code saying "done" is not done.
- Skim the diffs of at least the **controller + migration** files each day (`git diff` or GitHub PR view).
- The weekly **walkthrough day is non-negotiable** — sa defense, ikaw ang tatanungin ng panel, hindi si Claude. Yan ang learning insurance mo.
- Commit per working feature; **merge to main only after the Verify list passes.**

### 0.5 Hybrid pacing protocol

- **Weekly anchors** (each week's exit criteria, same as PRD §12) are the schedule of record.
- **Ahead?** Pull the next build day forward into a flex slot. Never skip Verify lists to go faster.
- **Behind?** Flex days absorb 1–2 day slips. If a full week slips, cut from the **designated slip candidate: batch workflow (W8–W9)** — never the kiosk → queue → encode → print loop.
- **Flex days hindi required gumamit ng sessions.** Resting a flex day = banking weekly allowance for the next Heavy day.

### 0.6 Reusable prompt templates

**BUGFIX** (use in Session 3 / freeze weeks):
```text
Bug: [what happened]. Steps to reproduce: [steps]. Expected: [x]. Actual/error: [paste exact error].
Find the root cause first and explain it in 2–3 sentences BEFORE changing anything.
Then fix it with the smallest possible change. Do not touch unrelated code.
```

**RESUME** (after hitting a limit mid-task):
```text
Continue the Day [N] task: "[task title]". Run git status and read the last 3 commits to see
where we stopped. Summarize done vs pending from the original scope, then finish ONLY the
pending items.
```

**WALKTHROUGH** (weekly learning day):
```text
Walk me through what we built this week, file by file, as if I'm new to Laravel (Taglish ok).
For each file: what it does, how a request flows through it, and one thing that could break.
End with a 5-question quiz for me. Do not change any code.
```

### 0.7 ⚠️ Prep checklist — gawin BAGO ang Day 3 Session 1

- [x] `CLAUDE.md` at repo root — DONE (detailed committed version; points to
  docs/HealthPass_PRD.md, docs/HealthPass_Context.md, docs/HealthPass_Project_Plan.md).
- [x] Move the three docs into `docs/`: `docs/HealthPass_PRD.md`,
  `docs/HealthPass_Context.md`, `docs/HealthPass_Project_Plan.md`.
- [x] The two Claude Design HTML prototypes are in `docs/prototypes/` (flat — both HTML
  files in that one folder). Filenames should clearly say which is web app vs kiosk.
- [x] `git add docs && git commit -m "docs: PRD, context, plan + design prototypes"`
- [ ] Schedule: get a **scan/photo of the official form DHVSU-QSP-OSS-004-FO002-R03** from
  the clinic team — needed by **Day 44 (Jul 22)** for the print template.

### 0.8 Day entry legend

`[Heavy]` ≈ 2 full build sessions · `[Medium]` ≈ 1–2 sessions · `[Light]` ≈ 1 session or less. Session 3 buffer applies every day and isn't restated.

### 0.9 Skill legend

Skills listed before a session prompt must be invoked **before sending the prompt** — type the `/skill-name` command, wait for it to load, then paste the prompt. This loads the relevant context and constraints into Claude Code so the output is more precise.

| Skill | When to invoke |
|---|---|
| `/laravel-patterns` | Any session building models, relationships, service layers, route groups, or complex Eloquent queries |
| `/laravel-security` | Any session implementing auth guards, middleware, role policies, CSRF, or access scoping |
| `/laravel-tdd` | Any session explicitly writing PHPUnit/Pest feature or unit tests |
| `/laravel-verification` | Before any weekly `git tag` — runs a full env/lint/test/security sweep |
| `/security-review` | Dedicated security audit sessions (adversarial sweeps, not just test writing) |
| `/run` | Sessions whose goal is visual browser verification, not code correctness |

---

# WEEK 1 — Foundation (Jun 8–14)
**Anchor (exit criteria):** all 10 migrations done + vertical slice login → student dashboard. **Baldo:** Web Serial spike verdict due — purchases gated on it.
*(Days 1–2: env setup — done na.)*

---

### Day 3 — Thu, Jun 11 — Project brain + database schema `[Heavy]`

**Ano'ng mangyayari:** Ito ang pinaka-importanteng araw ng buong plan. Bubuuin natin ang `CLAUDE.md` — yung persistent na "utak" ng repo na babasahin ni Claude Code sa bawat session — plus ang config file at lahat ng 10 migrations. Pag maayos ito, lahat ng susunod na prompts magiging maikli at mura.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  fully — it is the source of truth for this project. The two HTML design
prototypes in docs/prototypes/ are the visual source of truth.

Create a CLAUDE.md at the repo root containing:
1. PROJECT: HealthPass — Laravel 12 + Breeze (Blade) + Tailwind + MySQL clinic scheduling +
   digital medical clearance system for PamSU. NO AI features anywhere. Exactly 4 roles:
   student, college_admin, nurse, director.
2. ENVIRONMENT: Windows + XAMPP, project at C:\Capstone\healthpass. DB host must be 127.0.0.1
   (never "localhost"). Dev servers: `php artisan serve --port=8080` and `npm run dev` in
   parallel terminals.
3. DESIGN TOKENS: --white #FFFFFF, --bg #F6F2ED, --peach #FFCAA0, --orange #FF8C2A,
   --slate #4B5563; font Poppins 400/500/600/700. Shared Blade components (HPButton, HPBadge,
   HPCard, HPInput, HPSelect, HPTextarea, HPLogo, SidebarLayout) — always reuse, never
   restyle ad hoc. Prototypes live in docs/prototypes/.
4. CONVENTIONS: all thresholds/capacity/hours read from config/healthpass.php — never
   hardcoded. Reference numbers only via App\Services\ReferenceNumberService
   (APT-YYYY-####, BR-YYYY-###, HP-YYYY-####). DB transactions around batch approval and
   kiosk submit. Role middleware on all route groups. College Admin queries always scoped
   server-side by managed_college_id.
5. WORKFLOW RULES: one feature branch per task (feat/dayN-name). On multi-file tasks, plan
   first and wait for my GO. Never run migrate:fresh or any destructive command without
   asking. Build only what the prompt asks — no extra features. Commit with clear messages
   when I confirm a task works.
6. PRD MAP: §4 functional requirements by module, §5 business rules, §6 schema, §7 NFRs,
   §8 UI spec, §11 hardware/serial contract, §14 locked decisions.

Then create config/healthpass.php with: daily_capacity (placeholder 40 — clinic staff will
set the real value), clinic hours (Mon–Fri 07:00–17:00), vital flag thresholds per PRD §5.4
/ D-10 (temp > 37.2; BP systolic >= 140 OR diastolic >= 90; BMI >= 30.0), vital validation
ranges from FR-KSK-08, and kiosk timings (complete-screen reset 12s, idle timeout 90s).
Show me both files when done.
```

**Skill to invoke:** `/laravel-patterns`
**Session 2 prompt:**
```text
Read docs/HealthPass_PRD.md  §6 (Data Requirements). Create all 10 domain migrations in the FK-safe
order from §6.3: colleges, users (extend Breeze's users with role enum, nullable
managed_college_id FK, status), student_profiles, batch_requests, appointments,
batch_request_students, clinic_visits, vital_signs, screening_responses, clearance_records.

Match the §6.2 data dictionary exactly, including: the two locked deltas
(batch_requests.scheduled_date DATE NULL; vital_signs.entry_method
ENUM('sensor','manual','mixed') DEFAULT 'sensor'); unique constraints (all reference_no
columns, qr_token, student_number, UQ(batch_request_id, student_id), the 1:1 unique
clinic_visit_id columns); and the §6.4 indexes (appointments scheduled_date+status;
clinic_visits status+created_at). Use restrictive FK delete rules so users with clinical
records can't be deleted.

Plan the full column list per table first, show me, wait for my GO, then implement and run
php artisan migrate (ask before any fresh).
```

**✅ Verify:** `php artisan migrate:fresh` runs clean (GO it yourself — dev data lang); all 10 tables + columns visible sa phpMyAdmin; basahin mo ang CLAUDE.md — dapat naiintindihan mo lahat ng nakasulat doon.

---

### Day 4 — Fri, Jun 12 — Models, relationships, seeders `[Medium]`

**Ano'ng mangyayari:** Eloquent models with all relationships, the reference-number generator (shared ng halos lahat ng modules), at seeders para may makakalog-in ka agad sa lahat ng roles plus demo students.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  §6.2. Create the 10 Eloquent models: correct fillable/guarded, casts
(dates, booleans), and ALL relationships in both directions (e.g., College hasMany
users/studentProfiles; User hasOne studentProfile + hasMany appointments; ClinicVisit
belongsTo student/appointment + hasOne vitalSigns/screeningResponse/clearanceRecord;
BatchRequest belongsTo college/requester/reviewer + hasMany batchRequestStudents; etc.).

Then create App\Services\ReferenceNumberService per §5.6: generates APT-YYYY-####,
BR-YYYY-###, HP-YYYY-#### — per-year sequence, zero-padded, unique, concurrency-safe
(transaction + retry on unique violation). Add tinker examples for testing relationships
into docs/dev-notes.md. Plan first, wait for GO.
```

**Session 2 prompt:**
```text
Create the seeders per docs/HealthPass_PRD.md  §6.5: (1) the 12 colleges from §2.5 (codes + full names);
(2) staff users — 1 director, 1 nurse, 12 college admins each with the correct
managed_college_id — password "password"; (3) student factory + seeder: 30 demo students
spread across the colleges with complete student_profiles (realistic PH names, student
numbers like 2022300123, sex mix, courses appropriate per college, DOB, unique random
qr_token, privacy_consent_at set). Wire into DatabaseSeeder.

Run php artisan migrate:fresh --seed (GO — dev data only), then write the full credential
list (role / email / password) into docs/dev-notes.md and show it to me.
```

**✅ Verify:** `migrate:fresh --seed` clean; tinker spot-check 2–3 relationships (e.g. `College::first()->studentProfiles`); credentials list saved.

---

### Day 5 — Sat, Jun 13 — RBAC + design system components `[Heavy]`

**Ano'ng mangyayari:** Role middleware + route skeleton para sa apat na roles, tapos ang buong HP component library + restyled login. Pagkatapos nito, lahat ng screens ay "assembly ng components" na lang — bilis na ng builds.

**Skill to invoke:** `/laravel-security`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  §4.1 (Module AUTH). Implement:
1. EnsureRole middleware (role parameter), applied to four route groups: /student, /admin,
   /nurse, /director. Out-of-role requests redirect to the user's own dashboard with an
   error flash (FR-AUTH-03).
2. Post-login redirect per role (FR-AUTH-04): student→/student/dashboard,
   college_admin→/admin/dashboard, nurse→/nurse/queue, director→/director/dashboard —
   with placeholder Blade views for each.
3. Inactive users blocked at login with a clear message (FR-AUTH-07).
4. Confirm no staff registration path exists — public registration creates students only
   (FR-AUTH-05).
Write quick manual test steps for me at the end. Plan first, wait for GO.
```

**Skill to invoke:** `/laravel-patterns`
**Session 2 prompt:**
```text
Read docs/HealthPass_PRD.md  §8.1–8.2 and the styling in docs/prototypes/web. Build the design system:
1. A CSS token layer (the 5 CSS variables + Poppins import + 5px slate scrollbars).
2. Blade components: x-hp.button (variants primary/ghost/soft/muted; sizes sm–xl; pill
   radius; disabled 0.5 opacity), x-hp.badge (all variants listed in §8.2), x-hp.card,
   x-hp.input (optional password eye toggle), x-hp.select, x-hp.textarea, x-hp.logo
   (orange plus-cross + Health/Pass wordmark), x-layout.sidebar (220px sidebar with
   role-specific nav slot, 56px header, scrollable main on #F6F2ED).
3. A dev-only route /dev/components rendering every component variant on one page.
4. Restyle the Breeze login page per FR-AUTH-08: centered 420px column, HPLogo + tagline,
   email + password with eye toggle, "Register here" link, RA 10173 footer note.
Use the components everywhere — no raw duplicated HTML. Plan first, GO, then build.
```

**✅ Verify:** Log in as all 4 seeded roles → tamang dashboard placeholder bawat isa; student manually typing `/nurse/queue` → bounced; `/dev/components` page vs prototype — colors, pills, at fonts dapat mukhang kapareho; login page side-by-side vs prototype.

---

### Day 6 — Sun, Jun 14 — Vertical slice + Week 1 review `[Light]`

**Ano'ng mangyayari:** Student Dashboard (empty states muna), then weekly wrap: verify the W1 anchor, tag, and do your first walkthrough. **Baldo sync:** spike verdict due today — kung hindi pa validated ang Web Serial + BP monitor serial, escalate sa team bago bumili ng hardware.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-STU-01 and the student dashboard in docs/prototypes/web. Build
/student/dashboard using x-layout.sidebar with student nav (Dashboard, Book Appointment,
My Records, My ID & Profile — only Dashboard live, rest are stub routes):
- Three stat cards: Clearance Status (latest result badge, or "No clearance yet" empty
  state + "Book New Appointment" button), Next Appointment (or "No upcoming appointment"),
  Past Clearances (count + "View all" stub).
- Recent Activity timeline (clean empty state for a fresh student).
Query real data via Eloquent so the cards light up automatically once later modules exist.
Pixel-faithful to the prototype: HPCards on #F6F2ED, Poppins, peach/orange accents.
```

**Session 2:** WALKTHROUGH template (§0.6) covering migrations → models → middleware → components.

**✅ Verify:** W1 anchor met — login lands on a styled student dashboard; 10 tables exist. Run `/laravel-verification` before tagging. `git tag w1-done`. Kayang i-demo sa adviser check-in.

---

# WEEK 2 — Registration & Auth Hardening (Jun 15–21)
**Anchor:** register + log in as all roles. **Milestone:** adviser check-in with A. Viscayno end of this week. **Team:** documentation team starts the paper title/scope revision (Risk R-6) — remind them today.

---

### Day 7 — Mon, Jun 15 — Wizard shell + Steps 1–2 `[Medium]`

**Ano'ng mangyayari:** Papalitan ang default Breeze register ng 4-step wizard. Today: consent gate + ang mahabang personal info form with full validation. Walang user record na malilikha hangga't hindi verified ang email (mamayang Day 8 yun).

**Skill to invoke:** `/laravel-patterns` + `/laravel-security`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  §4.2 (FR-REG-01..03) and the register wizard in docs/prototypes/web.
Replace Breeze's register page with a 4-step wizard (Consent → Account Info → Email Verify
→ Link ID) with a top progress bar. Build Steps 1–2 today:

Step 1 (Consent): RA 10173 data-privacy notice; Continue disabled until the checkbox is
ticked; acceptance held server-side (session) — the final timestamp is written to
student_profiles.privacy_consent_at when the account is created after OTP.

Step 2 (Personal Information): every field in FR-REG-03 — First Name, Middle Name
(optional), Last Name, Student Number (unique), College (dropdown from DB), Sex (M/F),
Course & Year, Date of Birth with an auto-computed Age badge (JS), Place of Birth, Civil
Status (Single/Married/Widowed/Separated), Address, Email (unique), Password. Full
server-side validation with
field-level errors.

CRITICAL (FR-REG-08): no login-capable users row may exist before email verification —
stage the validated Step 2 data server-side and stop at a "Step 3" placeholder. Use HP
components. Plan first, GO.
```

**Session 2:** continuation/buffer — istyle pa vs prototype, validation edge cases.

**✅ Verify:** Consent checkbox gates Continue; duplicate email/student number → field error; abandoning after Step 2 → walang user sa DB; Age badge tama.

---

### Day 8 — Tue, Jun 16 — Step 3: Email OTP `[Medium]`

**Ano'ng mangyayari:** Yung 6-digit OTP verification (Decision D-8: hashed sa cache, 10-min TTL, walang OTP table). Dito pa lang malilikha ang totoong account.

**Skill to invoke:** `/laravel-security`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-REG-04/05 and Decision D-8. Build wizard Step 3 (Email Verify):
- Generate a 6-digit OTP server-side; store ONLY a hash in cache with a 10-minute TTL,
  keyed to the pending registration. Send via mail (set MAIL_MAILER=log for dev).
- UI: six auto-advancing input boxes; Resend link (resend invalidates the previous code);
  Verify & Continue disabled until 6 digits entered.
- Rate limit: max 5 verify attempts per code, then the code is invalidated.
- On success, inside one transaction: create the users row (role student,
  email_verified_at now) + student_profiles row (including middle_name, privacy_consent_at
  from Step 1 and a server-generated unique qr_token), then advance to a Step 4 placeholder.
Tell me exactly where to read the OTP in storage/logs/laravel.log for testing.
```

**Session 2:** buffer — test OTP flow end-to-end, expiry, resend, 5-strike lockout.

**✅ Verify:** OTP nasa log file at gumagana; maling OTP 5× → invalidated; resend → lumang code ayaw na; account + profile created only after success.

---

### Day 9 — Wed, Jun 17 — Step 4: QR linking + full wizard E2E `[Medium]`

**Ano'ng mangyayari:** ID linking via USB keyboard-wedge scanner (or skip), auto-login, tapos full end-to-end run ng buong wizard.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-REG-06/07. Build wizard Step 4 (Link ID):
- A clearly-focused input that receives a USB QR scanner's keyboard-wedge output (string +
  Enter). The scanned string becomes/binds student_profiles.qr_token for this student —
  must be unique; reject with a clear message if already bound to another student.
  (Note: account creation in Step 3 already generated a provisional qr_token; scanning
  REPLACES it with the physical ID's QR payload so the kiosk recognizes the actual ID.)
- A "Skip for now" path — linking can be finished later from My ID (FR-STU-10).
- Either path logs the student in and routes to /student/dashboard (FR-REG-07).
Then run the whole 4-step wizard yourself with test data (simulate the scanner by typing a
string + Enter) and list any rough edges you find — fix only blockers.
```

**Session 2:** buffer/fixes from the E2E run.

**✅ Verify:** Buong wizard sa isang upo — consent → info → OTP from log → scan (type+Enter) → nakalanding sa dashboard, logged in; skip path works din; duplicate QR rejected.

---

### Day 10 — Thu, Jun 18 — Security pass + UI polish `[Medium]`

**Ano'ng mangyayari:** Negative-path security tests para sa auth + registration (foundation ng SM-5 evidence), then polish vs prototype.

**Skill to invoke:** `/laravel-tdd` + `/laravel-security`
**Session 1 prompt:**
```text
Security pass on auth + registration. Write Pest/PHPUnit feature tests in
tests/Feature/AuthSecurityTest.php (manual checks only where a test is impractical) for:
1. Role isolation: each of the 4 roles visiting the other 3 roles' route groups is
   rejected (FR-AUTH-03).
2. Duplicate student_number / email → field-level errors.
3. Abandoned registration (stopped before OTP success) cannot log in and left no users
   row (FR-REG-08).
4. OTP brute force locked after 5 attempts; resend invalidates the old code (FR-REG-05).
5. Inactive account refused with a clear message (FR-AUTH-07).
6. CSRF active on all POSTs.
Run the suite, fix anything that fails, and summarize what was verified and how. No new
features.
```

**Session 2 prompt:**
```text
Side-by-side polish: compare the live Login page and all 4 Register wizard steps against
docs/prototypes/web. Fix visual drift only — spacing, typography weights, badge/button
variants, progress bar styling, empty/disabled states, the RA 10173 footer. List every
fix you made per screen. Zero behavior changes.
```

**✅ Verify:** Test suite green; ikaw mismo subukang pasukin ang `/admin/*` bilang student (at vice versa); login/register mukhang kapareho na ng prototype.

---

### Day 11 — Fri, Jun 19 — FLEX DAY

**Protocol (§0.5):** Behind → tapusin ang W2 leftovers (RESUME/BUGFIX templates). On track → pull Day 14 (booking UI) forward. Sobrang on track → rest; bank the weekly allowance.

---

### Day 12 — Sat, Jun 20 — Walkthrough / learning day `[Light]`

**Ano'ng mangyayari:** Hindi build day. Ito yung defense insurance mo — kailangan kaya mong i-explain ang OTP flow, middleware, at wizard architecture nang walang Claude.

**Session 1:** WALKTHROUGH template (§0.6), scope: "this week's registration wizard + auth security tests."

**✅ Verify:** Kaya mong sagutin: saan naka-store ang OTP at bakit hashed? Paano pinipigilan ang staff registration? Anong nangyayari sa abandoned registration?

---

### Day 13 — Sun, Jun 21 — Weekly wrap + adviser check-in prep `[Light]`

**Ano'ng mangyayari:** Verify the W2 anchor, tag, prep the demo flow para kay Sir Viscayno: register a fresh student live → login as all 4 roles. **Team sync:** paper revision status (R-6) — i-report sa adviser.

**Session 1 (optional):** BUGFIX leftovers only. Run `/laravel-verification` then `git tag w2-done`.

**✅ Verify:** W2 anchor — register + login as all roles, demo-able in under 5 minutes.

---

# WEEK 3 — Student Portal (Jun 22–28)
**Anchor:** student can book against capacity rules. **Baldo:** temperature sensor + repeatability tests.

---

### Day 14 — Mon, Jun 22 — Booking UI: service picker + calendar `[Heavy]`

**Ano'ng mangyayari:** Ang booking calendar — isa sa mga pinaka-visible na screens sa defense. Frontend muna today, backend bukas.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-STU-02/03, BR-01/02, and the booking screen in docs/prototypes/web.
Build /student/book (frontend + read-only queries today):
- Service picker: Medical Clearance / Dental Check as selectable cards.
- Month calendar grid with prev/next navigation: weekends and past dates disabled; a day
  is greyed with a "FULL" label when its count of non-cancelled appointments >=
  config('healthpass.daily_capacity').
- "Confirm Booking" disabled until BOTH a service and a date are selected; clicking posts
  to a stub for now.
Match the prototype's calendar styling closely. Plan first, GO.
```

**Session 2:** continuation — calendar edge cases (month boundaries, today highlighting, FULL computation correctness).

**✅ Verify:** Weekends/past unclickable; pag-seed ng appointments hanggang capacity sa isang date via tinker → nagiging FULL ang araw; button gating works.

---

### Day 15 — Tue, Jun 23 — Booking backend + cancellation `[Medium]`

**Ano'ng mangyayari:** Ang totoong booking transaction with all the server-side guards, confirmation screen, at cancel flow.

**Skill to invoke:** `/laravel-patterns` + `/laravel-tdd`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-STU-04/05/06, BR-04, §5.6. Implement booking submission:
- Server-side validation: no weekends/past dates; capacity re-checked at write time;
  duplicate non-cancelled appointment for the same student+service+date rejected as a
  VALIDATION error, not a DB constraint (BR-04).
- In a transaction: create the appointments row (source=self, status=scheduled, reference
  via ReferenceNumberService) then show the confirmation screen: Service, Date, Clinic
  Hours (from config), Reference No.
- FR-STU-06: students can cancel their own scheduled future appointments (confirm dialog;
  status → cancelled).
Feature tests: full-day rejection, duplicate rejection, weekend rejection, successful
booking yields an APT-YYYY-#### reference.
```

**Session 2:** buffer — tests green, manual booking + cancel run.

**✅ Verify:** Book → confirmation may APT ref; ulitin ang same service+date → rejected; cancel → date frees up sa calendar.

---

### Day 16 — Wed, Jun 24 — Dashboard real data `[Medium]`

**Ano'ng mangyayari:** Bubuhayin ang Day 6 dashboard ng totoong data + activity timeline.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Wire /student/dashboard (FR-STU-01) to live data: Clearance Status = the student's latest
clearance_records result badge (empty state if none); Next Appointment = nearest upcoming
non-cancelled appointment (date, service, clinic hours from config); Past Clearances =
count + link to My Records; Recent Activity = this student's recent events (registered,
booked APT-…, cancelled APT-…, visit captured HP-…, result encoded) assembled from
existing tables, newest first, max 8 items. Empty states must look intentional. Keep
queries efficient (no N+1 — eager load).
```

**Session 2:** buffer.

**✅ Verify:** Mag-book → agad lumalabas sa Next Appointment + timeline; fresh student → malinis na empty states.

---

### Day 17 — Thu, Jun 25 — My Records + record modal `[Medium]`

**Ano'ng mangyayari:** Records list + detail modal. Wala pang kiosk, kaya magse-seed tayo ng fake visits para makita lahat ng states ngayon pa lang.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-STU-07/08 and the records screen in docs/prototypes/web. Build
/student/records: table of the student's clinic visits — Date, Service, Result badge
("Pending" while the visit is still captured), Reference No., View. View opens a modal:
left column = kiosk vitals (height, weight, BMI, temp, HR, BP) + case category badge if
encoded; right column = the 9 questionnaire answers as Yes/No badges (Yes = flagged
style). FR-STU-08: results appear ONLY after encoding.

Since the kiosk doesn't exist yet, extend the DEV seeder (clearly marked dev-only) with
~6 fake clinic_visits + vital_signs + screening_responses for two demo students — 3
encoded (with clearance_records, mixed Fit/Unfit + categories) and 3 still captured —
so every UI state is visible now.
```

**Session 2:** buffer.

**✅ Verify:** Encoded visit → modal kumpleto with result; captured visit → "Pending", walang Fit/Unfit na nakikita.

---

### Day 18 — Fri, Jun 26 — My ID & Profile `[Medium]`

**Ano'ng mangyayari:** QR display (ito ang ipapakita sa kiosk scanner), profile editing, at late QR-linking para sa nag-skip sa registration.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-STU-09/10. Install simplesoftwareio/simple-qrcode. Build
/student/profile:
- Left: the student's kiosk QR rendered from qr_token inside an HPCard with an Active
  badge. If qr_token isn't linked yet, show the linking flow instead (focused
  keyboard-wedge input + skip note), same rules as registration Step 4.
- Right: read-only profile fields + an Edit Profile modal updating ONLY: name, email,
  course, year, address, DOB, place of birth, civil status. Student number, college, and
  sex are locked (display-only). Validate email uniqueness on edit.
```

**Session 2:** buffer.

**✅ Verify:** QR renders; i-test mo with an actual phone QR reader — dapat mabasa ang qr_token string; locked fields talagang hindi ma-edit kahit i-tamper ang request.

---

### Day 19 — Sat, Jun 27 — FLEX / Student portal UAT `[Light]`

**Protocol:** Kung may leftovers → tapusin. Kung wala → ikaw mismo (o isang teammate na non-programmer) ang dumaan sa BUONG student journey: register → book → cancel → rebook → records → profile edit. Log every awkward moment; BUGFIX template for real bugs lang.

---

### Day 20 — Sun, Jun 28 — Weekly wrap `[Light]`

**✅ Verify W3 anchor:** student books against capacity rules, server-enforced. Run `/laravel-verification` before tagging. `git tag w3-done`. **Heads up:** bukas magsisimula ang kiosk — pinaka-mabigat na 2 linggo ng app track. Magpahinga ka today kung kaya.

---

# WEEK 4 — Kiosk UI, Manual-First (Jun 29–Jul 5)
**Anchor:** kiosk completable end-to-end on manual entry alone. **Baldo:** BP + HR added; full combined reading handed off.
**Design note:** lahat ng vitals steps ngayon ay manual-entry + a simulated-sensor dev path na EXACT same interface na gagamitin ng Web Serial sa W5 (Decision D-7: sensors are progressive enhancement).

---

### Day 21 — Mon, Jun 29 — Kiosk shell + Welcome `[Heavy]`

**Ano'ng mangyayari:** Foundation ng kiosk: 800×480 letterbox, screen state machine, at Welcome screen with the invisible scanner input. Ang architecture decisions today ang bubuhatin ng buong linggo — kaya plan-first talaga.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  §4.6 intro, FR-KSK-01, §8.4, and docs/prototypes/kiosk. Build the kiosk
foundation at /kiosk:
- A single Blade page letterboxing a fixed 800×480 panel (#F6F2ED) centered on a #1c1917
  background, CSS-transform-scaled to fit any viewport.
- A small JS state machine (vanilla JS or Alpine — pick one, justify briefly, record the
  choice in CLAUDE.md) swapping screens: welcome → email_login → identity → consent →
  vitals(1..4) → questionnaire → review → complete. ALL session state lives in one JS
  object, fully cleared on reset-to-welcome.
- Render the Welcome screen fully: pulsing QR target + "Tap to Scan Your ID" left,
  divider, "Lost ID? Log in with email" right; an invisible always-focused input captures
  keyboard-wedge output (string + Enter) and posts the token to a stub endpoint.
- Other screens: labeled placeholders reachable via a dev-only screen-jumper.
Structure for a week of growth: one Blade partial per screen, separate JS module(s).
Plan first, wait for GO.
```

**Session 2:** continuation/buffer — scaling sa iba't ibang window sizes, focus persistence ng hidden input.

**✅ Verify:** Resize the browser → panel laging buo at nakacentro; type a string + Enter kahit saan sa Welcome → tumama sa stub endpoint; dev jumper nakakarating sa lahat ng placeholder screens.

---

### Day 22 — Tue, Jun 30 — Kiosk login screens + consent `[Heavy]`

**Ano'ng mangyayari:** Email login with the on-screen QWERTY keyboard (mabusising UI), identity confirmation, at per-session privacy consent.

**Skill to invoke:** `/laravel-security`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-KSK-02/03/04 and docs/prototypes/kiosk. Build three kiosk screens:
1. Email Login: email + password fields; on-screen QWERTY virtual keyboard — 4 rows
   including digits and @ . _ - keys, Delete/Space/Enter (Enter orange, Delete peach);
   typing routes to whichever field is focused; password eye toggle; "← Cancel" returns
   to Welcome. Auth accepts STUDENTS only — staff credentials rejected at the kiosk.
2. Identity Confirm: large initials avatar, "Identity Verified ✓", greeting with first
   name, college/course/year/student number, "That's me — Continue" and "Not you?"
   (full state reset to Welcome).
3. Privacy Consent (per session): RA 10173 text; "I Agree — Proceed" stores the consent
   timestamp in kiosk session state (persisted to clinic_visits.privacy_consent_at at
   submit); "Decline" resets to Welcome storing NOTHING.
Also wire the Welcome QR stub: a valid qr_token lands on Identity Confirm in under 2s;
invalid → brief inline error, refocus, stay on Welcome.
```

**Session 2:** continuation/buffer — keyboard feel, focus routing, decline/"Not you?" reset cleanliness.

**✅ Verify:** Email login ng demo student gamit ang virtual keyboard lang (walang pisikal); nurse credentials rejected; Decline → balik Welcome at walang naiwang state; valid token (typed+Enter) → Identity in <2s.

---

### Day 23 — Wed, Jul 1 — Vitals steps 1–2 (Height, Weight+BMI) `[Heavy]`

**Ano'ng mangyayari:** Ang 3-phase vital capture pattern (ready → scanning → captured) na gagamitin ng lahat ng 4 steps, manual numeric pad, at ang `receiveReading()` interface na pagkakabitan ng Web Serial sa W5.

**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-KSK-05/06/08/09 and docs/prototypes/kiosk. Build vitals steps 1–2
using one reusable 3-phase step pattern (ready: instructions → scanning: animation →
captured: large value + unit + status badge) with progress dots and Retry/Next:
- Step 1 Height (cm, range 50–250).
- Step 2 Weight (kg, range 10–300) + a BMI panel: weight ÷ height(m)², rounded to 1
  decimal, status label, caption "from X cm + Y kg" (FR-KSK-09 — computed, never entered).
- EVERY step has an "Enter manually" action opening a numeric on-screen pad; manual
  values validate against the same ranges with a re-entry prompt (FR-KSK-08).
- Track per-step provenance (sensor vs manual) in session state for the eventual
  vital_signs.entry_method.
- Sensor path stub: design the interface NOW — kiosk.receiveReading({H:163}) etc. — and
  add a dev-only "simulate reading" button that injects a plausible value through that
  exact code path (the Web Serial module will call the same function in W5).
Plan first, GO.
```

**Session 2:** continuation/buffer.

**✅ Verify:** Manual pad: out-of-range (e.g. height 300) → re-entry prompt; BMI math tama (63kg/1.63m → 23.7); simulate button dumadaan sa scanning phase tapos captured.

---

### Day 24 — Thu, Jul 2 — Vitals steps 3–4 (Temp, BP+HR) `[Medium]`

**Ano'ng mangyayari:** Huling dalawang vitals + refactor ng anumang duplicated sa 4 steps.

**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-KSK-05/06/08/14. Build vitals steps 3–4 on the same 3-phase pattern:
- Step 3 Temperature (°C, 1 decimal, range 30.0–45.0) with a status badge driven by the
  config thresholds — neutral wording like "Normal" / "Slightly Elevated" ONLY; never any
  diagnosis or Fit/Unfit language (FR-KSK-14).
- Step 4 Blood Pressure (systolic 60–260 / diastolic 30–160) + Heart Rate (30–220 bpm) in
  a peach sub-panel; status badge per the BP threshold.
Manual numeric pad + simulate-reading dev path + provenance tracking on both. After step
4, advance to the questionnaire placeholder. Then refactor anything duplicated across the
four steps into one shared step component/config — four steps should be mostly data, not
four copies of code.
```

**Session 2:** buffer.

**✅ Verify:** Lahat ng 4 steps tuloy-tuloy via manual entry; boundary check sa badges: 37.2 normal pero 37.3 elevated; 139/89 normal pero 140/90 elevated.

---

### Day 25 — Fri, Jul 3 — Questionnaire + Review `[Medium]`

**Ano'ng mangyayari:** Yung 9-system questionnaire + pregnancy/LMP + review screen bago mag-submit.

**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-KSK-10/11 and docs/prototypes/kiosk. Build:
1. Questionnaire: 3-column grid of 9 system cards — Vision/Eyes, Hearing/Ears, Nose &
   Throat, Skin, Respiratory/Breathing, Heart/Circulation, Digestive/Stomach, Bones &
   Joints, Nervous/Neurological — each Yes/No; plus a full-width pregnancy question whose
   "Yes" reveals an inline month calendar for Last Menstrual Period (future dates
   disabled; LMP required when pregnant = Yes). Footer: "{N} of 10 answered"; "Review &
   Submit" disabled until all 10 are answered.
2. Review screen: two cards — Vital Signs with flagged items in orange + ⚑ (display-time
   flags computed client-side from thresholds published into the page from config —
   authoritative flags are computed SERVER-side at submit), and Questionnaire as Yes/No
   badges — plus "Submit to Clinic →" posting to a stub.
```

**Session 2:** buffer.

**✅ Verify:** Counter gating works (9/10 → disabled); pregnancy Yes without LMP → hindi makaka-proceed; flagged BP sa review ay orange may ⚑.

---

### Day 26 — Sat, Jul 4 — Submit transaction + Complete + resets `[Heavy]`

**Ano'ng mangyayari:** Pinaka-critical na backend ng kiosk: ang atomic submit (3 rows, flags, walk-in linking), completion screen, at ang dalawang reset timers. May boundary-value tests ito — defense material.

**Skill to invoke:** `/laravel-patterns` + `/laravel-tdd`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-KSK-12/13/15, §5.3, §5.4 (BR-10..14, D-10). Implement kiosk submit:
ONE endpoint that, inside a single DB transaction, creates:
1. clinic_visits — status captured, HP-YYYY-#### via ReferenceNumberService, login_method
   (qr/email), privacy_consent_at + checked_in_at, appointment_id = the student's
   non-cancelled appointment scheduled TODAY if one exists, else NULL (walk-in, BR-10).
2. vital_signs — all values + entry_method (sensor if all steps sensor, manual if all
   manual, mixed otherwise) + the three flag booleans computed SERVER-side from
   config('healthpass.thresholds') (BR-13/14).
3. screening_responses — the 9 booleans + is_pregnant + LMP.
Re-validate everything server-side (ranges, completeness, consent present).

Then: the Complete screen — success check, "Submitted! … proceed to the nurse's station",
countdown pill auto-resetting to Welcome after 12 seconds (or instant Done tap), ALL
session state cleared (FR-KSK-13); and a 90s mid-flow idle timer that discards the
session and resets (FR-KSK-15).

Feature tests: submit creates exactly 3 linked rows; flag booleans at boundary values
(37.2 vs 37.3; 139/89 vs 140/90 vs 145/85; BMI 29.9 vs 30.0); booked student links the
appointment while a walk-in gets NULL.
```

**Session 2:** continuation/buffer — tests green, timers feel right.

**✅ Verify:** Full manual kiosk run → 3 rows sa DB na magkaka-link; book muna then kiosk → appointment_id naka-set; hayaan ang countdown → susunod na "student" ay malinis na Welcome; iwanan mid-flow 90s → reset.

---

### Day 27 — Sun, Jul 5 — Staff exit + W4 E2E wrap `[Medium]`

**Ano'ng mangyayari:** Discreet staff exit + ang week's anchor verification: tatlong buong kiosk runs.

**Skill to invoke:** `/laravel-security`
**Session 1 prompt:**
```text
Two tasks:
1. FR-KSK-16: a discreet staff exit — 5 taps on the logo corner within ~3 seconds opens a
   password prompt; the nurse's password exits kiosk mode (redirect to /nurse/queue).
   Students must have no other way to navigate out of /kiosk. Document it in
   docs/dev-notes.md.
2. Run the full kiosk flow end-to-end three ways: (a) a booked student via the QR path
   (typed token), (b) a walk-in via email login, (c) a declined consent. Verify DB rows
   (or absence, for c), reset cleanliness after the 12s countdown, and the W4 exit
   criterion: "kiosk completable end-to-end on manual entry alone." List anything broken;
   fix blockers only.
```

**Session 2:** WALKTHROUGH template — scope: kiosk state machine + submit transaction. (Importante 'to: ang kiosk ang pinaka-tatanungin sa defense.)

**✅ Verify W4 anchor:** kumpleto ang kiosk sa manual entry lang. Run `/laravel-verification` before tagging. `git tag w4-done`.

---

# WEEK 5 — Web Serial, QR Login & On-Pi Integration (Jul 6–12)
**Anchor:** a sensor-driven kiosk session submits to the DB. **Milestone:** adviser check-in (end of week) — paper revision (R-6) must show progress. **Baldo:** on-Pi integration in true kiosk mode — Days 31–32 are joint days.

---

### Day 28 — Mon, Jul 6 — Web Serial module `[Medium]`

**Ano'ng mangyayari:** Ang serial reader/parser na kakabit sa `kiosk.receiveReading()` interface na ginawa natin sa Day 23. Testable ito sa Windows laptop mo with the MCU plugged in — hindi mo kailangan ng Pi today.

**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  §11.2–11.3, FR-KSK-07, FR-HW-05. Build the kiosk's Web Serial module as
its own JS module:
- Connect flow: navigator.serial.requestPort → open (baud configurable, default 9600);
  a line reader buffering until newline.
- Parser for the contract "H:163;W:64;T:37.9;BP:145/92;HR:78" — partial lines are valid
  (consume whichever keys are present), unknown keys ignored (forward compatibility),
  malformed lines dropped silently with retry. Parsed values route into the existing
  kiosk.receiveReading() interface so the CURRENT step's scanning phase fills.
- Degradation (FR-KSK-07): no Web Serial API / no port granted / read timeout
  (configurable, default 10s) → a non-blocking notice and manual entry remains available.
  NEVER a dead end.
- Handle disconnect/reconnect without a page reload where the API allows (FR-HW-05).
- Keep the simulate-reading dev button working alongside the real path.
Document in docs/dev-notes.md: Chromium-only; secure context satisfied via
http://localhost (D-9). Plan first, GO.
```

**Session 2:** buffer — kung may MCU ka na from Baldo, live test; kung wala, test via the Web Serial API with any USB-serial device or the simulator.

**✅ Verify:** Sa Chrome: connect prompt lumalabas; simulated/real line na "T:37.9" habang nasa Temperature step → auto-fill ng scanning phase; bunutin ang cable → notice + manual entry usable pa rin.

---

### Day 29 — Tue, Jul 7 — Real QR login + left-edge integration `[Medium]`

**Ano'ng mangyayari:** Buhayin ang QR wedge login (hindi na stub), tapos integration test ng buong simula ng kiosk flow.

**Skill to invoke:** `/laravel-security`
**Session 1 prompt:**
```text
Wire the kiosk QR login for real (FR-KSK-01 + its acceptance criteria): the invisible
focused input's submitted string is looked up against student_profiles.qr_token — valid →
load that student into kiosk state and show Identity Confirm in under 2 seconds; invalid →
brief inline error + refocus, stay on Welcome. Re-assert focus whenever Welcome is
(re)shown and on any blur, and make sure the hidden input NEVER steals input from the
virtual keyboard screens.

Then integration-test the kiosk's left edge both ways: QR login → consent → vitals via
simulated readings → submit; and email login → same. Fix focus/keyboard conflicts found.
```

**Session 2:** buffer.

**✅ Verify:** Kung may USB scanner ka na: i-scan ang QR mula sa /student/profile screen ng ibang device — Identity in <2s. Wala pa? Type the token + Enter mabilisan (yan ang ginagaya ng scanner).

---

### Day 30 — Wed, Jul 8 — Raspberry Pi deployment docs & scripts `[Light]`

**Ano'ng mangyayari:** Hindi app code — deployment documentation + autostart scripts para magamit nina Baldo sa joint days. Ikaw pa rin ang software side nito.

**Session 1 prompt:**
```text
Create docs/deployment-pi.md (no app code changes): installing PHP 8.2+, Composer,
MySQL/MariaDB, Node on Raspberry Pi OS; cloning the repo + a Pi .env template
(APP_URL=http://localhost, DB on 127.0.0.1, production-ish settings); building assets
with npm run build (the Pi must NOT run Vite dev); serving on port 80 — recommend one of
artisan serve vs nginx+php-fpm and give steps for both; a Chromium autostart script
(chromium-browser --kiosk http://localhost/kiosk) via systemd or LXDE autostart; how
staff browsers reach the app over the campus LAN via the Pi's IP; and a placeholder
section "Web Serial permission persistence in unattended kiosk mode" for Baldo to fill
after testing (FR-HW-05). Add any helper shell scripts under scripts/pi/.
```

**Session 2:** none planned — bank it.

**✅ Verify:** Basahin ang doc bilang si Baldo: kaya ba niyang sundan ito mag-isa sa Pi? Padalhan mo siya ng link.

---

### Day 31 — Thu, Jul 9 — JOINT DAY with Baldo (flex)

**Protocol:** On-Pi integration — Laravel on the Pi, Chromium kiosk mode, totoong MCU. Dalhin ang BUGFIX template; mga serial quirks ang aayusin (permissions, port naming, timing). Kung hindi available si Baldo/Pi: regular flex day.

---

### Day 32 — Fri, Jul 10 — JOINT DAY #2 / FLEX

**Protocol:** Tuloy ang integration, or absorb slips. Goal bago mag-Day 33: at least one vital filled by a REAL sensor on the Pi.

---

### Day 33 — Sat, Jul 11 — Serial + kiosk hardening `[Medium]`

**Ano'ng mangyayari:** Adversarial testing ng serial path + entry_method correctness. Ang output nito ay defense Q&A material.

**Skill to invoke:** `/laravel-tdd`
**Session 1 prompt:**
```text
Hardening pass on the serial + kiosk path:
1. entry_method correctness: a session mixing sensor-filled and manual steps must save
   "mixed"; all-sensor → "sensor"; all-manual → "manual". Cover with a test.
2. Feed adversarial input through the parser: garbage bytes, half lines, unknown keys,
   absurd-but-parseable values (T:99, H:999). Absurd values must FAIL range validation
   and prompt retry/manual — never crash, never silently accept.
3. Mid-step disconnect → non-blocking notice + manual entry (no dead end).
4. The scanning phase must ping the 90s idle timer (a slow sensor wait must not trigger
   an idle reset mid-measurement).
Fix what fails. Summarize all failure modes + behaviors in docs/dev-notes.md under
"Kiosk failure modes (defense Q&A)".
```

**Session 2:** buffer.

**✅ Verify:** Sagutin without notes: ano'ng mangyayari pag binunot ang USB mid-BP-reading? (Dapat alam mo na — notice + manual, session continues.)

---

### Day 34 — Sun, Jul 12 — Weekly wrap + adviser check-in `[Light]`

**✅ Verify W5 anchor:** a sensor-driven (or simulator-driven, kung naipit ang hardware) kiosk session submits to the DB on the Pi. Run `/laravel-verification` before tagging. `git tag w5-done`. **Adviser check-in:** demo the kiosk; i-report ang paper revision status (R-6) — kailangan may revised title/objectives draft na ang docs team ngayon.

---

# WEEK 6 — Nurse Live Queue (Jul 13–19)
**Anchor:** kiosk submission visible in the queue in ≤5s (SM-2). **Team:** QA sub-team starts authoring test cases this week — Day 38 generates their materials.

---

### Day 35 — Mon, Jul 13 — Live Queue page `[Medium]`

**Ano'ng mangyayari:** Ang nurse's main screen — server-rendered muna, polling bukas.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-NRS-01 and the queue screen in docs/prototypes/web. Build
/nurse/queue on x-layout.sidebar with nurse nav (Live Queue, Enable Kiosk Mode):
a table of clinic_visits with status captured, oldest first (first come, first served) —
- Student: initials avatar + name; the top row (longest-waiting) tagged "NEXT" with a peach highlight.
- College, inline Vitals Summary (flagged values bold orange), Flags column (temp/bp/bmi
  badges or "—"), capture Time (humanized, e.g. "2m ago"), and an "Encode Result" button
  (stub).
Header: blinking LIVE pill + "{n} students waiting · updated just now". Server-rendered
first load; polling comes tomorrow. Verify against the dev seeder's captured visits.
```

**Session 2:** buffer.

**✅ Verify:** Seeded captured visits lumalabas oldest-first (FIFO); flagged values orange; encoded visits HINDI lumalabas.

---

### Day 36 — Tue, Jul 14 — Polling + in-place updates `[Medium]`

**Ano'ng mangyayari:** Ang "live" sa Live Queue. Polling by design (Decision D-11) — kaya mong i-defend kung bakit hindi WebSockets.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-NRS-02 + SM-2. Add polling to the Live Queue:
- A lean JSON endpoint returning current captured visits (id, student name + initials,
  college, vitals summary, flags, humanized time), oldest first (FIFO). Confirm the query uses
  the clinic_visits(status, created_at) index.
- JS fetch every 4s updating rows IN PLACE: new arrivals append at the bottom, the top row keeps the NEXT tag, encoded ones disappear,
 no full-page reload, no flicker; update "{n} students waiting ·
  updated Xs ago".
Acceptance: with two browser windows on the queue, a visit inserted via tinker appears in
BOTH within one cycle. Demonstrate this and tell me the tinker one-liner you used.
```

**Session 2:** buffer.

**✅ Verify:** Dalawang windows + kiosk submit sa third → parehong queue nag-update ≤5s nang hindi nagre-refresh. SM-2 evidence — i-screenshot/video para sa paper.

---

### Day 37 — Wed, Jul 15 — Kiosk mode launcher + SM-4 drill `[Light]`

**Ano'ng mangyayari:** Maliit na feature + isang importanteng reliability drill (zero-loss persistence).

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Two tasks:
1. FR-NRS-06: add "Enable Kiosk Mode" to the nurse nav, opening /kiosk in a new tab.
2. SM-4 persistence drill: with 5 captured (un-encoded) visits in the DB, stop the
   artisan server and MySQL, restart both, and verify all 5 still appear in the queue.
   Also audit the codebase to confirm NOTHING (scheduled jobs, cleanup code, cache
   expiry) can expire or delete captured visits (FR-NRS-08, BR-12). Document the exact
   drill steps + result in docs/dev-notes.md so QA can repeat it for SM-4 evidence.
```

**Session 2:** none planned — bank it.

**✅ Verify:** Gawin mo mismo ang restart drill minsan pa. 5 in, 5 out.

---

### Day 38 — Thu, Jul 16 — QA team materials `[Light]`

**Ano'ng mangyayari:** Hindi app code — generate ang traceability sheet at E2E checklists para makapagsimula ang QA teammates (David/Dela Cruz/etc.). Ibigay mo agad sa kanila after.

**Session 1 prompt:**
```text
No code changes. Generate QA materials from docs/HealthPass_PRD.md :
1. docs/qa/traceability-template.csv — one row per Must and Should FR in §4, columns:
   FR ID, Requirement summary (≤12 words), Module, Priority, Test Case ID (blank),
   Status (blank), Tester (blank), Date (blank).
2. docs/qa/e2e-scenarios.md — E2E-1..E2E-6 from §13 expanded into step-by-step checklists
   a non-programmer tester can follow (exact URLs, which seeded account to use, what to
   click, what to expect).
3. docs/qa/sm-evidence.md — the SM-1..SM-8 table from §1.5 with a "how to measure"
   paragraph per metric.
```

**Session 2:** flex.

---

### Day 39 — Fri, Jul 17 — FLEX DAY

**Protocol:** Absorb slips, or pull Day 42 (encode screen) forward. Kung maayos lahat: rest — papasok na tayo sa clinical loop.

---

### Day 40 — Sat, Jul 18 — Queue polish + cross-window test `[Light]`

**Session 1:** BUGFIX/polish ng queue UX vs prototype (NEXT-tag behavior, time refresh, empty state "No students waiting"), tapos isa pang two-window + kiosk live run.

**✅ Verify:** Empty queue looks intentional; mabilis at walang flicker ang updates.

---

### Day 41 — Sun, Jul 19 — Weekly wrap `[Light]`

**✅ Verify W6 anchor:** submission visible ≤5s. Run `/laravel-verification` before tagging. `git tag w6-done`. **Prep reminder:** kunin na ang official form scan — kailangan sa Day 44!

---

# WEEK 7 — Encode Result + Official Print (Jul 20–26)
**Anchor:** printed form matches the official DHVSU-QSP-OSS-004-FO002-R03 (SM-3 sign-off prep). **Baldo:** physical enclosure, mounting, cabling.
**⚠️ Prerequisite:** the official form scan must be in `docs/forms/` before Day 44.

---

### Day 42 — Mon, Jul 20 — Encode Result screen (UI) `[Medium]`

**Ano'ng mangyayari:** Ang "Doctor's Assessment" screen (nurse-operated, Decision D-2). UI muna today, transaction bukas.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-NRS-03 and the encode screen in docs/prototypes/web. Build the Encode
Result screen at /nurse/visits/{visit}/encode (nurse-only), opened from a queue row, UI
titled "Doctor's Assessment":
- Display: student identity block, full vitals with flag badges, and all 9 questionnaire
  answers (+ pregnancy/LMP if applicable).
- Form: Result — Fit/Unfit, REQUIRED, prominent toggle; Medical Case Category — optional
  select (Respiratory, Cardiovascular, Dermatologic, Gastrointestinal, ENT,
  Musculoskeletal, Vision, Other); Purpose — optional select (Off Campus Procedure,
  On-the-job Training, Field Trip/Educational Tour, Sports Activities); Nurse Notes
  textarea.
- Buttons: "Preview & Print" and "Save & Close" (both stubs today).
Rules: only captured visits open the editable form; an encoded visit renders the same
screen READ-ONLY with a Reprint button (stub).
```

**Session 2:** buffer.

**✅ Verify:** Galing queue → bukas ang encode na puno ng tamang data; encoded (seeded) visit → read-only view.

---

### Day 43 — Tue, Jul 21 — Save & Close transaction `[Medium]`

**Ano'ng mangyayari:** Ang clearance record creation — idempotent, transactional, at nagma-mark ng appointment as completed.

**Skill to invoke:** `/laravel-patterns` + `/laravel-tdd`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-NRS-04/07, BR-11/15/16, §7.5. Implement Save & Close:
- Validate: result (Fit/Unfit) required; category/purpose optional.
- In ONE transaction: create the 1:1 clearance_records row (encoded_by = the
  authenticated nurse, encoded_at = now, physician_name default "REYNALDO S. ALIPIO, MD",
  physician_license_no default "60252"); flip the visit to encoded; and if the visit has
  a linked appointment, mark that appointment completed (FR-NRS-07).
- Idempotency: a visit is encoded EXACTLY once — guarded at both DB level (unique
  clinic_visit_id) and application level; a re-submit gets a friendly "already encoded"
  redirect to the read-only view.
- After save: redirect to the queue with a success flash; the row must vanish from
  polling on the next cycle.
Feature tests: missing result blocked; double-encode safe; linked appointment becomes
completed; walk-in encodes fine; result then visible in the student's My Records
(FR-STU-08 / BR-18).
```

**Session 2:** buffer.

**✅ Verify:** Encode mula sa queue → nawala ang row sa BOTH windows; buksan ulit ang visit → read-only; check My Records ng student → kita na ang result.

---

### Day 44 — Wed, Jul 22 — Official print template `[Heavy]`

**Ano'ng mangyayari:** Ang pinaka-fidelity-critical na screen ng proyekto. Kailangan na ng form scan sa `docs/forms/` — kung wala pa, ipagpaliban ito at i-flex muna ang araw.

**Session 1 prompt:**
```text
I've placed a scan of the official form in docs/forms/ — study it closely (layout, field
order, labels, the physician block). Read docs/HealthPass_PRD.md  FR-PRT-01..04, BR-17. Build the
print view: a standalone Blade template (zero app chrome) reproducing
DHVSU-QSP-OSS-004-FO002-R03 "MEDICAL CLEARANCE", issuing office "Office of Student
Welfare and Formation — Health Services Unit", field-for-field:
- Student identity: name, student number, college, course/year, age (computed from DOB at
  encode date), sex, civil status, address, birth details.
- Vitals: height, weight, BMI, temperature, BP, heart rate. Respiratory Rate: present on
  the form but INTENTIONALLY BLANK (FR-PRT-03 / D-6).
- The encoded Result; Case Category and Purpose where set; encode date; nurse notes where
  the form provides space.
- Physician block pre-printed: "REYNALDO S. ALIPIO, MD — University Physician — License
  No. 60252" with a BLANK signature line for wet signing.
Route: /nurse/visits/{visit}/print — nurse-only, encoded visits only. Layout fidelity to
the scan beats prettiness. Plan the layout structure first against the scan, GO, then
build.
```

**Session 2:** continuation — iterate vs the scan. Mag-print preview ka bawat round at sabihin kay Claude Code ang mga mismatch (field positions, labels, line weights).

**✅ Verify:** Chrome print preview side-by-side vs the scan — bawat label at field nasa tamang lugar; resp rate blank; physician block exact.

---

### Day 45 — Thu, Jul 23 — Print CSS + iframe + printed_at `[Medium]`

**Ano'ng mangyayari:** One-page fit, the in-screen preview/print mechanism, at print timestamps.

**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-PRT-05, FR-NRS-05. Finish printing:
1. Print CSS: the template fits EXACTLY one page (A4; verify Letter too), margins
   matching the official form; @media print suppresses everything non-form; long nurse
   notes must not push to page 2 (clamp or auto-shrink the notes area).
2. Embed the print route in an iframe inside the Encode screen; "Preview & Print"
   triggers window.print() on the iframe's contentWindow.
3. Stamp clearance_records.printed_at on print, and re-stamp on Reprint from the
   read-only view (reprints allowed, FR-NRS-05).
Verify in Chrome print preview at 100% scale and tell me the exact print-dialog settings
to standardize on (margins, scale, paper size) — I'll write them into docs/dev-notes.md
for the clinic.
```

**Session 2:** buffer.

**✅ Verify:** Actual print kung may printer ka; kung wala, save-as-PDF sa print dialog at i-overlay sa scan. One page lagi, kahit mahaba ang notes.

---

### Day 46 — Fri, Jul 24 — FLEX / Physical print comparison

**Protocol:** Dalhin ang printout sa clinic staff (o sa teammate na hawak ang coordination) para sa side-by-side vs the blank official form — ito ang SM-3 dry run. Bumalik with a list of mismatches → BUGFIX template. Kung hindi makakapunta: overlay-on-scan review with a teammate.

---

### Day 47 — Sat, Jul 25 — FLEX DAY

**Protocol:** Print fixes from Day 46 feedback, absorb slips, or pull Day 49 forward.

---

### Day 48 — Sun, Jul 26 — Weekly wrap `[Light]`

**✅ Verify W7 anchor:** the printed form matches the official form per clinic-staff eyes (SM-3 prep — formal sign-off repeats at freeze). Run `/laravel-verification` before tagging. `git tag w7-done`. **Note:** ang natitirang malalaking build ay batch + director na lang — the core clearance loop is DONE. Kung dito ka man maipit later, ito ang designated slip candidate (§0.5).

---

# WEEK 8 — College Admin Batch Requests (Jul 27–Aug 2)
**Anchor:** batch submit → pending visible to the Director. **Milestone:** adviser check-in end of this week. **Baldo:** joint dry-run #1 (Day 53).
**⚠️ Slip-policy reminder:** W8–W9 (batch workflow) is the designated cut if the schedule collapses. Kung papasok ka sa week na ito nang delayed ng ≥4 days sa kiosk/encode/print loop, ayusin MUNA yun bago ito.

---

### Day 49 — Mon, Jul 27 — College scoping foundation + Admin Dashboard `[Medium]`

**Ano'ng mangyayari:** Ang pinaka-importanteng security mechanism ng admin module: server-side college scoping na hindi kayang i-tamper. Isulat once, gamitin everywhere this week. Tapos ang Admin Dashboard.

**Skill to invoke:** `/laravel-security` + `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  §2.2, FR-ADM-01, FR-AUTH-06, FR-ADM-06. First, the scoping foundation:
a reusable SERVER-side mechanism (middleware + controller enforcement, or a dedicated
query scope — your call, justify briefly) guaranteeing every /admin/* query filters by
auth()->user()->managed_college_id. It must be immune to request tampering — the college
is NEVER read from the request. Write it once; every admin feature this week uses it.

Then build the Admin Dashboard at /admin/dashboard on x-layout.sidebar with admin nav
(Dashboard, New Batch Request, Batch Tracking):
- The college-scope banner: "{College Full Name} — you can only manage students and batch
  requests for your assigned college."
- Four stat cards: Registered Students (in the college), Total Batches, Pending Approval,
  Approved.
- The college's batch_requests table (intentional empty state today).
Plan first, GO.
```

**Session 2:** buffer.

**✅ Verify:** Log in as CCS admin → CCS counts lang; as CEA admin → iba ang numbers; banner shows the right college.

---

### Day 50 — Tue, Jul 28 — New Batch Request form `[Heavy]`

**Ano'ng mangyayari:** Ang pinaka-complex na form ng web app — searchable multi-select na kaya ang 100+ students. Frontend + validation today, persistence bukas.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-ADM-02/03, BR-06/07, and the batch form in docs/prototypes/web. Build
/admin/batches/create (frontend + validation today; submission persists tomorrow):
- Reason select: graduation clearance, OJT/practicum, general enrollment, scholarship,
  sports/athletics, field trip/educational tour, others — with a "Please specify"
  textarea REQUIRED if and only if "others" is chosen (BR-07).
- Service Type: medical / dental.
- Student multi-select: ONLY the admin's college's students (server-filtered via the
  Day 49 scope), searchable live by name or student number, Select All / Clear, selected
  rows peach-highlighted, "(N of M selected)" counter. Must stay smooth with 100+ rows —
  choose server-side or hybrid search and justify.
- "Submit Batch Request" disabled until reason valid + at least 1 student selected.
Plan first, GO.
```

**Session 2:** continuation/buffer — search feel, Select All with an active search filter, counter correctness.

**✅ Verify:** Search "dela" → live filter; Select All → counter tamang-tama; "others" without detail → blocked; ibang college's students hindi lumalabas kahit sa search.

---

### Day 51 — Wed, Jul 29 — Batch submission + tracking `[Medium]`

**Ano'ng mangyayari:** Ang batch transaction (with server-side re-verification ng college membership) + confirmation + tracking page.

**Skill to invoke:** `/laravel-patterns` + `/laravel-tdd`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-ADM-04/05, §5.6. Implement batch submission:
- In ONE transaction: create batch_requests (status pending, BR-YYYY-### via
  ReferenceNumberService, college_id taken from the admin's scope — NEVER from the
  request, requested_by = the admin) + one batch_request_students row per selected
  student.
- Server-side re-verify EVERY submitted student id belongs to the admin's college;
  reject the whole submission otherwise.
- Confirmation screen: Batch ID, "Pending Director Approval", date, View Tracking /
  Back to Dashboard actions.
- Batch Tracking page: the college's requests — Batch ID, truncated reason, student
  count, submitted date, status badge (pending shown as "Pending Director Approval").
Feature tests: 30 students → exactly 30 pivot rows; a foreign-college student id
injected into the POST → rejected; "others" without detail → rejected.
```

**Session 2:** buffer.

**✅ Verify:** Submit ng 10-student batch → BR ref sa confirmation; tracking shows it pending; pivot rows = 10 sa phpMyAdmin.

---

### Day 52 — Thu, Jul 30 — Cross-college security tests `[Light]`

**Ano'ng mangyayari:** Ang SM-5 attack-vector suite para sa admin module. Maikling araw pero mataas ang defense value.

**Skill to invoke:** `/laravel-tdd` + `/laravel-security`
**Session 1 prompt:**
```text
Cross-college security pass (FR-ADM-06, SM-5), encoded as feature tests in
tests/Feature/CollegeScopeTest.php. As the CCS admin, attempt via direct request
manipulation to:
1. List/search another college's students (including through the batch form's search
   endpoint).
2. Read another college's batch request by URL/id.
3. Submit a batch containing another college's student ids.
4. Alter managed_college_id through any admin-facing request.
All four must fail server-side. Then re-run the Day 10 role-isolation suite extended to
the new /admin routes for the other 3 roles. Fix any hole with the smallest change. No
new features.
```

**Session 2:** buffer / bank it.

**✅ Verify:** Test file green; manually try opening a CEA batch URL as the CCS admin → bounced.

---

### Day 53 — Fri, Jul 31 — JOINT DRY-RUN #1 with Baldo (flex)

**Protocol:** Buong hardware + software run sa Pi: real QR scan → sensors → submit → queue sa ibang laptop over LAN → encode → print. Ito ang first full-system rehearsal. BUGFIX template ready; i-log lahat ng hardware quirks sa docs/dev-notes.md.

---

### Day 54 — Sat, Aug 1 — FLEX DAY

**Protocol:** Dry-run fixes, absorb slips, or pull Day 56 forward. Kung maayos: rest.

---

### Day 55 — Sun, Aug 2 — Weekly wrap `[Light]`

**✅ Verify W8 anchor:** batch submit → "Pending Director Approval" visible (Director side stubbed pa pero ang data ay queryable — tinker check). Run `/laravel-verification` before tagging. `git tag w8-done`. **Adviser check-in:** demo the batch form + report ng dry-run #1 results.

---

# WEEK 9 — Director Approvals + Analytics Start (Aug 3–9)
**Anchor:** a 25-student batch approves atomically into 25 appointments. **Baldo:** spare-parts kit + failure-mode drills.

---

### Day 56 — Mon, Aug 3 — Batch Approvals screen `[Medium]`

**Ano'ng mangyayari:** Ang Director's approval inbox — lahat ng colleges, may approve modal na may date picker at capacity warning.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-DIRA-01/05/06 and the approvals screen in docs/prototypes/web. Build
/director/batches on x-layout.sidebar with director nav (Dashboard, Batch Approvals,
Analytics, Flagged Anomalies):
- ALL colleges' batch requests as rows: Batch ID + status badge, college, reason in
  quotes, student count, submitted date.
- Approve / Reject buttons rendered ONLY on pending rows; decided rows show static
  "✓ Approved" / "✕ Rejected" (FR-DIRA-05).
- Approve opens a modal: date picker (default today; weekends/past disabled) + a capacity
  warning line when the chosen date's non-cancelled appointment count is at/over
  config('healthpass.daily_capacity') — WARN, don't block (FR-DIRA-06).
Buttons post to stubs today.
```

**Session 2:** buffer.

**✅ Verify:** Director nakikita ang batches ng LAHAT ng colleges; pending lang ang may buttons; pag pinili ang isang puno nang date → warning text lumalabas pero pwede pa rin.

---

### Day 57 — Tue, Aug 4 — Approve transaction `[Heavy]`

**Ano'ng mangyayari:** Ang pinaka-delikadong transaction ng system: isang approval = N appointments, all-or-nothing. May rollback test ito — premium defense material.

**Skill to invoke:** `/laravel-patterns` + `/laravel-tdd`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-DIRA-02/03/06, BR-08. Implement Approve, ALL inside ONE DB
transaction:
1. Set the batch status to approved; stamp reviewed_by, reviewed_at, and
   batch_requests.scheduled_date with the chosen date.
2. Create ONE appointment per batch_request_students row: service = the batch's
   service_type, scheduled_date = the chosen date, source = batch, status = scheduled,
   its own APT-YYYY-#### via ReferenceNumberService, created_by = the director.
3. Write each new appointment_id back to its pivot row.
Capacity is warn-only for batches — no blocking. Guards: a double-click / duplicate POST
cannot double-generate (status re-check inside the transaction + disable the button on
first click); a decided batch cannot be re-decided (FR-DIRA-05).

Feature tests: a 25-student batch yields exactly 25 appointments + 25 back-written pivot
ids; force a failure mid-loop in a test and assert FULL rollback (no partial
appointments, batch still pending); double-submit is safe; the generated appointments
appear in the students' dashboards (FR-DIRA-03).
```

**Session 2:** continuation/buffer — tests green; manual approve ng seeded batch.

**✅ Verify:** Approve ng 25-student batch → 25 appointments sa DB, bawat pivot row may appointment_id; i-double-click ang Approve → 25 pa rin, hindi 50; buksan ang isang student account → kita ang appointment.

---

### Day 58 — Wed, Aug 5 — Reject + student visibility sweep `[Light]`

**Ano'ng mangyayari:** Reject flow (terminal, walang appointments) + verification na ang batch appointments ay indistinguishable sa self-booked sa lahat ng student views.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-DIRA-04/05. Implement Reject: status rejected, reviewer fields
stamped, ZERO appointments created, terminal/immutable. Feature test it.

Then a verification sweep: approve a seeded batch and confirm (a) each listed student's
dashboard Next Appointment + booking calendar reflect the generated appointment exactly
like a self-booked one; (b) by code-reading the kiosk's appointment-linkage query,
confirm a batch appointment scheduled today WILL be linked at kiosk submit; (c) Admin
Batch Tracking reflects the decision with no admin action (it reads live — verify).
Report findings; fix discrepancies only.
```

**Session 2:** buffer / bank it.

**✅ Verify:** Reject → walang appointments, status final; approved batch student → appointment visible at counted sa calendar capacity.

---

### Day 59 — Thu, Aug 6 — Director Dashboard `[Medium]`

**Ano'ng mangyayari:** KPIs + preview panels — ang landing view ni Director.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-ANL-01 and the director dashboard in docs/prototypes/web. Build
/director/dashboard:
- KPI cards from live data: Total Encoded Clearances, Pending Batches, Today's
  Appointments, Flagged Visits.
- Two preview panels: Pending Batch Approvals (count + up to 3 preview rows + "View all →"
  to Batch Approvals) and Flagged Anomalies (count + up to 3 preview rows + "View all →"
  to a stub /director/anomalies).
Reuse HP components; intentional empty states; efficient queries (no N+1).
```

**Session 2:** buffer.

**✅ Verify:** Numbers tally vs phpMyAdmin counts; preview rows match the newest data.

---

### Day 60 — Fri, Aug 7 — Chart.js + Cases-by-College bar `[Medium]`

**Ano'ng mangyayari:** First analytics chart. Encoded records ONLY ang source (FR-ANL-07) — importanteng distinction sa defense.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-ANL-02/07. Install Chart.js locally via npm (not CDN). Build
/director/analytics with the first chart: "Medical Cases by College" grouped bar —
X-axis = the 12 college codes; series = the top case categories present in the data
(e.g. Vision, Respiratory, ENT, Cardiovascular); counts computed ONLY from encoded
clearance_records joined clinic_visits → student_profiles.college_id; subtitle "Top case
categories per college — Academic Year 2025–2026". Use the design palette for series
colors (orange/peach/slate tints).

Extend the DEV seeder (clearly dev-only) with a spread of encoded records across all
colleges, categories, and both sexes so the chart is meaningful.
```

**Session 2:** buffer.

**✅ Verify:** Bars match manual SQL counts for 2–3 cells; captured-but-not-encoded visits ay HINDI nabibilang.

---

### Day 61 — Sat, Aug 8 — FLEX DAY

**Protocol:** Absorb, pull Day 63 forward, or rest. Last stretch na ito ng feature work — 8 days na lang bago mag-freeze.

---

### Day 62 — Sun, Aug 9 — Weekly wrap `[Light]`

**✅ Verify W9 anchor:** 25-student batch approves atomically (rerun the test suite + one manual run). Run `/laravel-verification` before tagging. `git tag w9-done`.

---

# WEEK 10 — Analytics Complete + Demo Data (Aug 10–16)
**Anchor:** Director suite demo-ready; **feature-complete by Day 68 — freeze on Day 70.** **Baldo:** kiosk physical hardening + cable management final.

---

### Day 63 — Mon, Aug 10 — Summary of Medical Cases matrix `[Medium]`

**Ano'ng mangyayari:** Ang signature deliverable ni Director (Decision D-3): 8 categories × 12 colleges matrix.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-ANL-03/07. Add the "Summary of Medical Cases" matrix to
/director/analytics: 8 case-category rows × 12 college-code columns + a TOTAL column and
a totals row. Computed from ENCODED records only, grouped by student_profiles.college_id
× clearance_records.case_category; records with NULL category are excluded from the
matrix but show their count in a footnote ("N encoded without category"). Use ONE
efficient grouped query, not 48 queries.

Feature test: seed 3 Respiratory/CCS + 2 Respiratory/CEA → exactly those cells; and
assert the matrix grand TOTAL equals the count the by-sex donut (tomorrow) will use —
write both against the same shared query/scope now.
```

**Session 2:** buffer.

**✅ Verify:** Spot-check 3 cells vs manual SQL; totals row + column tugma; footnote count tama.

---

### Day 64 — Tue, Aug 11 — By-Sex donut + Flagged Anomalies `[Medium]`

**Ano'ng mangyayari:** Huling analytics visual + ang anomalies screen. Tandaan ang distinction: flags surface from CAPTURE, case stats from ENCODED only.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-ANL-04/05/07. Build:
1. The By-Sex donut (Chart.js) on Analytics: Male vs Female counts of encoded records,
   center total, legend with count + percentage. Must use the same shared query/scope as
   the matrix total.
2. /director/anomalies (Flagged Anomalies): three stat cards — High Blood Pressure,
   Fever, Abnormal BMI counts — + a table (Student, College, Flag badge, the offending
   Value, Case Category if encoded else "Pending", View → record detail), sourced from
   vital_signs where any flag boolean is true, INCLUDING still-captured visits (flags
   surface from capture; case statistics remain encoded-only — FR-ANL-07).
Verify with seeds: a captured flagged visit appears in Anomalies but not in the matrix.
```

**Session 2:** buffer.

**✅ Verify:** Donut total = matrix total; isang captured flagged visit → nasa Anomalies, wala sa matrix/donut.

---

### Day 65 — Wed, Aug 12 — CSV exports `[Light]`

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  FR-ANL-06. Add Export (CSV download) buttons:
1. On Analytics: (a) the matrix's underlying rows — college, case_category, sex, count;
   (b) raw encoded records — reference, encode date, college, sex, category, result.
2. On Flagged Anomalies: student number, name, college, flag type, value, capture date,
   encoded yes/no.
Proper headers, UTF-8 BOM for Excel-friendliness, filenames like
healthpass-cases-YYYYMMDD.csv. The exports MUST share the same queries/scopes as the
on-screen views — one source of truth.
```

**Session 2:** buffer / bank it.

**✅ Verify:** Buksan ang CSV sa Excel — readable, walang sirang characters; row counts match the screens.

---

### Day 66 — Thu, Aug 13 — DemoSeeder (presentation data) `[Medium]`

**Ano'ng mangyayari:** Ang "believable semester" dataset para sa defense demo — para bawat screen ay may laman nang hindi ka nagse-set up manually.

**Skill to invoke:** `/laravel-patterns`
**Session 1 prompt:**
```text
Read docs/HealthPass_PRD.md  §6.5. Build a presentation seeder (separate from the dev seeder,
idempotent, run via php artisan db:seed --class=DemoSeeder): a believable semester —
~120 students across all 12 colleges; encoded visit history covering ALL 8 case
categories and both sexes in realistic proportions; a handful of flagged anomalies
(some captured, some encoded); batch requests in every status across several colleges;
today's appointments + 2–3 captured visits sitting in the live queue; and a few upcoming
bookings — so EVERY screen (dashboards, queue, analytics, anomalies, tracking) demos
meaningfully with zero manual setup.

Document the exact "reset for demo" command sequence in docs/dev-notes.md.
```

**Session 2:** buffer.

**✅ Verify:** Run the reset sequence → click through ALL screens as all 4 roles — lahat may makabuluhang laman; analytics mukhang totoo.

---

### Day 67 — Fri, Aug 14 — FLEX DAY

**Protocol:** Absorb anything. 3 days to freeze — walang bagong features after Day 68.

---

### Day 68 — Sat, Aug 15 — Full-system UI consistency pass `[Medium]`

**Ano'ng mangyayari:** Huling visual pass bago mag-freeze — zero behavior changes.

**Skill to invoke:** `/run`
**Session 1 prompt:**
```text
Full-system UI consistency pass against docs/prototypes: walk EVERY screen in docs/HealthPass_PRD.md 
§8.3's inventory and fix visual drift only — spacing, badge variants, empty states,
button styles, sidebar active states, Poppins weights, the 5px slate scrollbars. Plus
kiosk touch basics: ≥48px touch targets, focus handling. Produce a checklist of fixes per
screen. ZERO behavior changes — we freeze in 2 days; if you find a functional bug, list
it for me instead of fixing it unprompted.
```

**Session 2:** buffer — ikaw mismo mag-screen-by-screen vs the prototypes.

**✅ Verify:** Side-by-side bawat major screen vs prototype; bug list (kung meron) triaged para sa freeze week.

---

### Day 69 — Sun, Aug 16 — Weekly wrap: FEATURE COMPLETE `[Light]`

**✅ Verify W10 anchor:** Director suite demo-ready; every PRD Must feature exists. Run `/laravel-verification` before tagging. `git tag w10-done`. **Bukas: FREEZE.** Mula bukas, bug fixes lang — walang features, kahit "maliit lang."

---

# WEEK 11 — FREEZE + Structured Testing (Aug 17–23)
**Anchor:** all E2E scenarios pass; SM-1..SM-8 measured. **Milestone:** adviser check-in end of week. **Mode shift:** ang Claude Code prompts mo this week ay BUGFIX template na lang (§0.6) — driven by what testing finds. Ang QA teammates ang nagpapatakbo ng test cases (docs/qa/ materials from Day 38); ikaw ang nag-aayos.

---

### Day 70 — Mon, Aug 17 — Freeze + E2E-1, E2E-2 `[Light]`

**Gawin:** `git tag v0.9-freeze`. Run E2E-1 (booked student happy path: book → kiosk → queue → encode → print) and E2E-2 (walk-in path) gamit ang docs/qa/e2e-scenarios.md checklists — QA teammates ang tumatakbo, ikaw nanonood + nag-lo-log. Sessions: BUGFIX lang sa nahanap.

**✅ Verify:** E2E-1 at E2E-2 pass end-to-end, evidence (screenshots/video) saved para sa paper.

---

### Day 71 — Tue, Aug 18 — E2E-3, E2E-6 `[Light]`

**Gawin:** E2E-3 (batch lifecycle: admin submit → director approve → appointments → kiosk links one → encode) and E2E-6 (director analytics review vs known seeded data). BUGFIX sessions only.

**✅ Verify:** Parehong pasado; analytics numbers verified vs DemoSeeder's known counts.

---

### Day 72 — Wed, Aug 19 — E2E-4 (degraded hardware) + E2E-5 (security) `[Medium]`

**Gawin:** E2E-4 — bunutin ang MCU mid-session (with Baldo): manual entry must complete the visit, entry_method correct. Then E2E-5 via this prompt:

**Skill to invoke:** `/security-review`
**Session 1 prompt:**
```text
Adversarial security sweep before defense (we are FROZEN — smallest-change fixes only):
attempt and document — cross-role URL access for all 4 roles × all route groups;
cross-college tampering on every admin endpoint; direct POSTs without CSRF tokens; the
kiosk submit endpoint called directly with forged payloads (out-of-range vitals, missing
consent, other students' ids); double-encode; double-approve; OTP brute force. Output
docs/qa/security-sweep.md with each attempt → result. Fix only genuine holes with the
smallest possible change; FLAG any risky fix to me before applying it.
```

**✅ Verify:** security-sweep.md kumpleto; lahat ng attempts denied; risky fixes (kung meron) na-review mo bago in-apply.

---

### Day 73 — Thu, Aug 20 — SM measurements + ISO 25010 runs `[Light]`

**Gawin (team day):** Sukatin at i-record sa docs/qa/sm-evidence.md ang SM-1 (kiosk session time), SM-2 (queue latency), SM-3 (print match sign-off — formal na ngayon), SM-4 (restart drill), SM-5 (security suite green), SM-6 (E2E pass rate), SM-7 (manual-entry completion), SM-8 (uptime/demo stability). QA team starts ang ISO/IEC 25010 evaluation instrument runs with users.

**✅ Verify:** SM table may laman na — anumang FAIL ay top priority bukas.

---

### Day 74 — Fri, Aug 21 — Bugfix day `[Medium]`

**Protocol:** Triage list mula Days 70–73, severity order. BUGFIX template, isa-isa, re-test pagkatapos ng bawat fix. Walang "habang nandito na rin ako" refactors.

---

### Day 75 — Sat, Aug 22 — Bugfix + clinic-floor rehearsal

**Protocol:** Natitirang fixes AM; PM — kung makakapasok sa clinic/venue, full-system rehearsal sa totoong pwesto (Pi, printer, LAN). Hardware quirks → docs/dev-notes.md.

---

### Day 76 — Sun, Aug 23 — Weekly wrap `[Light]`

**✅ Verify W11 anchor:** all 6 E2E pass; all 8 SM measured with evidence. Run `/laravel-verification` before tagging. `git tag w11-done`. **Adviser check-in:** present the SM table + ISO 25010 preliminary results.

---

# WEEK 12 — Polish, Deploy, Rehearse (Aug 24–30)
**Anchor:** **system done Friday, Aug 28 (Day 81)** — tag `v1.0`, absolute freeze. Sat–Sun = rehearsal + rest.

---

### Day 77 — Mon, Aug 24 — README + final deployment docs `[Light]`

**Session 1 prompt:**
```text
Docs only — no code changes. Write:
1. README.md: project overview (HealthPass, PamSU, the 4 roles, NO AI), stack, screenshot
   placeholders, local setup from git clone to running (XAMPP specifics, 127.0.0.1,
   parallel terminals), seeding (dev vs Demo), and how to run the test suite.
2. Final pass on docs/deployment.md: XAMPP dev setup + Raspberry Pi demo deployment
   (merge/point to docs/deployment-pi.md) + an "optional internet deployment" section
   noting the HTTPS requirement and the Web Serial/localhost caveat from PRD §10–11.
Verify every command by actually running it where possible in this environment; mark
any Windows-only steps clearly.
```

**✅ Verify:** Sundan ang README sa isang fresh clone (ibang folder) — gumagana ba talaga bawat step?

---

### Day 78 — Tue, Aug 25 — Pi deployment dry-run (with Baldo) `[Medium]`

**Protocol:** Fresh deploy sa Pi gamit ang docs/deployment-pi.md, verbatim — bawat hakbang na hindi gumana, ayusin ang DOC (at ang script kung kailangan, BUGFIX template). End state: Pi boots → Chromium kiosk auto-opens → buong flow gumagana → staff laptop umaabot sa queue over LAN.

**✅ Verify:** Power-cycle ang Pi → bumabalik sa working kiosk nang walang manual na hakbang.

---

### Day 79 — Wed, Aug 26 — Defense demo script `[Light]`

**Session 1 prompt:**
```text
Create docs/defense-demo-script.md: a timed 10–15 minute demo flow covering E2E-1 (book →
kiosk with sensors → queue → encode → print), one batch approval, and the Director
analytics — with the exact DemoSeeder accounts/emails to use at each step, the
reset-for-demo command to run beforehand, bullet-level speaker notes for each step, the
manual-entry fallback drill if hardware fails mid-demo (PRD D-7/R-8), and a
likely-panel-questions appendix with suggested answers: why no AI; why polling instead of
WebSockets; why the nurse encodes (D-2); why localhost/Web Serial (D-9); data privacy
posture (RA 10173, consent points, role scoping).
```

**✅ Verify:** Basahin nang malakas with a timer — tumama ba sa 10–15 min? I-share sa buong team.

---

### Day 80 — Thu, Aug 27 — Dress rehearsal #1 `[Medium]`

**Protocol:** Buong team, buong script, totoong hardware, timed. Isang teammate ang gumaganap na panelist na nang-iistorbo ng tanong. Fixes: BUGFIX template, pinaka-maliit na changes lang — bukas ang final tag.

---

### Day 81 — Fri, Aug 28 — SYSTEM DONE — `v1.0` `[Light]`

**Gawin:** Final smoke test ng lahat ng E2E happy paths sa umaga. Run `/laravel-verification` one last time → then `git tag v1.0` → push. **Absolute freeze** — mula ngayon, demo environment lang ang ginagalaw (data resets), hindi na ang code. I-backup: DB dump + repo zip sa USB at sa isang teammate's laptop.

**✅ Verify:** v1.0 tagged at pushed; backup kopya verified na nabubuksan.

---

### Day 82 — Sat, Aug 29 — Dress rehearsal #2 / buffer

**Protocol:** Isa pang timed run kung kailangan; otherwise spot-drills lang (hardware-failure fallback, demo reset speed). Walang code changes.

---

### Day 83 — Sun, Aug 30 — Rest + final checklist

**Final checklist:**
- [ ] Spares kit packed (FR-HW-07): backup scanner, cables, SD card image, powered hub.
- [ ] Blank official forms + printed clearances samples para sa panel.
- [ ] DB dump + DemoSeeder reset command tested isang beses pa.
- [ ] Backup laptop with the repo + DB ready as plan B sa demo.
- [ ] docs/defense-demo-script.md printed para sa bawat team member.
- [ ] Matulog ka nang maaga.

**Tapos na ang build. Defense mode na.**

---

## Appendix — Weekly anchors at a glance

| Wk | Days | Anchor (exit criteria) | Tag |
|---|---|---|---|
| 1 | 3–6 | 10 migrations + login → student dashboard slice | w1-done |
| 2 | 7–13 | Register + login as all 4 roles · *adviser check-in* | w2-done |
| 3 | 14–20 | Booking vs capacity rules, server-enforced | w3-done |
| 4 | 21–27 | Kiosk end-to-end on manual entry alone | w4-done |
| 5 | 28–34 | Sensor-driven session submits (on Pi) · *adviser check-in* | w5-done |
| 6 | 35–41 | Queue shows submission ≤5s (SM-2) | w6-done |
| 7 | 42–48 | Print matches official form (SM-3 prep) | w7-done |
| 8 | 49–55 | Batch submit → pending visible · *adviser check-in* | w8-done |
| 9 | 56–62 | 25-student batch approves atomically | w9-done |
| 10 | 63–69 | Director suite demo-ready · feature complete | w10-done |
| 11 | 70–76 | All E2E pass; SM-1..8 measured · *adviser check-in* | w11-done |
| 12 | 77–83 | **Aug 28: v1.0 — system done** | v1.0 |
