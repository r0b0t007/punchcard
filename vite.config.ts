import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { createHash } from 'node:crypto';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { VitePWA } from 'vite-plugin-pwa';
import { defineConfig, lazyPlugins } from 'vite-plus';

/**
 * The offline page's revision: everything it is made of, so phones fetch
 * it again whenever any of it changes. The build (its assets, whose names
 * carry their hash, and the fonts), its views and controller, its head
 * tags (UsePwa), its words, and the PHP packages that print its tags
 * (composer.lock). The server's ASSET_URL is outside the build: rebuild
 * after changing it.
 */
function offlineRevision(): string {
    const hash = createHash('sha256');

    [
        'public/build/manifest.json',
        'public/build/fonts-manifest.json',
        'resources/views/offline.blade.php',
        'resources/views/partials/head.blade.php',
        'app/Http/Controllers/OfflinePageController.php',
        'app/Http/Middleware/UsePwa.php',
        'config/punchcard.php',
        'composer.lock',
        ...readdirSync('lang')
            .filter((file) => file.endsWith('.json'))
            .sort()
            .map((file) => `lang/${file}`),
    ].forEach((file) => {
        hash.update(file).update(existsSync(file) ? readFileSync(file) : '');
    });

    return hash.digest('hex').slice(0, 16);
}

/**
 * The app shell the service worker precaches (pwa-conventions skill): the
 * app entry, the chunks it imports statically and their stylesheets. Each
 * page's own chunk loads when the page does, as before; fonts are cached
 * the first time a page uses one (sw.ts).
 */
function shellUrls(): Set<string> {
    const manifest = JSON.parse(
        readFileSync('public/build/manifest.json', 'utf8'),
    ) as Record<string, { file: string; imports?: string[]; css?: string[] }>;
    const urls = new Set<string>();
    const visit = (key: string) => {
        const chunk = manifest[key];

        if (!chunk || urls.has(`/build/${chunk.file}`)) {
            return;
        }

        urls.add(`/build/${chunk.file}`);
        (chunk.css ?? []).forEach((file) => urls.add(`/build/${file}`));
        (chunk.imports ?? []).forEach(visit);
    };
    [
        'resources/js/app.tsx',
        'resources/css/app.css',
        'resources/js/offline.tsx',
    ].forEach(visit);

    return urls;
}

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.tsx',
                // The offline My cards page (CHW-35, resources/views/offline.blade.php).
                'resources/js/offline.tsx',
            ],
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
        // The service worker (CHW-35, pwa-conventions skill): written at the site root so its
        // scope is the whole site, while the assets it precaches stay in /build.
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/js',
            filename: 'sw.ts',
            outDir: 'public',
            // Registered at /sw.js with the whole site as its scope, not under Vite's /build/ base.
            base: '/',
            scope: '/',
            registerType: 'prompt',
            injectRegister: false,
            manifest: false,
            injectManifest: {
                globDirectory: 'public/build',
                // The app shell only: page chunks load on visit, fonts are cached once used (sw.ts).
                globPatterns: ['**/*.{js,css}'],
                modifyURLPrefix: { '': '/build/' },
                // Their names carry a hash: precache them as the page loaded them, from the HTTP
                // cache, not again under a revision (the plugin's default expects assets/, not /build/assets/).
                dontCacheBustURLsMatching: /^\/build\/assets\//,
                manifestTransforms: [
                    (entries) => {
                        const shell = shellUrls();

                        return {
                            manifest: [
                                ...entries.filter((entry) =>
                                    shell.has(entry.url),
                                ),
                                // The offline page (CHW-35): a new revision whenever anything it is made of changes.
                                {
                                    url: '/offline',
                                    revision: offlineRevision(),
                                    size: 0,
                                },
                            ],
                            warnings: [],
                        };
                    },
                ],
            },
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
