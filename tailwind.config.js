import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                heading: ['"Noto Serif"', ...defaultTheme.fontFamily.serif],
                headline: ['"Noto Serif"', ...defaultTheme.fontFamily.serif],
                body: ['Inter', ...defaultTheme.fontFamily.sans],
                label: ['Inter', ...defaultTheme.fontFamily.sans],
            },

            borderRadius: {
                sm: '0.25rem',
                md: '0.75rem',
                lg: '1rem',
                xl: '1.5rem',
                '2xl': '2rem',
                full: '9999px',
            },

            // Color tokens using RGB CSS variables.
            // This enables Tailwind's opacity modifier syntax: bg-primary/10, text-on-surface/50, etc.
            // Pattern: `rgb(var(--rgb-xxx) / <alpha-value>)` where <alpha-value> defaults to 1.
            colors: {
                primary: {
                    DEFAULT: 'rgb(var(--rgb-primary) / <alpha-value>)',
                    container: 'rgb(var(--rgb-primary-container) / <alpha-value>)',
                    fixed: 'rgb(var(--rgb-primary-fixed) / <alpha-value>)',
                    'fixed-dim': 'rgb(var(--rgb-primary-fixed-dim) / <alpha-value>)',
                },
                'on-primary': {
                    DEFAULT: 'rgb(var(--rgb-on-primary) / <alpha-value>)',
                    'fixed-variant': 'rgb(var(--rgb-on-primary-fixed-variant) / <alpha-value>)',
                    fixed: 'rgb(var(--rgb-on-primary-fixed) / <alpha-value>)',
                    container: 'rgb(var(--rgb-on-primary-container) / <alpha-value>)',
                },

                secondary: {
                    DEFAULT: 'rgb(var(--rgb-secondary) / <alpha-value>)',
                    container: 'rgb(var(--rgb-secondary-container) / <alpha-value>)',
                    fixed: 'rgb(var(--rgb-secondary-fixed) / <alpha-value>)',
                    'fixed-dim': 'rgb(var(--rgb-secondary-fixed-dim) / <alpha-value>)',
                },
                'on-secondary': {
                    DEFAULT: 'rgb(var(--rgb-on-secondary) / <alpha-value>)',
                    'fixed-variant': 'rgb(var(--rgb-on-secondary-fixed-variant) / <alpha-value>)',
                    fixed: 'rgb(var(--rgb-on-secondary-fixed) / <alpha-value>)',
                    container: 'rgb(var(--rgb-on-secondary-container) / <alpha-value>)',
                },

                tertiary: {
                    DEFAULT: 'rgb(var(--rgb-tertiary) / <alpha-value>)',
                    container: 'rgb(var(--rgb-tertiary-container) / <alpha-value>)',
                    fixed: 'rgb(var(--rgb-tertiary-fixed) / <alpha-value>)',
                    'fixed-dim': 'rgb(var(--rgb-tertiary-fixed-dim) / <alpha-value>)',
                },
                'on-tertiary': {
                    DEFAULT: 'rgb(var(--rgb-on-tertiary) / <alpha-value>)',
                    'fixed-variant': 'rgb(var(--rgb-on-tertiary-fixed-variant) / <alpha-value>)',
                    fixed: 'rgb(var(--rgb-on-tertiary-fixed) / <alpha-value>)',
                    container: 'rgb(var(--rgb-on-tertiary-container) / <alpha-value>)',
                },

                surface: {
                    DEFAULT: 'rgb(var(--rgb-surface) / <alpha-value>)',
                    dim: 'rgb(var(--rgb-surface-dim) / <alpha-value>)',
                    bright: 'rgb(var(--rgb-surface-bright) / <alpha-value>)',
                    tint: 'rgb(var(--rgb-surface-tint) / <alpha-value>)',
                    variant: 'rgb(var(--rgb-surface-variant) / <alpha-value>)',
                    container: {
                        DEFAULT: 'rgb(var(--rgb-surface-container) / <alpha-value>)',
                        lowest: 'rgb(var(--rgb-surface-container-lowest) / <alpha-value>)',
                        low: 'rgb(var(--rgb-surface-container-low) / <alpha-value>)',
                        high: 'rgb(var(--rgb-surface-container-high) / <alpha-value>)',
                        highest: 'rgb(var(--rgb-surface-container-highest) / <alpha-value>)',
                    },
                },
                'on-surface': {
                    DEFAULT: 'rgb(var(--rgb-on-surface) / <alpha-value>)',
                    variant: 'rgb(var(--rgb-on-surface-variant) / <alpha-value>)',
                },

                outline: {
                    DEFAULT: 'rgb(var(--rgb-outline) / <alpha-value>)',
                    variant: 'rgb(var(--rgb-outline-variant) / <alpha-value>)',
                },

                error: {
                    DEFAULT: 'rgb(var(--rgb-error) / <alpha-value>)',
                    container: 'rgb(var(--rgb-error-container) / <alpha-value>)',
                },
                'on-error': {
                    DEFAULT: 'rgb(var(--rgb-on-error) / <alpha-value>)',
                    container: 'rgb(var(--rgb-on-error-container) / <alpha-value>)',
                },

                'inverse-surface': 'rgb(var(--rgb-inverse-surface) / <alpha-value>)',
                'inverse-on-surface': 'rgb(var(--rgb-inverse-on-surface) / <alpha-value>)',
                'inverse-primary': 'rgb(var(--rgb-inverse-primary) / <alpha-value>)',

                background: 'rgb(var(--rgb-background) / <alpha-value>)',
                'on-background': 'rgb(var(--rgb-on-background) / <alpha-value>)',
            },

            boxShadow: {
                ambient: 'var(--shadow-ambient)',
                'ambient-md': '0 4px 16px rgba(82, 69, 52, 0.08)',
                'ambient-lg': '0 20px 60px rgba(82, 69, 52, 0.10)',
            },
        },
    },

    plugins: [forms],
};
