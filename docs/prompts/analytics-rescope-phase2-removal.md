# Analytics Rescope — Phase 2: Removal (case categories + print + CSV)

> Paste to run: `Read docs/prompts/analytics-rescope-phase2-removal.md and execute it. Invoke the laravel-tdd skill before writing tests and the verify skill before finishing.`
> Prerequisite: the Phase 1 PRD commit (`docs: PRD v1.11 …`) exists on this branch. If it does not, STOP and tell Nat.

## Context

PRD v1.11 / Decision D-32 (read them first — they are the spec) removed the
Medical Cases analytics and the case-category concept. This phase deletes
that code. The old work is safe in git history (merge `6cc6d65`), so delete
cleanly — no commented-out corpses.

## Setup

- Work directly in `C:\Capstone\healthpass` on branch
  **`feature/analytics-rescope`**. **Never use a worktree.**
- Read: PRD §4.9 as amended + D-32; `CLAUDE.md`. XAMPP MySQL must be running
  for artisan commands.

## Remove (find every consumer before deleting — grep `case_categor`, `caseCategor`, `CASE_CATEG`, `MedicalCaseSummary`, `summary-print`, `ExportController`)

1. **Nurse encode:** the case-category checkbox block in
   `resources/views/nurse/encode.blade.php` + its validation rules (Form
   Request or controller) + persistence in the encode controller. The nurse
   now encodes Fit/Unfit (+ purpose, physical signs, etc.) only — touch
   nothing else on that screen.
2. **Models:** `ClearanceRecord::CASE_CATEGORIES`, the `caseCategories()`
   relation, `categoryNames()`; the `ClearanceCaseCategory` model. Leave
   `PURPOSES`, `PHYSICAL_SIGNS`, and everything else alone.
3. **Table #11:** a NEW migration `drop_clearance_case_categories_table`
   (Schema::dropIfExists in `up()`, recreate in `down()` by copying the
   original create-migration schema). Run `php artisan migrate` — NEVER
   `migrate:fresh`. (Nat approved losing seeded case rows, 2026-07-18.)
4. **Analytics page:** in `Director\AnalyticsController`, the college×category
   query usage, matrix, `casesBySystem()`, `SERIES_COLORS`, the
   `MATRIX_COLLEGE_ORDER` — delete `app/Support/MedicalCaseSummary.php`.
   KEEP: the By-Sex donut, `CaseMonths` month picker, and a rendering page —
   Phase 4 rebuilds the rest. Strip the corresponding Blade sections in
   `resources/views/director/analytics.blade.php` and the case-chart code in
   `resources/js/director/analytics.js` (keep the donut + Chart.js setup).
5. **Print:** `Director\CaseSummaryPrintController`,
   `resources/views/director/summary-print.blade.php`, their route(s), and
   the "Preview & Print" button + hidden iframe on the analytics page.
6. **CSV export (all of FR-ANL-06):** `Director\ExportController`, its
   routes, and the Export buttons on BOTH Analytics and Flagged Anomalies.
7. **Flagged Anomalies:** the Category column/badge in
   `resources/views/director/anomalies/index.blade.php` and the category
   display in `anomalies/show.blade.php`, plus whatever
   `AnomaliesController` loads for them.
8. **Student records:** the case-category display in
   `resources/views/student/records.blade.php`.
9. **Seeder:** in `database/seeders/DemoClinicVisitSeeder.php`, remove
   case-category seeding only — keep visits/vitals seeding working (Phase 4
   reworks it fully).
10. **Tests:** delete `CaseSummaryPrintTest` and `CsvExportTest`; update
    `AnalyticsPageTest`, `AnomaliesPageTest`, and any nurse encode tests
    that submit `case_categories`. Do not weaken unrelated assertions.

## Verify

- Full suite green: `php artisan test` (388 tests before this phase; the
  count will drop — that is expected, nothing unrelated may fail).
- Grep once more for `case_categor|caseCategor` — zero hits outside
  migrations' `down()` and docs history.
- Invoke the **verify** skill (runtime check): analytics page renders with
  donut + month picker; nurse encode saves without categories; anomalies
  pages render without the Category column.

## Gate

Present a file-by-file summary + test results, then **STOP for Nat's
approval** before committing:
`refactor(director): remove medical-cases analytics, print & CSV export (D-32)`
(no day tag). Do not push unless Nat says to.
