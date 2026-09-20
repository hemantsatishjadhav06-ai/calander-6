<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Default SEO / social-preview metadata. Per-page Inertia <Head> tags
             (e.g. the share page's noindex) are managed separately and win. --}}
        <meta name="description" content="{{ config('app.description') }}">
        <link rel="canonical" href="{{ url()->current() }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:title" content="{{ config('app.name') }} — {{ config('app.tagline') }}">
        <meta property="og:description" content="{{ config('app.description') }}">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ rtrim((string) config('app.url'), '/') }}/android-chrome-512x512.png">

        <meta name="twitter:card" content="summary">
        <meta name="twitter:title" content="{{ config('app.name') }} — {{ config('app.tagline') }}">
        <meta name="twitter:description" content="{{ config('app.description') }}">
        <meta name="twitter:image" content="{{ rtrim((string) config('app.url'), '/') }}/android-chrome-512x512.png">

        @if (config('sentry-browser.dsn'))
            {{-- Browser Sentry config, delivered at runtime so a prebuilt bundle
                 can be pointed at a DSN via env. The DSN is a public value. --}}
            <script nonce="{{ Vite::cspNonce() }}">
                window.__sentry = @js(config('sentry-browser'));
            </script>
        @endif

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script nonce="{{ Vite::cspNonce() }}">
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
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon-16x16.png" type="image/png" sizes="16x16">
        <link rel="icon" href="/favicon-32x32.png" type="image/png" sizes="32x32">
        <link rel="icon" href="/favicon-48x48.png" type="image/png" sizes="48x48">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">
        <link rel="manifest" href="/site.webmanifest">
        <meta name="theme-color" content="#101010">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'SM Manager') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
