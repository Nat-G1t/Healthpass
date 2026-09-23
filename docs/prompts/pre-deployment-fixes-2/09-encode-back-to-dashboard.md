# 09 — The visit page's back link returns to where you came from

Branch: `feature/pre-deployment-fixes-2`. **Do not commit.** Stop at the
verification block and wait for Nat's `commit`.

No decision. **Amends FR-NRS-09.** No schema change, no new package, no new
route.

## Why

Nat: "on the clinic dashboard, when I click View and I'm on the view page, the
back button on that page should take me back to the clinic dashboard, not the
live queue."

## What is there now

- The Clinic Dashboard's Encode History table (`resources/views/nurse/dashboard.blade.php:~191`)
  links **View** to `route('nurse.visits.encode', $visit)`. The table is filtered
  by GET `month`, `result`, `q` and paged by `page` (`Nurse\DashboardController`).
  The controller already cleans those values itself (e.g. a malformed month is
  ignored).
- The Live Queue links to the same page for encoding
  (`components/nurse/queue-row.blade.php:124–126`, and
  `QueueController.php:127` `encode_url` for the polled rows).
- The page (`resources/views/nurse/encode.blade.php:163–166`) always shows
  **"← Back to Live Queue"** → `route('nurse.queue')`.
- Nurse and Physician share these pages (D-64).

## What to do (settled with Nat)

Nat chose **"back to where you came from"**: from the dashboard, back to the
dashboard **with the same filters and page**; from the Live Queue, the Live
Queue as today.

1. **Dashboard View link:** add `from=dashboard` plus the current table state:
   `route('nurse.visits.encode', ['visit' => $visit, 'from' => 'dashboard'] + request()->only(['month', 'result', 'q', 'page']))`.
   Only pass keys that are present and non-empty, so the URL stays clean.
2. **`EncodeController::show()`:** work out the back link and hand it to the view:
   - `from === 'dashboard'` → `route('nurse.dashboard', $request->only(['month','result','q','page']))`,
     label **"← Back to Clinic Dashboard"**;
   - anything else (including no `from`) → `route('nurse.queue')`, **"← Back to
     Live Queue"**, exactly as today.

   Build the URL **only** from `route('nurse.dashboard', …)` with those four
   whitelisted keys, **never** from a posted/queried URL or `url()->previous()`.
   That rules out an open redirect, and the dashboard controller already cleans
   the values. Add a one-line comment saying so.
3. **View:** `encode.blade.php:163–166` renders the computed URL and label.
4. **Keep `from` through the page's own round trips.** Find every redirect or
   form on the encode page that lands back on `nurse.visits.encode`:
   - `EncodeController::alreadyEncoded()` (~line 180);
   - the print / reprint forms and `PrintClearanceController`, if any of them
     redirect back;
   - any validation-failure `back()`.

   Where one of these returns to the encode page, carry the same `from` +
   four keys, so the back link is still right after a reprint. If carrying them
   through a particular action is awkward, say so rather than forcing it.
   The **successful encode** (~line 172) still goes to the Live Queue, since a
   visit being encoded came from the queue. Don't change it.

## Out of scope

- Director pages that show visits (the anomalies drill-down has its own back
  link).
- Changing what View shows.

## Docs to update in this same change

- `docs/HealthPass_PRD.md` **FR-NRS-09**: add *"Opening a record with View shows
  '← Back to Clinic Dashboard', returning to the dashboard with the same
  month / result / search filters and page; opened from the Live Queue, the
  page links back to the Live Queue (2026-09-23)."*
- Next **revision-history row**. No schema change.
- `docs/HealthPass_Context.md` → NURSE + PHYSICIAN → Clinic Dashboard: the same
  line.
- `CHANGELOG.md`: one entry.

## Tests (next to `DashboardPageTest` / the encode page tests)

- the dashboard's View link carries `from=dashboard` and the active filters;
- `GET` the encode page with `from=dashboard&month=2026-09&result=Fit&q=Cruz&page=2`
  → the back link is `route('nurse.dashboard', [...those four...])` with the
  label "Back to Clinic Dashboard";
- without `from` → the back link is `route('nurse.queue')`, "Back to Live Queue";
- `from=dashboard&evil=https://example.com` → the `evil` key never appears in
  the back link;
- a physician gets the same result as a nurse.

## Verify

1. `php artisan serve --port=8080`, `npm run dev`. Log in as Nurse.
2. Clinic Dashboard → filter Month + Result, type a search, go to page 2 →
   **View** a record → the link reads "← Back to Clinic Dashboard" → click →
   the same filters and page 2.
3. Live Queue → open a captured visit → "← Back to Live Queue" as before.
4. From a dashboard-opened record, reprint → the back link still says Clinic
   Dashboard.
5. Repeat step 2 as Physician.
6. `php artisan test`: full suite green.

Then **stop and report**. Wait for `commit`.

Proposed commit message:

```
fix: the visit page links back to the Clinic Dashboard when opened from it
```
