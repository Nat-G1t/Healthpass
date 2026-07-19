# Analytics Rescope — Phase 1: PRD v1.11 + D-32/D-33 (documents only, NO code)

> Paste to run: `Read docs/prompts/analytics-rescope-phase1-prd.md and execute it.`
> No skill invocation needed for this phase — it is documentation-only.

## Context (decided by Nat, 2026-07-18 — do not re-litigate)

The Medical Cases analytics is **out of capstone scope**: HealthPass only ever
sees clearance encounters, never walk-in consultations, so a "Summary of
Medical Cases" can't represent the clinic's caseload and is indefensible at
panel. It is replaced by analytics over data the system itself collects.
The replacement design is **locked** — the approved visual mockup is
`docs/prototypes/web/director-analytics-rescope.html` (open in a browser).

## Setup

- Work directly in `C:\Capstone\healthpass` on branch
  **`feature/analytics-rescope`** (`git checkout feature/analytics-rescope`).
  **Never use a worktree** — the dev server serves this directory.
- Read first: `docs/HealthPass_PRD.md` §4.9 (Module ANL), the decisions log,
  the data dictionary, the changelog table; `docs/HealthPass_Context.md`
  schema section; `docs/qa/traceability-template.csv`; `CLAUDE.md`.
- Touch **no PHP/JS/Blade** in this phase.

## Changes to write

### 1. PRD changelog — version 1.11, dated July 18 2026, author N. Medina

Summarize D-32 + D-33 (below) in the same style as existing rows.

### 2. Decision D-32 — Director analytics rescoped to system-collected data

- Drops FR-ANL-02/03/06/08 and the case-category concept. **Supersedes D-23**
  (table #11 `clearance_case_categories` is removed — schema is now **10
  tables**) and **amends the locked decision** "the Nurse encodes Fit/Unfit +
  case category" → the Nurse encodes **Fit/Unfit only**. D-30 and D-31 become
  moot (note this in D-32; do not delete their rows).
- FR-ANL-06 (Export/print) is removed **entirely** — analytics is on-screen
  only: no monthly print, no analytics CSV, no Flagged Anomalies CSV.
- Rationale to record: defense risk ("does this include walk-ins?" — no);
  the replacement is the census of its own data; nurse encode sheds a step
  borrowed from a different clinic function.

### 3. Decision D-33 — Dental appointments link at the kiosk (amends D-3)

- Today (D-3) a dental-only student already runs the full kiosk flow but the
  visit is stored as a walk-in (`appointment_id` NULL). New rule: **kiosk
  submit links today's dental appointment** (server-side resolution, same
  trust rules). Flow is otherwise IDENTICAL — vitals + questionnaire + nurse
  queue; the existing encode step already marks the linked appointment
  completed, which now works for dental too, and gives dental visits a
  capture-time college snapshot.
- Edge rule: if a student has BOTH a medical and a dental appointment today,
  the **medical appointment wins** the link; the dental one stays `scheduled`
  (rare; record as a stated limitation in the FR).

### 4. Rewrite Module ANL requirements

Mark FR-ANL-02, -03, -06, -08 as **Superseded (D-32)** — keep the rows,
strike the text, do NOT renumber (the QA traceability matrix must keep its
audit trail; a documented descope is a defense strength). Amend and add:

- **FR-ANL-04 (amend):** retitle "Students Screened by Sex"; now also obeys
  the college filter.
- **FR-ANL-05 (amend):** drop the Category column from the table.
- **FR-ANL-07 (rewrite):** analytics compute from **captured** kiosk data
  (medical visits count at check-in; flags count from capture) and from
  **completed** dental appointments. The encoded-only rule dies with the
  case statistics.
- **FR-ANL-09 (new):** Clinic Visits by College — horizontal stacked bar, one
  row per college (all 12, zero rows included), segments Medical `#FF8C2A` /
  Dental `#2563EB`, sorted by total descending, total-visits headline,
  "View as table" toggle (college × service type). Medical = kiosk check-ins
  (`clinic_visits`, capture-time `college_id` snapshot); Dental = completed
  dental appointments (grouped by the student's current college — no
  capture-time snapshot exists for dental; state this limitation).
  Includes a **Visits by Purpose** mini bar chart inside the same card,
  sourced from the linked appointment's `purpose`/`purpose_other`; visits
  with no linked appointment or no purpose fall into a "Walk-in / not
  specified" bucket.
- **FR-ANL-10 (new):** Vital-Sign Flags card — three stat tiles (High BP,
  Fever, Abnormal BMI) each showing count **and rate (% of the month's
  captured screenings)**; obeys month + college filters. Row-level detail
  remains FR-ANL-05.
- **FR-ANL-11 (new):** Visits per Month trend — line chart across all months
  with data, TWO series: medical screenings and completed dental
  appointments; ignores the month filter by design.
- **FR-ANL-12 (new):** BMI Distribution — four rule-based buckets
  (Underweight < 18.5, Normal 18.5–24.9, Overweight 25–29.9, Obese ≥ 30)
  of captured screenings; obeys both filters. Descriptive only — no
  profiling (the no-AI lock stands).
- **FR-ANL-13 (new):** Page filters — the existing month picker plus a new
  college dropdown (default "All colleges"); both scope every card except
  the FR-ANL-11 trend.
- Rewrite the §4.9 **AC** line to match (e.g., seeding N medical + M dental
  visits in a college yields exactly those bars; a flagged captured visit
  counts in FR-ANL-10 immediately; superseded features absent from the page).

### 5. Mirror everywhere the old scope is stated

- `docs/HealthPass_Context.md`: 11-table schema → 10 tables; remove
  `clearance_case_categories`; adjust any case-analytics description.
- `CLAUDE.md`: the Database section's table list; the locked-decisions bullet
  about nurse encoding ("Fit/Unfit + case category" → "Fit/Unfit only");
  "dental is scheduling-only" → note D-33 kiosk linking.
- `docs/qa/traceability-template.csv`: mark FR-ANL-02/03/06/08 superseded
  (D-32), add FR-ANL-09..13 rows.
- Data dictionary: remove table #11 and any case-category columns.

### 6. Known wart — do not fix silently

The PRD decisions log has TWO rows numbered D-21. Leave them; flag to Nat
again in your summary.

## Gate (Nat has full supervision)

Show a summary of every file changed with the key wording of D-32/D-33 and
the new FRs, then **STOP and wait for Nat's approval**. Only after approval,
commit everything as ONE commit:
`docs: PRD v1.11 - D-32 analytics rescope + D-33 dental kiosk linking`
(no day tag — Nat assigns those). Do not push unless Nat says to.
