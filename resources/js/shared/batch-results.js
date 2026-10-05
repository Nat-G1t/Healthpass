/**
 * Alpine.data component behind a batch results page's main card
 * (resources/views/batches/results/_table.blade.php) — shared by the College
 * Admin (D-111), the Director (D-112) and the clinic (D-114).
 *
 * It holds the whole roster (OUTCOME ONLY — name, student no., status, Fit /
 * Unfit, and an encoded student's visit id) and filters it in the browser:
 * Result, Completion status and a name / student ID search.
 *
 * D-113 adds the document selection:
 *   - "Select students" switches select mode on. Only ENCODED students have a
 *     document, so select mode hides everyone else; the filters keep working.
 *   - "Select all" ticks every student currently shown.
 *   - "Clear" (shown once something is ticked) unticks everyone.
 *   - "Download documents (N)" posts the ticked visit ids (a normal form POST,
 *     so the browser downloads the ZIP).
 * `enabled` is false for a College Admin until the Director releases the
 * batch: the tools are drawn, greyed out, and do nothing.
 *
 * The browser only picks. The SERVER decides which visits may be downloaded
 * (BatchDocumentZip) and enforces the per-download cap again.
 *
 * Registered in app.js before Alpine.start() (same pattern as file-capture.js).
 *
 * @param {Alpine} Alpine - the single Alpine instance from app.js
 */

/** How long "Preparing…" shows before the button can be pressed again. */
const PREPARING_MS = 8000;

export function registerBatchResults(Alpine) {
    Alpine.data('batchResults', (config) => ({
        rows: config.rows,                       // [{name, number, status, result, visit_id}]
        enabled: config.enabled ?? false,        // may this viewer select and download?
        maxPerDownload: config.maxPerDownload ?? 0,

        result: '',                              // '' | 'Fit' | 'Unfit'
        progress: '',                            // '' | a status key
        search: '',

        selecting: false,                        // select mode on?
        selected: [],                            // ticked visit ids
        preparing: false,                        // a download was just sent

        // D-114 (clinic only): whose FRONT page was printed last, so the card
        // can say "put the sheet back in the tray, then click Print back".
        // Print back (or any other print) clears it.
        flipHintFor: null,

        /** The rows the table shows: the filters, and — in select mode — encoded students only. */
        get filtered() {
            const term = this.search.trim().toLowerCase();

            return this.rows.filter((row) =>
                (! this.selecting || row.visit_id !== null)
                && (this.result === '' || row.result === this.result)
                && (this.progress === '' || row.status === this.progress)
                && (term === '' || (row.name + ' ' + row.number).toLowerCase().includes(term)));
        },

        get overCap() {
            return this.selected.length > this.maxPerDownload;
        },

        get canDownload() {
            return this.enabled && this.selected.length > 0 && ! this.overCap && ! this.preparing;
        },

        isSelected(visitId) {
            return this.selected.includes(visitId);
        },

        toggle(visitId) {
            this.selected = this.isSelected(visitId)
                ? this.selected.filter((id) => id !== visitId)
                : [...this.selected, visitId];
        },

        /** "Select students": select mode on / off. Leaving it unticks everyone. */
        toggleSelecting() {
            if (! this.enabled) return;

            this.selecting = ! this.selecting;
            if (! this.selecting) this.selected = [];
        },

        /** "Select all": select mode on, and every student shown right now ticked. */
        selectAll() {
            if (! this.enabled) return;

            this.selecting = true;
            const shown = this.filtered.map((row) => row.visit_id);
            this.selected = [...new Set([...this.selected, ...shown])];
        },

        clear() {
            this.selected = [];
        },

        /**
         * The download form is submitting. The page doesn't navigate (the
         * response is a file), so there is no "done" signal: show Preparing…
         * for a while, then let the button be used again.
         */
        startDownload() {
            this.preparing = true;
            setTimeout(() => { this.preparing = false; }, PREPARING_MS);
        },
    }));
}
