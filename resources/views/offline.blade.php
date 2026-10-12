{{--
    The offline My cards page (CHW-35): the service worker serves this shell, precached at install, when a page of
    My cards can't load. It is the same for everyone, so nothing here comes from the request: the cards, the
    customer's language and their theme come from the phone (offline.tsx, localStorage).
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

                if (appearance === 'dark' || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

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

        {{-- In place of My cards: the customer app's tags. --}}
        @php($pwa = \App\Http\Middleware\UsePwa::describe('customer'))
        <link rel="manifest" href="{{ $pwa['manifest'] }}">
        @foreach ($pwa['meta'] as $name => $content)
            <meta name="{{ $name }}" content="{{ $content }}">
        @endforeach

        <title>punchcard</title>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/offline.tsx'])
    </head>
    <body class="font-sans antialiased">
        <div id="offline"></div>
    </body>
</html>
