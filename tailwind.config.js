import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    // Touch-kiosk friendly: only apply `hover:` styles on devices that actually
    // support hovering (a mouse). On the Pi's touchscreen, tapping a button no
    // longer leaves its hover colour "stuck" — press feedback comes from
    // `active:` instead, which fires only while the finger is down.
    future: {
        hoverOnlyWhenSupported: true,
    },

    theme: {
        extend: {
            fontFamily: {
                sans: ['Poppins', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                'hp-white':  '#FFFFFF',
                'hp-bg':     '#F6F2ED',
                'hp-peach':  '#FFCAA0',
                'hp-orange': '#FF8C2A',
                'hp-slate':  '#4B5563',
            },
        },
    },

    plugins: [forms],
};
