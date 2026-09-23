# 07 — Kiosk: "You've already completed today's screening"

Branch: `feature/pre-deployment-fixes-2`. **Do not commit.** Stop at the
verification block and wait for Nat's `commit`.

**New FR-KSK-03b; amends FR-KSK-12.** No decision number (it enforces the
existing one-visit-per-appointment intent of D-61 rather than changing a
rule). No schema change, no new package.

## Why

Nat: "After a student has finished the kiosk for their scheduled date and
submitted, if they log in on the kiosk again, the kiosk should show that
they've already used their slot for that day."

## What happens now (a real bug, not only a missing screen)

Confirm each point before changing anything:

- Kiosk submit (`app/Actions/Kiosk/SubmitKioskVisit.php`) creates a `captured`
  visit linked to today's appointment, but **does not change the appointment**.
  Only the clinic's encode sets it to `completed`
  (`Nurse\EncodeController.php:~162`).
- `Appointment::todayFor()` (`app/Models/Appointment.php:92`) returns today's
  appointments with status `scheduled`. So:
  - **between submit and encode**, a second kiosk login finds the same
    appointment, walks the whole flow again, and a second submit creates a
    **second `captured` visit**: two queue entries for one student.
    Reproduce this and say so in the report;
  - **after encode**, the appointment is `completed`, `todayFor()` returns null,
    and the kiosk shows **"No Clinic Schedule Today"**, which is wrong: they had
    one and used it.
- `todayFor()` is shared by scan/login (`KioskController::identityPayload`,
  ~line 354), submit (`SubmitKioskVisit` ~line 109) and
  `KioskSubmitRequest` (~line 103). CLAUDE.md: *"One shared resolution … serves
  scan/login and submit, so the screens a student saw and the row the server
  writes can never be about different appointments."* Keep it shared.
- A **resting** visit (D-72) is not "submitted" (`ClinicVisit::SUBMITTED_STATUSES
  = ['captured','encoded']`, `scopeSubmitted()`). The re-check path
  (`ClinicVisit::restingTodayFor`) must keep working **exactly** as it is.

## What to do (settled with Nat)

Nat chose: the screen shows the **visit reference** (`HP-YYYY-####`), and
**never** Fit/Unfit (CLAUDE.md: never show clearance outcomes on the kiosk).

### 1. One definition of "used"

