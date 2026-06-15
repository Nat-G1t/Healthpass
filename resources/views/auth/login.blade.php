<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign In — HealthPass</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full items-center justify-center bg-hp-bg px-4 py-12">

<div class="w-full max-w-[420px] space-y-8">

    {{-- Logo + tagline --}}
    <div class="flex flex-col items-center gap-3 text-center">
        <x-hp.logo size="lg" />
        <p class="text-sm text-hp-slate/60">Medical Clearance — Pampanga State University</p>
    </div>

    {{-- Card --}}
    <x-hp.card>

        {{-- Error / status flash --}}
        @if (session('status'))
            <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-700">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-5">
            @csrf

            {{-- Email --}}
            <x-hp.input
                label="Email Address"
                id="email"
                type="email"
                name="email"
                :value="old('email')"
                placeholder="you@example.com"
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
                placeholder="••••••••"
                required
                autocomplete="current-password"
                :error="$errors->first('password')"
            />

            {{-- Submit --}}
            <x-hp.button type="submit" variant="primary" size="lg" class="w-full">
                Sign In
            </x-hp.button>

        </form>

        {{-- Links --}}
        <div class="mt-5 flex flex-col items-center gap-2 text-sm text-hp-slate/60">
            <p>
                Don't have an account?
                <a href="{{ route('register') }}" class="font-semibold text-hp-orange hover:underline">
                    Register here
                </a>
            </p>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="hover:underline">
                    Forgot your password?
                </a>
            @endif
        </div>

    </x-hp.card>

    {{-- RA 10173 footer note --}}
    <p class="text-center text-[11px] leading-relaxed text-hp-slate/40">
        Information collected is protected under the Data Privacy Act of 2012 (RA 10173).<br>
        For concerns, contact the clinic or your Data Protection Officer.
    </p>

</div>

</body>
</html>
