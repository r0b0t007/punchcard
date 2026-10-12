<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Locales::direction(app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #fbf6ee;
            }

            html.dark {
                background-color: #17120e;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/icons/app-icon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- The installable app this page belongs to (UsePwa, CHW-35); PwaHead keeps these in step on Inertia visits. --}}
        @if ($pwa = $page['props']['pwa'] ?? null)
            <link rel="manifest" href="/manifest-{{ $pwa['app'] }}.webmanifest" data-pwa>
            <meta name="theme-color" content="#3B2A20" data-pwa>
            <meta name="mobile-web-app-capable" content="yes" data-pwa>
            <meta name="apple-mobile-web-app-capable" content="yes" data-pwa>
            <meta name="apple-mobile-web-app-status-bar-style" content="default" data-pwa>
            <meta name="apple-mobile-web-app-title" content="{{ $pwa['title'] }}" data-pwa>
        @endif

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
