/**
 * Alpine.data component behind <x-hp.file-capture> (D-109): the "Take photo"
 * and "Upload file" buttons, the preview of the chosen file, and a quick
 * type/size check.
 *
 * Two hidden file inputs, one per button: the camera one has
 * `capture="environment"` (camera on phones/tablets, file picker on laptops),
 * the upload one accepts JPG/PNG/PDF. Only ONE file is kept (Q9), so only the
 * input that holds it carries the form field's name — that is the one the form
 * posts. Choosing again replaces the file.
 *
 * The check here only saves a wasted upload. The SERVER still decides: it reads
 * the file's real content (StoreBatchRequestRequest), whatever this says.
 *
 * Registered in app.js before Alpine.start() (same pattern as qr-link-id.js).
 *
 * @param {Alpine} Alpine - the single Alpine instance from app.js
 */
export function registerFileCapture(Alpine) {
    Alpine.data('fileCapture', (config) => ({
        name: config.name,                 // the form field, e.g. 'memo'
        types: config.types,               // allowed MIME types, from config
        maxBytes: config.maxKb * 1024,
        messages: config.messages,         // { type, size } — the server's wording

        file: null,                        // the chosen File, or null
        source: null,                      // 'camera' | 'upload': whose input holds it
        previewUrl: null,                  // object URL of an image, for the thumbnail
        error: '',

        get isImage() {
            return this.file !== null && this.file.type.startsWith('image/');
        },

        /** "340 KB" / "2.4 MB" */
        get sizeLabel() {
            if (this.file === null) return '';
            const bytes = this.file.size;
            return bytes < 1024 * 1024
                ? `${Math.max(1, Math.round(bytes / 1024))} KB`
                : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        },

        /** A file was chosen (or the dialog cancelled) on one of the two inputs. */
        pick(event, source) {
            const chosen = event.target.files[0] ?? null;

            // Cancelling the dialog empties that input in some browsers.
            if (chosen === null) {
                if (this.source === source) this.remove();
                return;
            }

            const problem = this.problemWith(chosen);
            if (problem !== '') {
                this.remove();
                this.error = problem;
                return;
            }

            this.revokePreview();
            this.otherInput(source).value = '';
            this.file = chosen;
            this.source = source;
            this.error = '';
            this.previewUrl = this.isImage ? URL.createObjectURL(chosen) : null;
            this.notify();
        },

        /** The "Remove" link: back to nothing chosen. */
        remove() {
            this.revokePreview();
            this.$refs.camera.value = '';
            this.$refs.upload.value = '';
            this.file = null;
            this.source = null;
            this.error = '';
            this.notify();
        },

        problemWith(file) {
            if (!this.types.includes(file.type)) return this.messages.type;
            if (file.size > this.maxBytes) return this.messages.size;
            return '';
        },

        otherInput(source) {
            return source === 'camera' ? this.$refs.upload : this.$refs.camera;
        },

        revokePreview() {
            if (this.previewUrl !== null) URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = null;
        },

        /** Tells the surrounding form (e.g. the New Batch Submit button) whether a file is attached. */
        notify() {
            this.$dispatch('file-capture-change', { name: this.name, attached: this.file !== null });
        },

        destroy() {
            this.revokePreview();
        },
    }));
}
