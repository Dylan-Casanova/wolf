import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.tsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                'wolf-void': '#050510',
                'wolf-glass': 'rgba(15, 20, 40, 0.55)',
                'wolf-glass-border': 'rgba(255, 255, 255, 0.08)',
                'wolf-card': 'rgba(255, 255, 255, 0.03)',
                'wolf-card-border': 'rgba(255, 255, 255, 0.06)',
                'wolf-active': 'rgba(99, 102, 241, 0.18)',
                'wolf-active-border': 'rgba(99, 102, 241, 0.4)',
            },
            borderRadius: {
                'wolf-panel': '32px',
                'wolf-rail': '22px',
                'wolf-card': '18px',
                'wolf-pill': '14px',
            },
            backdropBlur: {
                'wolf-panel': '28px',
                'wolf-rail': '20px',
            },
        },
    },

    plugins: [forms],
};
