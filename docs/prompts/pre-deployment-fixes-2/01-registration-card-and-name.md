# 01 — Registration: a wider card, and the university's full name

Branch: `feature/pre-deployment-fixes-2` (see `00-README.md` step 0 — if you are
still on `feature/pre-deployment-minor-fixes`, stop and do step 0 first).
**Do not commit.** Stop at the verification block and wait for Nat's `commit`.

Two small registration-page changes Nat asked for together. No decision, no
FR, no schema change.

## Why

Nat: "on the registration page, can you make the card wider or stretch it but
still leave some room on the sides? Even on a desktop it looks like I am on
mobile view. And change the PamSU to Pampanga State University on the consent page."

## Part A — wider card

### What is there now

- Every step renders through `resources/views/components/register/wizard-shell.blade.php`.
  Its `maxWidth` prop defaults to `max-w-[480px]` and sizes **both** the progress
  bar and the card (`<x-hp.card class="hp-page-enter w-full {{ $maxWidth }}">`).
- Step 2 (`resources/views/auth/register/step2.blade.php:1`) overrides it to
  `max-w-[560px]`. Steps 1, 3, 4 use the default.
- `<body>` has `p-6`, which is the side gutter on phones.

### What to do (settled with Nat)

1. Raise the shell's default to about **768px** (`max-w-3xl` is exactly 768px;
   use it or `max-w-[768px]`, whichever matches how the file writes it). Keep
   `w-full`, so on a phone the card is still full width inside `p-6`. On a
   desktop the card is centred and leaves room on both sides.
2. Drop step 2's own `maxWidth="max-w-[560px]"` override so all four steps are
   the same width. The progress bar stays exactly as wide as the card, because
   it already reads the same prop.
3. **Step 2 uses the width:** short fields sit side by side from `sm:` up, and
   stay one column on phones. Read the whole form first. Some rows already use
   `sm:grid-cols-3` (names, course/year/DOB) and `sm:grid-cols-2` (passwords);
   for the rest, pair fields that belong together — for example student
   number + college, place of birth + civil status. **Address** stays full
   width (it's long). Do not reorder fields, do not rename anything, do not
   touch the Alpine college → program → year-level cascade or any `name=`.
4. Steps 1, 3, 4: just check they still look right at 768px. Step 1's scroll box
   (`h-56`) and step 3's OTP row will simply be centred in a wider card; that's
   fine. **Do not** turn step 1 or 3 into a two-column layout.
5. Step 4 (Link ID) holds the camera/QR scanner. Check the video preview isn't
   stretched or oddly huge at the new width. If it is, cap the *scanner* with
   its own `max-w-*`, not the card.

**Prototype note:** the Claude Design prototype is the visual source of truth,
and it shows a narrow card. Nat asked for this change on purpose, so the width
may differ from the prototype. The internal layout (headings, the progress
bar's look, buttons, spacing) must still match it.

## Part B — "PamSU" → "Pampanga State University" (consent page only)

File: `resources/views/auth/register/step1.blade.php`.

Nat's rule, **verbatim:** change only the things that say **PamSU, PSU, pamsu**
(the university's short name) — **"never touch the form codes, those are very
crucial."**

Known spots (grep the file for `PamSU`, `PSU` and `pamsu`, case-insensitive,
to be sure there are no more):

- line 29: `(PamSU form DHVSU-QSP-OSS-004-FO002-R03)` →
  `(Pampanga State University form DHVSU-QSP-OSS-004-FO002-R03)`.
  The code `DHVSU-QSP-OSS-004-FO002-R03` stays **byte-for-byte**, even though
  a later decision (D-67) printed a different form revision. Nat said not to
  touch it.
- line 75: `by PamSU Campus Clinic` → `by Pampanga State University Campus Clinic`.

Any `PSU` that is **part of a form code** (e.g. `PSU-QSP-…`) is **not** a
university-name mention — leave it. If you find a standalone `PSU` in the
notice, change it and list it in your report.

Stay on the consent page. Other pages that say "PamSU" are out of scope, but
**list them in your report** (grep `resources/views` for `PamSU|\bPSU\b`,
excluding form codes) so Nat can decide on them later.

Do not reword anything else in the notice, even text that looks outdated.
Nat looked at it and chose to leave it.

## Docs

None: this is presentation and copy only. Grep `docs/HealthPass_PRD.md` and
`docs/HealthPass_Context.md` for `480px` / `max-w-md` on the registration card.
If either states the card width, update that one line and say so.

## Verify

1. `php artisan serve --port=8080` and `npm run dev`.
2. Open `http://127.0.0.1:8080/register` on a desktop window (≥ 1280px wide):
   the card is about 768px, centred, with space on both sides. Do the same on
   steps 2, 3 and 4 (steps 3/4 need a real walk through the wizard).
3. DevTools device mode at **375px**: every step is single-column, with no
   horizontal scroll and the same 24px side gutter as before.
4. Step 2 at desktop width: paired fields sit side by side, and the college →
   program → year-level cascade still works. Submit with a missing field and
   check that the error sits under the right field.
5. Step 1 reads "Pampanga State University" twice. `DHVSU-QSP-OSS-004-FO002-R03`
   is unchanged. Confirm with `git diff` that no form code changed.
6. `php artisan test`: full suite green (some registration tests may assert on
   text; fix only those that asserted on "PamSU").

Then **stop and report**: what changed, the step-2 pairings you chose, the
out-of-scope "PamSU" list, and the test result. Wait for `commit`.

Proposed commit message (when Nat says go, stage this prompt folder,
`docs/prompts/pre-deployment-fixes-2/`, in the same commit):

```
feat: wider registration card and the university's full name on consent
```
