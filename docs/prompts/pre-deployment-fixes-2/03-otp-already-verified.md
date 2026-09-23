# 03 — Back to the OTP page after verifying: "you've already done this step"

Branch: `feature/pre-deployment-fixes-2`. **Do not commit.** Stop at the
verification block and wait for Nat's `commit`.

No new decision. **Amends FR-REG-04.** No schema change, no new package.

## Why

Nat: "when a student is registering and successfully passes the OTP verification,
and then, on the Link ID page, presses the browser's Back button and goes back
to the OTP page, the OTP page should say that the student already passed this step."

## What happens now (confirm before changing anything)

- A correct OTP creates the account, **logs the student in**, regenerates the
  session and redirects to `register.link-id`
  (`RegistrationWizardController::verifyOtp`, ~lines 176–226). It also forgets
  `reg.info` / `reg.consent_at`.
- `GET register/verify` (`routes/auth.php`) is inside the **`guest`** group. When
  the browser really asks the server, `RedirectIfAuthenticated` sends a
  logged-in user to `/` (the app has no route named `dashboard`), and `/` sends
  them to their dashboard (`routes/web.php:31`).
- So when Nat sees the OTP page after Back, the browser is showing its
  **back/forward-cache copy**: the old page, frozen, and no request reaches
  Laravel. Pressing Verify on that frozen copy then posts a rotated CSRF token.

Reproduce it first (register a throwaway student, Back from Link ID). Note
whether the Network tab shows a request. If it does not, the bfcache theory
holds. Report what you saw either way.

## What to do (settled with Nat)

Nat chose: **a green "already verified" notice plus a Continue → Link ID button.**

### 1. Make Back always ask the server

- Send `Cache-Control: no-store, private` on the four wizard GET pages
  (`register`, `register.info`, `register.verify`, `register.link-id`). Laravel
  ships a middleware for this. `cache.headers` is **middleware** (code that runs
  around a request): `->middleware('cache.headers:no_store;private')` sets the
  header on the response. Chrome and Firefox don't keep `no-store` pages in the
  bfcache, so Back re-requests the page.
- Belt and braces, in `components/register/wizard-shell.blade.php`: a tiny
  inline script. On `pageshow` with `event.persisted === true`, call
  `location.reload()`. Safari can still restore a `no-store` page from bfcache;
  this catches it. (`components/hp/splash.blade.php` already listens to
  `pageshow`. Read it so the two don't fight.)

Prompt 04 will want the same `no-store` treatment on the login and
forgot-password pages. Use the built-in middleware string so 04 can reuse it,
and don't write a custom class unless `cache.headers` can't do it. If it can't,
say why.

### 2. The OTP page knows a verified student

Move **only** `GET register/verify` out of the `guest` group, so a logged-in
request reaches the controller (keep its name `register.verify`, and keep the
POST verify / POST resend routes under `guest`). Then `step3()` decides:

| Who is asking | Result |
|---|---|
| Logged-in **student** | Render step 3 in its **already-verified** state (below) |
| Logged-in non-student (staff) | Redirect to their dashboard (`EnsureRole::dashboardFor`) |
| Guest with `reg.info` in session | The normal OTP page, as today |
| Guest without `reg.info` | Redirect to `register.info`, as today |

Check the order in `step3()`: today the first thing it does is the
`reg.info` check, and a logged-in student no longer has `reg.info`. That check
must come **after** the logged-in branch.

**Already-verified state** (same view, a boolean from the controller; no new
view file):
- keep the heading and the progress bar on step 3;
- replace the OTP form, resend button and dev panel with a green notice, in
  the same style as the page's existing `session('status')` flash:
  > **Your email is already verified.** You've completed this step.
- one primary button, **"Continue →"**, linking to `register.link-id`;
- hide "← Start over": they have an account now, so starting over makes no sense.

Don't show the email address in this state. The logged-in user has one, but
the page doesn't need it.

### 3. Things not to change

- The verify POST logic, attempts, throttles, the resend cooldown.
- Steps 1 and 2 for a logged-in student. They stay under `guest`, so with
  `no-store` a Back to them now reaches the server and gets redirected to the
  dashboard. That is prompt 04's territory; don't build an "already
  registered" page for them.

## Docs to update in this same change

- `docs/HealthPass_PRD.md` **FR-REG-04**: add *"Revisiting Step 3 after a
  successful verification (e.g. with the browser's Back button) shows 'Your
  email is already verified' and a Continue button to Step 4 instead of the
  code boxes; the wizard's pages are never served from the browser's
  back/forward cache (2026-09-23)."*
- Next **revision-history row** (check the last number). No schema change.
- `docs/HealthPass_Context.md` AUTH → registration: the same sentence.
- `CHANGELOG.md`: one entry.
- `docs/qa/e2e-scenarios.md`: add a "Back from Link ID" step to the
  registration scenario if one exists.

## Tests (`tests/Feature/Auth/RegistrationTest.php`)

- a logged-in student `GET register/verify` → 200, sees "already verified",
  sees a link to `route('register.link-id')`, and does **not** see the OTP form
  action `route('register.verify.submit')`;
- a logged-in nurse (or any staff) `GET register/verify` → redirect to their
  dashboard;
- a guest with no `reg.info` → redirect to `register.info` (unchanged);
- a guest with `reg.info` → 200 with the OTP form (unchanged);
- each of the four wizard GET pages has `no-store` in `Cache-Control`.

Known trap: the `array` session driver breaks registration-OTP HTTP tests
(the OTP cache key is the session id). Follow the pattern the existing
`RegistrationTest` already uses rather than inventing a new one.

## Verify

1. `php artisan serve --port=8080`, `npm run dev`. Use **Chrome**, not
   Playwright, for the Back-button checks: bfcache behaviour differs in
   automation.
2. Register a new student through step 3 → land on Link ID → press Back:
   the OTP page shows the green notice and Continue. Press Continue → Link ID.
3. From that notice press Back again → step 2 → you're redirected to the
   dashboard (a logged-in user can't see the guest form any more).
4. Log out, start a fresh registration, and stop on step 3: the normal OTP page.
5. DevTools → Network → the step 3 response shows `Cache-Control: no-store, private`.
6. `php artisan test`: full suite green.

Then **stop and report**: what the reproduction showed (bfcache or not), the
changes, and the test result. Wait for `commit`.

Proposed commit message:

```
feat: the OTP step tells a verified student it is already done
```
