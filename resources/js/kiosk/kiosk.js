import '../bootstrap';

import Alpine from 'alpinejs';
import { kioskMachine } from './state-machine';

window.Alpine = Alpine;

// Register the single kiosk component before Alpine.start() so it is available
// when Alpine walks the DOM on load.
Alpine.data('kiosk', kioskMachine);

Alpine.start();
