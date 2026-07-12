# One-shot prompt — Site-wide Motion & Perceived-Performance Pass ("iOS feel")

> **How to run:** start a fresh Claude Code session in `C:\Capstone\healthpass` and say:
> *"Read docs/prompts/ui-motion-pass.md and execute it."*
> The prompt is phased — if the session runs long, finish and commit the current
> phase; the inventory doc (Phase 0) tracks what remains for a follow-up session.

---

## 0. Mission

Make HealthPass *feel* like a polished native app instead of a static website.
Every user-visible state change — for **every role** (Student, College Admin,
Nurse, Clinic Director, and the public Kiosk) — should be accompanied by a
subtle, fast, purposeful animation or transition, in the spirit of iOS:
smooth, restrained, and communicative. Two flagship experiences define the bar:

1. **Nurse Live Queue "reverse-stack".** After the nurse encodes a student and
   returns to the Live Queue, the just-encoded row should visibly animate out
   (fade + collapse) while the remaining rows slide up to close the gap — the
   nurse *sees* the queue advance instead of the row silently being gone.
2. **No white screen on slow internet.** When the app is deployed on the
   internet and a user on a slow connection logs in or navigates, they must
   never stare at a blank page: an instant branded loading screen (and, for
   in-page fetches, skeletons/spinners) covers every wait.

This is a **presentation-only pass**: Blade, CSS, and a small amount of JS.
Controllers change only where an animation genuinely needs server help (one
case is specified in §6.1). No behavior, routing, or data changes otherwise.

## 1. Read first (in this order)

1. `CLAUDE.md` — all of it. Its locked decisions bind this task.
2. `docs/HealthPass_PRD.md` §4 (Modules AUTH, REG, STU, ADM, DIR-A, KSK, NRS,
   PRT, ANL, HW) — the full screen inventory, including screens not built yet.
3. **The prototypes — visual source of truth:**
   `docs/prototypes/web/web.html` and `docs/prototypes/kiosk/Kiosk.html`.
   ⚠️ These are single-file **JS-bundled artifacts** (~1.5 MB each, minified
   bundle inside). Do **not** try to read them as text — open each in a
   browser (`start docs/prototypes/web/web.html` from PowerShell) and click
   through **every screen of every role** to spot animatable moments. If you
   cannot render them, ask Nat for screenshots rather than guessing.
4. Current UI code: `resources/views/**` (all Blade views), `resources/css/app.css`,
   `resources/js/app.js`, `resources/js/nurse/live-queue.js`,
   `resources/js/kiosk/` (state machine + `kiosk.js` entry), `routes/web.php`.
5. `vite.config.js` + `tailwind.config.js` + `package.json` — confirm how
   Tailwind is wired (`app.css` uses v3 `@tailwind` directives, but
   `package.json` lists both `tailwindcss ^3.1` and `@tailwindcss/vite ^4`;
   verify which one is active before extending the config, and flag the
   mismatch to Nat instead of "fixing" it).

If anything in this prompt conflicts with the PRD or CLAUDE.md, **stop and
say so** — do not silently reconcile.

## 2. Hard guardrails

- **No new packages.** No framer-motion, GSAP, auto-animate, NProgress, etc.
  Everything is CSS transitions/keyframes + Alpine `x-transition` + at most
  ~200 lines of hand-rolled helper JS. If you believe a package is truly
  warranted, stop and propose it with justification (CLAUDE.md rule).
- **Prototypes stay the layout source of truth.** This pass adds *motion*,
  never new layouts, colors, or components that change how screens look at
  rest. At-rest screenshots before/after must be pixel-identical.
- **Kiosk never hints at outcomes** (FR-KSK-14). The Complete screen
  celebrates *submission* only — neutral motion, no green "you passed"
  fireworks, nothing that could read as Fit/Unfit.
- **Zero motion in the print flow** (`nurse/print.blade.php`, the print
  preview/reprint iframe posts) and in **emails** (`mail/*.blade.php`).
  Nothing may delay, animate, or restyle the official DHVSU form.
- **`prefers-reduced-motion` is mandatory.** A global override disables
  movement (see §5.1), and every JS-driven animation checks it first.
