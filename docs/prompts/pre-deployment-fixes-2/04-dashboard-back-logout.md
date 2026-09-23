# 04 — Back on a dashboard opens "Log out?", never a stale guest page

Branch: `feature/pre-deployment-fixes-2`. **Do not commit.** Stop at the
verification block and wait for Nat's `commit`.

**New FR-UI-07.** No decision, no schema change, no new package.

## Why

Nat: "when I finished registration as a student and I'm already at the
dashboard, when I click the browser's Back button I shouldn't be able to go
back to the registration. When I'm on the dashboard and press Back
repeatedly, the log out pop-up should appear, so I don't run into the
'Page Expired' error."

"Page Expired" is Laravel's **419**. The browser shows a cached guest page
(login or a registration step) whose CSRF token was rotated at login, and
submitting it fails.

## Settled with Nat

- **All five roles**: the Student, College Admin, Clinic (Nurse and Physician
  share `nurse.dashboard`) and Director dashboards.
- **Always** on the dashboard, not only right after login. Nat accepted the side
  effect: if you came to the dashboard from, say, My Records, Back asks
  "Log out?" instead of going to My Records.

## Two layers

### Layer 1 — guest pages are never served stale (the real 419 fix)

Every guest page with a form must be fetched fresh on Back, so that
`RedirectIfAuthenticated` (the `guest` middleware) can send a logged-in user
back to their dashboard instead of showing a dead form:

- `login`, `password.request` and the other forgot-password GET pages in
  `routes/auth.php`, and the registration wizard GET pages.

Use `->middleware('cache.headers:no_store;private')`. **Prompt 03 may already
have added this to the four registration pages.** Check `routes/auth.php`
first and don't add it twice. If 03 hasn't run, add it to the wizard pages
here too.

(`cache.headers` is Laravel's built-in middleware: code that runs around a
request. With `no_store` it tells the browser not to keep the page in its
back/forward cache, so Back asks the server again.)

### Layer 2 — Back on the dashboard asks "Log out?"

- The four dashboard views render a small Blade component,
  `resources/views/components/back-guard.blade.php` (name it however the
  `components/` folder's conventions suggest). When the page loads it
  `history.pushState`s one extra entry for the same URL. On `popstate` it
  pushes the entry again (so the user stays on the dashboard) and opens the
  logout dialog.
- The dialog is the **existing** `<x-logout-confirm>` inside the sidebar
  (`components/layout/sidebar.blade.php:312`). Today it opens only from its own
  trigger (`x-data="{ open: false }"`). Give it a way to be opened from outside:
  a window event, e.g. `@open-logout-confirm.window="open = true"` on its
  wrapper, and have the guard `window.dispatchEvent(new CustomEvent('open-logout-confirm'))`.
  Check there is exactly **one** `<x-logout-confirm>` rendered on a dashboard
  page. If a second one exists (a mobile drawer, `layouts/navigation.blade.php`),
  make sure only one dialog opens.
- **Cancel** / Esc / backdrop: close the dialog and stay on the dashboard;
  the guard entry is back in place, so the next Back asks again.
  **Log out**: the existing `POST /logout`.

**Chrome gotcha (must handle):** Chrome ignores history entries a page
added *without a user gesture* when the user presses Back (the "history
manipulation intervention"). A `pushState` on page load alone can be skipped,
and Back then leaves the dashboard anyway. Push the guard entry on load **and**
(once) on the first `pointerdown` / `keydown`, and verify in a **real Chrome
window** that it holds. Even when Chrome skips it, Layer 1 means the user lands
back on the dashboard via redirect, not on a 419. Say in your report how it
behaved.

Only the dashboard gets the guard. Other pages keep normal Back behaviour.
A dashboard URL with a query (the nurse dashboard's `?month=`/`?page=`
filters, the admin dashboard's pager) is still the dashboard, so it gets the
guard too. Mention this to Nat in the report: after applying a filter, Back
asks "Log out?" instead of undoing the filter.

The student dashboard runs a first-login **tutorial** overlay
(`TutorialCompletionController`). Check that Back during the tutorial doesn't
leave it half-open behind the dialog. If it does, close the tutorial or skip
the guard while it's open. Pick the smaller change and say which.

## Keep it small

One component, one event listener on `<x-logout-confirm>`, and one line per
dashboard view. No new JS file in `app.js` unless the component's inline
script genuinely can't do it.

## Docs to update in this same change

- `docs/HealthPass_PRD.md`: add **FR-UI-07** after FR-UI-06: *"Back on a
  dashboard. On every role's dashboard, the browser's Back button opens the
  Log out confirmation instead of leaving the page (Cancel stays on the
  dashboard). Guest pages (login, registration, forgot password) are sent with
  `Cache-Control: no-store`, so a signed-in user who reaches one through
  history is redirected to their dashboard rather than shown an expired form."*
  Priority column: match FR-UI-06.
- Next **revision-history row**. No schema change.
- `docs/HealthPass_Context.md` §6 `SidebarLayout`: one sentence on the
  back guard.
- `CHANGELOG.md`: one entry.
- `docs/qa/e2e-scenarios.md`: a "Back from dashboard" check per role.

## Tests

The guard itself is browser behaviour. Feature tests can cover the server side:
- each of the four dashboards' HTML contains the guard (assert on a stable
  marker such as a `data-back-guard` attribute);
- a non-dashboard page (e.g. `profile.edit`) does **not**;
- `GET /login`, `GET /register`, `GET /forgot-password` responses carry
  `no-store`;
- a logged-in user `GET /login` → redirected (to `/`, then their dashboard).

## Verify (real Chrome)

1. `php artisan serve --port=8080`, `npm run dev`.
2. Register a new student through to the dashboard. Press Back: "Log out?"
   appears. Cancel, press Back again: it appears again. Press Back several
   times fast: you never see a registration page or a 419.
3. Log in as College Admin, Nurse, Physician and Director in turn. Same result
   on each dashboard.
4. From a dashboard click into another page (e.g. Profile), then Back: you
   return to the dashboard as normal (the guard only acts *on* the dashboard).
5. "Log out" in the dialog logs out and lands on the login page. Then press
   Back and **note** what shows. A cached dashboard after logout is a separate
   issue Nat didn't ask about, so don't fix it here. Report it if you see it.
6. Nurse dashboard: apply a Month filter, press Back → "Log out?" (the
   accepted side effect).
7. `php artisan test`: full suite green.

Then **stop and report**: whether Chrome honoured the guard, the tutorial
decision, the filter side effect, and the test result. Wait for `commit`.

Proposed commit message:

```
feat: Back on a dashboard asks to log out; guest pages are never stale (FR-UI-07)
```
