import './bootstrap';

import Alpine from 'alpinejs';
import { registerQrLinkId } from './qr-link-id';
import { initPageMotion } from './shared/page-motion';

window.Alpine = Alpine;

// Site-wide motion behaviours: top progress bar, pending submit buttons,
// flash-banner auto-dismiss (docs/ui-motion-inventory.md, Global table).
document.addEventListener('DOMContentLoaded', initPageMotion);

// Register page-specific Alpine.data components before Alpine.start()
// so they are available when Alpine walks the DOM on load.
registerQrLinkId(Alpine);

Alpine.start();
