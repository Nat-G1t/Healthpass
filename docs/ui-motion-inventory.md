# HealthPass — UI Motion Inventory

The acceptance checklist for the site-wide motion pass
(`docs/prompts/ui-motion-pass.md`) **and** the motion contract for screens
built later. Future feature prompts should consult this file and implement the
motion spec'd for their screens.

- **Spec refs** (§) point into `docs/prompts/ui-motion-pass.md`.
- Motion language: `hp-` tokens/keyframes in `resources/css/app.css`, shared JS
  helpers in `resources/js/shared/motion.js`. Never hardcode durations/easings.
- **Status:** `now` = screen exists and is animated in this pass ·
  `when built` = spec'd here for a future prompt · `skip` = out of scope.
- Sources: PRD §4 modules, `routes/web.php`, every view under
  `resources/views/`. Note: this inventory was compiled from the PRD + live
  views; the JS-bundled prototypes could not be clicked through in-session —
  if a prototype shows an animatable moment missing here, add a row.

## Global (all roles) — §5

| Screen | Trigger | Element | Motion (spec ref) | Status |
|---|---|---|---|---|
| Every web shell | Slow page load | `<x-hp.splash />` branded overlay | Delayed fade-in ≥200 ms, fade-out on `load` (§5.2) | now |
| Every web shell | Same-origin link click / form submit | Top 3 px progress bar | Width → 85 % long-tail `--hp-ease-out` (§5.3) | now |
| Every web shell | Page navigation | Document crossfade | View Transitions API `hp-fade` (§5.5) | now |
| Every web shell | First paint | `<main>` content container | One-time `hp-fade-up` (§5.5) | now |
| All pages | OS "reduce motion" | Everything | Global kill switch + JS guards (§5.1) | now |
| All pages | Navigating form submit | Submit button | Pending spinner + stable width, double-submit guard (§5.6) | now |
| All pages | Flash/status banner appears | Banner | `hp-fade-up` in; info auto-dismiss ~5 s, errors stay (§5.7) | now |
| All pages | Validation error | `input-error` + offending input | Error `hp-fade` in + `hp-shake` on input (§5.7) | now |
| All pages | Modal open/close | `components/modal`, `logout-confirm` | Backdrop `hp-fade`; panel `hp-sheet-up` spring; exit `--hp-ease-in` ~60 % duration (§5.7) | now |
| All pages | Dropdown open | `components/dropdown` | Scale-in from `origin-top-right`, tokens (§5.7) | now |
| All pages | Button press | All `x-hp.button` | `active:scale-[0.97]` at `--hp-dur-fast` (§5.7) | now |
| All pages | Empty state appears | Empty-state block | `hp-fade-up` (§5.7) | now |
| Print flow (`nurse/print`), emails (`mail/*`) | — | — | **Zero motion** (§2) | skip |
| `welcome`, `hello`, `dev/components` | — | Dev-only views | — | skip |

## Guest / Auth (AUTH, REG) — §6.2

| Screen | Trigger | Element | Motion (spec ref) | Status |
|---|---|---|---|---|
| Login (`auth/login`) | Page enter | Logo + card | Card `hp-fade-up` on first paint | now |
| Login | Auth failure | Email/password inputs | `hp-shake` + error fade (§5.7) | now |
| Login | Submit | Sign In button | Pending state (§5.6) | now |
| Register wizard shell | Step page enter | Step card | `hp-fade-up` crossfeel between steps | now |
| Register wizard shell | Step advance | 4-segment progress bar | Animated fill (`transform: scaleX`, not width) + label emphasis | now |
| Register step 3 / OTP screens | Digit typed | OTP box | Fill tick `hp-pop`; focus ring (existing 150 ms transition kept) | now |
| OTP screens | Wrong code | OTP boxes row | `hp-shake` + error fade | now |
| OTP screens | Resend cooldown | Resend button countdown | Existing countdown kept; button state fades | now |
| Forgot/change password flows (`x-auth.shell`, sidebar pages) | Page enter / errors / submits | Card, inputs, buttons | `hp-fade-up` enter, shakes, pending states | now |
| Verify-email screens | Page enter / resend | Card, buttons | `hp-fade-up`, pending state | now |
| Confirm-password (Breeze `layouts/guest`) | Page enter / submit | Card, button | Splash + pending only (legacy shell, minimal) | now |

