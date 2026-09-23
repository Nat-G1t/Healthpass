# 06 — One clinic schedule per student per day (D-77)

Branch: `feature/pre-deployment-fixes-2`. **Do not commit.** Stop at the
verification block and wait for Nat's `commit`.

**Decision D-77** (check the log: D-76 was the last when this was written).
Amends **D-54 / BR-25**, **FR-ADM-04**, **FR-DIRA-02**. No schema change, no new
package, no new route.

## Why

Nat: "If the College Admin requests a date on the batch request page, and
one or more of the students they picked already has a schedule on that date,
there should be a pop-up saying those students already have a scheduled
Medical Clearance or Medical Assessment (whichever it is) for that day, with
the option to remove them or choose students again."

## What exists already (D-54): read it first

- `app/Services/ScheduleClashService.php` is the **one definition** of a
  clash, used by the College Admin's submit (`StoreBatchRequestRequest` ~line 199,
  re-checked under lock in `Admin\BatchRequestController` ~line 269) **and** the
  Director's approval (`Director\BatchApprovalController` ~line 216).
- Today a clash is an **hour overlap** (rule 1 in the class docblock):
  a 9–11 batch clashes with a 10–11 batch the student is on, but a 7–8 and a
  2–3 on the same day **don't** clash. That gap is what Nat hit.
- Holding rules (rule 2): `pending` and `approved` batches hold; `rejected`,
  `cancelled` and a **withdrawn** student (appointment `cancelled`) don't.
- Each clash comes back as `user id => ['on batch BR-2026-004, 9:00 AM – 11:00 AM']`.
  `StoreBatchRequestRequest::clashErrors()` turns them into one validation error
  per student, keyed `clashes.<student_profile_id>`.
- `resources/views/admin/batches/create.blade.php` collects those keys (~line 31)
  and opens a popup on page load (~line 752–827): title "Some students are
  already scheduled at this time", with **Close** and **Remove these students
  from the batch** (`removeClashingStudents()`).
- The Director's refusal text is `BatchApprovalController::clashMessage()`
  (~line 389): "…are already scheduled during its hours…".
- Unit tests: `tests/Unit/ScheduleClashServiceTest.php`. Feature tests:
  `tests/Feature/Admin/BatchRequestSubmitTest.php` and the Director approval tests.

## D-77, as settled with Nat

1. **A clash is the same DATE, not an overlapping hour.** A student may hold
   only **one** clinic schedule per day. Any `pending` or `approved` batch the
   student is on for that `requested_date` clashes, whatever its hours.
2. **Any form.** A Medical Clearance batch and a Medical Assessment Form batch on
   the same date clash with each other.
3. **Same holding rules as D-54**: pending/approved hold; rejected, cancelled
   and withdrawn don't. First come wins; the later write is refused.
4. **Both gates**: the College Admin's submit **and** the Director's approval,
   through the one service. So of two pending batches with the same student on
   the same day, only the first can be approved.
5. **Timing stays "on submit"**: Nat chose to extend today's popup, not add a
   live check. No new endpoint.
6. Pre-D-37 batches with no hour span now **also** clash on their date: a day
   rule needs no hour. (In practice they are all in the past; say so in the
   PRD text rather than special-casing them.)

## What to do

### Service (`ScheduleClashService`)

- Rewrite the docblock's rule 1 and rule 3 to D-77 (keep the history line
  "D-54 made it an hour overlap; D-77 widened it to the whole day").
- `clashesForBatch()` no longer needs `$span`. Remove the parameter, the
  `$span === []` early return and the `array_intersect` test, and update
  **all** callers (`grep -rn clashesForBatch app tests`). If removing the
  parameter turns out messier than expected, say so. Don't leave a dead
  parameter in silently.
- The message per clash names the **form** and the batch:
  `Already scheduled for a Medical Clearance that day, on BR-2026-004 (9:00 AM – 11:00 AM)`.
  The form label comes from `BatchRequest::FORM_TYPES[$batch->form_type]`
  (`'Medical Clearance'` / `'Medical Assessment Form'`). **Don't** hard-code the
  words. When the other batch has no span, drop the bracketed hours.
- The query (`batchPlaces()`) already filters by date and status. It doesn't
  change beyond dropping the overlap test, and it stays portable (no raw SQL).
  `with('batchRequest')` must include `form_type`.

### New Batch Request popup (`admin/batches/create.blade.php`)

- Title: **"Some students already have a clinic schedule that day"**.
- Body: **"A student can have only one clinic schedule per day. Remove them from
  this batch, or choose students again."**
