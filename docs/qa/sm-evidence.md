# HealthPass — Success Metrics Evidence (SM-1…SM-8)

Source: `docs/HealthPass_PRD.md` §1.5. This is the SM table plus a **"how to
measure"** paragraph for each metric, so a tester can produce the evidence
that closes it out. Metrics are verified mainly in Weeks 11–12 (integration,
UAT, dress rehearsal).

| ID | Metric | Target | Verification |
|---|---|---|---|
| SM-1 | Complete kiosk session (QR login → submit) | ≤ 5 minutes per student | Timed UAT runs (W11) |
| SM-2 | New kiosk submission appears in nurse Live Queue | ≤ 5 seconds (one polling cycle) | Stopwatch test during integration |
| SM-3 | Printed clearance vs. official form DHVSU-QSP-OSS-004-FO002-R03 | Field-for-field identical layout | Side-by-side print comparison signed off by clinic staff |
| SM-4 | Captured visits retained if encoding is delayed | 100% (zero loss) | Submit N visits, restart services, verify all N remain `captured` |
| SM-5 | Role isolation | 0 cross-role or cross-college data leaks | Negative-path test cases |
| SM-6 | Rule flags correctness | 100% agreement with §7.4 thresholds on a seeded test set | Automated/manual test against boundary values |
| SM-7 | End-to-end happy path | Book → kiosk → encode → print without developer intervention | Full dress rehearsal (W11–W12) |
| SM-8 | ISO/IEC 25010 evaluation | Acceptable rating per the team's instrument | Evaluation runs in W11 |

---

## SM-1 — Kiosk session ≤ 5 minutes

**How to measure.** Recruit a tester who is **not** on the dev team (this
mirrors real students). Start a stopwatch the moment they begin login at the
kiosk Welcome screen (QR scan on the Pi, or email login in UAT) and stop it
when the Complete screen appears after **Submit to Clinic**. Run this at least
5 times, ideally including manual-entry sessions (slower) and sensor sessions,
and record each duration. The metric passes when a realistic session finishes
in **≤ 5:00**. Log the fastest, slowest, and average; a slow run over 5 minutes
is a usability finding, not just a pass/fail — note where the time went (which
step stalled).

## SM-2 — Submission appears in Live Queue ≤ 5 seconds

**How to measure.** Open the nurse **Live Queue** in one window. In a second
window, complete a kiosk submission. At the instant you click **Submit to
Clinic →**, start a stopwatch; stop it when the new row appears in the queue.
Because the queue polls every 3–5 seconds (FR-NRS-02), the worst case is one
poll cycle. Repeat ~5 times and confirm every appearance is **≤ 5 s**. For a
stronger check, open the queue in **two** browser windows and confirm both
update within one cycle (matches the module AC). Record each measured delay.

## SM-3 — Printed form matches the official DHVSU form

**How to measure.** Encode a visit and use **Preview & Print** to produce a
physical printout (or a print-to-PDF at 100% scale; paper **Letter** — the
template default; A4 also fits one page). Lay it **side by side** with a blank official form
**DHVSU-QSP-OSS-004-FO002-R03**. With clinic staff present, check field by
field: issuing office header, all identity fields, vitals block, the **blank
respiratory-rate line** (must be empty, FR-PRT-03), the pre-printed physician
block (REYNALDO S. ALIPIO, MD — License No. 60252) with a blank signature line,
result, purpose (incl. the "Others, Specify" line printing the typed event
when used — D-24; case category is NOT printed, D-22), and date. The college
is not printed anywhere (D-25). It passes only when clinic staff
**sign off** that labels, ordering, and the physician block match, and long
nurse notes don't break the one-page layout. Keep the signed comparison as the
evidence artifact.

## SM-4 — Captured visits survive a restart (zero loss)

**How to measure.** Submit a known number **N** of kiosk visits (e.g. 5)
without encoding any of them — leave them all in `captured` status. Confirm all
N appear in the nurse Live Queue. Now **restart the services**: stop
`php artisan serve` (and, on the Pi, restart the app/host), then start it
again. Reload the Live Queue. The metric passes when **all N visits are still
present and still `captured`** — zero lost. A developer can additionally
confirm with a DB count of `clinic_visits` where `status = 'captured'` before
and after the restart (the two counts must be equal). This ties to BR-12
(captured visits are never auto-deleted) and NFR-5 reliability.

## SM-5 — Role isolation (zero cross-role / cross-college leaks)

**How to measure.** Run the **E2E-5** negative-path checklist in
`e2e-scenarios.md`: a student hitting `/nurse/*`, `/director/*`, `/admin/*`
(expect 403 / redirect); an unauthenticated user hitting protected routes
(expect redirect to login); the CCS admin searching for a CEA student and
tampering with a college query parameter (expect CCS-only results every time);
and a state-changing POST without a CSRF token (expect 419). The metric passes
only when **every** attempt is refused and **no** out-of-scope data is ever
displayed. Record each attempt and its result; a single leak fails the metric.
Automated feature tests covering these negatives are the durable evidence.

## SM-6 — Rule-flag correctness (100% vs §7.4 thresholds)

**How to measure.** Test against the **locked thresholds** (BR-13/§7.4), hitting
the boundaries exactly, since off-by-one errors hide there:

| Vital | Just below flag (expect NO flag) | At/over threshold (expect FLAG) |
|---|---|---|
| Temperature | `37.2 °C` | `37.3 °C` → Fever |
| BP systolic | `139` | `140` → High BP |
| BP diastolic | `89` | `90` → High BP |
| BMI | computed `29.9` | computed `30.0` → Abnormal BMI |

Enter each value at the kiosk (or run the seeded test set) and confirm the
flag booleans match the table with **100% agreement**. Include a case where
only diastolic crosses (e.g. `130/92`) to confirm the **OR** logic. The same
thresholds must drive the kiosk badge, the queue Flags column, and the
Director anomaly screen (single source: `config/healthpass.php`), so verify the
flag is consistent across all three surfaces. Automated tests against boundary
values are the strongest evidence.

## SM-7 — End-to-end happy path with no developer intervention

**How to measure.** Run the full **dress rehearsal**: a non-developer books an
appointment, walks up to the kiosk and completes it, the nurse sees it in the
queue and encodes a result, and prints the clearance — **start to finish with
no one touching code, the database, or a terminal** to unblock a step. This is
essentially E2E-1 performed under realistic conditions. It passes when the loop
completes cleanly and the printed form is produced. Any point where a developer
has to intervene is a fail and must be logged as the specific blocker.

## SM-8 — ISO/IEC 25010 evaluation (acceptable rating)

**How to measure.** The non-programmer team administers the project's ISO/IEC
25010:2023 evaluation **instrument** (the questionnaire/rubric defined in the
methodology chapter) to the intended evaluators in Week 11. Collect the scored
responses, tabulate them per the instrument's scoring method, and compare the
result against the team's defined "acceptable" threshold. The metric passes at
an **acceptable rating**; the completed instruments and the summary score table
are the evidence that feeds the capstone paper. The eight quality
characteristics map to NFR-1…NFR-9 in §7, so low scores can be traced back to a
specific requirement.

---

## Evidence log

For each metric, keep: the date measured, who measured it, the raw numbers /
signed artifact, and Pass/Fail. SM-3 and SM-8 additionally require a
**signed** artifact (clinic-staff sign-off; completed evaluation instruments).
