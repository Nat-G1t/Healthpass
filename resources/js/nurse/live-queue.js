/**
 * Nurse Live Queue poller (FR-NRS-02, SM-2).
 *
 * Every 4 s we fetch the lean JSON feed and reconcile the table IN PLACE —
 * new arrivals append at the bottom, encoded visits drop out, and the
 * longest-waiting row keeps the NEXT tag — with no full-page reload and no
 * flicker (existing <tr> nodes are moved, never re-created). A 1 s ticker
 * keeps the "{n} students waiting · updated Xs ago" subtitle honest.
 *
 * Rows are keyed by visit id. buildRow() mirrors the server-rendered markup in
 * resources/views/components/nurse/queue-row.blade.php — keep the two in step.
 *
 * Motion (docs/ui-motion-inventory.md, Nurse table): every queue change is
 * animated — arrivals fade up with a peach highlight, departures collapse
 * (fade + rows sliding up, FLIP), NEXT promotion pops its tag, and the count
 * counts up/down. The flagship "reverse-stack": returning from Save & Close,
 * the server renders the just-encoded visit once as a `data-leaving` ghost
 * row; after a short hold we collapse it so the nurse SEES the queue advance.
 * Rows mid-collapse keep `data-leaving`, and the reconciler ignores them
 * completely — the poll can never double-remove or resurrect one.
 */

import { collapseRow, countUp, prefersReducedMotion } from '../shared/motion.js';

const POLL_MS = 4000;

// How long the nurse's eye gets to land on the ghost row before it collapses.
const GHOST_HOLD_MS = 600;

// Button / badge class strings are copied verbatim from the x-hp.button and
// x-hp.badge Blade components so appended rows match the server-rendered ones.
// (Tailwind already emits these classes because the Blade sources use them.)
const BTN_BASE =
    'inline-flex items-center justify-center gap-2 rounded-full font-semibold ' +
    'transition-[color,background-color,border-color,transform] duration-hp-fast ease-hp-out ' +
    'active:scale-[0.97] focus-visible:outline-none focus-visible:ring-2 ' +
    'focus-visible:ring-offset-1 px-4 py-1.5 text-xs';
const BTN_PRIMARY = 'bg-hp-orange text-white hover:bg-orange-500 focus-visible:ring-hp-orange';
const BTN_GHOST =
    'bg-transparent text-hp-slate border-[1.5px] border-hp-slate/30 hover:bg-hp-slate/8 focus-visible:ring-hp-slate';
const BADGE = 'inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold leading-none bg-hp-peach text-hp-orange';

const FLAGGED = 'font-bold text-hp-orange';
const NORMAL = 'text-hp-slate/70';

let currentCount = 0;
let lastUpdatedAt = Date.now();