- The list stays as it is (name · number, then the service's message line,
  which now names the form).
- Buttons (the existing classes):
  - **Remove these students from the batch**: unchanged (`removeClashingStudents()`).
  - **Choose students again** replaces "Close". Nat's choice: it **closes the
    popup, keeps the whole selection**, scrolls the "Select Students" card into
    view, and **marks** the clashing students in the table (a small
    `Already scheduled` badge in the row, `x-hp.badge` in the flagged/warning
    variant the app already uses, plus the row keeps its normal
    selected/unselected styling). The admin can then untick them one by one,
    or change the date. The marks come from the same `clashes` array. They
    stay until the page is next submitted. Esc and backdrop click behave like
    "Choose students again" but without the scroll.
- If prompt 05 (program filter) has run, a marked student may be hidden by
  the current program filter. That's fine; the badge shows when they are
  visible. Don't reset the filter.

### Director approval

- `clashMessage()`: "…are already scheduled **that day**…" instead of "during
  its hours". Keep the three-names-then-"and N more" shape.
- Check the approval page and modal for any other "overlap"/"during its hours"
  wording (`grep -rn -i "during its\|overlap" resources/views/director app/Http/Controllers/Director`)
  and bring it in line.

## Docs to update in this same change

- `docs/HealthPass_PRD.md`, **Decisions Log**: new row **D-77**: *"One clinic
  schedule per student per day. A batch clashes with any other pending or
  approved batch the student is on for the same date, whatever its hours and
  whatever its form (Clearance or Assessment). Amends D-54 rule 1 (hour overlap
  → same date) and BR-25; both gates (College Admin submit, Director approval)
  read the one definition in ScheduleClashService. The New Batch popup names
  the other batch's form and offers Remove / Choose students again. No schema
  change."* Add a ⚠️ "AMENDED BY D-77" marker at the start of D-54's row, the
  way D-61 marked it.
- **BR-25**: rewrite clause (1) to the date rule; strike the "Rows with
  `scheduled_time` NULL never clash" sentence (`~~…~~`, the file's
  convention) with a D-77 note; keep (2) and the first-come-wins text.
- **FR-ADM-04** and **FR-DIRA-02**: wherever they say the refusal is for an
  overlapping hour, say "same day" and cite D-77.
- Next **revision-history row**: *D-77 — one clinic schedule per student per
  day; …* No schema change.
- `docs/HealthPass_Context.md` §5 **Batch requests**: the rule, one line.
- `CLAUDE.md` doesn't mention D-54's hour rule. Grep to confirm; if it does,
  update it.
- `CHANGELOG.md`: one entry. `docs/qa/e2e-scenarios.md`: update the D-54 clash
  scenario to a same-day, different-hour case.

## Tests

- `tests/Unit/ScheduleClashServiceTest.php`: update the overlap cases. New:
  - same date, **non-overlapping** hours → clash (this is the D-77 case);
  - same date, other **form** → clash, message says the other form's label;
  - different date → no clash;
  - rejected / cancelled batch, withdrawn student → no clash (unchanged);
  - a no-span (pre-D-37) batch on the same date → clash, message without hours;
  - `$exceptBatchId` still excludes the batch itself.
- `BatchRequestSubmitTest`: a submit for 2:00 PM refused because the student is
  on a pending 7:00 AM batch the same date; errors keyed `clashes.<profile id>`.
- Director approval test: of two pending same-day batches sharing a student,
  approving the first succeeds and the second is refused with "that day".
- Every test that asserted the old wording → the new wording.

## Verify

1. `php artisan serve --port=8080`, `npm run dev`.
2. As College Admin: submit batch A (date X, 7 AM, form Clearance, students S1,
   S2). Then batch B (date X, **2 PM**, form **Assessment**, S1 + S3) → the
   popup shows S1 with "Already scheduled for a Medical Clearance that day, on
   BR-…". Press **Choose students again**: popup closes, S1 still selected and
   marked "Already scheduled", page scrolled to the picker. Untick S1 → submit →
   accepted.
3. Repeat with **Remove these students from the batch**: S1 is deselected in one
   click.
4. As Director, with two *pending* same-day batches sharing a student: approve
   one → OK; approve the other → refused with the "that day" message.
5. A different date for batch B → no popup.
6. `php artisan test`: full suite green.

Then **stop and report**: the signature change and its callers, the wording on
both gates, and the test result. Wait for `commit`.

Proposed commit message:

```
feat: one clinic schedule per student per day (D-77)
```
