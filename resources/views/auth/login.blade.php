<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign In — HealthPass</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.favicon')
</head>
<body class="min-h-full bg-hp-bg">

<x-hp.splash />

{{-- Two-panel layout (FR-AUTH-08): login card left, illustration right, curved
     divider between. Below md (768px) the right panel is hidden and the left
     panel goes full-width, reverting to the original centered single-column. --}}
<div class="flex min-h-screen">

    {{-- Left panel — the existing login card column (internals unchanged) --}}
    <div class="flex w-full items-center justify-center p-6 md:w-1/2">

<div class="hp-page-enter w-full max-w-[420px]">

    {{-- Logo + tagline --}}
    <div class="mb-7 flex flex-col items-center gap-2 text-center">
        <x-hp.logo size="lg" />
        <p class="text-[13px] text-hp-slate/[65%]">Medical Clearance — Pampanga State University</p>
    </div>

    {{-- Card --}}
    <x-hp.card class="mb-3.5">

        {{-- "Sign In" card heading --}}
        <div class="mb-[18px] text-[17px] font-bold text-hp-slate">Sign In</div>

        {{-- Error / status flash --}}
        @if (session('status'))
            <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-700">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-[13px]">
            @csrf

            {{-- Email --}}
            <x-hp.input
                label="Email Address"
                id="email"
                type="email"
                name="email"
                :value="old('email')"
                placeholder="you@psu.edu.ph"
                required
                autofocus
                autocomplete="username"
                :error="$errors->first('email')"
            />

            {{-- Password with eye toggle --}}
            <x-hp.input
                label="Password"
                id="password"
                :password="true"
                name="password"
                placeholder="Enter your password"
                required
                autocomplete="current-password"
                :error="$errors->first('password')"
            />

            {{-- Submit --}}
            <x-hp.button type="submit" variant="primary" size="lg" class="mt-0.5 w-full">
                Sign In
            </x-hp.button>

        </form>

        {{-- Links --}}
        <div class="mt-[14px] text-center text-[12px] text-hp-slate/[55%]">
            Don't have an account?
            <a href="{{ route('register') }}" class="font-semibold text-hp-orange hover:underline">
                Register here
            </a>
        </div>

        @if (Route::has('password.request'))
            <div class="mt-2 text-center text-[12px]">
                <a href="{{ route('password.request') }}" class="text-hp-slate/[55%] hover:underline">
                    Forgot your password?
                </a>
            </div>
        @endif

    </x-hp.card>

    {{-- RA 10173 footer note --}}
    <p class="text-center text-[10.5px] leading-[1.7] text-hp-slate/[45%]">
        This system is governed by <strong>RA 10173</strong> (Data Privacy Act of 2012).<br>
        Health data is used solely for medical clearance purposes.
    </p>

</div>

    </div>{{-- /left panel --}}

    {{-- Right panel — decorative illustration (hidden on narrow screens).
         object-contain + centered shows the whole illustration uncropped at any
         resolution; the panel's off-white bg matches the image's own cream
         backdrop so there's no visible box edge. --}}
    <div class="hidden items-center justify-center bg-hp-bg p-10 md:flex md:w-1/2">
        <img
            src="{{ asset('images/login-illustration.png') }}"
            alt=""
            aria-hidden="true"
            class="max-h-full max-w-full object-contain"
        >
    </div>{{-- /right panel --}}

</div>{{-- /two-panel --}}

</body>
</html>
