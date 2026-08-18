# HealthPass v2 feature queue — session prompts

Five prompts, in execution order. **Run each in its own fresh session.**
Each prompt assumes the previous one is finished and merged to `main`.

Decisions locked with Nat on 2026-07-26 are baked into each prompt — do not
re-litigate them in-session.

| # | Prompt | Branch | Skill to invoke alongside |
|---|--------|--------|---------------------------|
| 1 | Mobile responsive fix (admin + shared chrome) | `feature/mobile-admin-fix` | `/run` |
| 2 | Dark mode | `feature/dark-mode` | `/verify` |
| 3 | Director approve/reject rework (D-36) | `feature/batch-decision-rework` | `/laravel-tdd` |
| 4 | Hourly time slots + capacity (D-37) | `feature/appointment-time-slots` | `/laravel-tdd` |
| 5 | Appointment email notifications | `feature/appointment-emails` | `/laravel-security` |

**Ordering rationale:** 1 before 2 so the dark-mode sweep styles the *new*
mobile markup once instead of twice. 3 before 4 because 4 builds on the
approve modal that 3 simplifies. 5 last because the email body must contain
the appointment time that 4 introduces.

---

## PROMPT 1 — Mobile responsive fix (College Admin + shared chrome)

```
Branch off main: feature/mobile-admin-fix

The College Admin pages are broken on phones. Reported by QA:

1. resources/views/admin/dashboard.blade.php and
   resources/views/admin/batches/index.blade.php both render as desktop
   layouts on a phone — the user has to scroll the page SIDEWAYS to see the
   whole dashboard, which is the single worst part of the experience.
2. The status badge text overflows its container/pill.
3. Both pages are so tall that when the burger drawer is open the user must
   scroll down inside the drawer to reach the Log out button.

Scope — do exactly these three areas, nothing else:

A. resources/views/components/layout/sidebar.blade.php (shared chrome)
   - The drawer's user footer (initials + name + logout) must be pinned and
     always visible regardless of nav length. The <nav> already has
     flex-1 overflow-y-auto and the footer has shrink-0, so verify why it
     still scrolls away on a phone — most likely the drawer's height is not
     constrained to the viewport (100dvh, not 100vh — mobile browser chrome).
     Fix the height constraint, don't restructure the component.
   - While in this file: the page-title <h1> and the logo row must not force
     horizontal overflow on a 360px viewport.
   - Do NOT change the desktop (lg+) collapsible-rail behaviour at all.

B. A reusable responsive-table pattern
   The two admin tables are wrapped in `overflow-x-auto`, which is exactly
   why the page slides sideways. Replace that with a proper pattern:
   below `md`, render each row as a stacked card (label/value pairs);
   at `md`+ render the existing table unchanged.
   Build it once as a Blade component under resources/views/components/hp/
   (follow the existing x-hp.card / x-hp.badge conventions) and use it in
   BOTH admin views. Do not touch the nurse/director/student tables in this
   pass — but leave the component general enough that they can adopt it.

C. resources/views/components/hp/badge.blade.php (or wherever the badge
   lives) — the status text must never overflow the pill. Check the longest
   real label produced by BatchRequest::statusLabel().

Verification (do not declare done without this):
- php artisan serve --port=8080 + npm run dev, then check the College Admin
  dashboard and Batch Tracking at 360x800 and 390x844 with devtools device
  emulation. There must be ZERO horizontal page scroll at 360px on either
  page. Confirm the logout button is reachable without scrolling the drawer.
- Run the existing test suite; it must stay green.

Constraints:
- Tailwind utilities only, mobile-first. No new packages.
- Do not change any controller, route, or query.
- Keep the existing HealthPass design tokens (hp-orange, hp-peach, hp-slate,
  hp-bg). This is a layout fix, not a restyle.

Docs to update in the same change:
- docs/qa/ — add or update the mobile checklist entry for these two pages
  (create docs/qa/mobile-checklist.md if there isn't one already) listing the
  viewports tested and the three defects closed.
- CHANGELOG.md — one entry under Unreleased.
- No PRD change: this is a defect fix against existing FR-ADM-01/FR-ADM-05,
  not a requirement change. If you find that it DOES require a PRD change,
  stop and tell me before editing the PRD.

DO NOT COMMIT. Leave everything staged-but-uncommitted in the working tree
and print a summary of changed files when done. I will review and commit.
```

---

## PROMPT 2 — Dark mode