- `Appointment::todayFor()` skips appointments that already have a
  **submitted** visit (`captured` or `encoded`). Express it as a
  `whereDoesntHave('clinicVisits', fn ($q) => $q->submitted())` (or whatever
  the relation is called; check `Appointment`'s relations). Don't hand-list the
  statuses again. A resting visit does not count as used, so re-check is untouched.
  With D-77 (prompt 06) a student has at most one appointment a day from now
  on, but legacy data may have two. This definition lets a second, unused
  appointment still through, which is the right answer.
- A new static on `ClinicVisit`, next to `restingTodayFor()`:
  `submittedTodayFor(int $studentId): ?self`, the student's latest
  **submitted** visit whose appointment is dated today. Anchor it on
  `appointments.scheduled_date = today` via the relation, **not** on the visit's
  timestamp, and say why in its docblock. Keep the SQL portable (`whereDate`
  and relations, no raw functions); SQLite runs the suite.

### 2. Scan / login payload (`KioskController::identityPayload`)

Add, computed server-side like `hasAppointmentToday`:

- `alreadyScreenedToday`: true when `todayFor()` is null **and**
  `submittedTodayFor()` is not;
- `screenedReference`: that visit's `reference_no` (only when true);
- `screenedStatus`: `'captured'` or `'encoded'` (only when true). This drives
  one sentence of wording, nothing else. It is not an outcome.

### 3. The screen

- New partial `resources/views/kiosk/screens/already-screened.blade.php`,
  modelled on `no-schedule.blade.php` (same layout, icon tile, sizes, single
  button). Include it in `kiosk/index.blade.php` next to `no-schedule`.
  Screen key `already-screened`.
- Copy:
  - Title: **"You're all done for today"**
  - Line: **"You've already completed today's clinic screening."**
  - `Reference HP-2026-0042` (same style as the Complete screen's reference line)
  - If `captured`: **"Please proceed to the clinic and wait to be called."**
    If `encoded`: **"The clinic has already seen you today."**
  - Button: **"Back to start"** → `reset()`.
- Idle auto-reset: whatever `no-schedule` does. Check `state-machine.js` for an
  idle timer per screen and give the new screen the same one.
- `confirmIdentity()` (`resources/js/kiosk/state-machine.js:~1195`): after the
  D-72 re-check branch, **before** the no-schedule decision:
  `alreadyScreenedToday` → `go('already-screened')`. The re-check branch stays
  first.
- The dev-only screen jumper (`kiosk/partials/dev-jumper`) lists screens.
  Add it there if the jumper enumerates them.

### 4. The server refuses a second submit (the actual gate)

The screen is a courtesy; the gate is the server (same rule as FR-KSK-03a).

- In `SubmitKioskVisit`, after `todayFor()` comes back null, distinguish the two
  refusals: if `submittedTodayFor()` finds a visit, throw with a new
  `ALREADY_SCREENED_MESSAGE` (*"You've already completed today's clinic
  screening. Please proceed to the clinic."*); otherwise the existing
  `NO_SCHEDULE_MESSAGE`.
- **Race:** two terminals, or a double-tap, can both pass `todayFor()` before
  either inserts. Inside the existing `DB::transaction`, lock the appointment
  row (`Appointment::whereKey($id)->lockForUpdate()->first()`), then re-check
  that it has no submitted visit, *before* `ClinicVisit::create`. That's the
  same pattern the capacity re-checks use.
- `KioskSubmitRequest` (~line 103) reads `todayFor()` for the form type. With a
  used appointment it will fall back to `'clearance'`, and the submit is then
  refused by the action. Confirm that ordering produces the new message, not a
  confusing validation error about form fields.
- The D-72 **rest** endpoint also creates a visit. Does it go through the same
  `todayFor()`? It should get the same protection. Check and say.

## Docs to update in this same change

- `docs/HealthPass_PRD.md`: new **FR-KSK-03b** after FR-KSK-03a:
  *"Already screened (after Identity Confirm, before the schedule check). When
  the student has no unused `scheduled` appointment today but already has a
  submitted visit (`captured` or `encoded`) on today's appointment, the kiosk
  shows 'You're all done for today' with the visit reference and one 'Back to
  start' button; it never shows Fit/Unfit. The server refuses a second submit
  for an appointment that already has a submitted visit, under a row lock. A
  resting visit (D-72) is not a submitted visit."*
- **FR-KSK-12**: add that submit refuses an appointment that already has a
  submitted visit (FR-KSK-03b).
- §UI screen list (the "Kiosk (`/kiosk`)" row, ~line 611): add
  `Already Screened (FR-KSK-03b)`.
- Next **revision-history row**. No schema change.
- `docs/HealthPass_Context.md` → KIOSK: the new screen in the flow, one line.
- `CHANGELOG.md`: one entry. `docs/qa/e2e-scenarios.md`: a "second kiosk
  login the same day" step.

## Tests

- Kiosk scan/login feature tests:
  - student with a `captured` visit on today's appointment → payload
    `alreadyScreenedToday: true`, `screenedReference`, `screenedStatus: 'captured'`,
    `hasAppointmentToday: false`;
  - same after encode → `screenedStatus: 'encoded'`;
  - a **resting** visit → *not* already screened (re-check payload as before);
  - no appointment at all → `alreadyScreenedToday: false` (no-schedule path).
- Submit: a second submit for the same appointment → 422 with
  `ALREADY_SCREENED_MESSAGE`, and exactly **one** visit row.
- Legacy: two scheduled appointments today, one used → the second is still
  resolved by `todayFor()`.
- The existing D-61 no-schedule and D-72 re-check tests stay green unchanged.

## Verify

1. `php artisan serve --port=8080`, `npm run dev`. Open `http://127.0.0.1:8080/kiosk`.
2. A student with a batch appointment today: complete the kiosk → Complete
   screen. Log in again (QR or email) → **You're all done for today**, with the
   same HP reference, and "please proceed to the clinic…". Back to start works.
3. Encode that visit as nurse. Log in on the kiosk again → same screen, "The
   clinic has already seen you today". **No** Fit/Unfit anywhere.
4. Replay the submit request from the network tab → 422 with the new
   message; the Live Queue shows the student **once**.
5. A student with a first-pass high temp (D-72 rest): the re-check still works
   exactly as before.
6. A student with no appointment → "No Clinic Schedule Today" as before.
7. `php artisan test`: full suite green.

Then **stop and report**: whether the duplicate-visit bug reproduced, the
changes, whether the rest endpoint needed the same guard, and the test result.
Wait for `commit`.

Proposed commit message:

```
fix: the kiosk refuses a second visit on a used appointment and says so (FR-KSK-03b)
```
