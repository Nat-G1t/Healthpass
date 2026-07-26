<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        {{-- NOTE (flagged, not fixed): this Breeze layout still loads Figtree
             while the design system is Poppins (loaded via app.css). --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

        @include('partials.theme-init')

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @include('partials.favicon')
    </head>
    <body class="font-sans text-gray-900 dark:text-hp-slate antialiased">
        <x-hp.splash />

        <div class="fixed right-4 top-4 z-10">
            <x-hp.theme-toggle />
        </div>

        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100 dark:bg-hp-bg">
            <div>
                <a href="/">
                    <x-application-logo class="w-20 h-20 fill-current text-gray-500 dark:text-hp-slate/60" />
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-hp-white shadow-md overflow-hidden sm:rounded-lg dark:border dark:border-hp-slate/15 dark:shadow-none">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