```
Branch off main: feature/dark-mode

Add a site-wide dark mode to the HealthPass web app.

DECISIONS (locked — implement these, do not propose alternatives):
- Toggle lives in the top-right of the sticky header in
  resources/views/components/layout/sidebar.blade.php, on the same row as the
  HealthPass logo and page title. It must be present on every page that uses
  that layout, so a user can toggle from anywhere.
- Scope: all four roles (student, college admin, nurse, director) + the
  auth/guest pages (login, register, OTP, password change) + layouts/app.blade.php.
- EXCLUDED: the kiosk (resources/views/kiosk/**). It is a fixed clinic
  appliance on a 1080x1920 panel tuned for standing-distance readability and
  it is auth-less — it stays permanently light and gets no toggle.
- EXCLUDED: any print/clearance view. Printed output must stay light
  regardless of theme — force it with a print media query.
- Persistence: localStorage, defaulting to the OS `prefers-color-scheme` on
  first visit, then remembering the manual choice. NO schema change, no
  users column, no POST on toggle.
- Transition: smooth colour transition both directions, using the existing
  motion tokens in resources/css/app.css (--hp-dur-base / --hp-ease-out).
  It must respect the existing prefers-reduced-motion kill switch already in
  app.css — do not add a transition that bypasses it.

DARK PALETTE (base — you MAY adjust any value that fails WCAG AA, and must
tell me which ones you changed and why):
  #0F1115  background
  #1A1D24  cards / sidebar / inputs
  #FFC89A  peach (selected / badges)
  #FF8C2A  orange (primary)
  #E5E7EB  primary text
  #2B303B  borders / dividers
Known issue to resolve: #FF8C2A on #1A1D24 is roughly 3.3:1 — fine for large
text, borders and icons, FAILS AA for body text. Do not use raw orange for
small body copy in dark; pick a lighter orange for that case and document it.
The LIGHT theme keeps its current tokens exactly as they are.

IMPLEMENTATION APPROACH:
- Tailwind `darkMode: 'class'` in tailwind.config.js.
- Drive colours through CSS custom properties in resources/css/app.css so
  each token has a light and a dark value in one place. The existing
  :root block already defines --hp-bg, --hp-slate etc. — add a
  `.dark` / `[data-theme="dark"]` block that overrides them, and make the
  Tailwind hp-* colour names resolve to those vars. This is what keeps the
  sweep from becoming `dark:` classes on 40 files.
- Apply the theme class to <html> in an inline <head> script BEFORE first
  paint — copy the exact FOUC-prevention pattern already used for
  window.__hpSidebarCollapsed in sidebar.blade.php. A dark-mode flash on
  reload is a defect, not a nitpick.
- Alpine holds the reactive flag, same as the existing sidebar collapse state.

Where token indirection is not enough (hardcoded bg-white, text-hp-slate/50,
border-hp-slate/10 etc. scattered through views), add explicit dark: variants.
Go role by role and verify each page visually.

Verification (do not declare done without this):
- php artisan serve --port=8080 + npm run dev. Log in as each of the four
  roles and toggle dark on every page in that role's sidebar nav. Every page
  must be legible in both themes: no white-on-white, no invisible borders, no
  unreadable placeholder text, no un-themed modal/dropdown/flash message.
- Reload with dark active — confirm no light flash.
- Confirm the kiosk route is unaffected and still light.
- Print-preview a clearance record in dark mode — output must be light.
- Run the full test suite; it must stay green.

Docs to update in the same change:
- docs/HealthPass_PRD.md — add the dark-mode decision to the Decisions Log as
  the next free D-number, and add the dark palette to the design-system
  section alongside the existing light palette. Add an FR-ID under the
  cross-cutting/NFR section for the theme toggle.
- CLAUDE.md — extend the "Design system" section with the dark palette and
  the rule that the kiosk and print views are excluded.
- CHANGELOG.md — one entry.

DO NOT COMMIT. Leave everything uncommitted and print a summary of changed
files plus the list of palette values you adjusted for contrast.
```

---

## PROMPT 3 — Director approve/reject rework (Decision D-36)

