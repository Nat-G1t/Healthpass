# Release Notes

## [Unreleased](https://github.com/laravel/laravel/compare/v12.12.1...12.x)

### Added

* **Appointments now have a time, and capacity is enforced per hour**
  (D-37 — BR-01/BR-02/BR-04 amended, BR-21/BR-22 added, FR-STU-03/04,
  FR-ADM-04 and FR-DIRA-02 amended).
  The clinic day is **ten bookable one-hour slots** — 7–8 AM through 4–5 PM,
  **the lunch hour included** — derived at runtime from
  `healthpass.clinic_hours` by the new `App\Services\ClinicScheduleService`,
  so the grid is never hardcoded in a view. A kiosk session takes at most
  five minutes, so a slot holds **12** students: `hourly_capacity` = 12 and
  `daily_capacity` **raised from 40 to 120**. Both stay config values.
  **Medical and dental share one counter** — a dental appointment consumes an
  hour-seat exactly like a medical one, because what is being capped is
  clinic congestion, not kiosk throughput.
  - **Students** pick a one-hour slot after picking a date. Each slot shows
    its remaining seats, a full slot is disabled, and a date whose every hour
    is full is greyed out in the calendar just as a capacity-full day is. The
    existing availability endpoint was **extended** (no new route) to return
    per-slot `booked`/`remaining`/`full` for a given date.
  - **College Admins** pick a **start hour**; the system computes the span as
    `ceil(students ÷ 12)` contiguous hours and shows it back live — *"30
    students → 7:00 AM – 10:00 AM (3 slots)"*. Submission is blocked when any
    hour in the span is full (**the error names the hour**), when the span
    would run past 5 PM, or when the roster exceeds 120 (which cannot fit in
    one clinic day). The span **length is always derived server-side**, so a
    posted `requested_blocks` is inert.
  - **Directors** confirm the span read-only alongside the date, and the
    fan-out writes `scheduled_time` onto every generated appointment — 12 per
    hour in pivot-row id order, the last block taking the remainder.
  - Capacity is checked in the Form Request **and re-checked under
    `lockForUpdate()` inside the write transaction**, for both self-booking
    and batch submission: the Form Request's read is unlocked and races with a
    concurrent booking for the last seat in an hour. A feature test injects a
    competing booking at the exact moment that unlocked read returns and
    asserts only one booking wins.
  - Schema (flagged): `appointments.scheduled_time` TIME NULL plus
    `index(scheduled_date, scheduled_time, status)`;
    `batch_requests.requested_time` TIME NULL and `requested_blocks`
    TINYINT NULL — span **start + block count, never an end time and never
    both**. Slot keys are the canonical string `'07:00:00'` everywhere (select
    value, DB column, WHERE clause), because MySQL TIME and SQLite TEXT
    round-trip that form identically.
  - **An hour that has already ended cannot be booked on today** (BR-23). A
    slot dies when it **ends**, not when it starts: at 12:00 the 11 AM–12 PM
    hour is gone and 12–1 PM is the earliest still available — which keeps
    this consistent with BR-20's existing "today is bookable right up to
    16:59" rule. It binds all three write paths: the **student** slot
    picker (greyed and labelled "Past", distinct from "Full"), the **College
    Admin's** batch start hour (only the start needs checking at submission —
    a span runs forwards), and **Director approval**, where any elapsed hour in
    the batch's span refuses the approval. The Director case matters because
    D-36 still permits approving a batch requested for *today*, so its hours
    can end between submission and review; fanning out then would create
    appointments for a time already gone. A partially-elapsed span is refused
    too — some of the cohort would land in the past, and a same-day batch whose
    span is still ahead approves normally. Only today is
    affected; a future date has no elapsed hours. Decided on the server clock
    and shipped to both pages as data, **never computed in the browser**, the
    same rule BR-20 already follows. A date whose remaining hours have all
    elapsed greys out in the calendar, which makes BR-20's after-closing case
    fall out naturally. Deliberately **not** re-checked under lock, unlike
    capacity: no concurrent request can change whether an hour has ended.
  - **Pre-D-37 appointments were deliberately not backfilled.** They keep
    `scheduled_time` NULL, render as "—", are invisible to the per-slot
    counters, and are still counted by the daily cap — which is why the daily
    cap was kept alongside the hourly one. Backfilling them to 07:00 would
    have invented twelve fake bookings in the first hour of every past clinic
    day.