- **Animate only `transform` and `opacity`** (compositor-friendly). Never
  animate `width/height/top/left/box-shadow/filter` — the kiosk runs Chromium
  on a Raspberry Pi 4 at 1080×1920 and must not jank. Layout moves (rows
  closing ranks) use the FLIP technique: measure positions before/after the
  DOM change, then animate the *transform* between them.
- **Subtle means subtle.** Durations 120–400 ms; travel 8–24 px; scale range
  0.96–1.0; stagger ≤ 40 ms per item and cap the stagger after ~8 items;
  nothing loops (existing LIVE pulse and spinners excepted); motion never
  blocks input or delays data that is already available.
- Respect CLAUDE.md's team context: Nat and Baldo are new to Laravel —
  briefly explain any Laravel concept you introduce (session flash, Blade
  component slots, `@stack`, etc.), and keep every helper beginner-readable.
- Don't touch unrelated code, even if it looks wrong — flag it instead.

## 3. The HealthPass motion language

Define once, reuse everywhere. All names are prefixed `hp-`/`--hp-` so they
are grep-able.

### 3.1 Tokens (add to `resources/css/app.css` `:root`)

```css
--hp-dur-fast: 150ms;   /* hovers, presses, small fades            */
--hp-dur-base: 250ms;   /* most enters: rows, dropdowns, screens   */
--hp-dur-slow: 400ms;   /* page-level: splash fade, modals, sheets */
--hp-ease-out:    cubic-bezier(0.25, 1, 0.5, 1);   /* default decelerate (enters)  */
--hp-ease-spring: cubic-bezier(0.32, 0.72, 0, 1);  /* iOS sheet feel (modals)      */
--hp-ease-in:     cubic-bezier(0.55, 0, 1, 0.45);  /* exits — faster than enters   */
```

Mirror the same values as Tailwind theme extensions (`transitionDuration`,
`transitionTimingFunction`, plus `keyframes`/`animation` entries) so Blade can
use utilities like `hp-anim-fade-up`. Keyframes to define: `hp-fade-up`
(opacity 0→1, translateY 12px→0), `hp-fade` (opacity only), `hp-scale-in`
(0.96→1 + fade), `hp-sheet-up` (translateY 24px→0 + fade, spring ease),
`hp-shake` (±6 px x-axis, 3 oscillations, ~350 ms — validation errors),
`hp-shimmer` (skeleton gradient sweep), `hp-pop` (scale 1→1.06→1, ~200 ms —
selection ticks), `hp-check-draw` (SVG `stroke-dashoffset` draw-in for
success checkmarks).

### 3.2 Where code lives

- `resources/css/app.css` — tokens, keyframes, `hp-anim-*` utility classes,
  the global reduced-motion override, splash + skeleton styles.
- `resources/js/shared/motion.js` — **new**, imported by BOTH entries
  (`app.js` and `kiosk/kiosk.js` — Vite tree-shakes per bundle, and CLAUDE.md
  requires the kiosk bundle to stay independent, so shared code must live in
  a leaf module like this, never in `app.js` itself). Contents (~150 lines):
  - `prefersReducedMotion()` — single source of truth for the media query;
  - `collapseRow(tr, {onDone})` — fade a table row, FLIP-translate the rows
    below it up by its slot height, then remove it and clear transforms;
  - `flipMove(container, mutate)` — generic FLIP: snapshot child positions,
    run `mutate()`, animate children from old→new positions;
  - `countUp(el, to, {duration})` — animate an integer in place (queue count,
    stat tiles); skips straight to the value under reduced motion.
- Blade: `resources/views/components/hp/splash.blade.php` (§5.2) and
  `resources/views/components/hp/skeleton.blade.php` (§5.4) — new components.
- Never duplicate these helpers per page; import them.

### 3.3 MPA reality — pick the right tool per wait

HealthPass is a classic multi-page Laravel app (full page load per
navigation). Use this decision rule everywhere:

| Wait type                              | Treatment                            |
|----------------------------------------|--------------------------------------|
| Full page navigation (slow network)    | Splash overlay (§5.2) + top progress bar (§5.3) + view-transition crossfade (§5.5) |
| In-page fetch (poll, availability, …)  | Skeleton or inline spinner (§5.4)    |
| Form submit that navigates             | Pending button state (§5.6)          |
| Data already on the page               | Entrance animation only — never fake a wait |

## 4. Phase 0 — Motion inventory (do this first)

Produce **`docs/ui-motion-inventory.md`**: one table per role/area with
columns `Screen · Trigger · Element · Motion (spec ref) · Status`, where
Status is `now` (screen exists) or `when built` (prototype/PRD only — ADM
batch workflow, DIR-A approvals, ANL analytics, etc.). Sources: the two
prototypes clicked through in a browser, PRD §4 modules, `routes/web.php`,
and the existing views. Mark dev-only views (`welcome`, `hello`,
`dev/components`) as `skip`. This doc is the acceptance checklist for this
pass **and** the motion contract for screens built later — future prompts
will say "consult ui-motion-inventory.md".

Seed it with (verify + extend by actually looking — this list is a floor,
not the ceiling; the mission is *every* action for *every* role):

- **Guest/Auth (AUTH, REG):** login card enter, error shake, register wizard
  steps 1–4 (crossfade between step pages + animated progress indicator),
  OTP boxes (focus pop, fill tick, error shake, resend countdown), forgot/
  change-password flows, verify-email screens.
- **Student (STU):** dashboard cards staggered enter; Book Appointment —
  skeleton while `appointments/availability` fetch runs, date change
  crossfades the slot grid, slot select pop, confirmed page success-check
  draw + APT ref reveal; cancel confirm modal; Records list stagger; Tutorial
  step cards; ID & Profile (save feedback, link-ID flow, email-change OTP).
- **Nurse (NRS, PRT):** Live Queue flagship (§6.1); Encode page (section
  reveals, BMI/flag chips, Save & Close pending state, print-preview iframe
  — *print itself excluded*); Kiosk Devices (new device row slides in,
  revoke collapses row).
- **College Admin (ADM)** — `when built`: batch request create/monitor,
  status changes, student list interactions.
- **Director (DIR-A, ANL)** — `when built`: approvals, batch date picking,
  capacity config, analytics (Chart.js animation defaults + chart skeletons,
  stat count-ups).
- **Kiosk (KSK, HW):** every screen transition + per-screen moments (§7).
- **Global:** splash, progress bar, view transitions, flash/status banners,
  modals (`components/modal`, `logout-confirm`), dropdowns, sidebar
  hover/active states, empty states, pending buttons, validation errors.

## 5. Phase 1 — Foundation (global, both bundles)

### 5.1 Reduced motion — global kill switch

In `app.css`:

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}
```

Plus `prefersReducedMotion()` guards in every JS helper (FLIP, count-up,
splash fade can simply hide instantly).

### 5.2 Branded splash — the "slow internet" fix

A `<x-hp.splash />` partial added **inside every real user-facing shell**,
immediately after `<body>` opens, with its own **inline `<style>` and inline
`<script>`** so it paints from the raw HTML before Vite CSS/JS ever arrives
(that is the whole point — it must not depend on the bundle). Behavior:

- Full-viewport overlay: off-white `#F6F2ED` background, the HealthPass logo
  mark (reuse the inline SVG from `components/hp/logo.blade.php`), a soft
  pulsing dot — brand palette only.
- **Appears only when loading is actually slow:** overlay starts invisible
  and fades in after a ~200 ms CSS `animation-delay` — fast loads never see
  a flash of splash.
- Hides on `window` `load` (fade `--hp-dur-slow`, then `visibility:hidden`),
  with a hard failsafe timeout (~10 s) and a `<noscript>` rule that hides it
  when JS is off. It must be impossible for the splash to permanently cover
  a rendered page.
- Zero layout shift: it overlays, never displaces content.