```
Branch off main: feature/batch-decision-rework

Two changes to the Director's Batch Approvals flow, plus the College Admin
side that consumes them.

*** READ THIS FIRST — PRD CONFLICT, ALREADY RESOLVED, DO NOT RE-ASK ***
This change overturns locked decision D-29 ("the Director may adjust the
date at approval"), which is also listed in CLAUDE.md under "Locked
decisions — do not change or improve". Nat has taken this to the team and
authorised superseding it. Your job includes writing that supersession
properly, not questioning whether it should happen.

CHANGE 1 — Approval is confirm-only (new decision D-36)
- The Director's approve modal no longer contains a date picker. The batch is
  approved on the date the College Admin requested (batch_requests.requested_date);
  the modal displays that date read-only for confirmation.
- App\Http\Requests\Director\ApproveBatchRequest must no longer accept a
  client-supplied scheduled_date. The date comes from the locked row inside
  the transaction, server-side. Do not trust the request for it.
- Legacy batches with requested_date NULL (submitted before D-29) cannot be
  confirm-approved because there is nothing to confirm. For those, disable
  approve and show the Director a one-line notice telling them to reject with
  the reason "predates the requested-date field — please resubmit". Add a
  feature test for this case.
- The escape hatch when a Director dislikes the date is Change 2: reject with
  a reason like "date unavailable, please resubmit for <X>". Document that
  reasoning in the decision entry — it produces a better audit trail than a
  silent date move. Keep the existing capacity WARNING (FR-DIRA-06) behaviour
  in this prompt; prompt 4 changes it to a hard block.

CHANGE 2 — Rejection requires a reason
- New nullable text column batch_requests.rejection_reason via migration.
  Nullable because existing rejected rows have no reason. Follow the migration
  ordering rules in the PRD data dictionary and add the column to the data
  dictionary in the same change.
- The Director's reject action requires the reason: required, min 10, max 500
  characters. Use a Form Request (App\Http\Requests\Director\RejectBatchRequest)
  — the existing reject() takes a bare Request, which is why validation is
  missing today. Keep the existing lockForUpdate + already-decided no-op guard
  exactly as it is.
- The reject modal gets a required textarea with a live character counter and
  a disabled submit until the minimum is met. Server-side validation is the
  real gate; the client hint is convenience only.

CHANGE 3 — College Admin can read the reason
- On Batch Tracking (resources/views/admin/batches/index.blade.php): when a
  row's status is `rejected`, a "Reason" column appears beside Status with a
  View button on that row; approved/pending rows show an em dash. The column
  header should only render when at least one rejected row exists in the list.
- The button opens a modal showing the reason, the reviewing Director's name
  and reviewed_at. Follow the existing modal conventions in the codebase
  (x-logout-confirm is the reference).
- Escape the reason on output — it is Director-supplied free text rendered to
  another user. Use {{ }}, never {!! !!}.
- This view was restructured for mobile in the previous branch; the new column
  must work in BOTH the desktop table and the mobile stacked-card rendering.

TESTS (required — this is the /laravel-tdd pass):
- Approve ignores a scheduled_date injected into the POST body and uses
  requested_date.
- Approve on a NULL-requested_date batch is rejected.
- Reject without a reason fails validation; with a <10-char reason fails;
  with a valid reason persists it and flips status.
- Rejecting an already-decided batch still no-ops and does not overwrite an
  existing reason.
- A College Admin from college A cannot see college B's rejection reason.
- Keep all queries portable — the suite runs on SQLite in-memory.

Docs to update in the same change:
- docs/HealthPass_PRD.md:
  * Decisions Log: new entry D-36, explicitly stating it SUPERSEDES D-29,
    with the audit-trail rationale.
  * Mark D-29 as superseded in place rather than deleting it.
  * Amend FR-DIRA-02 (approval) and FR-DIRA-04 (rejection) to match.
  * Data dictionary: batch_requests.rejection_reason.
  * Amend FR-ADM-05 (Batch Tracking) for the new Reason column.
  * Add a revision-history row.
- CLAUDE.md: update the locked-decisions bullet that currently reads
  "Director-confirmed at approval (the Director may adjust — D-29)" so it
  reflects D-36.
- docs/HealthPass_Context.md if it describes the approval flow.
- CHANGELOG.md.

DO NOT COMMIT. Leave everything uncommitted, print the test results verbatim
(including any failures), and summarise changed files.
```

---

## PROMPT 4 — Hourly time slots + capacity rework (Decision D-37)

