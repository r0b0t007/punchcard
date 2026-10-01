import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            // Both faces cover Latin and Arabic (fr/en/ar). Readex Pro for UI,
            // Baloo Bhaijaan 2 for card titles and reward moments.
            fonts: [
                bunny('Readex Pro', {
                    weights: [400, 500, 600],
                    subsets: ['latin', 'latin-ext', 'arabic'],
                    preload: [{ weight: 400 }],
                }),
                bunny('Baloo Bhaijaan 2', {
                    weights: [600, 700],
                    subsets: ['latin', 'latin-ext', 'arabic'],
                    preload: false,
                }),
            ],
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    test: {
        // Unit tests live next to the code; tests/e2e is Playwright's.
        include: ['resources/js/**/*.test.{ts,tsx}'],
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
        // Design-token rules (CHW-136): colours, radii and sizes come from the
        // theme in resources/css/app.css, not raw or arbitrary values. The
        // errors suggest the matching token, so agents fix drift themselves.
        jsPlugins: ['@shadcn/lint'],
        rules: {
            'shadcn/no-raw-colors': 'error',
            'shadcn/no-arbitrary-values': 'error',
            'shadcn/no-restyle': [
                'error',
                {
                    allow: ['layout'],
                    contracts: [
                        // Structural wrapper, not a styled control: callers set its padding.
                        {
                            pattern: '^SidebarGroup$',
                            allow: ['layout', 'spacing'],
                        },
                        // Room for an adornment inside the field (password eye button).
                        { pattern: '^Input$', allow: ['layout', 'ps', 'pe'] },
                    ],
                },
            ],
            'shadcn/no-inline-styles': 'error',
            'shadcn/no-unknown-classes': 'error',
            'shadcn/require-static-classes': 'error',
        },
        overrides: [
            {
                // The Laravel starter splash, replaced by the marketing home in
                // CHW-62. Remove this override with it.
                files: ['resources/js/pages/welcome.tsx'],
                rules: {
                    'shadcn/no-raw-colors': 'off',
                    'shadcn/no-arbitrary-values': 'off',
                },
            },
        ],
        settings: {
            shadcn: {
                note: 'Tokens and rules: .claude/skills/design-tokens/SKILL.md.',
            },
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