/** HTML-escape untrusted text (student names, college names) before injection. */
function esc(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

/** Inline vitals summary — flagged values bold orange (mirrors the Blade). */
function vitalsHtml(vitals) {
    if (!vitals) {
        return '<span class="text-hp-slate/30">—</span>';
    }
    const dot = '<span class="text-hp-slate/20">·</span>';
    return (
        '<div class="flex items-center gap-x-3">' +
        `<span class="${vitals.is_temp_flagged ? FLAGGED : NORMAL}">${esc(vitals.temperature_c)}°C</span>${dot}` +
        `<span class="${vitals.is_bp_flagged ? FLAGGED : NORMAL}">${esc(vitals.bp_systolic)}/${esc(vitals.bp_diastolic)}</span>${dot}` +
        `<span class="${vitals.is_bmi_flagged ? FLAGGED : NORMAL}">BMI ${esc(vitals.bmi)}</span>${dot}` +
        `<span class="${NORMAL}">${esc(vitals.heart_rate_bpm)} bpm</span>` +
        '</div>'
    );
}

/** Flag badges, or a dash when nothing is flagged (mirrors the Blade). */
function flagsHtml(vitals) {
    if (!vitals || (!vitals.is_temp_flagged && !vitals.is_bp_flagged && !vitals.is_bmi_flagged)) {
        return '<span class="text-hp-slate/30">—</span>';
    }
    const badges = [];
    if (vitals.is_temp_flagged) badges.push(`<span class="${BADGE}">Temp</span>`);
    if (vitals.is_bp_flagged) badges.push(`<span class="${BADGE}">BP</span>`);
    if (vitals.is_bmi_flagged) badges.push(`<span class="${BADGE}">BMI</span>`);
    return `<div class="flex flex-wrap gap-1.5">${badges.join('')}</div>`;
}

/** Build a fresh <tr> for a newly-arrived visit. */
function buildRow(visit) {
    const row = document.createElement('tr');
    row.dataset.visitId = String(visit.id);
    row.innerHTML =
        // Student
        '<td class="py-4 pl-4 pr-6 whitespace-nowrap"><div class="flex items-center gap-3">' +
        '<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-hp-peach text-xs font-bold text-hp-orange">' +
        `${esc(visit.initials)}</div><div class="min-w-0">` +
        `<p class="text-sm font-semibold text-hp-slate">${esc(visit.name)}</p>` +
        '<span class="queue-next-tag mt-0.5 inline-flex items-center rounded-full bg-hp-orange px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest text-white">Next</span>' +
        `<p class="queue-ref font-mono text-[11px] text-hp-slate/35">${esc(visit.reference_no)}</p>` +
        '</div></div></td>' +
        // College
        `<td class="py-4 pr-6 text-sm text-hp-slate/60 whitespace-nowrap">${esc(visit.college)}</td>` +
        // Vitals summary
        `<td class="py-4 pr-6 text-sm whitespace-nowrap">${vitalsHtml(visit.vitals)}</td>` +
        // Flags
        `<td class="py-4 pr-6 whitespace-nowrap">${flagsHtml(visit.vitals)}</td>` +
        // Time
        `<td class="py-4 pr-6 text-sm text-hp-slate/50 whitespace-nowrap" data-cell="time">${esc(visit.time_human ?? '—')}</td>` +
        // Action — both links, CSS shows the right one per data-next.
        // encode_url comes from the feed (QueueController::toRow), so the JS
        // never has to guess the route shape.
        '<td class="py-4 pr-4 text-right whitespace-nowrap">' +
        `<span class="queue-btn-next"><a href="${esc(visit.encode_url)}" class="${BTN_BASE} ${BTN_PRIMARY}">Encode Result</a></span>` +
        `<span class="queue-btn-rest"><a href="${esc(visit.encode_url)}" class="${BTN_BASE} ${BTN_GHOST}">Encode Result</a></span>` +
        '</td>';
    return row;
}

/** Set/clear the single attribute that drives the whole NEXT-row look. */
function setNext(row, isNext) {
    const wasNext = row.hasAttribute('data-next');
    if (isNext) {
        row.setAttribute('data-next', '');
        // Promotion moment: the td background-color transition (queue.blade
        // CSS) fades the peach band in; one subtle pop on the Next tag marks
        // the hand-off. Remove-reflow-add restarts the one-shot animation.
        if (!wasNext) {
            const tag = row.querySelector('.queue-next-tag');
            if (tag) {
                tag.classList.remove('hp-anim-pop');
                void tag.offsetWidth; // force reflow so the animation restarts
                tag.classList.add('hp-anim-pop');
            }
        }
    } else {
        row.removeAttribute('data-next');
    }
}

/** Rewrite just the humanized time cell (the only per-poll change to an existing row). */
function setTime(row, timeHuman) {
    const cell = row.querySelector('[data-cell="time"]');
    if (cell) cell.textContent = timeHuman ?? '—';
}

/**
 * Show/hide the empty state vs. the table. Rows still animating out
 * (`data-leaving`) count as present, so the table never vanishes mid-collapse;
 * when the queue truly empties, the empty state fades in.
 */
function syncEmptyState() {
    const body = document.querySelector('[data-queue-body]');
    if (!body) return;
    const hasRows = currentCount > 0 || body.querySelector('tr[data-leaving]') !== null;

    const empty = document.querySelector('[data-queue-empty]');
    if (empty && !hasRows && empty.classList.contains('hidden')) {
        // Becoming visible now — fade it up (remove/reflow/add restarts it).
        empty.classList.remove('hp-anim-fade-up');
        void empty.offsetWidth;
        empty.classList.add('hp-anim-fade-up');
    }
    empty?.classList.toggle('hidden', hasRows);
    document.querySelector('[data-queue-table]')?.classList.toggle('hidden', !hasRows);
}

/** Reconcile the table body with the feed, keying rows by visit id. */
function render(data) {
    const body = document.querySelector('[data-queue-body]');
    if (!body) return;

    const visits = data.visits ?? [];
    const incomingIds = new Set(visits.map((v) => String(v.id)));

    // Rows that have left the queue (encoded / gone) collapse out: fade, then
    // the rows below slide up into the gap. Marking them data-leaving FIRST
    // takes them out of this reconciler's world — a later poll tick skips
    // them, so a row can never be double-removed mid-animation.
    body.querySelectorAll('tr[data-visit-id]').forEach((row) => {
        if (row.hasAttribute('data-leaving')) return; // already on its way out
        if (!incomingIds.has(row.dataset.visitId)) {
            row.setAttribute('data-leaving', '');
            collapseRow(row, { onDone: syncEmptyState });
        }
    });

    // Upsert in server (FCFS) order, moving a row ONLY when it is out of
    // place. `cursor` walks the desired order; data-leaving rows are ignored
    // (they keep their visual slot until their collapse finishes) — this is
    // why a wholesale appendChild sweep is no longer used: it would shuffle
    // rows past a mid-collapse ghost.
    let cursor = null;
    visits.forEach((visit, index) => {
        let row = body.querySelector(`tr[data-visit-id="${visit.id}"]:not([data-leaving])`);
        const isNew = row === null;
        if (isNew) row = buildRow(visit);

        let expected = cursor ? cursor.nextElementSibling : body.firstElementChild;
        while (expected && expected.hasAttribute('data-leaving')) {
            expected = expected.nextElementSibling;
        }
        if (expected !== row) body.insertBefore(row, expected);
        cursor = row;

        setNext(row, index === 0);
        setTime(row, visit.time_human);

        // New arrival (§6.1a): fade-up + peach highlight fading over ~1.5 s.
        if (isNew && !prefersReducedMotion()) row.classList.add('queue-row-new');
    });

    const newCount = data.count ?? visits.length;
    if (newCount !== currentCount) {
        currentCount = newCount;
        const countEl = document.querySelector('[data-queue-count]');
        if (countEl) countUp(countEl, newCount);
    }
    syncEmptyState();

    lastUpdatedAt = Date.now();
    updateSubtitle();
}

/**
 * "{n} students waiting · updated Xs ago" — refreshed every second. The count
 * lives in its own span (animated by countUp in render); this ticker only
 * rewrites the text AFTER the number, so the two never fight.
 */
function updateSubtitle() {
    const rest = document.querySelector('[data-queue-subtitle-rest]');
    if (!rest) return;
    const seconds = Math.floor((Date.now() - lastUpdatedAt) / 1000);
    const ago = seconds < 1 ? 'just now' : `${seconds}s ago`;
    const noun = currentCount === 1 ? 'student' : 'students';
    rest.textContent = `${noun} waiting · updated ${ago}`;
}

async function poll(feedUrl) {
    try {
        const response = await fetch(feedUrl, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error(`Queue feed HTTP ${response.status}`);
        render(await response.json());
    } catch (error) {
        // Leave the last good table in place; the next tick retries.
        console.warn('Live Queue poll failed:', error);
    }
}

function init() {
    const root = document.querySelector('[data-live-queue]');
    if (!root) return;

    const feedUrl = root.dataset.feedUrl;
    // The ghost row is scenery, not a waiting student — exclude it from the count.
    currentCount = document.querySelectorAll(
        '[data-queue-body] tr[data-visit-id]:not([data-leaving])',
    ).length;

    // FLAGSHIP reverse-stack (§6.1b): the server rendered the just-encoded
    // visit as a data-leaving ghost. Hold ~600 ms so the nurse's eye lands on
    // "Encoded ✓", then collapse it — the rows below visibly close ranks.
    // Under reduced motion collapseRow removes it instantly, so skip the hold
    // too. Done well before the first 4 s poll tick, and the reconciler
    // ignores data-leaving rows anyway, so the poll can neither double-remove
    // nor resurrect it.
    const ghost = document.querySelector('[data-queue-body] tr[data-leaving]');
    if (ghost) {
        setTimeout(
            () => collapseRow(ghost, { onDone: syncEmptyState }),
            prefersReducedMotion() ? 0 : GHOST_HOLD_MS,
        );
    }

    setInterval(() => poll(feedUrl), POLL_MS);
    setInterval(updateSubtitle, 1000);
}

document.addEventListener('DOMContentLoaded', init);