### Changed

* **Batch approval capacity is now a HARD BLOCK, not a warning** (D-37,
  amending FR-DIRA-06). If any one-hour slot in a batch's span has reached the
  hourly cap when the Director opens *or* submits the approval, approval is
  refused — the Approve button is disabled with the offending hour named, and
  the endpoint refuses the POST after re-reading the counts under lock. The
  Director is directed to reject with a reason so the college can resubmit.
  **Stated plainly: combined with D-36's confirm-only approval, a batch whose
  slots filled up between submission and review has exactly one outcome —
  rejection and resubmission. That is the intended workflow, not a bug.** A
  batch submitted before D-37 (no `requested_time`) is a third un-approvable
  case alongside D-36's two, with the same remedy.

* **Batch approval is now confirm-only** (D-36, FR-DIRA-02 — **supersedes the
  "the Director may adjust the date" clause of D-29**). The Director's approve
  modal no longer has a date picker: it shows the College Admin's requested
  clinic date read-only, and the batch is approved on that date. The date is
  read from the `batch_requests` row **locked inside the approval transaction**,
  never from the request — `ApproveBatchRequest` now accepts no input at all, so
  a `scheduled_date` posted by a client is ignored.
  Two kinds of batch **cannot be confirm-approved at all**, because neither
  leaves a date to confirm — Approve is disabled on the row with a one-line
  notice *and* the endpoint refuses the POST, so the UI is never the gate:
  batches submitted before D-29, which have no requested date (reject with
  "predates the requested-date field — please resubmit"), and batches whose
  **requested date passed while they sat pending**, where approving would
  schedule the cohort in the past. A batch requested for *today* is still
  confirmable; the staleness rule lives in one place,
  `BatchRequest::hasStaleRequestedDate()`, used by both the view and the
  endpoint's locked-row check. *(Superseded in part by D-37 above: capacity at
  approval is no longer a warning, and a batch with no hour span is a third
  case that cannot be approved.)*
  When a Director can't take the requested date, the move is now to **reject
  with a reason** ("date unavailable, please resubmit for …") rather than
  silently shifting the cohort — the original request survives, the objection is
  on the record, and the college resubmits for a date it has agreed to. The
  FR-DIRA-06 capacity **warning** was unchanged by D-36 — **D-37 has since
  replaced it with a hard block on the batch's hour span** (see above).

### Added

* **Rejecting a batch now requires a written reason** (D-36, FR-DIRA-04).
  New nullable `batch_requests.rejection_reason` (TEXT — nullable because
  rejections made before this change have none). The reason is required,
  10–500 characters, validated server-side by the new
  `App\Http\Requests\Director\RejectBatchRequest`; the reject modal's live
  character counter and disabled-until-valid submit are convenience only. The
  existing `lockForUpdate()` + already-decided no-op guard is untouched, so a
  replayed reject still no-ops and cannot overwrite a reason already on record.
* **College admins can read why a batch was rejected** (D-36, FR-ADM-05). Batch
  Tracking grows a **Rejection Reason** column beside Status, rendered only when
  the list actually contains a rejected row; that row gets a View button opening
  a modal with the reason, the reviewing Director's name and the review
  timestamp, and every other row shows an em dash. The reason is Director-written
  free text, so it is escaped on output and set via `x-text`, never raw HTML.
  Works in both the desktop table and the mobile stacked-card rendering.

