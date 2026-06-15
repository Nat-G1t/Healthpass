import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

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
