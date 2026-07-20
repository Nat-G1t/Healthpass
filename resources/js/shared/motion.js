/**
 * Shared motion helpers — imported by BOTH Vite entries (app.js and
 * kiosk/kiosk.js). This lives in a leaf module (never in app.js itself) so
 * each bundle tree-shakes only what it uses and the kiosk bundle stays
 * independent, per CLAUDE.md.
 *
 * House rules (docs/ui-motion-inventory.md):
 *  - animate only transform + opacity (compositor-friendly — Pi 4 budget);
 *  - every helper checks prefersReducedMotion() and skips straight to the
 *    end state when the OS asks for less motion;
 *  - durations/easings come from the --hp-* CSS tokens where CSS runs the
 *    animation; the JS fallbacks below mirror the same values.
 */

// Mirrors --hp-dur-base / --hp-ease-out in app.css for JS-driven animations.
const DUR_BASE_MS = 250;
const EASE_OUT = 'cubic-bezier(0.25, 1, 0.5, 1)';

/** Single source of truth for the OS "reduce motion" preference. */
export function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Fade a table row out and slide the rows below it up into the gap, then
 * remove it — the Live Queue "reverse-stack" (§6.1).
 *
 * Uses the FLIP technique (First–Last–Invert–Play): because animating a row's
 * height would re-layout the table every frame, we instead measure where each
 * following row IS (First), let the browser compute where it WILL BE once the
 * gap closes (Last), then translate each row between the two positions with a
 * pure transform (Invert + Play) — compositor-only, no layout thrash.
 *
 * @param {HTMLTableRowElement} tr
 * @param {{ onDone?: () => void }} [options]
 */
export function collapseRow(tr, { onDone } = {}) {
    const finish = () => {
        tr.remove();
        if (onDone) onDone();
    };

    if (prefersReducedMotion() || !tr.isConnected || !tr.animate) {
        finish();
        return;
    }

    // The vertical slot this row occupies = its height + the border-spacing
    // gap the table adds between rows (the queue table is border-separate).
    const gap = parseFloat(getComputedStyle(tr.parentElement).borderSpacing.split(' ').pop() || '0');
    const slot = tr.getBoundingClientRect().height + gap;

    const following = [];
    for (let next = tr.nextElementSibling; next; next = next.nextElementSibling) {
        following.push(next);
    }

    // Fade the leaving row (kept in place so the table doesn't jump yet)…
    const fade = tr.animate([{ opacity: 1 }, { opacity: 0 }], {
        duration: DUR_BASE_MS,
        easing: EASE_OUT,
        fill: 'forwards',
    });

    fade.onfinish = () => {
        // …then slide every following row up by one slot height. We animate
        // FROM 0 TO -slot, remove the row at the end, and the rows land
        // exactly where the closed-up layout puts them — no snap.
        if (following.length === 0) {
            finish();
            return;
        }
        let done = 0;
        following.forEach((row) => {
            const move = row.animate(
                [{ transform: 'translateY(0)' }, { transform: `translateY(-${slot}px)` }],
                { duration: DUR_BASE_MS, easing: EASE_OUT },
            );
            move.onfinish = () => {
                done += 1;
                if (done === following.length) finish();
            };
        });
    };
}

/**
 * Generic FLIP move: snapshot child positions, run `mutate()` (which may add,
 * remove or reorder children), then animate every surviving child from its
 * old position to its new one. Children are keyed by node identity.
 *
 * @param {Element} container
 * @param {() => void} mutate
 */
export function flipMove(container, mutate) {
    if (prefersReducedMotion() || !container.animate) {
        mutate();
        return;
    }

    // First: where is everything now?
    const first = new Map();
    for (const child of container.children) {
        first.set(child, child.getBoundingClientRect());
    }

    // Mutate: let the caller change the DOM (the browser re-lays-out once).
    mutate();

    // Last + Invert + Play: any child that moved gets a transform animation
    // from its old spot to its new one.
    for (const child of container.children) {
        const before = first.get(child);
        if (!before) continue; // newly added — entrance animations handle it
        const after = child.getBoundingClientRect();
        const dx = before.left - after.left;
        const dy = before.top - after.top;
        if (dx === 0 && dy === 0) continue;
        child.animate(
            [{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'translate(0, 0)' }],
            { duration: DUR_BASE_MS, easing: EASE_OUT },
        );
    }
}

/**
 * Animate an integer in place, e.g. the queue count or a stat tile.
 * Under reduced motion (or for a no-op change) it skips straight to the value.
 *
 * @param {Element} el
 * @param {number} to
 * @param {{ duration?: number }} [options]
 */
export function countUp(el, to, { duration = 600 } = {}) {
    const from = parseInt(el.textContent.replace(/[^\d-]/g, ''), 10) || 0;
    if (prefersReducedMotion() || from === to) {
        el.textContent = String(to);
        return;
    }
    const start = performance.now();
    const tick = (now) => {
        const progress = Math.min((now - start) / duration, 1);
        // Decelerating curve (ease-out cubic) so the count "lands" gently.
        const eased = 1 - (1 - progress) ** 3;
        el.textContent = String(Math.round(from + (to - from) * eased));
        if (progress < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
}