* Site-wide **light/dark theme** for the web app (D-38, FR-UI-04 / NFR-10). A
  toggle sits in the top-right of the sticky header on every page that uses the
  sidebar layout, and fixed top-right on the auth/guest shells. The choice is
  remembered per device in `localStorage` and defaults to the OS
  `prefers-color-scheme` — no schema change, no user column, no request on
  toggle. The theme class is applied by an inline `<head>` script before first
  paint, so a dark reload never flashes light. Colour transitions use the
  existing `--hp-dur-base` / `--hp-ease-out` motion tokens and still collapse
  under `prefers-reduced-motion`.
  Implemented by **re-pointing the five `--hp-*` colour tokens** rather than
  scattering `dark:` variants: the tokens are now `R G B` channel triplets and
  Tailwind resolves `hp-*` through `rgb(var(--…) / <alpha-value>)`, so ~300
  existing `text-hp-slate/NN` utilities re-theme untouched. **In plain CSS a
  colour token must now be wrapped — `rgb(var(--hp-bg))`, not `var(--hp-bg)`.**
  Dark peach becomes a dark amber chip (`#3B2A17`) because the app pairs it with
  orange text, which was 1.55:1 on a light peach; dark slate is `#F3F4F6` so the
  muted alpha steps still clear AA. Brand orange is unchanged — it measures
  7.3:1 on the dark card. Known issue, pre-existing and identical in light: the
  white-on-orange button label is 2.32:1.
  **The kiosk and all print output are excluded and stay light** — the kiosk is
  a fixed clinic appliance (D-26) and printed clearances must match the official
  form. Verified with a per-page contrast audit across all four roles in both
  themes, plus no-flash-on-reload, kiosk-stays-light and print-stays-light checks.

### Fixed

* Mobile layout of the College Admin pages (FR-ADM-01 / FR-ADM-05). The
  Dashboard and Batch Tracking tables no longer scroll the page sideways on a
  phone — a new `<x-hp.table>` / `<x-hp.table-row>` / `<x-hp.table-cell>`
  component set re-flows each row into a stacked label/value card below `md`
  and renders the unchanged table at `md`+. Status badges keep long labels
  ("Pending Director Approval") inside the pill, and the sidebar drawer is
  sized to `100dvh` so its Log out footer is reachable without scrolling.
  Verified at 360×800 and 390×844 with no horizontal page scroll; desktop
  tables and the collapsible rail are unchanged. See
  `docs/qa/mobile-checklist.md`.

## [v12.12.1](https://github.com/laravel/laravel/compare/v12.12.0...v12.12.1) - 2026-03-10

* [12.x] Makes imports consistent by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6760

## [v12.12.0](https://github.com/laravel/laravel/compare/v12.11.2...v12.12.0) - 2026-03-09