## Student (STU) — §6.2

| Screen | Trigger | Element | Motion (spec ref) | Status |
|---|---|---|---|---|
| Dashboard | First paint | Stat/action cards | Staggered `hp-anim-fade-up` (≤40 ms, cap 8) | now |
| Book Appointment | Availability fetch (month load/change) | Slot/calendar grid | `<x-hp.skeleton>` shimmer while fetching (§5.4); date change crossfades grid | now |
| Book Appointment | Slot/date select | Selected cell | `hp-pop` selection feedback | now |
| Book Appointment | Confirm modal | Modal | Token-aligned sheet-up (§5.7) | now |
| Book Appointment | Submit booking | Confirm button | Pending state (§5.6) | now |
| Booking Confirmed | Page enter | Success check + APT ref | `hp-check-draw` + ref reveal; card `hp-fade-up` | now |
| Booking Confirmed / dashboard | Cancel appointment | Confirm modal + row | Modal tokens; row leaves via collapse where applicable | now |
| My Records | First paint | Record rows/cards | Staggered `hp-fade-up` (first paint only, never on poll) | now |
| Kiosk Tutorial | Page enter | Step cards | Staggered `hp-fade-up` | now |
| ID & Profile | Save / link-ID / email-change OTP | Buttons, banners, QR panel | Pending states, banner fade-up, OTP motions as Auth | now |

## Nurse (NRS, PRT) — §6.1–6.2

| Screen | Trigger | Element | Motion (spec ref) | Status |
|---|---|---|---|---|
| Live Queue | Poll: new arrival | New `<tr>` | `hp-fade-up` + peach highlight fading ~1.5 s (§6.1a) | now |
| Live Queue | Poll: departure | Leaving `<tr>` + rows below | `collapseRow`: fade + FLIP rows up (§6.1a) | now |
| Live Queue | Poll: NEXT promotion | Peach band + Next tag | `background-color` transition on `td`s + `hp-pop` on tag (§6.1a) | now |
| Live Queue | Poll: count change | Subtitle count | `countUp` (§6.1a) | now |
| Live Queue | **Return from encode (flagship)** | Ghost row (`data-leaving`) | Hold ~600 ms → `collapseRow`; banner enters without shifting table; poll never resurrects ghost (§6.1b) | now |
| Live Queue | Queue empties | Empty state | `hp-fade-up` (§6.1b) | now |
| Encode Result | Page enter | Section cards | Staggered `hp-fade-up` | now |
| Encode Result | Flagged vitals | BMI/flag chips | `hp-pop` on first paint | now |
| Encode Result | Save & Close | Submit button | Pending state — verify one-shot guard unaffected (§5.6) | now |
| Encode Result | Preview & Print | Iframe post buttons | **Excluded** from pending states (`data-no-pending`), no progress bar (§5.3/5.6) | skip |
| Kiosk Devices | Enroll device | New row | `hp-fade-up` + highlight | now |
| Kiosk Devices | Revoke device | Row | `collapseRow` on confirm-submit navigation return (banner + list re-render: fade-up) | now |
| Print flow | — | — | **Zero motion** (§2) | skip |

## College Admin (ADM) — §6.2 (screens exist — animated now)

| Screen | Trigger | Element | Motion (spec ref) | Status |
|---|---|---|---|---|
| Dashboard | First paint | KPI cards | Staggered `hp-fade-up` | now |
| New Batch Request | Student list interactions | Checkboxes/rows | `hp-pop` selection tick | now |
| New Batch Request | Submit | Button | Pending state (§5.6) | now |
| Batch Confirmation | Page enter | BR ref + summary | `hp-check-draw` + `hp-fade-up` | now |
| Batch Tracking | First paint | Status rows | Staggered `hp-fade-up`; status badges transition colors | now |

