import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/kiosk/kiosk.js',
                'resources/js/nurse/live-queue.js',
                'resources/js/director/analytics.js',
            ],
            refresh: true,
        }),
    ],
});
