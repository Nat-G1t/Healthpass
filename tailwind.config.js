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

            // ── Motion tokens — mirrors the --hp-* CSS vars in app.css ───────
            // var() references keep app.css the single source of the values.
            // Usage: duration-hp-fast, ease-hp-out, animate-hp-fade-up, …
            transitionDuration: {
                'hp-fast': 'var(--hp-dur-fast)',
                'hp-base': 'var(--hp-dur-base)',
                'hp-slow': 'var(--hp-dur-slow)',
            },
            transitionTimingFunction: {
                'hp-out':    'var(--hp-ease-out)',
                'hp-spring': 'var(--hp-ease-spring)',
                'hp-in':     'var(--hp-ease-in)',
            },
            keyframes: {
                'hp-fade-up':  { from: { opacity: '0', transform: 'translateY(12px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
                'hp-fade':     { from: { opacity: '0' }, to: { opacity: '1' } },
                'hp-scale-in': { from: { opacity: '0', transform: 'scale(0.96)' }, to: { opacity: '1', transform: 'scale(1)' } },
                'hp-sheet-up': { from: { opacity: '0', transform: 'translateY(24px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
                'hp-pop':      { '0%': { transform: 'scale(1)' }, '50%': { transform: 'scale(1.06)' }, '100%': { transform: 'scale(1)' } },
            },
            animation: {
                'hp-fade-up':  'hp-fade-up var(--hp-dur-base) var(--hp-ease-out) both',
                'hp-fade':     'hp-fade var(--hp-dur-base) var(--hp-ease-out) both',
                'hp-scale-in': 'hp-scale-in var(--hp-dur-base) var(--hp-ease-out) both',
                'hp-sheet-up': 'hp-sheet-up var(--hp-dur-slow) var(--hp-ease-spring) both',
                'hp-pop':      'hp-pop 200ms var(--hp-ease-out)',
            },
        },
    },

    plugins: [forms],
};
