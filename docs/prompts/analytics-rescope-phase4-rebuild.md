# Analytics Rescope — Phase 4: Build the new Director Analytics (FR-ANL-09..13)

> Paste to run: `Read docs/prompts/analytics-rescope-phase4-rebuild.md and execute it. Invoke the laravel-patterns skill first, the dataviz skill before any chart code, the laravel-tdd skill before writing tests, and the verify skill before finishing.`
> Prerequisite: Phases 1–3 commits exist on this branch (PRD v1.11, removal, dental linking). If not, STOP and tell Nat.

## Context

PRD v1.11 FR-ANL-09..13 + D-32/D-33 are the spec — read §4.9 first. The
approved visual mockup is `docs/prototypes/web/director-analytics-rescope.html`
(open in a browser; match it, don't improvise). One sentence of intent:
every chart reads only data HealthPass itself collects — appointments from
the web app, vitals from the kiosk.

## Setup

- Work directly in `C:\Capstone\healthpass` on branch
  **`feature/analytics-rescope`**. **Never use a worktree.**
- Existing pieces to REUSE, not rebuild: the Chart.js Vite entry
  `resources/js/director/analytics.js`, the By-Sex donut, `CaseMonths`
  (generalize it — see below), the card layout in
  `resources/views/director/analytics.blade.php`, `x-hp.card`, the
  auto-submitting month `<select>` pattern.

## Build (per FR — verify column/status names against the actual schema before writing queries; if reality contradicts an assumption here, STOP and show Nat)

1. **Filters (FR-ANL-13):** generalize `CaseMonths` into visit months (months
   having any `clinic_visits` check-in OR any completed dental appointment),
   newest first. Add a college dropdown (all 12 by `code`, default "All
   colleges") next to the month picker; both auto-submit as GET params and
   scope every card except the trend. Validate both server-side (reject
   unknown month formats / college ids gracefully).
2. **Clinic Visits by College (FR-ANL-09):** one grouped query per source —
   medical: `clinic_visits` count per capture-time `college_id` for the
   month; dental: completed dental appointments per the student's current
   college. Stacked horizontal bar (Chart.js), Medical `#FF8C2A`, Dental
   `#2563EB` (pair CVD-validated 2026-07-18), all 12 college rows including
   zeros, sorted desc (stable tie-break by code), headline total, "View as
   table" `<details>` toggle (college × Medical × Dental × Total — this is
   the contrast relief for the orange). Inside the same card: **Visits by
   Purpose** mini horizontal bars (single muted hue, direct value labels)
   from the linked appointments' `purpose`; NULL appointment or NULL purpose
   → "Walk-in / not specified" bucket. Check `purpose` nullability in the
   migration before writing this query.
3. **Vital-Sign Flags (FR-ANL-10):** three stat tiles — High BP, Fever,
   Abnormal BMI — count + rate (% of the month's captured screenings,
   one decimal). Source: the `is_bp_flagged / is_temp_flagged /
   is_bmi_flagged` columns, ALL captured visits (no encoded-only guard).
   Server-rendered tiles, no chart needed. Note under the card: detail rows
   live on Flagged Anomalies.
4. **Visits per Month trend (FR-ANL-11):** line chart, two series — medical
   screenings (orange) and completed dental appointments (blue `#2563EB`),
   2px lines, across ALL months with data; ignores both filters (say so in
   its subtitle). Legend required (two series) + direct label on the latest
   point of each line.
5. **BMI Distribution (FR-ANL-12):** four ordinal buckets (Underweight
   < 18.5 / Normal 18.5–24.9 / Overweight 25–29.9 / Obese ≥ 30) of captured
   screenings' stored BMI values, horizontal bars in a single hue with
   direct labels; obeys both filters. Compute buckets in PHP or portable
   SQL — NO MySQL-only functions (suite runs on SQLite).
6. **Donut (FR-ANL-04 as amended):** retitle "Students Screened by Sex",
   make it obey the college filter; keep prototype colors orange/peach and
   the count+% legend.
7. **Layout:** exactly the mockup's order — filters row, Visits by College
   (with purpose inside), Vital-Sign Flags, then trend + donut side by side
   (stacking on narrow), BMI card last. Empty states in the house style
   ("No visits recorded for this month yet").
8. **Seeder:** rework `DemoClinicVisitSeeder` — multi-month spread (5–6
   months), varied colleges/purposes/sexes, some flagged vitals, dental
   appointments in mixed `scheduled`/`completed` states, a couple of
   walk-ins. Keep the HP-2026-91xx reference spread convention.

## Tests (laravel-tdd; every aggregate gets a feature test — the SQLite suite is what catches raw-SQL drift)

- Seed a known dataset → exact expected counts for: visits by college
  (medical + dental split), purpose buckets (including "Walk-in / not
  specified"), flag counts AND rates, trend series per month, BMI buckets,
  donut counts; month + college filters actually scope each of them.
- A freshly captured (un-encoded) medical visit COUNTS in visits and flags
  (FR-ANL-07 as rewritten).
- A `scheduled` dental appointment does NOT count; a `completed` one does.
- Guest/non-director access to the analytics route is denied (regression).

## Verify

- Full suite green: `php artisan test`.
- Invoke the **verify** skill: seed demo data, open the page, check every
  card against the mockup, switch month and college filters, confirm the
  removed features (print button, export buttons, case charts) are gone.

## Gate

Present a summary + test results + how each FR-ANL-09..13 was satisfied,
then **STOP for Nat's approval**. Commit (no day tag):
`feat(director): rescoped analytics - visits, flags, trend, BMI (FR-ANL-09..13)`
Suggest `/code-review` to Nat, and leave the merge-to-main decision to him.
