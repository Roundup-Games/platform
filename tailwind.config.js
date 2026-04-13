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

            colors: {
                // Primary — Amber/Gold family
                primary: {
                    DEFAULT: '#835500',
                    container: '#f5a623',
                    fixed: '#ffddb4',
                    'fixed-dim': '#ffb955',
                },
                'on-primary': {
                    DEFAULT: '#ffffff',
                    'fixed-variant': '#633f00',
                    fixed: '#291800',
                    container: '#644000',
                },

                // Secondary — Trustworthy Blue family
                secondary: {
                    DEFAULT: '#0060ac',
                    container: '#68abff',
                    fixed: '#d4e3ff',
                    'fixed-dim': '#a4c9ff',
                },
                'on-secondary': {
                    DEFAULT: '#ffffff',
                    'fixed-variant': '#004883',
                    fixed: '#001c39',
                    container: '#003e73',
                },

                // Tertiary — Warm terracotta family
                tertiary: {
                    DEFAULT: '#944925',
                    container: '#ff9e73',
                    fixed: '#ffdbcd',
                    'fixed-dim': '#ffb596',
                },
                'on-tertiary': {
                    DEFAULT: '#ffffff',
                    'fixed-variant': '#76320f',
                    fixed: '#360f00',
                    container: '#773310',
                },

                // Surface hierarchy — Tonal layering
                surface: {
                    DEFAULT: '#fbf9f1',
                    dim: '#dcdad2',
                    bright: '#fbf9f1',
                    tint: '#835500',
                    variant: '#e4e3db',
                    container: {
                        DEFAULT: '#f0eee6',
                        lowest: '#ffffff',
                        low: '#f5f4ec',
                        high: '#eae8e0',
                        highest: '#e4e3db',
                    },
                },
                'on-surface': {
                    DEFAULT: '#1b1c17',
                    variant: '#524534',
                },

                // Outlines & borders
                outline: {
                    DEFAULT: '#857462',
                    variant: '#d7c3ae',
                },

                // Error
                error: {
                    DEFAULT: '#ba1a1a',
                    container: '#ffdad6',
                },
                'on-error': {
                    DEFAULT: '#ffffff',
                    container: '#93000a',
                },

                // Inverse surfaces
                'inverse-surface': '#30312c',
                'inverse-on-surface': '#f3f1e9',
                'inverse-primary': '#ffb955',

                // Background (alias for surface base)
                background: '#fbf9f1',
                'on-background': '#1b1c17',

                // Backward-compat alias — maps old `brand` to primary.
                // Remove after all Blade templates are migrated to `primary` tokens.
                brand: {
                    DEFAULT: '#835500',
                    dark: '#633f00',
                    light: '#f5a623',
                },
            },

            boxShadow: {
                // Warm ambient shadows — never pure black
                'ambient': '0 12px 40px rgba(82, 69, 52, 0.06)',
                'ambient-md': '0 4px 16px rgba(82, 69, 52, 0.08)',
                'ambient-lg': '0 20px 60px rgba(82, 69, 52, 0.10)',
            },
        },
    },

    plugins: [forms],
};
