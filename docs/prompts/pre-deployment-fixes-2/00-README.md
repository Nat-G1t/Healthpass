# Pre-deployment fixes, wave 2 — session prompt queue

Nine prompts, one session each, **in order**. Every prompt is self-contained:
it names its own files, its own PRD / Context edits and its own verification
steps. Run one, verify it, say `commit`, then move to the next.

**No prompt commits anything by itself.** Each one stops after its verification
block and waits for Nat's explicit `commit`.

## Step 0 — branch (do this once, before prompt 01)

All eight wave-1 prompts are committed on `feature/pre-deployment-minor-fixes`
(last commit `65a83c9`) but not merged. Per the merge-before-branch rule:

```
git checkout main
git merge --ff-only feature/pre-deployment-minor-fixes
git checkout -b feature/pre-deployment-fixes-2
```

Local only — **do not push**. If `--ff-only` refuses, stop and tell Nat; do not
fall back to a merge commit or a rebase on your own. This folder is untracked,
so it survives the branch switch; it gets committed with prompt 01.

| # | File | Nat's fix | Decision / FR |
|---|------|-----------|---------------|
| 01 | `01-registration-card-and-name.md` | Wider registration card; "PamSU" → "Pampanga State University" on the consent page | — (presentation + copy) |
| 02 | `02-otp-one-digit-per-box.md` | One character per OTP box; letters are refused with a message — **all four OTP screens** | FR-REG-04 amended |
| 03 | `03-otp-already-verified.md` | Back from Link ID to the OTP page says the step is already done | FR-REG-04 amended |
| 04 | `04-dashboard-back-logout.md` | Back on any dashboard opens the Log out popup; no way back to a stale guest page | **FR-UI-07** |
| 05 | `05-batch-program-filter.md` | Program dropdown left of the search in Select Students | FR-ADM-03 amended |
| 06 | `06-batch-same-day-clash.md` | A student can hold only one clinic schedule per day; popup offers Remove / Choose students again | **D-77** |
| 07 | `07-kiosk-already-screened.md` | A student who already submitted today sees "already done", and can't submit twice | **FR-KSK-03b** |
| 08 | `08-bmi-flag-not-normal.md` | BMI flagged whenever it is not Normal (< 18.5 or ≥ 25); all past visits recomputed | **D-78** |
| 09 | `09-encode-back-to-dashboard.md` | The visit page's back link returns to where you came from | FR-NRS-09 amended |

## Decisions settled with Nat before these were written (2026-09-23)

1. **Registration card** — about **768px** on desktop, side margins kept; step 2
   puts short fields side by side. Phones stay single-column.
2. **Consent wording** — change only the university-name mentions ("PamSU",
   "PSU", "pamsu") on the consent page. **Never touch a form code**
   (`DHVSU-QSP-OSS-004-FO002-R03` and every other code stay byte-for-byte).
3. **OTP scope** — all four OTP screens: registration step 3, Forgot Password,
   Change Password, and the student's change-email verify.
4. **OTP letters** — a typed letter *shows* in its box; Verify is enabled once
   all six boxes hold a character; on submit the page says letters are not
   allowed, and no attempt is used up.
5. **OTP revisit** — a green "already verified" notice plus **Continue → Link ID**.
6. **Back → Log out** — all five roles' dashboards, and **always** on the
   dashboard (not only right after login). Nat accepted the side effect: Back
   on the dashboard asks to log out even if you arrived from another page.
7. **Program dropdown** — "All programs" + the college's **full catalog** from
   `config/programs.php`, even programs with no registered students yet.
8. **Same-day clash timing** — **on submit**, extending today's D-54 popup (no
   live check endpoint).
9. **"Choose students again"** — closes the popup, keeps the selection, scrolls
   to Select Students and marks the clashing students.
10. **Same-day clash scope** — any form type (a Clearance batch and an
    Assessment batch on one date clash), and the **Director's approval uses the
    same rule** (one definition in `ScheduleClashService`).
11. **Kiosk already-screened screen** — shows the visit reference `HP-YYYY-####`;
    never Fit/Unfit. The server also refuses a second submit.
12. **BMI** — flagged when **< 18.5 or ≥ 25.0**, and **all past visits are
    recomputed** by a data migration (Nat chose this over new-visits-only,
    knowing it rewrites past months' analytics).
13. **Visit page back link** — back to wherever you came from: the Clinic
    Dashboard with its filters and page kept, or the Live Queue as today.

## Numbering

When this was written the PRD's last decision was **D-76**, the last revision
row **1.52**, the last FR-UI **FR-UI-06**, and there was no FR-KSK-03b. The
prompts take D-77 (06), D-78 (08), FR-UI-07 (04), FR-KSK-03b (07), and the
next revision row each. **If you run them out of order, re-check the log
before you claim a number.**
