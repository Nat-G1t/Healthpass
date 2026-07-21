# Analytics Rescope — Phase 3: Dental appointment linking at the kiosk (D-33)

> Paste to run: `Read docs/prompts/analytics-rescope-phase3-dental-link.md and execute it. Invoke the laravel-tdd skill before writing tests, the laravel-security skill before touching the kiosk submit path, and the verify skill before finishing.`
> Prerequisite: Phase 1 (PRD v1.11) and Phase 2 (removal) commits exist on this branch. If not, STOP and tell Nat.

## Context

Decision D-33 (PRD v1.11 — read it first): today a dental-only student
already completes the full kiosk flow, but the visit is stored as a walk-in
(`appointment_id` NULL) per old D-3, so the dental appointment is never
completed. New rule: **kiosk submit links today's dental appointment**. The
flow itself does not change — no new screens, no questionnaire branch. Once
linked, the EXISTING nurse-encode step (`Nurse\EncodeController`, the
`$visit->appointment?->update(['status' => 'completed'])` line) completes
dental appointments with zero changes, and dental visits get a capture-time
college snapshot like any visit.

## Setup

- Work directly in `C:\Capstone\healthpass` on branch
  **`feature/analytics-rescope`**. **Never use a worktree.**
- Read: PRD D-33 + §4.6/kiosk module; `CLAUDE.md` kiosk architecture rules;
  `app/Http/Controllers/Kiosk/KioskController.php` — especially the submit
  path where today's appointment is resolved server-side, and
  `hasAppointmentToday()` (whose doc comment still describes D-3 — update it).

## Change

In the kiosk submit's server-side appointment resolution:

1. Resolve today's non-cancelled appointments for the session-bound student
   (identity from the server session, NEVER from client payload — the
   existing trust rule stands; do not loosen any of it).
2. Link by priority: today's **medical** appointment if one exists, else
   today's **dental** appointment, else NULL (true walk-in — unchanged).
3. Both-booked edge (D-33): medical wins; the dental appointment stays
   `scheduled`. Add a code comment citing D-33.
4. Update the stale D-3 comments in `KioskController` to cite D-33.

Keep the diff minimal — this is a linking-rule change, not a kiosk redesign.
If the submit path's appointment resolution looks materially different from
what this prompt assumes, STOP and show Nat before improvising.

## Tests (write first — laravel-tdd)

Feature tests (SQLite in-memory, portable SQL only):

- Dental-only student today → submit links the dental appointment
  (`appointment_id` set, visit college snapshot recorded).
- Nurse encodes that visit → the dental appointment becomes `completed`.
- Student with BOTH appointments today → medical linked, dental stays
  `scheduled`.
- No appointment today → still recorded as walk-in (regression guard).
- Client-supplied appointment/student IDs in the submit payload are ignored
  (trust-boundary regression guard, if not already covered).

## Verify

- Full suite green: `php artisan test`.
- Invoke the **verify** skill: run the kiosk flow for a seeded dental-only
  student end-to-end (scan/login → vitals → submit) and confirm the linkage
  in the DB, then encode as nurse and confirm the appointment completes.

## Gate

Present the diff + test results, then **STOP for Nat's approval** before
committing: `feat(kiosk): link dental appointments at submit (D-33)`
(no day tag). Do not push unless Nat says to.
