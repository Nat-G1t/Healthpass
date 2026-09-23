# 02 — OTP boxes: one character per box, and letters are refused out loud

Branch: `feature/pre-deployment-fixes-2`. **Do not commit.** Stop at the
verification block and wait for Nat's `commit`.

No new decision. **Amends FR-REG-04** (its "Verify & Continue is disabled until
6 digits are entered" clause changes). No schema change, no new package.

## Why

Nat, on the registration OTP page: "each of the 6 boxes accepts more than 1
input, it should only accept 1 per box. And when the student inputs a
character on the box and submits, the website should say that letters are invalid."

## What is there now (read these before editing)

There are **three copies** of the same Alpine OTP logic:

| Screen | File | How |
|---|---|---|
| Registration step 3 | `resources/views/auth/register/step3.blade.php` | inline copy |
| Student change-email verify | `resources/views/student/verify-email.blade.php` | inline copy |
| Forgot Password + Change Password | `resources/views/components/otp/boxes.blade.php`, used by `auth/password/forgot-verify.blade.php` and `auth/password/change-verify.blade.php` | shared component |

All three have the same bugs:

- Every box has **`maxlength="6"`**, so a box can hold up to six characters.
- `onInput` strips non-digits (`replace(/\D/g, '')`). When a letter is typed,
  `digits[i]` stays `''`, Alpine sees no change and doesn't re-render, so **the
  letter stays visible in the box** while the model thinks the box is empty.
- Typing a second digit into a filled box produces `"12"`. That goes down the
  paste branch and spreads across the next boxes.
- `pattern="\d*"` is on each box. **Gotcha:** once a letter is in a box,
  the browser's own constraint validation blocks the submit with a native
  "Please match the requested format" bubble, even though the box has no
  `name`. This has to go (see below), or Nat's message will never show.
- Verify is `x-bind:disabled="!ready"` with `ready = /^\d{6}$/`, so a code with a
  letter can never be submitted and no message ever appears.

The **server already refuses letters** before counting an attempt, in all four
controllers (`'otp' => ['required','string','size:6','regex:/^\d{6}$/']`, and the
regex message is "The code must contain digits only."):
`Auth/RegistrationWizardController.php:134–140`, `Auth/PasswordResetOtpController.php:~100`,
`Auth/PasswordChangeController.php:~85`, `Student/ProfileController.php:~140`.
Validation runs before the attempt counter, so a letter never uses up one of
the 5 attempts. Keep it that way.

## What to do (settled with Nat)

Nat chose **all four screens** and **"show on submit"** for letters.

### 1. One component

Move registration step 3 and the student verify-email page onto
`<x-otp.boxes>` so the logic exists **once**. Keep each page's surrounding
content (headings, flash, `@error('otp')` block, resend button, dev panel,
"Start over"). Only the `<form>` with its boxes and button is replaced.

Two small visible differences come with this; accept both and **mention them in
your report**:
- step 3's boxes are 60×50 / 26px and the component's are 58×46 / 24px;
- step 3 gains the component's shake-on-error and pop-on-digit animations.

If `verify-email.blade.php`'s form carries anything the component lacks (a
different action, a hidden field, a different button label), use the
component's props or add a prop. Do **not** fork the component again.

### 2. One character per box

- Typing into an **empty** box puts that one character in it and moves focus
  to the next box.
- Typing into a **filled** box **replaces** its character with the new one and
  moves on. It never spreads into the next boxes.
- A **paste**, or the browser's one-time-code **autofill**, of a whole code
  still spreads across the boxes, digits only, as it does today. Autofill on
  iOS/Android drops the whole code into box 0 through an `input` event (not
  `paste`). Tell the two apart with `e.inputType` (`insertText` = one typed
  key; `insertFromPaste` / `insertReplacementText` / missing = paste or
  autofill). Some Android keyboards report `insertCompositionText` or
  `e.data === null`. Treat "the box now holds more than one character and
  this wasn't a single keypress" as the spread case.
- After every input, **write the model back into the element**
  (`e.target.value = this.digits[i]`), so what's on screen is always what's
  in `digits[i]`. That is what fixes the "letter stays visible" bug.