Shells to cover — first *map* which of these actually wrap user-facing pages
(some nest; add the splash only to the outermost real shells, exactly once
per page): `layouts/app.blade.php`, `layouts/guest.blade.php`,
`components/auth/shell.blade.php`, `components/layout/sidebar.blade.php`,
`resources/views/kiosk/index.blade.php` (lightweight variant — on the Pi it
is localhost-instant, but the internet deployment shape also serves /kiosk
over HTTPS). **Never** in `nurse/print.blade.php` or mail views.

While in the shells: add `<link rel="preconnect">` for the Google Fonts
origins used by the Poppins `@import` in `app.css` (a small, on-mission win
for slow connections). You will notice the Breeze layout still loads
Figtree — flag it to Nat; don't silently rip it out.

### 5.3 Top navigation progress bar (hand-rolled, ~40 lines)

A 3 px `--hp-orange` bar fixed to the top: on click of same-origin links and
on submit of navigating forms, animate width to ~85% with `--hp-ease-out`
(long tail); the next page load finishes the story (its splash/render).
Opt-outs via `data-no-progress` (print-preview iframe forms, downloads,
`target="_blank"`). Skip entirely on the kiosk bundle — the kiosk never
navigates.

### 5.4 Skeletons + spinners for in-page fetches

`<x-hp.skeleton>` — shimmer blocks (`hp-shimmer` keyframe) sized by the
caller. Apply where the page fetches after load: Book Appointment slot grid
during the availability fetch, and (when built) analytics charts. Do NOT
skeleton server-rendered content — the splash already covers full-page
loads, and fake skeletons that flash for 50 ms are worse than nothing.
Reserve real dimensions so nothing shifts when content lands (CLS ≈ 0).

### 5.5 Cross-page transitions — View Transitions API

Pure-CSS progressive enhancement in `app.css`:

```css
@view-transition { navigation: auto; }
::view-transition-old(root) { animation: hp-fade var(--hp-dur-fast) var(--hp-ease-in) both reverse; }
::view-transition-new(root) { animation: hp-fade var(--hp-dur-base) var(--hp-ease-out) both; }
```

Unsupported browsers simply get instant navigation — no JS fallback needed,
no polyfill. Also give `<main>` content a one-time `hp-fade-up` entrance in
the web shells so even non-supporting browsers feel a soft landing. Keep the
entrance on the *content container*, not `body`, so the sidebar/nav feel
stationary like a native app chrome.

### 5.6 Pending button states

Extend `components/hp/button.blade.php` with an opt-in `data-pending-label`
behavior (tiny shared JS): on submit of the owning form → disable, swap in a
spinner + label, keep width stable. Roll out to every navigating submit
(login, register steps, booking, encode Save & Close, password/OTP forms,
kiosk device enroll…). **Skip** forms that target iframes (print preview /
reprint — the page never navigates, the button would stick disabled) and
check the encode controller's existing one-shot guard still behaves. This
doubles as double-submit protection — mention that in the Blade comment.

### 5.7 Shared micro-interactions

- Press feedback on all `hp` buttons: `active:scale-[0.97]` +
  `--hp-dur-fast` transform transition (CSS only, both bundles).
- Flash/status banners (e.g. the green Save & Close banner on the queue):
  enter with `hp-fade-up`; auto-dismiss informational ones after ~5 s with a
  fade — errors stay until dismissed.
- Validation errors (`input-error` component): `hp-fade` in + one `hp-shake`
  on the offending input.
- Modals (`components/modal`, `logout-confirm`): align the existing Alpine
  `x-transition`s to the tokens — backdrop `hp-fade`, panel `hp-sheet-up`
  with `--hp-ease-spring` (iOS sheet feel), exits use `--hp-ease-in` and
  ~60% of the enter duration.
- Dropdowns (`components/dropdown`): scale-in from `origin-top-right`,
  tokens applied.
- Empty states: `hp-fade-up` when they appear.

## 6. Phase 2 — Web app flows

### 6.1 FLAGSHIP — Nurse Live Queue (FR-NRS-02/04)

Read `resources/js/nurse/live-queue.js` + `nurse/queue.blade.php` +
`components/nurse/queue-row.blade.php` + `Nurse/QueueController` +
`Nurse/EncodeController@store` first. The queue is a real `<table>`
(`border-separate`, `data-queue-body` tbody) polled every 4 s; a `data-next`
attribute drives NEXT styling; encode redirects back with a
`session('status')` flash. Deliver:

**(a) Poll diff animations.** However the poller currently reconciles rows,
make each kind of change animate: new arrival → `hp-fade-up` + brief peach
highlight that fades over ~1.5 s; departure → `collapseRow` (fade the row,
FLIP the rows below upward into the gap, then remove); NEXT promotion → the
peach band change gets a `background-color` transition on the `td`s plus one
subtle `hp-pop` on the "Next" tag; queue count in the subtitle → `countUp`.
If the poller replaces the whole tbody wholesale, refactor it to reconcile
per-row by visit id first (explain the diffing in comments) — animations are
impossible across a wholesale innerHTML swap.

**(b) Return-from-encode "reverse-stack" (the flagship moment).** After Save
& Close, the visit is encoded and excluded from the queue query, so by the
time the nurse is back the row has *already vanished* — to animate its exit
the server must hand the page one "ghost":

- `EncodeController@store`: also flash the id —
  `->with('encoded_visit_id', $visit->id)` (a session flash lives for
  exactly one request — perfect here, and gone on reload by design).
- `QueueController@index`: when that flash is present, fetch that (now
  encoded) visit and hand it to the view separately; render it via the same
  row component in its original queue position, marked `data-leaving`, action
  button replaced by a static "Encoded ✓" chip (it must not be clickable —
  it is scenery, not a live queue entry).
- On page load, `live-queue.js`: hold ~600 ms so the nurse's eye lands, then
  run the same `collapseRow` used by the poll (reduced motion → remove
  instantly). If the collapse empties the queue, fade the empty state in.
- **Pitfalls to handle:** the first 4 s poll tick must not double-remove or
  resurrect the ghost (remove it from the DOM/bookkeeping before the first
  reconcile, or key the reconciler to ignore `data-leaving` rows); the green
  status banner animates in per §5.7 and must not shift the table mid-FLIP
  (reserve its space or sequence banner-then-collapse).
- **Feature test** (this is the one controller change, so it gets a test):
  encoding then following the redirect shows the ghost row exactly once
  (`data-leaving` present, marked encoded); a plain reload of the queue does
  not include it; a never-encoded visit id in the flash slot fails safe
  (no ghost, no error).

### 6.2 Remaining web flows (consult the Phase 0 inventory)

Work through the `now` rows role by role: auth + register wizard, student
dashboard/booking/confirmed/records/tutorial/profile flows, nurse encode +
kiosk-devices, profile/password/OTP screens, sidebar/nav polish. Apply the
motion language — every enter `hp-fade-up`-family, every removal a collapse,
every success a drawn check or pop, every error a shake, every wait covered
per §3.3. Success pages (booking confirmed) get the `hp-check-draw`
checkmark + reference number `countUp`-style reveal. Stat tiles and lists
stagger on first paint only — never re-stagger on poll updates.

## 7. Phase 3 — Kiosk (Pi 4 budget — be extra frugal)

Screens swap via `x-show="state.screen === '…'"` on one Alpine state object
(`resources/js/kiosk/state-machine.js`, screens: welcome → email_login /
identity / walkin → consent → vitals (internal steps 1–4) → questionnaire →
review → complete).

- **Screen enters:** add enter-only `x-transition` (`hp-fade-up`,
  `--hp-dur-base`) to every screen root. Enter-only is deliberate: with
  `x-show`, a leave transition would briefly show two full screens stacked
  and double the page height — do not add leave transitions unless you make
  screens absolutely positioned, which you should not do without checking
  the existing `.kiosk-panel { inset: 0 }` layout carefully.
- **Vitals steps 1–4:** same enter-only pattern per step body; the step
  progress indicator animates its fill/active dot (`transform: scaleX` or a
  sliding pill, not width). The existing 1.2 s "scanning" phase (`SCAN_MS`)
  gets a polished pulse; the settle to "captured" gets `hp-pop` + check
  draw. BMI panel (step 2) fades up when it appears — **neutral styling
  only**, it must never read as a verdict (FR-KSK-14).
