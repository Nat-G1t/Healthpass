import '../bootstrap';

import Alpine from 'alpinejs';
import { kioskMachine } from './state-machine';
import { parseReadingLine } from './serial';

window.Alpine = Alpine;

// Dev-only: expose the pure §11.2 parser on window so you can verify the serial
// contract from the browser console with NO hardware — e.g.
//   parseReadingLine('H:163;W:64;T:37.9;BP:145/92;HR:78')
// import.meta.env.DEV is true under `npm run dev` and stripped from prod builds,
// so this never ships. (The old `import('/resources/…')` trick 404s because
// Laravel serves the page but Vite serves the JS from a different origin.)
if (import.meta.env.DEV) {
    window.parseReadingLine = parseReadingLine;
}

// Register the single kiosk component before Alpine.start() so it is available
// when Alpine walks the DOM on load.
Alpine.data('kiosk', kioskMachine);

Alpine.start();