- `maxlength`: `1` on boxes 1–5. Box 0 needs room for autofill (keep `6`
  there, or handle it in JS). Choose one approach, and say which and why.
- Backspace behaviour stays as it is.

### 3. Letters are refused, out loud

- A typed letter (or any non-digit) **stays** in its box, one character, like
  a digit.
- `ready` becomes "all six boxes hold one character" (not "six digits").
- **Remove `pattern="\d*"`** from the boxes (or put `novalidate` on the form) so
  the browser's own bubble never pre-empts our message. Keep
  `inputmode="numeric"`: phones still show the number pad.
- On submit, if any box holds a non-digit, **don't send the form**. Show this
  in the page's existing error style (the red box the `@error('otp')` block
  uses), and shake the row:

  > **Letters aren't allowed — the code is 6 numbers.**

  Put this string in **one** place in the component (a prop default or a
  `data-` attribute), not three.
- The server is the real gate: change the `otp.regex` message in **all four**
  controllers to the same sentence, so a request that skips the JS gets the
  same words. The rule itself doesn't change, and letters still never cost
  an attempt.

## Out of scope

- The resend button (`<x-otp.resend-button>`), the attempt counter, expiry,
  throttles.
- The kiosk (it has no OTP).

## Docs to update in this same change

- `docs/HealthPass_PRD.md` **FR-REG-04**: replace "Verify & Continue is disabled
  until 6 digits are entered" with: *each box holds exactly one character; a
  paste or autofill of the whole code fills all six; Verify & Continue is
  enabled once all six boxes are filled, and a code containing a non-digit is
  refused with "Letters aren't allowed — the code is 6 numbers." before any
  attempt is counted.* Add "(2026-09-23)" in the same style other amendments use.
- Add the next **revision-history row** (it was 1.52 when this was written;
  check). One line: OTP boxes take one character each; letters are refused
  with a message; all four OTP screens share one component. No schema change.
- `docs/HealthPass_Context.md` AUTH section: if it describes the OTP boxes, add
  the same sentence.
- `CHANGELOG.md`: one entry, in the same format as the latest ones.
- `docs/qa/e2e-scenarios.md`: if there is a registration-OTP scenario, add a
  step "type a letter → message, no attempt used".

## Tests

Extend the existing OTP feature tests (`tests/Feature/Auth/RegistrationTest.php`,
`PasswordResetOtpTest.php`, `PasswordChangeTest.php`,
`tests/Feature/Student/IdProfilePageTest.php`). For each of the four endpoints:
- posting `otp=12a456` → session error on `otp` with the **new** message, **and**
  the stored attempt count is unchanged (a following correct code still works);
- any test asserting on "The code must contain digits only." → the new string.

The box behaviour itself is JavaScript, so check it by hand (below).

## Verify

1. `php artisan serve --port=8080`, `npm run dev`.
2. On **each** of the four screens (register step 3; `/forgot-password` →
   verify; Profile → Change Password → verify; student ID Profile → change
   email → verify):
   - type `1` then `2` into the **same** box (click back into it): the box
     shows `2` only and the next box doesn't change;
   - type `1 2 a 4 5 6`: `a` is visible in box 3, Verify is enabled, press it:
     the red "Letters aren't allowed…" message shows, the row shakes, and the
     network tab shows **no** request;
   - fix box 3 to `3`: submits normally;
   - paste `123456` into box 0: all six fill;
   - Backspace across boxes still steps back.
3. DevTools device mode (Android): the number pad appears; no native
   "match the requested format" bubble ever shows.
4. With the JS disabled or the request replayed from the network tab with
   `otp=12a456`: the server answers with the same message, and a correct
   code afterwards still verifies (no attempt was used).
5. `php artisan test`: full suite green.

Then **stop and report**: what changed per screen, the `maxlength`/autofill
approach you chose, the two visible differences on step 3, and the test result.
Wait for `commit`.

Proposed commit message:

```
fix: OTP boxes take one character each and refuse letters with a message
```
