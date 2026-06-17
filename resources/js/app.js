import './bootstrap';

import Alpine from 'alpinejs';
import { registerQrLinkId } from './qr-link-id';

window.Alpine = Alpine;

// Register page-specific Alpine.data components before Alpine.start()
// so they are available when Alpine walks the DOM on load.
registerQrLinkId(Alpine);

Alpine.start();
