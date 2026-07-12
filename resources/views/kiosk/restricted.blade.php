<!DOCTYPE html>
{{--
    Friendly branded 403 for the kiosk network gate (KioskAccess).
    Shown to anyone who reaches /kiosk without being an enrolled device, an
    active nurse, or allowed loopback — e.g. a logged-in student/admin/director
    or a guest hitting the URL directly. Standalone doc (no sidebar) because
    guests, who have no app shell, must be able to see it too.

    $backUrl / $backLabel are passed by the middleware: the signed-in user's
    dashboard, or the login page for a guest.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Restricted — HealthPass</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=poppins:400,500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css'])
    @include('partials.favicon')
</head>
<body class="h-full bg-hp-bg" style="font-family: 'Poppins', sans-serif;">
    <div class="min-h-full flex flex-col items-center justify-center px-6 py-12">

        <div class="mb-8">
            <x-hp.logo size="lg" />
        </div>

        <x-hp.card class="w-full max-w-md text-center">
            {{-- Lock mark --}}
            <div class="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-hp-peach">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#FF8C2A"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            </div>

            <h1 class="text-lg font-semibold text-hp-slate">Terminal access restricted</h1>

            <p class="mt-2 text-sm leading-relaxed text-hp-slate/70">
                This terminal page is restricted to clinic staff. If you came here by
                mistake, head back below — students book clearances and view results
                from their own dashboard.
            </p>

            {{-- The link itself is styled as the primary pill (an <a>, not a
                 <button> inside an <a> — the latter is invalid HTML). --}}
            <a href="{{ $backUrl }}"
               class="mt-6 inline-flex items-center justify-center rounded-full bg-hp-orange px-8 py-[13px]
                      text-[15px] font-semibold text-white transition-colors duration-150 hover:bg-orange-500
                      focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-hp-orange focus-visible:ring-offset-1">
                {{ $backLabel }}
            </a>
        </x-hp.card>

        <p class="mt-6 text-xs text-hp-slate/50">Error 403 · HealthPass Clinic Terminal</p>
    </div>
</body>
</html>