* Update phpunit version to ^11.5.50 to address CVE by [@PerryvanderMeer](https://github.com/PerryvanderMeer) in https://github.com/laravel/laravel/pull/6746
* [12.x] Add `APP_NAME` fallback in mail config by [@apoorvdarshan](https://github.com/apoorvdarshan) in https://github.com/laravel/laravel/pull/6755
* [12.x] Neutralize DB_URL in default phpunit.xml by [@Husseinadq](https://github.com/Husseinadq) in https://github.com/laravel/laravel/pull/6761

## [v12.11.2](https://github.com/laravel/laravel/compare/v12.11.1...v12.11.2) - 2026-01-19

* [12.x] Update composer dev script to ensure no timeout by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6735
* [12.x] Update jobs/cache migrations by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6736
* [12.x] Remove failed jobs indexes by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6739
* [12.x] Add `APP_URL` fallback in filesystems config by [@KentarouTakeda](https://github.com/KentarouTakeda) in https://github.com/laravel/laravel/pull/6742
* chore: Update outdated GitHub Actions version by [@pgoslatara](https://github.com/pgoslatara) in https://github.com/laravel/laravel/pull/6743

## [v12.11.1](https://github.com/laravel/laravel/compare/v12.11.0...v12.11.1) - 2025-12-23

* Use environment variable for `DB_SSLMODE` - Postgres by [@robsontenorio](https://github.com/robsontenorio) in https://github.com/laravel/laravel/pull/6727
* fix: ensure APP_URL does not have trailing slash in filesystem by [@msamgan](https://github.com/msamgan) in https://github.com/laravel/laravel/pull/6728

## [v12.11.0](https://github.com/laravel/laravel/compare/v12.10.1...v12.11.0) - 2025-11-25

* fix: cookies are not available for subdomains by default by [@joostdebruijn](https://github.com/joostdebruijn) in https://github.com/laravel/laravel/pull/6705
* Fix PHP 8.5 PDO Driver Specific Constant Deprecation by [@RyanSchaefer](https://github.com/RyanSchaefer) in https://github.com/laravel/laravel/pull/6710
* Ignore Laravel compiled views for Vite  by [@QistiAmal1212](https://github.com/QistiAmal1212) in https://github.com/laravel/laravel/pull/6714

## [v12.10.1](https://github.com/laravel/laravel/compare/v12.10.0...v12.10.1) - 2025-11-06

* Update schema URL in package.json by [@robinmiau](https://github.com/robinmiau) in https://github.com/laravel/laravel/pull/6701

## [v12.10.0](https://github.com/laravel/laravel/compare/v12.9.1...v12.10.0) - 2025-11-04

* Add background driver by [@barryvdh](https://github.com/barryvdh) in https://github.com/laravel/laravel/pull/6699

## [v12.9.1](https://github.com/laravel/laravel/compare/v12.9.0...v12.9.1) - 2025-10-23

* [12.x] Replace Bootcamp with Laravel Learn by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6692
* [12.x] Comment out CLI workers for fresh applications by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/laravel/pull/6693

## [v12.9.0](https://github.com/laravel/laravel/compare/v12.8.0...v12.9.0) - 2025-10-21

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.8.0...v12.9.0

## [v12.8.0](https://github.com/laravel/laravel/compare/v12.7.1...v12.8.0) - 2025-10-20

* [12.x] Makes test suite using broadcast's `null` driver by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6691

## [v12.7.1](https://github.com/laravel/laravel/compare/v12.7.0...v12.7.1) - 2025-10-15

* Added `failover` driver to the `queue` config comment.  by [@sajjadhossainshohag](https://github.com/sajjadhossainshohag) in https://github.com/laravel/laravel/pull/6688

## [v12.7.0](https://github.com/laravel/laravel/compare/v12.6.0...v12.7.0) - 2025-10-14

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.6.0...v12.7.0

## [v12.6.0](https://github.com/laravel/laravel/compare/v12.5.0...v12.6.0) - 2025-10-02

* Fix setup script by [@goldmont](https://github.com/goldmont) in https://github.com/laravel/laravel/pull/6682

## [v12.5.0](https://github.com/laravel/laravel/compare/v12.4.0...v12.5.0) - 2025-09-30

* [12.x] Fix type casting for environment variables in config files by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6670
* Fix CVEs affecting vite by [@faissaloux](https://github.com/faissaloux) in https://github.com/laravel/laravel/pull/6672
* Update .editorconfig to target compose.yaml by [@fredikaputra](https://github.com/fredikaputra) in https://github.com/laravel/laravel/pull/6679
* Add pre-package-uninstall script to composer.json by [@cosmastech](https://github.com/cosmastech) in https://github.com/laravel/laravel/pull/6681

## [v12.4.0](https://github.com/laravel/laravel/compare/v12.3.1...v12.4.0) - 2025-08-29

* [12.x] Add default Redis retry configuration by [@mateusjatenee](https://github.com/mateusjatenee) in https://github.com/laravel/laravel/pull/6666

## [v12.3.1](https://github.com/laravel/laravel/compare/v12.3.0...v12.3.1) - 2025-08-21

* [12.x] Bump Pint version by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6653
* [12.x] Making sure all related processed are closed when terminating the currently command by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6654
* [12.x] Use application name from configuration by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6655
* Bring back postAutoloadDump script by [@jasonvarga](https://github.com/jasonvarga) in https://github.com/laravel/laravel/pull/6662

## [v12.3.0](https://github.com/laravel/laravel/compare/v12.2.0...v12.3.0) - 2025-08-03

* Fix Critical Security Vulnerability in form-data Dependency by [@izzygld](https://github.com/izzygld) in https://github.com/laravel/laravel/pull/6645
* Revert "fix" by [@RobertBoes](https://github.com/RobertBoes) in https://github.com/laravel/laravel/pull/6646
* Change composer post-autoload-dump script to Artisan command by [@lmjhs](https://github.com/lmjhs) in https://github.com/laravel/laravel/pull/6647

## [v12.2.0](https://github.com/laravel/laravel/compare/v12.1.0...v12.2.0) - 2025-07-11

* Add Vite 7 support by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/laravel/pull/6639

## [v12.1.0](https://github.com/laravel/laravel/compare/v12.0.11...v12.1.0) - 2025-07-03

* [12.x] Disable nightwatch in testing by [@laserhybiz](https://github.com/laserhybiz) in https://github.com/laravel/laravel/pull/6632
* [12.x] Reorder environment variables in phpunit.xml for logical grouping by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6634
* Change to hyphenate prefixes and cookie names by [@u01jmg3](https://github.com/u01jmg3) in https://github.com/laravel/laravel/pull/6636
* [12.x] Fix type casting for environment variables in config files by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6637

## [v12.0.11](https://github.com/laravel/laravel/compare/v12.0.10...v12.0.11) - 2025-06-10

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.0.10...v12.0.11

## [v12.0.10](https://github.com/laravel/laravel/compare/v12.0.9...v12.0.10) - 2025-06-09

* fix alphabetical order by [@Khuthaily](https://github.com/Khuthaily) in https://github.com/laravel/laravel/pull/6627
* [12.x] Reduce redundancy and keeps the .gitignore file cleaner by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6629
* [12.x] Fix: Add void return type to satisfy Rector analysis by [@Aluisio-Pires](https://github.com/Aluisio-Pires) in https://github.com/laravel/laravel/pull/6628

## [v12.0.9](https://github.com/laravel/laravel/compare/v12.0.8...v12.0.9) - 2025-05-26

* [12.x] Remove apc by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6611
* [12.x] Add JSON Schema to package.json by [@martinbean](https://github.com/martinbean) in https://github.com/laravel/laravel/pull/6613
* Minor language update by [@woganmay](https://github.com/woganmay) in https://github.com/laravel/laravel/pull/6615
* Enhance .gitignore to exclude common OS and log files by [@mohammadRezaei1380](https://github.com/mohammadRezaei1380) in https://github.com/laravel/laravel/pull/6619

## [v12.0.8](https://github.com/laravel/laravel/compare/v12.0.7...v12.0.8) - 2025-05-12

* [12.x] Clean up URL formatting in README by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6601

## [v12.0.7](https://github.com/laravel/laravel/compare/v12.0.6...v12.0.7) - 2025-04-15

* Add `composer run test` command by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/laravel/pull/6598
* Partner Directory Changes in ReadME by [@joshcirre](https://github.com/joshcirre) in https://github.com/laravel/laravel/pull/6599

## [v12.0.6](https://github.com/laravel/laravel/compare/v12.0.5...v12.0.6) - 2025-04-08

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.0.5...v12.0.6

## [v12.0.5](https://github.com/laravel/laravel/compare/v12.0.4...v12.0.5) - 2025-04-02

* [12.x] Update `config/mail.php` to match the latest core configuration by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6594

## [v12.0.4](https://github.com/laravel/laravel/compare/v12.0.3...v12.0.4) - 2025-03-31

* Bump vite from 6.0.11 to 6.2.3 - Vulnerability patch by [@abdel-aouby](https://github.com/abdel-aouby) in https://github.com/laravel/laravel/pull/6586
* Bump vite from 6.2.3 to 6.2.4 by [@thinkverse](https://github.com/thinkverse) in https://github.com/laravel/laravel/pull/6590

## [v12.0.3](https://github.com/laravel/laravel/compare/v12.0.2...v12.0.3) - 2025-03-17

* Remove reverted change from CHANGELOG.md by [@AJenbo](https://github.com/AJenbo) in https://github.com/laravel/laravel/pull/6565
* Improves clarity in app.css file by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6569
* [12.x] Refactor: Structural improvement for clarity by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6574
* Bump axios from 1.7.9 to 1.8.2 - Vulnerability patch by [@abdel-aouby](https://github.com/abdel-aouby) in https://github.com/laravel/laravel/pull/6572
* [12.x] Remove Unnecessarily [@source](https://github.com/source) by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6584

## [v12.0.2](https://github.com/laravel/laravel/compare/v12.0.1...v12.0.2) - 2025-03-04

* Make the github test action run out of the box independent of the choice of testing framework by [@ndeblauw](https://github.com/ndeblauw) in https://github.com/laravel/laravel/pull/6555

## [v12.0.1](https://github.com/laravel/laravel/compare/v12.0.0...v12.0.1) - 2025-02-24

* [12.x] prefer stable stability by [@pataar](https://github.com/pataar) in https://github.com/laravel/laravel/pull/6548

## [v12.0.0 (2025-??-??)](https://github.com/laravel/laravel/compare/v11.0.2...v12.0.0)

Laravel 12 includes a variety of changes to the application skeleton. Please consult the diff to see what's new.
