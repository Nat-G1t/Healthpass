{{--
    Minimal guest-page shell (logo, centered card, RA 10173 footer) for the
    forgot-password flow — same visual language as the login page, without
    the two-panel illustration layout.
--}}
@props(['title'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} — HealthPass</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.favicon')
</head>
<body class="min-h-full bg-hp-bg">

<x-hp.splash />

<div class="flex min-h-screen items-center justify-center p-6">
    <div class="hp-page-enter w-full max-w-[420px]">

        {{-- Logo + tagline --}}
        <div class="mb-7 flex flex-col items-center gap-2 text-center">
            <x-hp.logo size="lg" />
            <p class="text-[13px] text-hp-slate/[65%]">Medical Clearance — Pampanga State University</p>
        </div>

        <x-hp.card class="mb-3.5">
            {{ $slot }}
        </x-hp.card>

        <p class="text-center text-[10.5px] leading-[1.7] text-hp-slate/[45%]">
            This system is governed by <strong>RA 10173</strong> (Data Privacy Act of 2012).<br>
            Health data is used solely for medical clearance purposes.
        </p>

    </div>
</div>

</body>
</html>
