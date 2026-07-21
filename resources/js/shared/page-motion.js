/**
 * Web-app page motion (imported by app.js ONLY — never by the kiosk bundle;
 * the kiosk never navigates, so none of this applies there).
 *
 * Three behaviours, all opt-out rather than per-page copies:
 *
 *  1. Top progress bar (§5.3) — a 3 px orange bar that starts running when a
 *     same-origin navigation begins (link click or form submit). CSS gives the
 *     scaleX transition a long tail toward 85%; the next page's own load
 *     finishes the story. Opt out with `data-no-progress` on the link/form.
 *
 *  2. Pending submit buttons (§5.6) — a button with `data-pending-label`
 *     disables itself and swaps in a spinner + label while its form navigates.
 *     This doubles as double-submit protection: the disabled button cannot be
 *     clicked again while the request is in flight. Forms that target an
 *     iframe (print preview / reprint) never navigate, so they are skipped
 *     automatically via their `target` attribute.
 *
 *  3. Flash banners (§5.7) — `[data-hp-flash]` banners enter with a fade-up
 *     (CSS); informational ones auto-dismiss after ~5 s. Errors opt out with
 *     `data-flash-sticky` and stay until the user leaves the page.
 */

import { countUp } from './motion.js';

const FLASH_DISMISS_MS = 5000;
const FLASH_FADE_MS = 400; // mirrors --hp-dur-slow

/**
 * True when a form submit will actually navigate THIS window. The submitter
 * button's `formtarget` overrides the form's `target` (the nurse encode form
 * posts its print buttons into a hidden iframe this way while Save & Close
 * navigates normally — only the latter should run progress/pending motion).
 */
function submitNavigates(event) {
    if (event.defaultPrevented) return false; // JS-handled (fetch) — no navigation
    const target = event.submitter?.getAttribute('formtarget') || event.target.target;
    return !target || target === '_self';
}

/** True when a link click will actually navigate this window. */
function isNavigatingLink(link, event) {
    if (!link || link.hasAttribute('data-no-progress')) return false;
    if (link.target && link.target !== '_self') return false;
    if (link.hasAttribute('download')) return false;
    // Modified clicks open new tabs/windows — this page stays put.
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false;
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin) return false;
    // Same-page hash jump — no navigation.
    if (url.pathname === window.location.pathname && url.hash) return false;
    return true;
}

function initProgressBar() {
    const bar = document.createElement('div');
    bar.className = 'hp-progress';
    bar.setAttribute('aria-hidden', 'true');
    document.body.appendChild(bar);

    // Double rAF so the browser paints scaleX(0) before the transition starts.
    const start = () => {
        requestAnimationFrame(() => requestAnimationFrame(() => bar.classList.add('is-running')));
    };

    document.addEventListener('click', (event) => {
        if (isNavigatingLink(event.target.closest('a[href]'), event)) start();
    });

    document.addEventListener('submit', (event) => {
        if (!submitNavigates(event)) return;
        if (event.target.hasAttribute('data-no-progress')) return;
        start();
    });

    // Coming back via the back/forward cache re-shows the old page as-is —
    // reset the bar so it isn't stuck mid-run.
    window.addEventListener('pageshow', () => bar.classList.remove('is-running'));
}

function initPendingButtons() {
    document.addEventListener('submit', (event) => {
        if (!submitNavigates(event)) return; // iframe posts never navigate
        const form = event.target;
        // Prefer the button that actually fired the submit; fall back to the
        // form's (single) pending button for Enter-key submits.
        const button = event.submitter?.dataset.pendingLabel
            ? event.submitter
            : form.querySelector('button[data-pending-label]');
        if (!button || button.disabled) return;

        // Freeze the width first so the label swap never changes the layout.
        button.style.width = `${button.offsetWidth}px`;

        // Disable on the NEXT tick: disabling inside the submit event can drop
        // the button's own name/value from the submitted form data.
        setTimeout(() => {
            button.disabled = true;
            button.innerHTML =
                '<svg class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
                + '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity="0.25"/>'
                + '<path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>'
                + '</svg>'
                + `<span>${button.dataset.pendingLabel}</span>`;
        }, 0);
    });
}

function initFlashBanners() {
    document.querySelectorAll('[data-hp-flash]:not([data-flash-sticky])').forEach((banner) => {
        setTimeout(() => {
            banner.classList.add('is-dismissed');
            // After the fade completes, take it out of the layout entirely.
            setTimeout(() => { banner.hidden = true; }, FLASH_FADE_MS);
        }, FLASH_DISMISS_MS);
    });
}

/**
 * Stat tiles marked data-hp-countup count up from 0 to their server-rendered
 * integer on first paint (once per page load — never re-run on polls).
 * countUp() itself skips straight to the value under reduced motion.
 */
function initCountUps() {
    document.querySelectorAll('[data-hp-countup]').forEach((el) => {
        const value = parseInt(el.textContent.replace(/[^\d-]/g, ''), 10);
        if (Number.isNaN(value)) return;
        el.textContent = '0';
        countUp(el, value);
    });
}

export function initPageMotion() {
    initProgressBar();
    initPendingButtons();
    initFlashBanners();
    initCountUps();
}