```
Branch off main: feature/appointment-time-slots

Every appointment now has a scheduled TIME, and capacity is enforced per hour
instead of per day. This is the largest change in the queue — read
docs/HealthPass_PRD.md BR-01/BR-02/BR-04 and FR-STU-03/04, FR-ADM-04,
FR-DIRA-02/06 before writing code.

THE RULE (locked with Nat — implement exactly, do not propose alternatives):
- The clinic day is TEN one-hour slots, 7-8 AM through 4-5 PM, lunch hour
  (12-1 PM) INCLUDED as a bookable slot.
- A kiosk session is assumed to take at most 5 minutes → 12 students per hour.
- Therefore: 12 appointments per hour, 120 per day.
  config('healthpass.daily_capacity') changes from 40 to 120 and a new
  'hourly_capacity' => 12 is added. Both stay config values, not constants in
  a controller. Derive the slot list from the existing clinic_hours open/close
  config so 7-5 is not hardcoded in a view.
- Medical and dental share ONE counter. A dental appointment consumes an
  hour-slot exactly like a medical one — the constraint being modelled is
  clinic congestion, not kiosk throughput alone. Say this in the PRD.
- A day is fully booked when every slot in it is at 12. A College Admin must
  not be able to schedule into a full day OR a full slot.

SCHEMA:
- New nullable column appointments.scheduled_time (TIME) via migration.
  Nullable so pre-existing rows stay NULL — do NOT backfill them to 07:00 and
  do NOT wipe or reseed anything. Legacy rows render as "—" in the UI.
  New bookings require a time.
- New nullable columns on batch_requests for the requested start time and the
  computed span (requested_time, plus however many hours the batch occupies —
  choose the minimal representation and justify it; a stored end time or a
  stored block count, not both).
- Add whatever composite index makes the per-slot count query fast; the table
  already has index(['scheduled_date','status']).

STUDENT SELF-BOOKING (FR-STU-03/04):
- After picking a date, the student picks a one-hour slot. Each slot shows
  remaining capacity and full slots are disabled. A date with zero free slots
  is greyed out in the calendar exactly as fully-booked days are today.
- Extend the existing availability JSON endpoint in
  App\Http\Controllers\Student\BookAppointmentController rather than adding a
  new one, and keep fullDaysForMonth()'s SQLite-portable grouping style —
  no MySQL-only date functions in selectRaw/havingRaw (see CLAUDE.md).
- The BR-04 duplicate check (one active appointment per student/service/date)
  is unchanged — it stays per DATE, not per slot.
- CRITICAL: the existing store() re-checks capacity under lockForUpdate()
  inside the transaction because the Form Request's read races. The new
  per-slot check must be inside that same locked block. Do not move capacity
  enforcement into the Form Request only.

COLLEGE ADMIN BATCH REQUEST (FR-ADM-04):
- The admin picks a date and a START hour. The system computes the span as
  ceil(students / 12) contiguous hour-blocks and shows it back, e.g.
  "30 students → 7:00 AM – 10:00 AM (3 slots)".
- Submission is blocked if ANY block in the computed span is already at
  capacity, or if the span would run past 5 PM. The error must name the
  offending hour, not just say "full".
- Enforce this in the Form Request AND re-check under lock at write time,
  same reasoning as above.
- A batch of >120 students cannot fit in one day — reject it at validation
  with a clear message.

DIRECTOR APPROVAL (FR-DIRA-02/06) — depends on prompt 3 being merged:
- Approval is already confirm-only after D-36. It now also confirms the time
  span, displayed read-only.
- CHANGED BEHAVIOUR: capacity at approval becomes a HARD BLOCK, replacing
  today's warn-but-allow (FR-DIRA-06). If any hour in the batch's span is at
  capacity when the Director opens or submits the approval, approval is
  refused and the Director is told to reject with a reason so the admin can
  resubmit. Amend FR-DIRA-06 in the PRD accordingly.
  Be aware and state plainly in the docs: combined with D-36's confirm-only
  approval, a batch whose slots filled up between submission and review has
  exactly one outcome — rejection and resubmission. That is the intended
  workflow, not a bug.
- The fan-out writes scheduled_time onto every generated appointment,
  distributing students across the span 12 per hour in a deterministic order.

TESTS (required):
- Slot list derives from config, not hardcoded.
- 12th booking in a slot succeeds, 13th fails with the right message.
- A day with all 10 slots full is reported as a full day by the availability
  endpoint.
- Concurrent booking into the last seat in a slot: only one wins.
- Batch of 25 spans 3 hours; batch of 24 spans 2; batch of 121 is rejected.
- Batch span that would cross 5 PM is rejected.
- Director approval hard-blocks on a full slot in the span.
- Fan-out assigns exactly 12 per hour and the last block takes the remainder.
- Legacy NULL-time appointments do not break any list, count, or the nurse
  queue.
- All queries portable to SQLite.

Docs to update in the same change:
- docs/HealthPass_PRD.md:
  * Decisions Log: new entry D-37 covering hourly slots, 12/hour, 120/day,
    shared medical+dental pool, and the FR-DIRA-06 warn→block change.
  * Amend BR-01 (clinic hours), BR-02 (capacity — this is the 40→120 change),
    and add a new business rule for the per-hour cap and the batch span rule.
  * Data dictionary: appointments.scheduled_time and the new batch_requests
    columns.
  * Amend FR-STU-03, FR-STU-04, FR-ADM-04, FR-DIRA-02, FR-DIRA-06.
  * Revision-history row.
- docs/HealthPass_Context.md — schema section.
- CLAUDE.md — the locked-decisions bullet about clinic capacity being a config
  value needs the hourly cap added.
- CHANGELOG.md.

DO NOT COMMIT. Print test results verbatim and summarise changed files.
```

