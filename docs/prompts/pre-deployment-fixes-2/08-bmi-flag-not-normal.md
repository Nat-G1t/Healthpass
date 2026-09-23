# 08 — BMI is flagged whenever it is not Normal (D-78)

Branch: `feature/pre-deployment-fixes-2`. **Do not commit.** Stop at the
verification block and wait for Nat's `commit`. **Do not run the data
migration on the dev database without asking Nat first** (CLAUDE.md: seeded
data may be in use).

**Decision D-78** (check the log: D-77 is taken by prompt 06 if it ran;
otherwise re-check). Amends the §7.4 business-rule table, **BR-13**, and the
flag wording in the analytics FRs. **Data change:** existing
`vital_signs.is_bmi_flagged` values are recomputed. No schema change (no
new column), no new package.

## Why

Nat: "BMI is flagged when it is not normal. Ours currently flags only BMI ≥ 30;
now flag it whenever it isn't normal. This should also show in the analytics."

## Settled with Nat

- **Normal is 18.5–24.9.** Flag when **BMI < 18.5 or BMI ≥ 25.0**. Stored BMI
  has one decimal, so 24.9 is normal and 25.0 is flagged.
- **Recompute every past visit.** Nat chose this over "new visits only",
  knowing it rewrites the stored flag on historical rows and changes past
  months' analytics. (BR-14 says flags are computed at capture and stored. The
  D-78 row must record this deliberate one-time exception.)
- BMI still never triggers **rest & re-check** (D-72): resting doesn't change a
  height or a weight. Don't change `VitalSigns::recheckStepsFor()`.
- The kiosk's colour-coded BMI **badge** keeps its four colours (Underweight
  red, Normal green, Overweight orange, Obese red). Only the ⚑ flag semantics
  change.

## What is there now (read before editing)

- `config/healthpass.php:92`: `'bmi_obese' => 30.0, // ≥ 30.0 → is_bmi_flagged`.
- `SubmitKioskVisit::flagsFor()` (~line 199): `'is_bmi_flagged' => $bmi >= $thresholds['bmi_obese']`.
  `RecheckKioskVisit` (~line 86) reuses `flagsFor()`.
- Kiosk front end: `resources/views/kiosk/index.blade.php:156` injects
  `'bmiObese' => config(...)`. `resources/js/kiosk/state-machine.js:~1633`
  has `bmiFlagged(bmi)` and the `bmiBadgeClass` comment ("⚑ stays obese-only").
- Analytics: `app/Services/ClinicAnalytics.php:288` tile subtitle
  `"BMI ≥ {$thresholds['bmi_obese']} · flagged at capture"`. The BMI
  Distribution buckets (~line 367) are **descriptive** and stay as they are.
  Everything else (anomalies, queue badges, Flagged Vitals by Sex FR-ANL-14,
  monthly report, `ClinicVisit::scopeFlagged`) reads the **stored**
  `is_bmi_flagged`, so the recompute is what makes history show up in analytics.
- Grep for every other reader/writer before editing:
  `grep -rn "bmi_obese\|bmiObese\|is_bmi_flagged\|Obese" app resources config tests database`.
  Check whether the nurse encode (D-65, `EncodeController` ~line 113) or
  `ClearanceRecord` writes a BMI flag anywhere, and whether
  `vital_signs.first_reading` (D-72) stores one. It shouldn't (BMI isn't
  re-checked), but confirm.

## What to do

1. **Config:** replace `bmi_obese` with the Normal band:
   ```php
   'bmi_normal_min' => 18.5,  // < 18.5 → is_bmi_flagged ("Abnormal BMI", underweight)
   'bmi_normal_max' => 25.0,  // ≥ 25.0 → is_bmi_flagged ("Abnormal BMI", overweight/obese) — D-78
   ```
   Explain the exclusive upper bound in the comment (Normal is 18.5–24.9).
2. **`flagsFor()`:** `$bmi < min || $bmi >= max`. One expression, read from
   `$thresholds`, no literals.
3. **Kiosk:** inject both bounds instead of `bmiObese`; `bmiFlagged()` uses the
   same rule; fix the stale comments ("obese-only" → D-78).
4. **Analytics tile subtitle:** `BMI < 18.5 or ≥ 25 · flagged at capture`, built
   from the two config values.