## Director (DIR-A, ANL) — §6.2 / §8 (screens exist — animated now)

| Screen | Trigger | Element | Motion (spec ref) | Status |
|---|---|---|---|---|
| Dashboard | First paint | KPI cards + preview panels | Staggered `hp-fade-up`; stat `countUp` | now |
| Batch Approvals | Approve/Reject modal | Modal | Token-aligned sheet-up | now |
| Batch Approvals | Decision submit | Button | Pending state (§5.6) | now |
| Batch Approvals | Row after decision | Row/banner | Banner `hp-fade-up`; list re-renders with fade-up | now |
| Analytics | Chart data ready | Chart.js charts | `Chart.defaults.animation = { duration: 600, easing: 'easeOutQuart' }` once, globally (§8) | now |
| Analytics | Filter change | Charts area | Chart.js re-animate (built-in); no fake skeleton for server-rendered data (§5.4) | now |
| Anomalies | First paint | Stat cards + flagged table | Staggered `hp-fade-up`; stat `countUp` | now |
| Anomalies detail | Page enter | Record card | `hp-fade-up` | now |

## Kiosk (KSK, HW) — §7 (Pi 4 budget: transform/opacity only, ≤2 elements at once, travel ~8 px because `--k-zoom: 2` doubles it)

| Screen | Trigger | Element | Motion (spec ref) | Status |
|---|---|---|---|---|
| Every screen | `state.screen` change | Screen root | Enter-only `x-transition` `hp-fade-up` `--hp-dur-base` (§7 — no leave transitions: screens are stacked `x-show` layers) | now |
| Welcome | Idle | QR pulse ring | Existing `k-pulse` kept (allowed loop) | now |
| Welcome | Scan `sending` | Scan affordance | Pulse on scan status | now |
| Welcome | Scan error | Error text/panel | `hp-shake` + fade in | now |
| Email login | Login error | Fields/error | `hp-shake` + fade | now |
| Email login / staff exit | Keyboard shown | Credential keyboard | `hp-sheet-up` slide; keys `active:scale-95` `--hp-dur-fast` (existing `k-key-press` kept) | now |
| Identity | Enter | Identity card | Screen enter covers it | now |
| Walk-in | Enter | Panel | Screen enter covers it | now |
| Consent | Enter | Consent card | Screen enter covers it | now |
| Vitals | Step 1–4 change | Step body | Enter-only fade-up per step | now |
| Vitals | Step change | Progress indicator | Sliding pill / `scaleX` fill — never `width` (§7) | now |
| Vitals | Scanning phase (`SCAN_MS`) | Reading panel | Polished pulse (transform/opacity) | now |
| Vitals | Reading captured | Value + status badge | `hp-pop` + check draw | now |
| Vitals | BMI panel appears (step 2) | BMI panel | `hp-fade-up`, **neutral styling only** (FR-KSK-14) | now |
| Vitals | Manual pad opens / out-of-range | Pad, pad error | Pad `hp-sheet-up`; error `hp-shake` | now |
| Questionnaire | Answer tap | Yes/No control | `hp-pop` selection feedback | now |
| Questionnaire | Card answered | Next unanswered card | Gentle scroll-into-view + fade (no hijack mid-read) | now |
| Review | Enter | Summary rows | Stagger ≤8 items | now |
| Review | Submit | Pending overlay | "Saving your visit…" spinner, min ~400 ms (§7) | now |
| Complete | Enter | Check + HP ref | `hp-check-draw` + ref reveal — **submission only, outcome-neutral** (FR-KSK-14) | now |
| Complete → Welcome | Auto/tap reset | Whole kiosk | `freshState()` untouched; Welcome's enter IS the reset animation (FR-KSK-13) | now |
| Restricted (`kiosk/restricted`) | Page enter | Message card | `hp-fade-up` | now |

## Deferred / future screens

Nothing left in `when built`: ADM, DIR-A, and ANL screens all exist on this
branch and are covered above. Any **new** screen added later inherits the
Global table plus the closest role table's patterns; add its rows here in the
same PR that builds it.