---

## PROMPT 5 — Appointment email notifications

```
Branch off main: feature/appointment-emails

Students receive an email when an appointment is created for them.

SCOPE (locked with Nat):
- Batch appointments: one email per student when the Director APPROVES the
  batch. This is the main ask — students currently have no idea their college
  admin booked them.
- Self-booked appointments: the student also gets a confirmation email for
  their own booking, so the inbox experience is consistent.
- Queued, not synchronous. QUEUE_CONNECTION is already 'database'. Approving
  a 60-student batch must not block the Director's browser or let an SMTP
  failure roll back the approval transaction. Dispatch AFTER the transaction
  commits — inside it, a failed send would roll back real appointments.

EMAIL CONTENTS — everything the student needs, no login required to read it:
  - Their name and the appointment reference number (APT-YYYY-####)
  - Service type (Medical / Dental)
  - Scheduled DATE and the one-hour TIME SLOT (e.g. "9:00 – 10:00 AM").
    The time column ships in the previous branch; this prompt depends on it.
  - Purpose / reason for the clearance
  - Whether it was self-booked or booked by their college
  - Clinic location and what to bring
  - A pointer to the kiosk tutorial page
  - Cancellation guidance: batch-booked students cannot self-cancel — tell
    them to contact their college admin. Verify that is actually true in
    BookAppointmentController::cancel() before writing it; if the code says
    otherwise, tell me rather than writing a false statement into an email
    that goes to real students.
DO NOT include: any clearance outcome, fit/unfit status, vitals, or health
data. This is a scheduling notice. Locked project rule — the kiosk and its
outcomes are never surfaced to the student this way.

IMPLEMENTATION:
- Follow the existing App\Mail\OtpVerificationMail + resources/views/mail/
  pattern — same structure, same Blade layout conventions. One new Mailable.
- One queued job per student, not one job that loops 60 students, so a single
  bad address does not lose the rest. Set sane tries/backoff.
- Never let a mail failure break the booking or the approval. Log failures
  with enough context to trace which appointment they belong to; do not
  swallow them silently.
- Rate-limit or throttle the batch dispatch if the provider needs it — check
  before assuming; do not add a package for this.
- Add MAIL_* guidance to .env.example. The app currently runs MAIL_MAILER=log,
  which is correct for dev — do NOT change the default. Real SMTP is a
  deployment concern.

SECURITY (this is the /laravel-security pass):
- The recipient address comes from the User record, never from a request.
- Escape everything rendered into the mail Blade; reason_detail and
  purpose_other are free text supplied by a College Admin and a student.
- No PII in log lines beyond the appointment reference and user id.
- Confirm no route or signed URL in the email exposes anything without auth.

TESTS (required):
- Mail::fake(): approving a batch of N queues exactly N mails, addressed to
  the right students, containing the right date and slot.
- Self-booking queues exactly one.
- The mail is dispatched only after commit — a failed/rolled-back approval
  queues nothing.
- A rejected batch queues nothing.
- Legacy appointments with NULL scheduled_time render without error.

Docs to update in the same change:
- docs/HealthPass_PRD.md — new FR-ID for the notification under the student
  module, a Decisions Log entry for the queued-email approach, and a
  revision-history row.
- docs/deployment-hosted.md — a section on running `php artisan queue:work`
  as a supervised process, plus the SMTP env vars the hosted deploy needs.
  Without a running worker these emails silently never send; say so loudly.
- docs/deployment-pi.md — note what happens to the queue on the offline
  fallback shape (no internet = no mail; jobs pile up and drain on reconnect).
- docs/dev-notes.md — how to see the mails locally with MAIL_MAILER=log.
- CHANGELOG.md.

DO NOT COMMIT. Print test results verbatim and summarise changed files.
```

---

## Cross-cutting reminders for every session

- Feature branch off `main`, never commit to `main`.
- `main` must be up to date before branching — merge the previous branch first.
- Never run `migrate:fresh` or anything destructive without asking.
- Test suite runs on SQLite in-memory; keep raw SQL portable.
- Nat and Baldo are new to Laravel — explain any Laravel concept the first
  time it appears (Form Requests, queued jobs, Mailables, migrations with
  nullable backfill).
- No AI/predictive features, ever.