- **Questionnaire (9 systems + pregnancy):** answer taps get `hp-pop`
  selection feedback; advancing highlights the next unanswered card with a
  gentle scroll-into-view + fade (no auto-scroll hijacking mid-read).
- **Review:** summary rows stagger in (≤ 8 items rule).
- **Submit:** pending overlay ("Saving your visit…") with spinner, minimum
  ~400 ms display so it never strobes; on success, Complete screen enters
  with `hp-check-draw` + HP-YYYY-#### reveal. On the auto/tap reset, the
  wholesale `freshState()` replacement stays untouched (FR-KSK-13) — the
  welcome screen's enter transition IS the reset animation; add nothing
  stateful.
- **Errors:** failed scan / email login / out-of-range manual entry →
  `hp-shake` on the relevant field + existing error text fades in. Scan
  `sending` status pulses the scan affordance.
- **Credential keyboard partial:** keys get `active:scale-95` at
  `--hp-dur-fast`; the keyboard itself slides up `hp-sheet-up` when shown
  (staff exit prompt too).
- **Performance:** transform/opacity only, no animated blurs/shadows, at
  most two elements animating simultaneously, and verify with DevTools CPU
  4–6× throttle at 1080×1920 portrait; remember `--k-zoom: 2` scales
  everything, so travel distances are effectively doubled on the panel —
  tune the kiosk's translate distances down (e.g. 8 px, not 16). Test on the
  Pi before calling it done if the hardware is available.

## 8. Future screens (`when built` rows)

Spec now in the inventory so later prompts inherit them: ADM batch workflow
(row status transitions, submit pending, approval banners), DIR-A approvals
(approve/decline collapses + date-picker feedback), ANL dashboard —
Chart.js: set ONCE globally `Chart.defaults.animation = { duration: 600,
easing: 'easeOutQuart' }`, chart-area skeletons while data loads, stat-tile
`countUp` on first paint. Do not build any of these screens now.

## 9. Acceptance checklist (verify each; the inventory doc is the sign-off sheet)

1. `npm run build` clean; `php artisan test` fully green (including the new
   ghost-row feature test); `npm run test:js` still passes.
2. At-rest visuals unchanged (spot-check screenshots vs. prototypes).
3. Flagship 1: encode → redirect → banner in, ghost row collapses, rows
   close ranks, poll neither duplicates nor double-removes; queue-empties
   case shows the empty state gracefully.
4. Flagship 2: DevTools "Slow 3G + 4× CPU": login → dashboard shows splash
   (never a white page), no layout shift when it lifts, progress bar runs on
   navigations, booking slots show skeletons. Fast connection: splash never
   visibly flashes.
5. OS "reduce motion" on: nothing moves, everything still works.
6. Kiosk full happy path + error paths animate at 1080×1920 without jank
   under CPU throttle; no outcome-suggesting motion anywhere; reset to
   Welcome leaves zero residue (FR-KSK-13).
7. Print preview + reprint output byte-identical in behavior — no motion,
   no pending-button interference with iframe posts.
8. Reduced-motion, splash, progress bar, and pending states all live in
   shared single sources (no per-page copies).
9. Every animation uses the `hp-` tokens — `grep` for stray hardcoded
   durations/beziers in views.

## 10. Workflow

- **Branch:** per the repo rule, first verify `main` already contains the
  previously finished branch (`git log --oneline main`); surface it if not.
  Then branch `feature/ui-motion-pass` off up-to-date `main` — unless Nat
  says to stack it elsewhere.
- **Commits:** one per phase minimum, conventional format (`feat(ui): …`),
  e.g. `feat(ui): motion tokens, reduced-motion + branded splash (Phase 1)`.
  Ask Nat for the `(day N)` tag — do not guess it.
- **Order:** Phase 0 → 1 → 6.1 flagship → rest of Phase 2 → Phase 3. Commit
  the inventory doc with Phase 1.
- **Stop-and-ask triggers:** any need for a new package; any layout change
  beyond motion; any PRD/CLAUDE.md conflict; anything unclear about how the
  live-queue poller reconciles rows after you read it.