5. **Labels:** "Abnormal BMI" stays (it's already neutral). Drop any "/ Obese"
   from labels or comments that describe the flag.
6. **Data migration**, `database/migrations/2026_09_2X_XXXXXX_recompute_bmi_flags_d78.php`
   (date it the day you write it; keep the order after the latest migration):
   - `up()`: set `is_bmi_flagged = true` where `bmi < 18.5 OR bmi >= 25.0`,
     and `false` where `bmi >= 18.5 AND bmi < 25.0`. Leave rows with `bmi IS NULL`
     alone. Use the query builder (`DB::table('vital_signs')->where(...)->update(...)`),
     which is portable to SQLite.
   - Use **literal numbers in the migration, not `config()`**. A migration
     records what was done on that day. If the config changes later, this
     migration must not change meaning. Say so in a comment.
   - `down()`: re-apply the old rule (`bmi >= 30.0` → true, else false).
   - A class docblock: what it does, why (D-78), that it deliberately rewrites
     history (Nat's choice, 2026-09-23).

## Docs to update in this same change

- `docs/HealthPass_PRD.md`, **Decisions Log**, new row **D-78**: *"BMI is
  flagged whenever it is outside Normal (18.5–24.9): `is_bmi_flagged` when BMI
  < 18.5 or ≥ 25.0, replacing ≥ 30.0. Config `bmi_obese` → `bmi_normal_min` /
  `bmi_normal_max`. A one-time data migration recomputes `is_bmi_flagged` on
  every existing row, a deliberate exception to BR-14's 'computed at capture'
  (Nat, 2026-09-23), so past months' analytics change too. BMI still never
  triggers rest & re-check (D-72). No schema change."*
- §7.4 business-rule table (~line 485): the BMI row → `< 18.5 or ≥ 25.0 →
  is_bmi_flagged ("Abnormal BMI")`, cite D-78.
- **BR-13**: if it names `bmi_obese`, rename it.
- Any FR that quotes "BMI ≥ 30" as the flag (`grep -n "≥ 30\|>= 30\|bmi_obese" docs/HealthPass_PRD.md`).
  **Don't** touch FR-ANL-12's bucket names; those describe the distribution
  chart, not the flag.
- Next **revision-history row**: D-78, note the data migration.
- `docs/HealthPass_Context.md:202` (vital flags table) and any other "≥ 30"
  flag mention.
- `CLAUDE.md` "Locked decisions" says *"other thresholds per PRD business
  rules"*, so no edit is needed there. Grep to confirm it never quotes 30.
- `CHANGELOG.md`: one entry.

## Tests

- `flagsFor()` boundaries: 18.4 → flagged, 18.5 → not, 24.9 → not, 25.0 → flagged,
  32.0 → flagged.
- A kiosk submit with BMI 26 → stored `is_bmi_flagged = true`; the visit is
  **captured**, not resting (BMI never rests).
- Analytics: a visit with BMI 17 counts in the Abnormal BMI tile and in Flagged
  Vitals by Sex.
- The migration: seed rows with BMI 17.0 / 22.0 / 26.0 / 31.0 / NULL with the
  **old** flags, run the migration's `up()` (instantiate the anonymous class from
  the file and call `up()`, or run `artisan migrate` in the test), and assert
  the new flags; `down()` restores the old rule.
- Every test asserting the old ≥ 30 behaviour → updated.

## Verify

1. Show Nat the **before** numbers on the dev DB, read-only:
   `php artisan tinker --execute="dump(DB::table('vital_signs')->where('is_bmi_flagged',true)->count(), DB::table('vital_signs')->where(fn(\$q)=>\$q->where('bmi','<',18.5)->orWhere('bmi','>=',25))->count());"`
   (current flagged vs. flagged after D-78).
2. **Ask Nat** before `php artisan migrate`. Suggest a dump first:
   `C:\xampp\mysql\bin\mysqldump -u root healthpass > healthpass_backup_before_d78.sql`.
   After he says yes, run it and show the count again.
3. `php artisan serve --port=8080`, `npm run dev`. On the kiosk, enter height
   170 cm / weight 75 kg (BMI 26.0) → ⚑ on the Review screen; the badge still
   reads "Overweight" in orange.
4. Director and College Admin analytics for a past month: the Abnormal BMI tile
   and Flagged Vitals by Sex now include under/overweight visits; the tile
   subtitle reads "BMI < 18.5 or ≥ 25".
5. `php artisan test`: full suite green.

Then **stop and report**: before/after counts, the migration name, and the
test result. Wait for `commit`.

Proposed commit message:

```
feat: flag BMI whenever it is outside Normal, and recompute past flags (D-78)
```
