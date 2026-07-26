# HealthPass — Mobile QA Checklist

Manual checks for the **web app on a phone**. The kiosk is out of scope here
— it is a fixed 1080×1920 portrait panel (D-26), not a phone.

> **How to test.** Open Chrome DevTools (F12) → click the **Toggle device
> toolbar** icon (Ctrl+Shift+M) → pick **Responsive** and type the width and
> height by hand. Test at **360 × 800** (the narrowest Android phone we
> support) and **390 × 844** (iPhone 14/15).
>
> Server: `php artisan serve --port=8080` + `npm run dev`, then browse to
> `http://127.0.0.1:8080` — never `localhost`.

---

## The three rules every page must pass

| # | Rule | How to check |
|---|------|--------------|
| M-1 | **No sideways scrolling.** The page must never slide left/right. | Swipe/drag the page sideways. Nothing should move. In DevTools console, `document.documentElement.scrollWidth` must equal `document.documentElement.clientWidth`. |
| M-2 | **Nothing overflows its box.** Text stays inside its badge, card, or button. | Look at the status pills. The text must sit inside the rounded pill, never spill past its edge. |
| M-3 | **Log out is always reachable.** | Tap the burger (☰) to open the menu. The **Log out** icon must already be on screen — you must not have to scroll inside the menu to reach it. |

---

## College Admin — Dashboard (FR-ADM-01)

`http://127.0.0.1:8080/admin/dashboard` — sign in as a college admin
(e.g. `admin.ccs@healthpass.test`).

- [ ] **360 × 800** — no sideways scroll (M-1)
- [ ] **390 × 844** — no sideways scroll (M-1)
- [ ] The four stat cards stack in one column, full width
- [ ] The **Batch Requests** table is **not** a wide table: each request is
      its own bordered card, with the column name on the left
      (REFERENCE NO., PURPOSE, SERVICE, STUDENTS, SCHEDULED DATE, STATUS,
      SUBMITTED) and its value on the right
- [ ] Status pill text stays inside the pill (M-2)
- [ ] Burger menu → **Log out** visible without scrolling the menu (M-3)
- [ ] The page title "College Admin Dashboard" in the top bar shortens with
      an ellipsis rather than pushing the bar off-screen

## College Admin — Batch Tracking (FR-ADM-05)

`http://127.0.0.1:8080/admin/batches`

- [ ] **360 × 800** — no sideways scroll (M-1)
- [ ] **390 × 844** — no sideways scroll (M-1)
- [ ] Each batch is a stacked card: BATCH ID, REASON, STUDENTS, SUBMITTED,
      STATUS
- [ ] The longest status label, **"Pending Director Approval"**, stays inside
      its pill — it may wrap onto two lines, but no letter may sit outside
      the rounded edge (M-2)
- [ ] **New Batch Request** button is fully visible and tappable
- [ ] Burger menu → **Log out** visible without scrolling the menu (M-3)

## Desktop must not regress

At **1440 × 900**, both pages must still show a normal table with a visible
header row (Batch ID, Reason, …), and the collapsible sidebar rail must still
collapse and expand.

- [ ] Dashboard renders as a table, not as cards
- [ ] Batch Tracking renders as a table, not as cards
- [ ] Sidebar rail still collapses to the icon strip and remembers the choice

---

## Defects closed by this pass (2026-07-26)

Reported by QA against the College Admin pages; fixed as a layout defect
against the existing FR-ADM-01 / FR-ADM-05 (no requirement change).

| Defect | Cause | Fix |
|--------|-------|-----|
| Dashboard and Batch Tracking scrolled sideways on a phone | The tables sat in an `overflow-x-auto` wrapper, and the main column was a flex item with the default `min-width: auto`, so a wide table stretched the whole shell past the viewport | New `<x-hp.table>` component re-flows rows into stacked cards below `md`; `min-w-0` added to the main column so it can never be stretched wider than the screen |
| Status badge text overflowed its pill | The pill had no maximum width and used `leading-none`, so a long label such as "Pending Director Approval" had nowhere to go | `max-w-full` + `break-words` + `leading-tight` on `<x-hp.badge>` — long labels wrap and the pill grows around them |
| Log out unreachable without scrolling the open menu | The drawer was sized to `100vh` / `100%`, which on a phone is the **large** viewport (the height with the browser URL bar hidden), so its pinned footer sat below the visible area | The shell and the drawer are now sized with `100dvh` (the *dynamic* viewport), so the footer lands on the last visible pixel row |

**Verified on 2026-07-26** at 360 × 800 and 390 × 844 (Chromium, device
emulation), plus a 1440 × 900 desktop regression pass:

- `document.scrollWidth === clientWidth` on both pages at both widths — zero
  horizontal scroll
- "Pending Director Approval" pill measured 167 px wide inside a 360 px
  viewport, no text overflow
- Drawer height matched the viewport exactly (800 px / 844 px), the nav did
  not scroll, and the Log out button sat fully on screen
- At 1440 px both tables still render as real tables with the header row
  visible and the mobile labels hidden
