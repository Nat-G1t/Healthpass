import { Html5QrcodeScanner, Html5Qrcode, Html5QrcodeScanType } from 'html5-qrcode';

/**
 * Alpine.data component for Step 4 — Link ID (FR-REG-06).
 *
 * Registered in app.js before Alpine.start() so it is available when Alpine
 * walks the DOM on page load.
 *
 * @param {Alpine} Alpine - the single Alpine instance from app.js
 */
export function registerQrLinkId(Alpine) {
    Alpine.data('qrLinkId', (studentNumberDigits) => ({
        // Possible modes: idle | scanning | matched | error
        mode: 'idle',
        matchedId: '',
        errorMsg: '',
        devInput: '',
        _scanner: null,

        startCamera() {
            this.mode = 'scanning';
            this.errorMsg = '';
            // $nextTick waits for Alpine to remove display:none from #qr-reader
            // before html5-qrcode tries to measure its dimensions.
            this.$nextTick(() => {
                this._scanner = new Html5QrcodeScanner(
                    'qr-reader',
                    {
                        fps: 10,
                        qrbox: { width: 250, height: 250 },
                        rememberLastUsedCamera: true,
                        supportedScanTypes: [Html5QrcodeScanType.SCAN_TYPE_CAMERA],
                        // Prefer rear camera on mobile devices
                        videoConstraints: { facingMode: { ideal: 'environment' } },
                    },
                    /* verbose */ false
                );
                this._scanner.render(
                    (decodedText) => this._handleDecode(decodedText),
                    () => {} // per-frame failures are normal — ignore them
                );
            });
        },

        stopCamera() {
            if (this._scanner) {
                this._scanner.clear().catch(() => {});
                this._scanner = null;
            }
            this.mode = 'idle';
        },

        async scanFile(event) {
            const file = event.target.files[0];
            if (!file) return;
            // Use the hidden #qr-file-reader div as the element anchor.
            // showImage: false — no need to display the image in that div.
            const reader = new Html5Qrcode('qr-file-reader');
            try {
                const decodedText = await reader.scanFile(file, false);
                this._handleDecode(decodedText);
            } catch {
                this.errorMsg = 'No QR code found in this image — make sure the ID is well-lit and fully in frame.';
                this.mode = 'error';
            }
        },

        // Dev-only shortcut: user types the IDNo directly into a text field.
        submitDev() {
            const raw = this.devInput.trim();
            if (!raw) return;
            // Simulate the QR payload format so _handleDecode can extract it.
            this._handleDecode('IDNo: ' + raw);
        },

        reset() {
            this.mode = 'idle';
            this.errorMsg = '';
            this.matchedId = '';
            this.devInput = '';
        },

        _handleDecode(decodedText) {
            // Stop scanner if still running (camera path calls this from inside render callback)
            if (this._scanner) {
                this._scanner.clear().catch(() => {});
                this._scanner = null;
            }

            // Extract "IDNo: XXXXXXXXXX" from multi-line QR payload
            const lineMatch = decodedText.match(/IDNo\s*:\s*([^\n\r]+)/i);
            if (!lineMatch) {
                this.errorMsg = 'QR code does not contain an IDNo field. Is this a HealthPass student ID?';
                this.mode = 'error';
                return;
            }

            const rawId = lineMatch[1].trim();
            const extractedDigits = rawId.replace(/\D/g, '');

            // studentNumberDigits is digits-only, passed from PHP via x-data="qrLinkId('...')"
            if (extractedDigits !== String(studentNumberDigits)) {
                this.errorMsg = 'ID mismatch — the scanned ID does not match your registered student number. Please use your own HealthPass ID.';
                this.mode = 'error';
                return;
            }

            this.matchedId = rawId;
            this.mode = 'matched';
        },
    }));
}
