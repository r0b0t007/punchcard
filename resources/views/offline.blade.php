{{--
    The offline My cards page (CHW-35): the service worker serves this shell, precached at install, when a page of
    My cards can't load. It is the same for everyone, so nothing here comes from the request: the cards, the
    customer's language and their theme come from the phone (offline.tsx, localStorage). With no cards kept, its
    words come in every language (OfflinePageController), and the page picks the phone's.
--}}
<!DOCTYPE html>
<html lang="en" dir="ltr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <script>
            (function() {
                let appearance = 'system';

                try {
                    appearance = localStorage.getItem('appearance') || 'system';
                } catch (error) {}

                const dark = appearance === 'dark' || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            })();
        </script>

        @include('partials.head')

        {{-- In place of My cards: the customer app's tags. --}}
        @php($pwa = \App\Http\Middleware\UsePwa::describe('customer'))
        <link rel="manifest" href="{{ $pwa['manifest'] }}">
        @foreach ($pwa['meta'] as $name => $content)
            <meta name="{{ $name }}" content="{{ $content }}">
        @endforeach

        <title>punchcard</title>

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/offline.tsx'])
    </head>
    <body class="font-sans antialiased">
        <script type="application/json" id="nothing-kept">@json(['defaultLocale' => $defaultLocale, 'locales' => $nothingKept])</script>
        <div id="offline"></div>
    </body>
</html>
