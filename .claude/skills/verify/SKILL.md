# Verify — HealthPass runtime verification recipe

How to drive the running app to verify a change end-to-end (what worked
on 2026-07-13; Windows + XAMPP).

## Build & launch

```bash
npm run build                      # so @vite works without the dev server
php artisan serve --port=8080      # background; app at http://127.0.0.1:8080
```

MySQL (XAMPP) must already be running — check with `php artisan migrate:status`.

## Seeded logins (dev DB, all password `password`)

- `admin.<code>@healthpass.test` — college admin per college (e.g. `admin.coe@`)
- `nurse@healthpass.test`, `director@healthpass.test`
- Students: see `database/seeders/StudentSeeder.php`

## Browser driving (Playwright)

Playwright is NOT a project dependency — do not add it. Install it in the
job temp dir and run from there (browsers are already in
`%LOCALAPPDATA%\ms-playwright`, chromium build 1228 ⇒ playwright 1.61.x):

```bash
cd "$CLAUDE_JOB_DIR/tmp" && npm init -y && npm i playwright@1.61.1
node your-script.js   # require('playwright') resolves locally
```

## Gotchas

- **Alpine timing:** after `selectOption`/`click`, wait ~200ms before reading
  `isVisible()` / computed styles — reads in the same tick race Alpine's
  reactive flush and return stale values (looks like x-show is broken; it isn't).
- **`locator('form')` is ambiguous** on sidebar pages — the logout modal has a
  form too. Scope by action: `form[action*="..."]`.
- **`waitForURL` after submit resolves instantly** if you're already on the
  target URL (redirect-back flows). Use
  `Promise.all([page.waitForNavigation(), page.click(...)])`.
- **Checkboxes:** `@tailwindcss/forms` sets `appearance:none` and paints
  checked boxes with `currentColor` — style with `text-hp-orange`, never
  `accent-*` (renders default blue).
- **Test data:** dev colleges have only ~3 students each. For volume tests,
  factory-create into a college, then delete those profiles + their users
  after (record the pre-existing max id first). Never `migrate:fresh`.
